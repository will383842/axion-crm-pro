<?php

namespace App\Console\Commands;

use App\Crm\EspaceProspection;
use App\Crm\Insee\MiseAJourMensuelle;
use App\Services\Insee\HttpInseeClient;
use Illuminate\Console\Command;

/**
 * MISE À JOUR MENSUELLE INSEE (lot N8, 03/10/2026).
 *
 * Reporte sur les fiches d'un espace TOUTES les modifications Sirene depuis
 * une date : créations du périmètre de l'import, fermetures et passages en
 * non diffusible MARQUÉS (jamais supprimés), champs INSEE mis à jour en
 * respectant les fiches protégées et `field_origins`. La règle complète est
 * dans `App\Crm\Insee\MiseAJourMensuelle`.
 *
 *   php artisan crm:insee:mise-a-jour-mensuelle --dry-run
 *   php artisan crm:insee:mise-a-jour-mensuelle --depuis=2026-07-06 --limite=5000
 *
 * Sans `--depuis` : reprend le passage inachevé s'il y en a un (même date,
 * même curseur), sinon part de la dernière exécution réussie, sinon du
 * rattrapage initial (`MiseAJourMensuelle::DEPUIS_INITIAL`).
 *
 * Planifiée dans `routes/console.php` (mensuelle, mardi→samedi, jamais les
 * 1er/2/3, 08:00-19:00 heure de Paris, sans chevauchement).
 *
 * MISE EN PRODUCTION (avis exactitude R2) : lancer D'ABORD, à la main,
 *   php artisan crm:insee:mise-a-jour-mensuelle --dry-run --duree-max=0
 * pour connaître le volume du rattrapage initial (créations, modifications,
 * fermetures), puis seulement laisser la planification s'en charger. Le
 * plafond `--max-modifications` (défaut 100 000 écritures par passage) et la
 * pause entre pages qui écrivent (`--pause-ms`) bornent le WAL et le
 * gonflement des tables ; un passage plafonné reste `en_cours` et reprend.
 *
 * RIEN N'EST JAMAIS SUPPRIMÉ.
 */
class CrmInseeMiseAJourMensuelle extends Command
{
    public const SIGNATURE_PLANIFIEE = 'crm:insee:mise-a-jour-mensuelle';

    protected $signature = self::SIGNATURE_PLANIFIEE
        . ' {--depuis= : date AAAA-MM-JJ (défaut : reprise, sinon dernière exécution réussie, sinon ' . MiseAJourMensuelle::DEPUIS_INITIAL . ')}'
        . ' {--dry-run : essai à blanc — lit Sirene et donne le bilan chiffré, n écrit RIEN}'
        . ' {--limite=0 : nombre maximal d unités Sirene traitées (0 = sans limite) ; le passage reprendra ensuite}'
        . ' {--duree-max=300 : durée maximale en minutes (0 = sans limite) ; le passage reprendra ensuite}'
        . ' {--workspace= : identifiant ou slug de l espace (défaut : l espace de prospection)}'
        . ' {--departements= : périmètre imposé, ex. 38,69 (défaut : les départements déjà présents dans l espace)}'
        . ' {--delai-ms=2100 : délai minimal entre deux requêtes Sirene (2100 ≈ 30/min, plan public)}'
        . ' {--max-modifications=' . MiseAJourMensuelle::MAX_ECRITURES_DEFAUT . ' : écritures de fiches au plus par passage (0 = sans plafond) ; le passage reprendra ensuite}'
        . ' {--pause-ms=500 : pause après chaque page Sirene qui a écrit (checkpoints, autovacuum)}';

    protected $description = 'Met à jour les fiches depuis les modifications Sirene (créations, fermetures, non diffusibles, champs) — ne supprime rien.';

    public function handle(HttpInseeClient $insee): int
    {
        $depuis = $this->option('depuis');
        $depuis = is_string($depuis) && trim($depuis) !== '' ? trim($depuis) : null;
        if ($depuis !== null && ! HttpInseeClient::estDateIso($depuis)) {
            $this->error("--depuis invalide : « {$depuis} » (attendu AAAA-MM-JJ).");

            return self::FAILURE;
        }

        $designation = $this->option('workspace');
        $workspaceId = EspaceProspection::resoudre(is_string($designation) ? $designation : null);
        if ($workspaceId === null) {
            $this->error('Aucun espace cible (--workspace=UUID ou slug).');

            return self::FAILURE;
        }

        $departements = null;
        $option = $this->option('departements');
        if (is_string($option) && trim($option) !== '') {
            $departements = array_values(array_filter(array_map(
                static fn (string $d): string => strtoupper(trim($d)),
                explode(',', $option),
            ), static fn (string $d): bool => preg_match('/^(\d{2,3}|2A|2B)$/', $d) === 1));
        }

        $essai = (bool) $this->option('dry-run');
        $insee->avecDelaiEntreRequetes((int) $this->option('delai-ms'));
        if ($essai) {
            $this->warn('ESSAI À BLANC — Sirene est lu, RIEN n\'est écrit (ni fiche, ni journal).');
        }

        $resultat = (new MiseAJourMensuelle($insee))->executer(
            $workspaceId,
            $depuis,
            $essai,
            (int) $this->option('limite'),
            (int) $this->option('duree-max'),
            fn (string $ligne) => $this->line($ligne),
            $departements,
            (int) $this->option('max-modifications'),
            (int) $this->option('pause-ms'),
        );

        $b = $resultat['bilan'];
        $this->info(sprintf(
            '%s depuis le %s%s — espace %s',
            $essai ? 'Bilan (ESSAI À BLANC)' : 'Bilan',
            $resultat['depuis'],
            $resultat['reprise'] ? ' (reprise du passage inachevé)' : '',
            substr($workspaceId, 0, 8),
        ));
        $this->line(sprintf('  créations : %d', $b['creations']));
        $this->line(sprintf('  modifications : %d', $b['modifications']));
        $this->line(sprintf('  fermetures : %d', $b['fermetures']));
        $this->line(sprintf('  non diffusibles : %d', $b['non_diffusibles']));
        $this->line(sprintf('  réouvertures : %d', $b['reouvertures']));
        $this->line(sprintf(
            '  (unités lues : %d · pages : %d · fiches tiers prioritaires : %d dont %d inconnues de Sirene · champs gardés (saisie/origine) : %d · fiches protégées gardées : %d · hors périmètre : %d · archivages d un autre motif gardés : %d · valeurs Sirene rejetées (format) : %d · lignes ignorées : %d)',
            $b['unites_lues'],
            $b['pages'],
            $b['prioritaires'],
            $b['inconnues_sirene'],
            $b['champs_preserves'],
            $b['protegees_preservees'],
            $b['hors_perimetre'],
            $b['archives_gardees'],
            $b['valeurs_rejetees'],
            $b['lignes_ignorees'],
        ));
        if ($resultat['statut'] !== 'reussie') {
            $this->warn('Passage INACHEVÉ (limite, plafond d écritures ou durée atteinte) : le curseur est mémorisé, le prochain passage reprendra.');
        }

        return self::SUCCESS;
    }
}
