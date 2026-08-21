<?php

namespace App\Console\Commands;

use App\Crm\Identite\CleDePersonne;
use App\Support\WorkspaceContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * SONDE `A05-001` — LA FICHE 360° EST-ELLE ATTEIGNABLE, OUI OU NON ?
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * POURQUOI CETTE SONDE EXISTE, ET CE QU'ELLE NE FAIT PAS
 * ═══════════════════════════════════════════════════════════════════════════
 *
 * `contacts.person_key` est la clé de rapprochement des personnes : sans elle,
 * la fiche 360° (`/console/personnes/{personKey}`) n'est offerte par le hub pour
 * AUCUNE personne, et le rapprochement entre univers ne peut pas fonctionner.
 *
 * Elle se calcule par HMAC-SHA256 avec un secret qui vit **côté site**
 * (`PII_ENCRYPTION_KEY`), et que le CRM doit recevoir sous le nom
 * `CRM_PERSON_KEY_SECRET`. Son défaut est la **chaîne vide**.
 *
 * ── LE VRAI DÉFAUT N'EST PAS QUE LE SECRET MANQUE. C'EST QUE RIEN NE SONNE ──
 *
 * Mesure du 2026-08-21 :
 *
 *   - la migration `2026_08_20_141000_remplir_cle_personne_sur_le_stock_contacts`
 *     s'AJOURNE proprement quand le secret est absent — elle le journalise UNE
 *     FOIS, au milieu d'un déploiement, et passe ;
 *   - `crm:remplir-cle-personne` n'est **PAS planifiée** (`routes/console.php` :
 *     aucune occurrence) : personne ne la rejoue jamais ;
 *   - **aucune sonde** ne vérifie ce secret.
 *
 * Autrement dit : le produit fonctionne, aucune erreur n'apparaît, et une
 * fonctionnalité entière — celle qui relie une personne à toute son histoire —
 * n'existe pas. *Une panne qui ne fait aucun bruit est une panne qu'on ne
 * répare jamais.* C'est exactement ce que le patron `A08-001 / B16-006` reproche
 * ailleurs à une commande qui a cessé de tourner sans que personne le voie.
 *
 * ⚠️ CETTE SONDE NE POSE PAS LE SECRET, et c'est délibéré : un secret de
 * production ne se pose pas depuis le dépôt (règle 6 du mandat d'audit). Elle
 * rend son absence AUDIBLE, et dit le geste exact qui la fait taire.
 *
 * ── Ce qu'elle rend ────────────────────────────────────────────────────────
 *
 *   CRITICAL  le secret est absent      → la fiche 360° est inatteignable
 *   WARNING   secret posé, stock à faire → il reste N fiches à rattacher
 *   (rien)    secret posé, stock rempli  → le silence est alors mérité
 *
 * Elle sort en ÉCHEC dans les deux premiers cas : le crochet `onFailure()` de
 * `routes/console.php` transforme cet échec en alerte, comme pour les
 * partitions.
 */
class CrmSondeCleDePersonne extends Command
{
    public const SIGNATURE_PLANIFIEE = 'crm:sonde-cle-personne';

    public const PREFIXE_ALERTE = '[A05-001] fiche 360 INATTEIGNABLE';

    protected $signature = self::SIGNATURE_PLANIFIEE;

    protected $description = 'Verifie que la cle de rapprochement des personnes est calculable et posee sur le stock.';

    public function handle(): int
    {
        // ── 1. LE SECRET ────────────────────────────────────────────────────
        if (CleDePersonne::secret() === '') {
            $message = self::PREFIXE_ALERTE . ' : `CRM_PERSON_KEY_SECRET` n\'est pas configure. '
                . 'La cle de rapprochement ne peut pas etre calculee, donc AUCUNE personne n\'a de '
                . 'fiche 360, et le rapprochement entre univers est inerte. '
                . 'GESTE : poser `CRM_PERSON_KEY_SECRET` = `PII_ENCRYPTION_KEY` du site '
                . '(Coolify -> Application -> Env vars, portee RUN), redemarrer, puis jouer '
                . '`php artisan crm:remplir-cle-personne`.';

            Log::critical($message);
            $this->error($message);

            return self::FAILURE;
        }

        // ── 2. LE STOCK ─────────────────────────────────────────────────────
        //
        // ⚠️ `runWithoutScope()` : cette sonde compte a travers TOUS les espaces,
        // et elle n'en a aucun — c'est une tache planifiee. La ceinture
        // applicative est levee explicitement, et elle le DIT (le nom de la
        // raison part dans le journal). Le jour ou `CRM_DB_APP_ROLE_ENABLED`
        // sera arme, cette lecture rendra 0 comme toutes les autres : la sonde
        // annoncera alors « rien a faire » a tort, et c'est le meme piege que
        // celui documente dans `RunsInWorkspace::espaceDepuisLaLigne()`.
        $restants = WorkspaceContext::runWithoutScope(
            'sonde A05-001 : compter les fiches sans cle de rapprochement, tous espaces confondus',
            static fn (): int => (int) DB::table('contacts')
                ->whereNull('person_key')
                ->whereNotNull('email')
                ->whereRaw("btrim(email::text) <> ''")
                ->count(),
        );

        if ($restants > 0) {
            $message = self::PREFIXE_ALERTE . ' : le secret est bien pose, mais ' . $restants
                . ' fiche(s) portent une adresse SANS cle de rapprochement. Leur fiche 360 reste '
                . 'inatteignable. GESTE : jouer `php artisan crm:remplir-cle-personne` '
                . '(rejouable, elle reprend ou elle en etait).';

            Log::warning($message);
            $this->warn($message);

            return self::FAILURE;
        }

        $this->info('Cle de rapprochement : secret pose, et aucune fiche en attente.');

        return self::SUCCESS;
    }
}
