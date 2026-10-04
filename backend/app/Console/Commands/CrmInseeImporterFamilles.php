<?php

namespace App\Console\Commands;

use App\Crm\EspaceProspection;
use App\Crm\Insee\FamillesInsee;
use App\Crm\Insee\ImportFamilles;
use App\Crm\Insee\MiseAJourMensuelle;
use App\Crm\Opco\FenetreOpco;
use App\Services\Insee\HttpInseeClient;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * IMPORT DES FAMILLES INSEE (décision du propriétaire du 04/10/2026).
 *
 * Crée les fiches des unités légales ACTIVES et DIFFUSIBLES d'une famille de
 * catégories juridiques ABSENTES de l'espace — sans jamais modifier une
 * fiche existante, sans jamais rien supprimer (`App\Crm\Insee\ImportFamilles`) :
 *
 *   --famille=7  secteur public (communes, hôpitaux, établissements publics…) : toutes ;
 *   --famille=8  mutuelles, syndicats, CSE, ordres… : toutes ;
 *   --famille=6  sociétés civiles (SCI, SCP, SCM, GAEC…) : avec salariés seulement ;
 *   --famille=9  associations : avec salariés seulement ;
 *   --famille=5  sociétés commerciales : rattrapage des SIREN absents.
 *
 * JAMAIS la catégorie 1 (entrepreneurs individuels), JAMAIS un non diffusible.
 *
 *   php artisan crm:insee:importer-familles --famille=7 --dry-run
 *   php artisan crm:insee:importer-familles --famille=7 --jusqua=18:30
 *
 * Une famille à la fois. Le curseur Sirene est mémorisé à chaque page : un
 * passage arrêté (`--jusqua`, `--limite`, mémoire, coupure) est REPRIS au
 * lancement suivant de la même famille. Un essai à blanc n'écrit RIEN (ni
 * fiche, ni journal, ni curseur) et part toujours du début.
 *
 * FENÊTRE (celle de la mise à jour mensuelle) : un passage réel ne part que
 * du mardi au samedi, de 08:00 à 19:00 heure de Paris, jamais les 1er, 2 et
 * 3 du mois, et s'arrête au plus tard à 19:00. Un essai à blanc, qui n'écrit
 * rien, peut partir à toute heure. Le verrou de la mise à jour mensuelle est
 * pris : les deux ne tournent jamais ensemble (même quota Sirene, même table).
 */
class CrmInseeImporterFamilles extends Command
{
    public const SIGNATURE = 'crm:insee:importer-familles';

    /** Fin de la fenêtre de lancement (19:00), heure de Paris. */
    public const FIN_FENETRE_HEURE = 19;

    /** Durée du verrou partagé avec la mise à jour mensuelle (minutes). */
    public const VERROU_MINUTES = 720;

    protected $signature = self::SIGNATURE
        . ' {--famille= : famille de catégories juridiques : 7, 8, 6, 9 ou 5 (une à la fois)}'
        . ' {--dry-run : essai à blanc — lit Sirene et donne le bilan (à créer, déjà présents, ignorés), n écrit RIEN}'
        . ' {--limite=0 : nombre maximal d unités Sirene lues (0 = sans limite) ; le passage reprendra ensuite}'
        . ' {--jusqua= : heure d arrêt HH:MM (heure de Paris) ; le passage s arrête proprement et reprendra}'
        . ' {--workspace= : identifiant ou slug de l espace (défaut : l espace de prospection)}'
        . ' {--departements= : périmètre imposé, ex. 38,69 (défaut : les départements INSEE déjà présents dans l espace)}'
        . ' {--delai-ms=2100 : délai minimal entre deux requêtes Sirene (2100 ≈ 28/min, sous le quota public de 30)}'
        . ' {--pause-ms=500 : pause après chaque page qui a créé des fiches}'
        . ' {--memoire-max=0 : occupation mémoire en Mo au-delà de laquelle le passage s arrête proprement (0 = 60 % de memory_limit)}';

    protected $description = 'Importe une famille de catégories juridiques INSEE (7, 8, 6/9 avec salariés, 5 en rattrapage) — crée les absents, ne modifie ni ne supprime rien.';

    public function handle(HttpInseeClient $insee): int
    {
        $famille = trim((string) $this->option('famille'));
        if (! FamillesInsee::estFamille($famille)) {
            $this->error('--famille obligatoire : une seule parmi ' . implode(', ', FamillesInsee::FAMILLES) . ' (jamais 1 : entrepreneurs individuels exclus).');

            return self::FAILURE;
        }

        $designation = $this->option('workspace');
        $workspaceId = EspaceProspection::resoudre(is_string($designation) ? $designation : null);
        if ($workspaceId === null) {
            $this->error('Aucun espace cible (--workspace=UUID ou slug).');

            return self::FAILURE;
        }

        $essai = (bool) $this->option('dry-run');
        $maintenant = CarbonImmutable::now(MiseAJourMensuelle::FUSEAU);
        if (! $essai) {
            $refus = FenetreOpco::refus($maintenant);
            if ($refus !== null) {
                $this->error($refus . ' (un --dry-run, qui n écrit rien, peut partir à toute heure).');

                return self::FAILURE;
            }
        }

        $jusqua = null;
        $option = $this->option('jusqua');
        if (is_string($option) && trim($option) !== '') {
            if (preg_match('/^([01]\d|2[0-3]):([0-5]\d)$/', trim($option), $m) !== 1) {
                $this->error("--jusqua invalide : « {$option} » (attendu HH:MM, heure de Paris).");

                return self::FAILURE;
            }
            $jusqua = $maintenant->setTime((int) $m[1], (int) $m[2]);
            if ($jusqua->lessThanOrEqualTo($maintenant)) {
                $this->error("--jusqua={$option} est déjà passé (il est " . $maintenant->format('H:i') . ', heure de Paris).');

                return self::FAILURE;
            }
        }
        if (! $essai) {
            // Un passage réel ne déborde jamais de la fenêtre.
            $fin = $maintenant->setTime(self::FIN_FENETRE_HEURE, 0);
            $jusqua = $jusqua === null || $jusqua->greaterThan($fin) ? $fin : $jusqua;
        }

        $departements = null;
        $option = $this->option('departements');
        if (is_string($option) && trim($option) !== '') {
            $departements = array_values(array_filter(array_map(
                static fn (string $d): string => strtoupper(trim($d)),
                explode(',', $option),
            ), static fn (string $d): bool => preg_match('/^(\d{2,3}|2A|2B)$/', $d) === 1));
        }

        // Des centaines de pages : aucune requête SQL gardée en mémoire.
        foreach (DB::getConnections() as $connexion) {
            $connexion->disableQueryLog();
            $connexion->flushQueryLog();
        }
        DB::connection()->disableQueryLog();

        $verrou = Cache::lock(MiseAJourMensuelle::VERROU, self::VERROU_MINUTES * 60);
        if (! $verrou->get()) {
            $this->error('La mise à jour mensuelle INSEE (ou un autre import) tourne déjà : relancer plus tard.');

            return self::FAILURE;
        }

        try {
            $insee->avecDelaiEntreRequetes((int) $this->option('delai-ms'));
            $memoireMax = min(max(0, (int) $this->option('memoire-max')), CrmInseeMiseAJourMensuelle::MEMOIRE_MAX_MO);
            $import = (new ImportFamilles($insee))
                ->avecPlafondMemoire($memoireMax > 0 ? $memoireMax * 1024 * 1024 : null);

            $this->info(sprintf('Famille %s — %s (lot %s)', $famille, FamillesInsee::LIBELLES[$famille], FamillesInsee::LOT));
            $this->line('  requête Sirene : ' . FamillesInsee::requete($famille));
            if ($essai) {
                $this->warn('ESSAI À BLANC — Sirene est lu, RIEN n\'est écrit (ni fiche, ni journal, ni curseur).');
            }
            if ($jusqua !== null) {
                $this->line('  arrêt prévu à ' . $jusqua->format('H:i') . ' (heure de Paris)');
            }

            $r = $import->executer(
                $workspaceId,
                $famille,
                $essai,
                (int) $this->option('limite'),
                $jusqua,
                fn (string $ligne) => $this->line($ligne),
                $departements,
                (int) $this->option('pause-ms'),
            );
            $this->afficher($r, $essai, $import);
        } finally {
            $verrou->release();
        }

        return self::SUCCESS;
    }

    /**
     * @param  array{statut: string, reprise: bool, bilan: array<string, int>, bilan_passage: array<string, int>, fiches_avant: int, fiches_debut: int, fiches_apres: int, arret_memoire: bool, arret_heure: bool, departements: int}  $r
     */
    private function afficher(array $r, bool $essai, ImportFamilles $import): void
    {
        $b = $r['bilan'];
        $this->info(($essai ? 'Bilan (ESSAI À BLANC)' : 'Bilan') . ($r['reprise'] ? ' — reprise du passage inachevé' : ''));
        $this->line(sprintf('  unités Sirene lues : %d (pages : %d)', $b['unites_lues'], $b['pages']));
        $this->line(sprintf('  à créer : %d', $b['a_creer']));
        $this->line(sprintf('  créées : %d', $b['creees']));
        $this->line(sprintf('  déjà présents : %d', $b['deja_presentes']));
        $this->line(sprintf('  ignorés : %d', $import->ignorees()));
        $this->line(sprintf(
            '    (entrepreneurs individuels : %d · autre famille : %d · non diffusibles : %d · inactives : %d · sans salariés : %d · siège non retenu : %d · hors périmètre (département) : %d · lignes invalides ou refusées : %d)',
            $b['ignorees_individuelles'],
            $b['ignorees_autre_famille'],
            $b['ignorees_non_diffusibles'],
            $b['ignorees_inactives'],
            $b['ignorees_sans_salaries'],
            $b['ignorees_siege'],
            $b['hors_perimetre'],
            $b['lignes_ignorees'],
        ));
        $this->line(sprintf('  départements du périmètre : %d', $r['departements']));
        if ($r['departements'] === 0) {
            $this->warn('Aucun département INSEE dans l espace : rien ne peut être créé (--departements=… pour l imposer).');
        }

        // Comptage avant/après : rien n'est supprimé, après = avant + créées.
        $attendu = $r['fiches_debut'] + ($essai ? 0 : $b['creees']);
        $this->line(sprintf('  fiches de l espace avant : %d · après : %d', $r['fiches_debut'], $r['fiches_apres']));
        if ($essai) {
            $this->line(sprintf('  (après import réel, attendu : %d + %d = %d)', $r['fiches_debut'], $b['a_creer'], $r['fiches_debut'] + $b['a_creer']));
        }
        if ($r['fiches_apres'] === $attendu) {
            $this->line(sprintf('  contrôle : après = avant + créées (%d = %d + %d) ✔', $r['fiches_apres'], $r['fiches_debut'], $essai ? 0 : $b['creees']));
        } else {
            $this->warn(sprintf(
                'CONTRÔLE : après (%d) ≠ avant (%d) + créées (%d) — une autre écriture a eu lieu pendant le passage ; à vérifier.',
                $r['fiches_apres'],
                $r['fiches_debut'],
                $essai ? 0 : $b['creees'],
            ));
        }
        if (! $essai && $r['reprise']) {
            $p = $r['bilan_passage'];
            $this->line(sprintf(
                '  passage complet (tous lancements) : fiches au premier lancement %d, créées %d, après %d',
                $r['fiches_avant'],
                $p['creees'] ?? 0,
                $r['fiches_apres'],
            ));
        }
        $this->line(sprintf('  mémoire : %.1F Mo (pic %.1F Mo)', memory_get_usage(false) / 1048576, memory_get_peak_usage(false) / 1048576));

        if ($r['statut'] === 'reussie') {
            $this->info($essai ? 'Famille lue jusqu au bout.' : 'Famille importée jusqu au bout : passage « reussie ».');

            return;
        }
        $raison = $r['arret_memoire'] ? 'mémoire proche de la limite PHP' : ($r['arret_heure'] ? 'heure d arrêt atteinte' : 'limite atteinte');
        $this->warn($essai
            ? "Essai INCOMPLET ({$raison}) : bilan PARTIEL, rien n est mémorisé."
            : "Passage INACHEVÉ ({$raison}) : le curseur est mémorisé, relancer la même commande le reprend.");
    }
}
