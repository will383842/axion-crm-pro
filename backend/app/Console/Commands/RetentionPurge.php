<?php

namespace App\Console\Commands;

use App\Console\Concerns\RefuseUneSuppressionMassive;
use Illuminate\Console\Command;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Politique de rétention RGPD :
 * - audit_logs > 24 mois         → archivage S3 + suppression (pg_partman gère le detach)
 * - email_validations expirées   → suppression
 * - scraper_runs > 90 jours      → suppression payload_path + response_payload (garde meta)
 * - llm_usage > 12 mois          → archivage + suppression
 * - notifications > 90 jours     → suppression
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * 🔴 DEUX DÉFAUTS MESURÉS DANS CE FICHIER (audit 360).
 *
 * B17-001 / H45-002 (S1) — « L'ESSAI À BLANC ÉCRIVAIT ».
 *
 * L'ancienne boucle réécrivait chaque SQL en `SELECT COUNT(*)` par expression
 * régulière, puis passait le résultat à `DB::selectOne()`. Deux des trois
 * réécritures marchaient ; la troisième, non :
 *
 *     preg_replace('/^UPDATE (\w+) SET .* WHERE/', 'SELECT COUNT(*) AS c FROM $1 WHERE', $sql)
 *
 * Sans le modificateur `s`, le `.` de PCRE **ne franchit pas le saut de ligne**,
 * et l'UPDATE des `scraper_runs` était écrit sur DEUX lignes. La réécriture
 * échouait donc silencieusement (`preg_replace` rend la chaîne inchangée quand
 * rien ne correspond, il ne signale rien), et l'UPDATE INTACT partait dans
 * `DB::selectOne()` — qui fait `prepare()` **puis `execute()`**. Un `--dry-run`
 * détruisait réellement les charges utiles.
 *
 * Mesure jouée sur le code cassé, base `axion_crm_test_lot2` :
 *   - `notifications` après `--dry-run` : 1 ligne — le DELETE, lui, était bien
 *     transformé en COUNT (témoin négatif : la faute portait bien sur le seul
 *     UPDATE, pas sur toute la commande) ;
 *   - `scraper_runs.response_payload` après `--dry-run` : **NULL** — l'UPDATE
 *     s'était exécuté.
 *
 * CORRECTIF : on ne réécrit plus rien. Une chaîne SQL qu'on transforme en
 * expression régulière pour deviner ce qu'elle ferait est une devinette ; le
 * query builder sait compter et écrire à partir de la MÊME condition, sans
 * qu'aucune analyse de texte ne s'interpose. Le compte et l'écriture partagent
 * désormais le même objet `Builder` : ils ne peuvent plus diverger.
 *
 * B11-003 (S1) — « PURGE SANS FILTRE D'ESPACE ».
 *
 * Les trois requêtes portaient sur la table entière, tous locataires confondus :
 * un exploitant ne pouvait purger qu'ABSOLUMENT TOUT, ou rien. Désormais la
 * PORTÉE EST OBLIGATOIRE et EXPLICITE : `--workspace=<uuid|slug>` pour un
 * espace, `--all-workspaces` pour l'aveu écrit qu'on veut tous les toucher.
 * Sans portée, la commande REFUSE — c'est un `artisan` lancé sans y penser qui
 * doit échouer, pas une base qui doit se vider.
 *
 * PATRON A-011 — le trait `RefuseUneSuppressionMassive` existait déjà pour
 * `prospection:purge-non-commercial` / `-non-diffusible` (B15-004) et n'avait
 * jamais été porté ici, alors que cette commande-ci est PLANIFIÉE tous les
 * jours à 04:00. On le branche : essai à blanc, plafond de proportion,
 * confirmation ou `--force`.
 * ══════════════════════════════════════════════════════════════════════════════
 */
class RetentionPurge extends Command
{
    use RefuseUneSuppressionMassive;

    protected $signature = 'retention:purge
                            {--dry-run : Montre ce qui serait purge, sans rien ecrire}
                            {--force : Assume la purge, y compris au-dela du plafond de proportion}
                            {--workspace= : UUID ou slug de l espace a purger}
                            {--all-workspaces : Purge TOUS les espaces a la fois (geste volontaire)}';

    protected $description = 'Applique la politique de rétention RGPD aux tables transversales.';

    public function handle(): int
    {
        $portee = $this->resoudreLaPortee();
        if ($portee === false) {
            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');
        $this->info('Retention purge — dry-run=' . ($dryRun ? 'true' : 'false')
            . ' portee=' . ($portee ?? 'TOUS LES ESPACES'));

        // `email_validations` n'a PAS de colonne `workspace_id` (schéma vérifié :
        // id, email UNIQUE, status, score, mx_host, is_*, checked_at,
        // expires_at). C'est un cache de validation GLOBAL, partagé par tous les
        // espaces. On ne peut donc pas le restreindre à un locataire, et on
        // préfère le DIRE plutôt que purger le cache d'autrui sous couvert d'un
        // `--workspace`.
        $this->purger(
            'email_validations expirees',
            'email_validations',
            fn (): Builder => DB::table('email_validations')
                ->where('expires_at', '<', now()->subDays(7)),
            fn (Builder $q): int => $q->delete(),
            portee: $portee,
            colonneEspace: null,
        );

        $this->purger(
            'notifications anciennes (>90j)',
            'notifications',
            fn (): Builder => DB::table('notifications')
                ->where('created_at', '<', now()->subDays(90)),
            fn (Builder $q): int => $q->delete(),
            portee: $portee,
            colonneEspace: 'workspace_id',
        );

        // Rétention de la charge utile de scraping : on efface le PAYLOAD (qui
        // porte des données personnelles collectées) et on GARDE la ligne de
        // run (méta : source, statut, latence) — la traçabilité de la collecte
        // survit à l'effacement des données collectées.
        $this->purger(
            'scraper_runs payload (>90j)',
            'scraper_runs',
            fn (): Builder => DB::table('scraper_runs')
                ->where('created_at', '<', now()->subDays(90))
                ->whereNotNull('response_payload'),
            fn (Builder $q): int => $q->update(['response_payload' => null, 'payload_path' => null]),
            portee: $portee,
            colonneEspace: 'workspace_id',
        );

        return self::SUCCESS;
    }

    /**
     * Rend l'UUID de l'espace visé, `null` pour « tous les espaces », ou
     * `false` si la portée n'a pas été dite — auquel cas on ne purge rien.
     */
    private function resoudreLaPortee(): string|null|false
    {
        $demande = $this->option('workspace');
        $tous = (bool) $this->option('all-workspaces');

        if ($demande !== null && $demande !== '' && $tous) {
            $this->error('REFUS : --workspace et --all-workspaces se contredisent. Choisissez.');

            return false;
        }

        if ($demande === null || $demande === '') {
            if (! $tous) {
                // 🔴 B11-003 : sans cette porte, `artisan retention:purge` purgeait
                // silencieusement TOUS les locataires à la fois.
                $this->error('REFUS : portee non precisee. Utilisez --workspace=<uuid|slug> ou, en connaissance de cause, --all-workspaces.');

                return false;
            }

            return null;
        }

        $espace = Str::isUuid((string) $demande)
            ? DB::table('workspaces')->where('id', $demande)->value('id')
            : DB::table('workspaces')->where('slug', $demande)->value('id');

        if ($espace === null) {
            $this->error("REFUS : espace « {$demande} » introuvable. On ne devine pas un locataire.");

            return false;
        }

        return (string) $espace;
    }

    /**
     * Une tâche de rétention : compter, demander l'autorisation, puis agir —
     * sur EXACTEMENT la même condition. Le compte et l'écriture partagent le
     * même `Builder`, ce qui rend structurellement impossible la divergence qui
     * a produit B17-001.
     *
     * @param  \Closure():Builder  $condition
     * @param  \Closure(Builder):int  $action
     * @param  string|null  $portee  UUID de l'espace, ou null pour tous
     * @param  string|null  $colonneEspace  colonne de rattachement, ou null si la table est globale
     */
    private function purger(
        string $libelle,
        string $table,
        \Closure $condition,
        \Closure $action,
        ?string $portee,
        ?string $colonneEspace,
    ): void {
        $this->line("→ {$libelle}");

        if ($portee !== null && $colonneEspace === null) {
            $this->warn("  SAUTEE : « {$table} » est GLOBALE (aucune colonne d'espace), elle n'est purgee qu'avec --all-workspaces.");

            return;
        }

        // ⚠️ ON NE RE-TESTE PAS `$colonneEspace !== null`, ET CE N'EST PAS UN
        // OUBLI. La garde ci-dessus renvoie des qu'une portee est demandee sur
        // une table GLOBALE : passe ce point, « `$portee` non nul » IMPLIQUE
        // « `$colonneEspace` non nul ». Le re-tester etait du code mort — mesure
        // du 2026-08-21, PHPStan : « Strict comparison using !== between string
        // and null will always evaluate to true ». Une condition qui ne peut pas
        // etre fausse ne protege rien ; elle donne seulement l'impression qu'un
        // cas est couvert.
        $filtree = function () use ($condition, $portee, $colonneEspace): Builder {
            $q = $condition();
            if ($portee !== null) {
                $q->where($colonneEspace, $portee);
            }

            return $q;
        };

        // Le total sert de dénominateur au plafond du trait. Il est mesuré DANS
        // LA MÊME PORTÉE que la condition : sinon, purger 100 % d'un petit
        // espace passerait pour 0,1 % de la table et échapperait au plafond.
        // Meme raison qu'au-dessus pour la condition unique. Le `when()` est
        // remplace par un branchement : la condition d'un `when()` est evaluee
        // HORS de sa fermeture, donc le fait que `$colonneEspace` soit non nul
        // n'y est pas su — `->where()` recevait `string|null`.
        $total = $portee !== null
            ? DB::table($table)->where($colonneEspace, $portee)->count()
            : DB::table($table)->count();

        $aPurger = $filtree()->count();

        if (! $this->suppressionAutorisee($table, $aPurger, $total)) {
            return;
        }

        $traitees = $action($filtree());
        $this->info("  OK {$libelle} : {$traitees} lignes traitees.");
    }
}
