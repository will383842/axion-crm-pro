<?php

namespace App\Console\Commands;

use App\Crm\Identite\CleDePersonne;
use App\Support\WorkspaceContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * 🔴 A05-001 (S1) — REMPLISSAGE DE `contacts.person_key` SUR LE STOCK.
 *
 * Mesure du 2026-08-18 en production : **1 319 567 contacts, 410 481 avec
 * e-mail, 0 avec `person_key`**. La fiche 360° (`/console/personnes/$personKey`)
 * n'était offerte par le hub que `si contact.person_key !== null` : un écran
 * réel, jamais atteignable.
 *
 * ── POURQUOI PAS UN SEUL `UPDATE` ──────────────────────────────────────────
 *
 * Un `UPDATE contacts SET person_key = …` global sur 1,3 M de lignes prend un
 * verrou de ligne sur chacune, fait enfler le WAL, et une coupure au milieu
 * laisse un travail à moitié fait qu'il faut recommencer en entier. Ici :
 *
 *   · par LOTS bornés (`--taille-lot`, 5 000 par défaut) ;
 *   · par PAGINATION SUR LA CLÉ (`id > curseur`), pas par `OFFSET` — un OFFSET
 *     re-parcourt tout ce qui précède à chaque lot ;
 *   · REJOUABLE : le filtre est `person_key IS NULL`. Une exécution
 *     interrompue se reprend en la relançant, et une exécution complète
 *     relancée ne touche 0 ligne.
 *
 * ── POURQUOI UNE BOUCLE PAR WORKSPACE ──────────────────────────────────────
 *
 * `contacts` porte `FORCE ROW LEVEL SECURITY` et une policy STRICTE (lot L0) :
 * sans `app.current_workspace_id`, un rôle non-propriétaire voit ZÉRO ligne, et
 * l'`UPDATE` réussirait en n'écrivant rien. Un remplissage qui rend « 0 fiche
 * traitée » alors qu'il en reste 410 481 est exactement le genre de silence qui
 * a produit ce constat. On pose donc le contexte, espace par espace.
 *
 * ── CE QUE CETTE COMMANDE NE FAIT JAMAIS ───────────────────────────────────
 *
 * Inventer un sel. Sans `CRM_PERSON_KEY_SECRET`, elle REFUSE (cf.
 * `App\Crm\Identite\CleDePersonne` pour le pourquoi : des clés incompatibles
 * avec le site rendraient muets l'export art. 15 et l'effacement art. 17).
 */
class CrmRemplirClePersonne extends Command
{
    protected $signature = 'crm:remplir-cle-personne
        {--taille-lot=5000 : nombre de fiches par lot}
        {--max-lots=0 : borne le nombre total de lots (0 = jusqu au bout)}
        {--simulation : ne rien ecrire, compter seulement ce qui serait rempli}';

    protected $description = 'Pose contacts.person_key sur les fiches qui en sont depourvues (par lots, rejouable).';

    public function handle(): int
    {
        if (! CleDePersonne::estConfiguree()) {
            $this->error(
                'REFUS : CRM_PERSON_KEY_SECRET n\'est pas configure. '
                . 'La cle de rapprochement est un HMAC-SHA256 dont le secret est celui du site '
                . '(PII_ENCRYPTION_KEY). Sans lui, ce remplissage fabriquerait 410 481 cles '
                . 'incompatibles avec le site : la fiche 360 deviendrait cliquable, mais le '
                . 'rapprochement resterait impossible ET l\'export RGPD art. 15 / l\'effacement '
                . 'art. 17 servis par /internal/site-sync/gdpr cesseraient de retrouver les fiches. '
                . 'Poser CRM_PERSON_KEY_SECRET = PII_ENCRYPTION_KEY du site, puis relancer.',
            );

            return self::FAILURE;
        }

        $taille = max(1, (int) $this->option('taille-lot'));
        $maxLots = max(0, (int) $this->option('max-lots'));
        $simulation = (bool) $this->option('simulation');

        $espaces = DB::table('workspaces')->orderBy('id')->pluck('id');

        $total = 0;
        $lotsJoues = 0;

        foreach ($espaces as $espace) {
            $restant = $maxLots === 0 ? null : max(0, $maxLots - $lotsJoues);
            if ($restant === 0) {
                break;
            }

            [$remplies, $lots] = WorkspaceContext::run(
                (string) $espace,
                fn (): array => $simulation
                    ? [$this->compterUnEspace((string) $espace), 0]
                    : $this->remplirUnEspace((string) $espace, $taille, $restant),
            );

            $total += $remplies;
            $lotsJoues += $lots;
        }

        $verbe = $simulation ? 'a remplir' : 'remplie(s)';
        $this->info("Remplissage termine : {$total} fiche(s) {$verbe} en {$lotsJoues} lot(s), sur "
            . $espaces->count() . ' espace(s) de travail.');

        // Le RESTE, dit explicitement : une commande bornee par `--max-lots` ne
        // doit pas laisser croire que le stock est traite.
        $reste = $this->compterToutesLesFiches($espaces->all());
        if ($reste > 0) {
            $this->warn("Il reste {$reste} fiche(s) avec une adresse et sans cle. Relancer la commande "
                . '(elle est rejouable et reprend la ou elle en etait).');
        }

        return self::SUCCESS;
    }

    /**
     * Un lot = un `UPDATE … FROM (CTE)` qui rend les identifiants traites.
     * Le curseur avance sur `id` : la boucle se termine meme si une ligne
     * refusait obstinement d'etre remplie.
     *
     * @return array{0: int, 1: int} [fiches remplies, lots joues]
     */
    private function remplirUnEspace(string $espace, int $taille, ?int $lotsRestants): array
    {
        $expression = CleDePersonne::expressionSql('c.email');
        $secret = CleDePersonne::secret();

        $curseur = 0;
        $remplies = 0;
        $lots = 0;

        while ($lotsRestants === null || $lots < $lotsRestants) {
            // ⚠️ Ordre des parametres lies = ordre d'APPARITION dans le texte
            // SQL : la CTE (espace, curseur) precede le SET (secret).
            $lignes = DB::select(
                <<<SQL
                WITH lot AS (
                    SELECT id
                    FROM   contacts
                    WHERE  workspace_id = ?
                      AND  person_key IS NULL
                      AND  email IS NOT NULL
                      AND  btrim(email::text) <> ''
                      AND  id > ?
                    ORDER  BY id
                    LIMIT  {$taille}
                )
                UPDATE contacts c
                SET    person_key = {$expression}
                FROM   lot
                WHERE  c.id = lot.id
                RETURNING c.id
                SQL,
                [$espace, $curseur, $secret],
            );

            $nombre = count($lignes);
            if ($nombre === 0) {
                break;
            }

            $remplies += $nombre;
            $lots++;
            $curseur = max(array_map(static fn ($l): int => (int) $l->id, $lignes));

            $this->line("  espace {$espace} : lot {$lots}, {$nombre} fiche(s), curseur id={$curseur}");

            if ($nombre < $taille) {
                break;
            }
        }

        return [$remplies, $lots];
    }

    private function compterUnEspace(string $espace): int
    {
        return (int) DB::table('contacts')
            ->where('workspace_id', $espace)
            ->whereNull('person_key')
            ->whereNotNull('email')
            ->whereRaw("btrim(email::text) <> ''")
            ->count();
    }

    /** @param  list<mixed>  $espaces */
    private function compterToutesLesFiches(array $espaces): int
    {
        $reste = 0;
        foreach ($espaces as $espace) {
            $reste += WorkspaceContext::run(
                (string) $espace,
                fn (): int => $this->compterUnEspace((string) $espace),
            );
        }

        return $reste;
    }
}
