<?php

namespace App\Crm\Insee;

use App\Crm\EspaceProspection;
use App\Crm\FichesProtegees;
use App\Crm\Referentiels\Classement;
use App\Crm\Referentiels\NomenclatureNaf;
use App\Services\Insee\HttpInseeClient;
use App\Services\Insee\InseeErreurHttp;
use App\Support\WorkspaceContext;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;

/**
 * LA MISE À JOUR MENSUELLE INSEE (lot N8, 03/10/2026) —
 * `crm:insee:mise-a-jour-mensuelle`.
 *
 * Interroge Sirene sur `dateDernierTraitementUniteLegale` depuis une date et
 * reporte TOUTES les modifications sur les fiches d'un espace :
 *
 *  - CRÉATION   une unité absente de l'espace, dont le siège entre dans le
 *               périmètre des familles (`HttpInseeClient::
 *               estDansPerimetreFamilles` : siège actif, diffusible, catégorie
 *               juridique 5, 7 ou 8, ou 6 et 9 avec salariés — décision du
 *               04/10/2026, `FamillesInsee` ; jamais 1) et dans un département
 *               DÉJÀ présent dans l'espace ;
 *               ligne construite par `LigneFicheInsee` (celle de
 *               `prospection:collect`). Jamais par-dessus une fiche
 *               existante (`ON CONFLICT DO NOTHING`).
 *  - FERMETURE  état administratif `C` : la fiche est MARQUÉE
 *               (`insee_ferme_le`). Elle sort de la prospection
 *               (`archived_no_email` / `entreprise_radiee`, la règle déjà
 *               respectée par le triage) SEULEMENT si elle n'est pas déjà
 *               archivée pour un autre motif : un `archive_reason` posé par
 *               une personne ou un autre traitement (`manual`, `duplicate`,
 *               `archived_no_email`…) n'est JAMAIS écrasé (veto sécurité
 *               #313, bloquant 1). Une réouverture (`A`) lève le marquage et
 *               ne remet en prospection QUE ce que l'INSEE avait archivé
 *               (`archive_reason = entreprise_radiee`).
 *  - NON DIFFUSIBLE statut `P` ou `N` de l'unité — ou de son siège, joint
 *               par la passe prioritaire (`HttpInseeClient::estDiffusible`) :
 *               la fiche est MARQUÉE (`insee_non_diffusible_le`) et sort de
 *               toute campagne (motif `non_diffusible` d'`EligibiliteAdresse`),
 *               de toute audience et de l'export des entreprises (filtre
 *               sur le marquage). `archive_reason = non_diffusible` n'est posé
 *               que si la fiche n'est pas archivée pour un motif non INSEE.
 *               Aucun champ n'est recopié d'une unité opposée (les `[ND]`).
 *               Le marquage est À SENS UNIQUE : une unité redevenue `O` reste
 *               marquée — lever une opposition est une décision humaine
 *               (avis exactitude R4, choix prudent, testé). Une unité non
 *               diffusible ET fermée reçoit aussi `insee_ferme_le` (simple
 *               marqueur : la fiche est déjà hors prospection).
 *  - MODIFICATION dénomination, activité (et son classement), forme
 *               juridique, tranche d'effectif (et la taille) — SAUF :
 *               les colonnes métier d'une fiche PROTÉGÉE (`FichesProtegees`)
 *               ne sont jamais réécrites — seuls les marqueurs
 *               `insee_ferme_le`, `insee_non_diffusible_le` et
 *               `insee_verifiee_le` y sont posés, décision explicite : c'est
 *               ce qui exclut une fiche protégée opposée des campagnes
 *               (avis exactitude R7) ; un champ dont `field_origins` dit
 *               autre chose que `ORIGINES_REMPLACABLES` (saisie manuelle
 *               `declared`, import de fédérations…) est gardé. Une valeur au
 *               format inattendu (`FORMATS`) ou une dénomination trop longue
 *               est IGNORÉE et comptée (`valeurs_rejetees`) ; une fiche dont
 *               l'écriture échoue est ignorée et comptée (`lignes_ignorees`),
 *               dans son propre point de sauvegarde : jamais de page
 *               « poison » rejouée sans fin.
 *
 * RIEN N'EST JAMAIS SUPPRIMÉ : aucune ligne de ce fichier n'émet de DELETE,
 * ni ne pose `deleted_at`.
 *
 * Les fiches « non vérifiées INSEE » (`insee_verifiee_le` NULL) de
 * PROVENANCE TIERS passent EN PREMIER (`fichesPrioritaires`), par paquets
 * de SIREN, avant le flux des modifications ; le flux SAUTE les SIREN déjà
 * traités par cette passe (sinon `--dry-run` les compterait deux fois —
 * avis exactitude R1).
 *
 * GARDE-FOUS D'ÉCRITURE (avis exactitude R2, disque à ≈ 8 Go libres) : au
 * plus `--max-modifications` écritures par passage (défaut
 * `MAX_ECRITURES_DEFAUT`), au-delà le passage reste `en_cours` et reprendra ;
 * une pause (`--pause-ms`) après chaque page qui a écrit, pour laisser
 * checkpoints et autovacuum suivre.
 *
 * Traitement SÉQUENTIEL et léger (serveur à 2 CPU) : une page Sirene à la
 * fois, une requête de lecture indexée par page, une transaction par page.
 * Le curseur Sirene est mémorisé dans `insee_mises_a_jour` à chaque page :
 * une coupure, `--limite` ou la durée maximale laissent un passage
 * `en_cours`, que le passage suivant REPREND. Une écriture se fait par fiche
 * et par clé primaire (avis R8 : acceptable au débit imposé par Sirene,
 * ≈ 28 pages par minute au plus ; un `UPDATE … FROM (VALUES …)` par page est
 * une optimisation possible plus tard).
 *
 * MÉMOIRE CONSTANTE (incident du 03/10/2026 : 128 Mo épuisés après ≈ 6 min
 * d'un rattrapage à blanc) : une page à la fois, libérée avant la suivante
 * (`PageSirene`), un bilan fait de compteurs seulement, le journal des
 * requêtes SQL coupé. GARDE : si l'occupation approche la limite PHP
 * (`SEUIL_MEMOIRE` de `memory_limit`), le passage s'arrête PROPREMENT après
 * la page en cours — curseur mémorisé, statut `en_cours`, comme
 * `--duree-max` — au lieu de mourir.
 *
 * LIMITE CONNUE (avis R5) : dans le FLUX, seul le statut de l'unité est lu —
 * la voie `/siren` ne porte pas celui du siège. Le statut du siège
 * (`statutDiffusionEtablissement`) est lu pour les fiches de la passe
 * prioritaire et pour toute création (voie `/siret`).
 */
final class MiseAJourMensuelle
{
    /** Rattrapage initial : la veille de l'import initial. */
    public const DEPUIS_INITIAL = '2026-07-06';

    public const MOTIF_NON_DIFFUSIBLE = 'non_diffusible';

    public const MOTIF_FERMETURE = 'entreprise_radiee';

    public const ORIGINE = 'insee';

    /**
     * Les motifs d'archivage que CE traitement pose. Un `archive_reason`
     * différent (`manual`, `duplicate`, `archived_no_email`…) est une
     * décision d'une personne ou d'un autre traitement : jamais écrasé.
     *
     * @var list<string>
     */
    public const MOTIFS_INSEE = [self::MOTIF_FERMETURE, self::MOTIF_NON_DIFFUSIBLE];

    /** Écritures de fiches (créations comprises), au plus, par passage. */
    public const MAX_ECRITURES_DEFAUT = 100000;

    /** Longueur maximale d'une dénomination recopiée (Sirene : 120). */
    public const DENOMINATION_MAX = 250;

    /**
     * Le format attendu des valeurs Sirene recopiées : une autre valeur est
     * ignorée et comptée (`valeurs_rejetees`).
     *
     * @var array<string, string>
     */
    public const FORMATS = [
        'naf' => '/^\d{2}\.\d{2}[A-Z]$/',
        'legal_form' => '/^\d{4}$/',
        'effectif_range' => '/^(NN|\d{2})$/',
    ];

    /**
     * Les origines (`field_origins`) qu'une donnée INSEE peut remplacer :
     * l'INSEE lui-même, et une valeur collectée automatiquement. Toute autre
     * (saisie `declared`, import de fédérations…) est GARDÉE. Un champ sans
     * origine connue est remplaçable.
     *
     * @var list<string>
     */
    public const ORIGINES_REMPLACABLES = [self::ORIGINE, 'collected', 'scraped'];

    /** Fiches de la passe prioritaire, au plus, par passage. */
    public const PRIORITE_PAR_PASSAGE = 20000;

    /**
     * Le prédicat de l'index partiel `idx_companies_insee_priorite`, MOT POUR
     * MOT (migration `2026_10_04_000020`) : une autre écriture ne serait plus
     * reconnue par le planificateur.
     */
    public const PREDICAT_PRIORITE = "siren IS NOT NULL AND discovery_source IS DISTINCT FROM 'insee' AND deleted_at IS NULL";

    public const FUSEAU = 'Europe/Paris';

    /** Le jour du mois de la mensuelle (`estJourPlanifie`). */
    public const JOUR_DU_MOIS = 4;

    /** Le verrou partagé par la mensuelle et ses reprises (`routes/console.php`). */
    public const VERROU = 'crm-insee-mise-a-jour-mensuelle';

    /** Compteurs du bilan, dans l'ordre d'affichage. */
    public const COMPTEURS = [
        'creations', 'modifications', 'fermetures', 'non_diffusibles', 'reouvertures',
        'prioritaires', 'inconnues_sirene', 'unites_lues', 'pages', 'champs_preserves', 'protegees_preservees', 'hors_perimetre',
        'archives_gardees', 'valeurs_rejetees', 'lignes_ignorees',
    ];

    /**
     * Part de `memory_limit` au-delà de laquelle le passage s'arrête
     * proprement : la marge restante doit tenir une page Sirene (corps brut
     * et décodage) et sa transaction.
     */
    public const SEUIL_MEMOIRE = 0.6;

    /** @var array<string, int> */
    private array $bilan = [];

    /** Plafond mémoire en octets (null : déduit de `memory_limit`, 0 : sans garde). */
    private ?int $plafondMemoire = null;

    private bool $arretMemoire = false;

    /** @var array<string, int> bilan déjà journalisé d'un passage repris */
    private array $bilanAnterieur = [];

    private bool $essai = false;

    private int $limite = 0;

    private ?float $echeance = null;

    private int $maxEcritures = self::MAX_ECRITURES_DEFAUT;

    private int $pauseMs = 0;

    /** @var array<string, true> SIREN déjà traités par la passe prioritaire */
    private array $dejaTraites = [];

    private string $workspaceId = '';

    private ?string $maintenant = null;

    /** @var list<string>|null */
    private ?array $departements = null;

    public function __construct(private readonly HttpInseeClient $insee) {}

    /**
     * Impose le plafond mémoire de la garde, en octets (0 : sans garde ;
     * null : `SEUIL_MEMOIRE` de `memory_limit`).
     */
    public function avecPlafondMemoire(?int $octets): static
    {
        $this->plafondMemoire = $octets === null ? null : max(0, $octets);

        return $this;
    }

    /** Le plafond effectif de la garde, en octets (0 : aucune garde, `memory_limit = -1`). */
    public function plafondMemoire(): int
    {
        if ($this->plafondMemoire !== null) {
            return $this->plafondMemoire;
        }
        $limite = self::octets((string) ini_get('memory_limit'));

        return $limite > 0 ? (int) ($limite * self::SEUIL_MEMOIRE) : 0;
    }

    /** `128M`, `1G`, `-1`… en octets (≤ 0 : sans limite). */
    public static function octets(string $valeur): int
    {
        $valeur = trim($valeur);
        if ($valeur === '' || ! is_numeric(rtrim($valeur, 'kKmMgG'))) {
            return 0;
        }
        $n = (int) $valeur;

        return match (strtolower(substr($valeur, -1))) {
            'g' => $n * 1024 * 1024 * 1024,
            'm' => $n * 1024 * 1024,
            'k' => $n * 1024,
            default => $n,
        };
    }

    /** Vrai si le dernier `executer()` s'est arrêté sur la garde mémoire. */
    public function arreteParLaMemoire(): bool
    {
        return $this->arretMemoire;
    }

    /**
     * Le jour et l'heure de la planification : le 4 du mois
     * (`JOUR_DU_MOIS`), QUEL QUE SOIT le jour de la semaine — dimanche et
     * lundi compris (décision du 04/10/2026 : les tâches planifiées lourdes
     * tournent la nuit, la journée est libre) —, entre 08:00 et 19:00, heure
     * de Paris. Exactement un jour par mois.
     */
    public static function estJourPlanifie(CarbonInterface $instant): bool
    {
        $t = CarbonImmutable::instance($instant)->setTimezone(self::FUSEAU);

        return self::dansLesHeures($t) && $t->day === self::JOUR_DU_MOIS;
    }

    /**
     * Un jour de REPRISE possible (avis exactitude R6) : TOUS LES JOURS,
     * 08:00-19:00 heure de Paris, sauf le jour de la mensuelle
     * (`estJourPlanifie`) — tant qu'un passage reste à finir
     * (`repriseEnAttente`).
     */
    public static function estJourDeReprise(CarbonInterface $instant): bool
    {
        $t = CarbonImmutable::instance($instant)->setTimezone(self::FUSEAU);

        return self::dansLesHeures($t) && $t->day !== self::JOUR_DU_MOIS;
    }

    /**
     * Un passage `en_cours` ou `echouee`, postérieur à la dernière exécution
     * réussie, attend-il d'être repris dans l'espace de prospection ?
     */
    public static function repriseEnAttente(?string $workspaceId = null): bool
    {
        $workspaceId ??= EspaceProspection::resoudre(null);
        if ($workspaceId === null) {
            return false;
        }

        return WorkspaceContext::run($workspaceId, static function () use ($workspaceId): bool {
            $idReussie = DB::table('insee_mises_a_jour')
                ->where('workspace_id', $workspaceId)->where('statut', 'reussie')->max('id');

            return DB::table('insee_mises_a_jour')
                ->where('workspace_id', $workspaceId)->whereIn('statut', ['en_cours', 'echouee'])
                ->when($idReussie !== null, static fn ($q) => $q->where('id', '>', $idReussie))
                ->exists();
        });
    }

    private static function dansLesHeures(CarbonImmutable $t): bool
    {
        return $t->hour >= 8 && $t->hour < 19;
    }

    /**
     * @param  ?string  $depuis  AAAA-MM-JJ ; null = reprise du passage inachevé, sinon dernière exécution réussie, sinon `DEPUIS_INITIAL`
     * @param  ?callable(string): void  $journal  reçoit l'avancement (une ligne par page)
     * @param  ?list<string>  $departements  périmètre imposé (sinon : les départements présents dans l'espace)
     * @param  int  $maxEcritures  écritures de fiches au plus par passage (0 = sans plafond)
     * @param  int  $pauseMs  pause après chaque page qui a écrit
     * @return array{statut: string, depuis: string, reprise: bool, bilan: array<string, int>, arret_memoire: bool}
     */
    public function executer(
        string $workspaceId,
        ?string $depuis,
        bool $essai = false,
        int $limite = 0,
        int $dureeMaxMinutes = 0,
        ?callable $journal = null,
        ?array $departements = null,
        int $maxEcritures = self::MAX_ECRITURES_DEFAUT,
        int $pauseMs = 0,
    ): array {
        $this->workspaceId = $workspaceId;
        $this->essai = $essai;
        $this->limite = max(0, $limite);
        $this->echeance = $dureeMaxMinutes > 0 ? microtime(true) + $dureeMaxMinutes * 60 : null;
        $this->bilan = array_fill_keys(self::COMPTEURS, 0);
        $this->bilanAnterieur = [];
        $this->departements = $departements;
        $this->maxEcritures = max(0, $maxEcritures);
        $this->pauseMs = max(0, $pauseMs);
        $this->dejaTraites = [];
        $this->arretMemoire = false;
        $this->maintenant = now()->toIso8601String();

        return WorkspaceContext::run($workspaceId, function () use ($depuis, $journal): array {
            [$passageId, $depuisRetenu, $curseur, $reprise] = $this->ouvrirPassage($depuis);

            try {
                $termine = $this->derouler($passageId, $depuisRetenu, $curseur, $journal);
            } catch (\Throwable $e) {
                if ($passageId !== null) {
                    DB::table('insee_mises_a_jour')->where('id', $passageId)->where('workspace_id', $this->workspaceId)->update([
                        // Statut et chemin seulement (réserve sécurité 7) :
                        // jamais un corps de réponse ni un message SQL.
                        'statut' => 'echouee', 'erreur' => self::erreurJournalisable($e),
                        'bilan' => json_encode($this->bilanDuPassage()), 'maj_le' => now(),
                    ]);
                }
                throw $e;
            }

            if ($passageId !== null) {
                DB::table('insee_mises_a_jour')->where('id', $passageId)->where('workspace_id', $this->workspaceId)->update([
                    'statut' => $termine ? 'reussie' : 'en_cours', 'erreur' => null,
                    'bilan' => json_encode($this->bilanDuPassage()), 'maj_le' => now(),
                    'terminee_le' => $termine ? now() : null,
                ]);
            }

            return [
                'statut' => $termine ? 'reussie' : 'en_cours',
                'depuis' => $depuisRetenu,
                'reprise' => $reprise,
                'bilan' => $this->bilan,
                'arret_memoire' => $this->arretMemoire,
            ];
        });
    }

    /** Ce que le journal garde d'une erreur : statut et chemin Sirene, sinon sa seule classe. */
    public static function erreurJournalisable(\Throwable $e): string
    {
        return $e instanceof InseeErreurHttp
            ? mb_substr($e->getMessage(), 0, 200)
            : 'Erreur ' . class_basename($e) . ' (détail dans le journal applicatif)';
    }

    /**
     * Le passage à mener : reprise du dernier passage inachevé (sans `--depuis`),
     * sinon un nouveau, depuis la date donnée, la dernière exécution réussie
     * ou le rattrapage initial. Un essai à blanc n'écrit pas de journal.
     *
     * @return array{0: ?int, 1: string, 2: string, 3: bool} [id du passage, depuis, curseur, reprise]
     */
    private function ouvrirPassage(?string $depuis): array
    {
        $derniereReussie = DB::table('insee_mises_a_jour')
            ->where('workspace_id', $this->workspaceId)->where('statut', 'reussie')
            ->orderByDesc('demarree_le')->orderByDesc('id')->first(['id', 'demarree_le']);

        $idReussie = $derniereReussie !== null ? (int) $derniereReussie->id : null;

        if ($depuis === null) {
            $inacheve = DB::table('insee_mises_a_jour')
                ->where('workspace_id', $this->workspaceId)->whereIn('statut', ['en_cours', 'echouee'])
                ->when($idReussie !== null, static fn ($q) => $q->where('id', '>', $idReussie))
                ->orderByDesc('id')->first(['id', 'depuis', 'curseur', 'bilan']);
            if ($inacheve !== null) {
                $anterieur = json_decode(is_string($inacheve->bilan) ? $inacheve->bilan : '{}', true);
                foreach (is_array($anterieur) ? $anterieur : [] as $cle => $n) {
                    if (in_array($cle, self::COMPTEURS, true) && is_int($n)) {
                        $this->bilanAnterieur[$cle] = $n;
                    }
                }
                if (! $this->essai) {
                    DB::table('insee_mises_a_jour')->where('id', $inacheve->id)->where('workspace_id', $this->workspaceId)
                        ->update(['statut' => 'en_cours', 'erreur' => null, 'maj_le' => now()]);
                }

                return [$this->essai ? null : (int) $inacheve->id, substr((string) $inacheve->depuis, 0, 10), (string) $inacheve->curseur, true];
            }
            $depuis = $derniereReussie !== null
                ? CarbonImmutable::parse((string) $derniereReussie->demarree_le)->setTimezone(self::FUSEAU)->toDateString()
                : self::DEPUIS_INITIAL;
        }

        if ($this->essai) {
            return [null, $depuis, '*', false];
        }

        $id = (int) DB::table('insee_mises_a_jour')->insertGetId([
            'workspace_id' => $this->workspaceId, 'depuis' => $depuis, 'statut' => 'en_cours', 'curseur' => '*',
            'demarree_le' => now(), 'maj_le' => now(),
        ]);

        return [$id, $depuis, '*', false];
    }

    /**
     * La passe prioritaire, puis le flux des modifications. Vrai si le flux a
     * été lu jusqu'au bout.
     *
     * @param  ?callable(string): void  $journal
     */
    private function derouler(?int $passageId, string $depuis, string $curseur, ?callable $journal): bool
    {
        $this->passePrioritaire($depuis, $journal);
        if ($this->arret()) {
            return false;
        }

        foreach ($this->insee->iterateModificationsDepuis($depuis, $curseur) as $page) {
            $unites = $page->unites;
            $ecrituresAvant = $this->ecritures();
            $reste = $this->limite > 0 ? $this->limite - $this->bilan['unites_lues'] : null;
            $partielle = $reste !== null && count($unites) > $reste;
            if ($partielle) {
                $unites = array_slice($unites, 0, max(0, $reste));
            }
            // Page entière : on reprendra à la SUIVANTE ; page coupée par la
            // limite : on la relira (le traitement est idempotent).
            $aReprendre = $partielle ? $page->curseur : ($page->suivant ?? $page->curseur);
            $derniere = $page->suivant === null;
            // Une page à la fois : plus rien ne la retient après son traitement.
            $page->liberer();
            unset($page);

            $this->transaction(function () use ($unites, $passageId, $aReprendre): void {
                // R1 : une unité déjà traitée par la passe prioritaire n'est
                // ni retraitée ni recomptée (elle compte dans les unités lues).
                $this->bilan['unites_lues'] += count($unites);
                $this->traiterUnites(array_values(array_filter(
                    $unites,
                    fn (array $u): bool => ! isset($this->dejaTraites[trim((string) ($u['siren'] ?? ''))]),
                )), true, false);
                $this->bilan['pages']++;
                if ($passageId !== null) {
                    DB::table('insee_mises_a_jour')->where('id', $passageId)->where('workspace_id', $this->workspaceId)->update([
                        'curseur' => $aReprendre, 'pages' => DB::raw('pages + 1'),
                        'bilan' => json_encode($this->bilanDuPassage()), 'maj_le' => now(),
                    ]);
                }
            });
            if ($journal !== null) {
                $journal(sprintf(
                    '  page %d — %d unité(s) lue(s), %d création(s), %d modification(s), %d fermeture(s), %d non diffusible(s)',
                    $this->bilan['pages'],
                    $this->bilan['unites_lues'],
                    $this->bilan['creations'],
                    $this->bilan['modifications'],
                    $this->bilan['fermetures'],
                    $this->bilan['non_diffusibles'],
                ));
            }

            unset($unites);
            gc_collect_cycles();

            if ($derniere && ! $partielle) {
                return true;
            }
            if ($partielle || $this->arret()) {
                return false;
            }
            // R2 : laisser checkpoints et autovacuum suivre après une page
            // qui a écrit (jamais en essai à blanc, rien n'est écrit).
            if (! $this->essai && $this->pauseMs > 0 && $this->ecritures() > $ecrituresAvant) {
                Sleep::usleep($this->pauseMs * 1000);
            }
        }

        return true;
    }

    /**
     * Les fiches de PROVENANCE TIERS à SIREN non confrontées à Sirene depuis
     * la date du passage — « non vérifiées INSEE » (`insee_verifiee_le`
     * NULL, jamais confrontées) d'abord. Servie par
     * `idx_companies_insee_priorite` (tri sur le petit ensemble qu'il rend).
     *
     * @return list<string> SIREN
     */
    public static function fichesPrioritaires(string $workspaceId, string $depuis, int $nombre): array
    {
        return array_values(DB::table('companies')
            ->where('workspace_id', $workspaceId)
            ->whereRaw(self::PREDICAT_PRIORITE)
            // Déjà dans le prédicat ; nommé ici pour la garde B10-016 : la
            // corbeille n'est jamais rafraîchie.
            ->whereNull('deleted_at')
            ->where(static fn ($q) => $q->whereNull('insee_verifiee_le')->orWhere('insee_verifiee_le', '<', $depuis))
            ->orderByRaw('insee_verifiee_le NULLS FIRST, id')
            ->limit($nombre)
            ->pluck('siren')
            ->map(static fn ($s): string => trim((string) $s))
            ->all());
    }

    /** @param  ?callable(string): void  $journal */
    private function passePrioritaire(string $depuis, ?callable $journal): void
    {
        $nombre = self::PRIORITE_PAR_PASSAGE;
        if ($this->limite > 0) {
            $nombre = min($nombre, $this->limite);
        }
        $sirens = self::fichesPrioritaires($this->workspaceId, $depuis, $nombre);

        foreach (array_chunk($sirens, HttpInseeClient::PAR_REQUETE) as $paquet) {
            $unites = $this->avecDiffusionDuSiege($this->insee->unitesParSiren($paquet));
            foreach ($paquet as $siren) {
                $this->dejaTraites[$siren] = true;
            }
            $this->transaction(function () use ($unites, $paquet, $depuis): void {
                $this->traiterUnites($unites, false);
                $this->bilan['prioritaires'] += count($paquet);
                // Un SIREN que Sirene ne connaît pas (saisi faux par la source
                // tierce) est CONFRONTÉ quand même : horodaté, il ne reviendra
                // pas en tête de la passe chaque mois.
                $rendus = array_map(static fn (array $u): string => trim((string) ($u['siren'] ?? '')), $unites);
                $inconnus = array_values(array_diff($paquet, $rendus));
                $this->bilan['inconnues_sirene'] += count($inconnus);
                if ($inconnus !== [] && ! $this->essai) {
                    DB::table('companies')
                        ->where('workspace_id', $this->workspaceId)
                        ->whereIn('siren', $inconnus)
                        ->whereRaw(self::PREDICAT_PRIORITE)
                        ->where(static fn ($q) => $q->whereNull('insee_verifiee_le')->orWhere('insee_verifiee_le', '<', $depuis))
                        ->update(['insee_verifiee_le' => $this->maintenant]);
                }
            });
            // Un SIREN inconnu compte aussi dans la limite : il a été demandé.
            $this->bilan['unites_lues'] += count($paquet) - count($unites);
            if ($this->arret()) {
                break;
            }
        }
        if ($journal !== null && $sirens !== []) {
            $journal(sprintf('  priorité — %d fiche(s) de provenance tiers confrontée(s) à Sirene', $this->bilan['prioritaires']));
        }
    }

    /**
     * Joint à chaque unité diffusible le statut de diffusion de son SIÈGE
     * (`statutDiffusionEtablissement`, voie `/siret`) : l'opposition vaut aussi
     * au niveau établissement (C19-010 ; relecture #313, réserve 6). Une
     * requête par paquet de 100 — seulement pour la passe prioritaire.
     *
     * @param  list<array<string, mixed>>  $unites
     * @return list<array<string, mixed>>
     */
    private function avecDiffusionDuSiege(array $unites): array
    {
        $sirets = [];
        foreach ($unites as $u) {
            $p = is_array($u['periodesUniteLegale'][0] ?? null) ? $u['periodesUniteLegale'][0] : [];
            $nic = (string) ($p['nicSiegeUniteLegale'] ?? '');
            $siren = trim((string) ($u['siren'] ?? ''));
            if (HttpInseeClient::estDiffusible($u) && preg_match('/^\d{9}$/', $siren) === 1 && preg_match('/^\d{5}$/', $nic) === 1) {
                $sirets[$siren] = $siren . $nic;
            }
        }
        if ($sirets === []) {
            return $unites;
        }
        $sieges = $this->insee->etablissementsParSiret(array_values($sirets));
        foreach ($unites as $i => $u) {
            $siret = $sirets[trim((string) ($u['siren'] ?? ''))] ?? null;
            $statut = $siret !== null ? ($sieges[$siret]['statutDiffusionEtablissement'] ?? null) : null;
            if (is_string($statut)) {
                $unites[$i]['statutDiffusionEtablissement'] = $statut;
            }
        }

        return $unites;
    }

    /** Les écritures de fiches de ce lancement (plafond R2). */
    private function ecritures(): int
    {
        return $this->bilan['creations'] + $this->bilan['modifications'] + $this->bilan['fermetures']
            + $this->bilan['non_diffusibles'] + $this->bilan['reouvertures'];
    }

    /**
     * Le bilan à journaliser : celui de ce lancement, ajouté à celui déjà
     * journalisé quand le passage est repris.
     *
     * @return array<string, int>
     */
    private function bilanDuPassage(): array
    {
        $total = $this->bilan;
        foreach ($this->bilanAnterieur as $cle => $n) {
            $total[$cle] = ($total[$cle] ?? 0) + $n;
        }

        return $total;
    }

    private function arret(): bool
    {
        return ($this->limite > 0 && $this->bilan['unites_lues'] >= $this->limite)
            || ($this->maxEcritures > 0 && $this->ecritures() >= $this->maxEcritures)
            || ($this->echeance !== null && microtime(true) >= $this->echeance)
            || $this->memoireProcheDeLaLimite();
    }

    /**
     * La garde mémoire : au-delà du plafond, le passage s'arrête après la
     * page en cours (curseur déjà mémorisé), et le dit.
     */
    private function memoireProcheDeLaLimite(): bool
    {
        $plafond = $this->plafondMemoire();
        // Mémoire réellement UTILISÉE (`false`), et non réservée par Zend :
        // après un pic de décodage, la réserve redescend mal (morcellement)
        // et arrêterait le passage trop tôt (réserve 4 de #320). Le pic d'une
        // page, lui, est borné par `HttpInseeClient::REPONSE_MAX_OCTETS`.
        if ($plafond <= 0 || memory_get_usage(false) < $plafond) {
            return false;
        }
        gc_collect_cycles();
        if (memory_get_usage(false) < $plafond) {
            return false;
        }
        if (! $this->arretMemoire) {
            $this->arretMemoire = true;
            Log::warning('[INSEE] mise à jour mensuelle : arrêt propre, mémoire proche de la limite', [
                'octets' => memory_get_usage(false), 'plafond' => $plafond, 'pages' => $this->bilan['pages'],
            ]);
        }

        return true;
    }

    /** @param  \Closure(): void  $travail */
    private function transaction(\Closure $travail): void
    {
        if ($this->essai) {
            $travail();

            return;
        }
        DB::transaction($travail);
    }

    /**
     * Une page d'unités Sirene : les fiches existantes (une lecture indexée
     * par `(workspace_id, siren)`), puis les créations éventuelles.
     *
     * @param  list<array<string, mixed>>  $unites
     */
    private function traiterUnites(array $unites, bool $avecCreations, bool $compter = true): void
    {
        $parSiren = [];
        foreach ($unites as $u) {
            $siren = is_scalar($u['siren'] ?? null) ? trim((string) $u['siren']) : '';
            if (preg_match('/^\d{9}$/', $siren) === 1) {
                $parSiren[$siren] = $u;
            } else {
                $this->bilan['lignes_ignorees']++;
            }
        }
        if ($compter) {
            $this->bilan['unites_lues'] += count($unites);
        }
        if ($parSiren === []) {
            return;
        }

        $fiches = DB::table('companies')
            ->where('workspace_id', $this->workspaceId)
            ->whereIn('siren', array_map('strval', array_keys($parSiren)))
            ->get([
                'id', 'siren', 'denomination', 'naf', 'legal_form', 'effectif_range', 'size_category', 'sector_main',
                'field_origins', 'prospection_status', 'archive_reason', 'deleted_at', 'discovery_source',
                'insee_ferme_le', 'insee_non_diffusible_le',
            ]);
        $protegees = $this->protegees(array_values($fiches->pluck('id')->map(static fn ($i): int => (int) $i)->all()));

        $connues = [];
        foreach ($fiches as $f) {
            $siren = trim((string) $f->siren);
            $connues[$siren] = true;
            $this->traiterFicheSure($f, $parSiren[$siren], isset($protegees[(int) $f->id]));
        }

        if ($avecCreations) {
            $this->creer(array_diff_key($parSiren, $connues));
        }
    }

    /**
     * @param  list<int>  $ids
     * @return array<int, true>
     */
    private function protegees(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $protegees = [];
        $lignes = DB::table('company_tag')
            ->join('tags', 'tags.id', '=', 'company_tag.tag_id')
            ->whereIn('company_tag.company_id', $ids)
            ->whereIn('tags.slug', FichesProtegees::TAGS)
            ->pluck('company_tag.company_id');
        foreach ($lignes as $id) {
            $protegees[(int) $id] = true;
        }

        return $protegees;
    }

    /**
     * Une fiche, isolée : une donnée Sirene inattendue ou une écriture refusée
     * est ignorée et comptée, dans son propre point de sauvegarde — jamais de
     * page « poison » rejouée sans fin (relecture sécurité #313, réserve 4).
     *
     * @param  array<string, mixed>  $u
     */
    private function traiterFicheSure(\stdClass $f, array $u, bool $protegee): void
    {
        $bilan = $this->bilan;
        try {
            $this->traiterFiche($f, $u, $protegee);
        } catch (QueryException|\TypeError|\ValueError|\JsonException $e) {
            $this->bilan = $bilan;
            $this->bilan['lignes_ignorees']++;
            Log::warning('[INSEE] mise à jour mensuelle : fiche ignorée', [
                'company_id' => (int) $f->id, 'erreur' => class_basename($e),
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $u
     */
    private function traiterFiche(\stdClass $f, array $u, bool $protegee): void
    {
        $p = is_array($u['periodesUniteLegale'][0] ?? null) ? $u['periodesUniteLegale'][0] : [];
        // Unité ET, quand il est joint, siège (réserve sécurité 6).
        $diffusible = HttpInseeClient::estDiffusible($u);
        $etat = $p['etatAdministratifUniteLegale'] ?? $u['etatAdministratifUniteLegale'] ?? null;
        $aujourdhui = CarbonImmutable::parse((string) $this->maintenant)->setTimezone(self::FUSEAU)->toDateString();
        $hors = $protegee || $f->deleted_at !== null;
        $motif = is_string($f->archive_reason ?? null) && $f->archive_reason !== '' ? $f->archive_reason : null;
        $maj = [];

        if (! $diffusible && $f->insee_non_diffusible_le === null) {
            // Opposée à la diffusion : MARQUÉE (c'est le marquage qui l'exclut
            // des campagnes, des audiences et de l'export, protégée ou non).
            // Le motif d'archivage n'est posé que sur une fiche qui n'est pas
            // archivée pour un motif NON INSEE (bloquant 1). Aucun champ n'est
            // lu dans une unité masquée « [ND] ».
            $maj['insee_non_diffusible_le'] = $aujourdhui;
            $this->bilan['non_diffusibles']++;
            if (! $hors && ($motif === null || in_array($motif, self::MOTIFS_INSEE, true))) {
                $maj['prospection_status'] = 'archived_no_email';
                $maj['archive_reason'] = self::MOTIF_NON_DIFFUSIBLE;
            } elseif (! $hors) {
                $this->bilan['archives_gardees']++;
            }
        }

        if ($etat === 'C') {
            // Fermée : MARQUÉE ; sortie de la prospection seulement si rien
            // d'autre ne l'archive déjà (bloquant 1) ; champs figés.
            if ($f->insee_ferme_le === null) {
                $debut = is_string($p['dateDebut'] ?? null) && HttpInseeClient::estDateIso($p['dateDebut']) ? $p['dateDebut'] : $aujourdhui;
                $maj['insee_ferme_le'] = $debut;
                $this->bilan['fermetures']++;
                $motifCourant = $maj['archive_reason'] ?? $motif;
                if (! $hors && ($motifCourant === null || $motifCourant === self::MOTIF_FERMETURE)) {
                    $maj['prospection_status'] = 'archived_no_email';
                    $maj['archive_reason'] = self::MOTIF_FERMETURE;
                } elseif (! $hors && $motifCourant !== self::MOTIF_NON_DIFFUSIBLE) {
                    $this->bilan['archives_gardees']++;
                }
            }
        } else {
            if ($etat === 'A' && $f->insee_ferme_le !== null) {
                // Réouverte : le marquage est levé. Ne revient en prospection
                // QUE ce que la fermeture INSEE avait archivé : un motif posé
                // par une personne (`manual`, `duplicate`…) reste. Elle repasse
                // en `pending`, pas dans son statut d'avant la fermeture (non
                // conservé) : le triage la reclasse ensuite (réserve A3 de #313).
                $maj['insee_ferme_le'] = null;
                $this->bilan['reouvertures']++;
                if (! $hors && $motif === self::MOTIF_FERMETURE && ! isset($maj['archive_reason'])) {
                    $maj['prospection_status'] = 'pending';
                    $maj['archive_reason'] = null;
                }
            }
            // Rien n'est recopié d'une unité opposée.
            if ($diffusible && $protegee) {
                $this->bilan['protegees_preservees']++;
            } elseif ($diffusible && $f->deleted_at === null) {
                $champs = $this->champsAMettreAJour($f, $u, $p);
                if ($champs !== []) {
                    $maj += $champs;
                    $this->bilan['modifications']++;
                }
            }
        }

        // Une fiche INSEE que rien ne change n'est PAS réécrite : le flux en
        // rend des centaines de milliers par mois, et chaque UPDATE de
        // `companies` (4,35 M de lignes, une trentaine d'index) coûte. Une
        // fiche de provenance TIERS est horodatée même inchangée : c'est ce qui
        // la fait sortir de la passe prioritaire.
        $tiers = ($f->discovery_source ?? null) !== self::ORIGINE;
        if ($this->essai || ($maj === [] && ! $tiers)) {
            return;
        }
        if ($maj !== []) {
            $maj['updated_at'] = now();
        }
        $maj['insee_verifiee_le'] = $this->maintenant;
        // Point de sauvegarde propre à la fiche : une écriture refusée ne
        // fait pas échouer la page (`traiterFicheSure`).
        DB::transaction(fn () => DB::table('companies')->where('id', $f->id)->where('workspace_id', $this->workspaceId)->update($maj));
    }

    /**
     * Les champs INSEE à écrire sur une fiche : seulement ceux qui CHANGENT,
     * dont la nouvelle valeur n'est pas vide, et dont l'origine est
     * remplaçable. Le classement (secteur, taille, nomenclature) suit.
     *
     * @param  array<string, mixed>  $u
     * @param  array<string, mixed>  $p  période courante
     * @return array<string, mixed>
     */
    private function champsAMettreAJour(\stdClass $f, array $u, array $p): array
    {
        $origines = json_decode(is_string($f->field_origins ?? null) ? $f->field_origins : '{}', true);
        $origines = is_array($origines) ? $origines : [];

        $prenom = $u['prenom1UniteLegale'] ?? $p['prenom1UniteLegale'] ?? '';
        $denomination = $p['denominationUniteLegale'] ?? trim((is_string($prenom) ? $prenom : '') . ' ' . (is_string($p['nomUniteLegale'] ?? null) ? $p['nomUniteLegale'] : ''));
        $valeurs = [
            'denomination' => $denomination,
            'naf' => $p['activitePrincipaleUniteLegale'] ?? null,
            'legal_form' => $p['categorieJuridiqueUniteLegale'] ?? null,
            'effectif_range' => $u['trancheEffectifsUniteLegale'] ?? null,
        ];

        $maj = [];
        foreach ($valeurs as $colonne => $valeur) {
            if (! is_string($valeur) || trim($valeur) === '' || str_contains($valeur, '[ND]')) {
                continue;
            }
            $valeur = trim($valeur);
            // Réserve sécurité 4 : une valeur au format inattendu (ou une
            // dénomination démesurée, ou de l'UTF-8 invalide) est IGNORÉE et
            // comptée — la fiche garde sa valeur, la page continue.
            if (! self::valeurValide($colonne, $valeur)) {
                $this->bilan['valeurs_rejetees']++;

                continue;
            }
            if ($valeur === trim((string) ($f->{$colonne} ?? ''))) {
                continue;
            }
            if (! $this->remplacable($origines, $colonne)) {
                $this->bilan['champs_preserves']++;

                continue;
            }
            $maj[$colonne] = $valeur;
            $origines[$colonne] = self::ORIGINE;
        }

        if (isset($maj['naf'])) {
            $naf = NomenclatureNaf::classer($maj['naf']);
            $maj['naf_nomenclature'] = $naf->nomenclature;
            $maj['naf_rev2'] = $naf->codeRev2;
            if ($this->remplacable($origines, 'sector_main')) {
                $secteur = Classement::secteurRetenu($naf->secteur, is_string($f->sector_main) ? $f->sector_main : null);
                if ($secteur !== $f->sector_main) {
                    $maj['sector_main'] = $secteur;
                }
            }
        }
        if (isset($maj['effectif_range']) && $this->remplacable($origines, 'size_category')) {
            $categorie = $u['categorieEntreprise'] ?? null;
            $taille = Classement::tailleDepuisInsee($maj['effectif_range'], is_string($categorie) ? $categorie : null);
            if ($taille !== $f->size_category) {
                $maj['size_category'] = $taille;
            }
        }

        if ($maj !== []) {
            $maj['field_origins'] = json_encode($origines, JSON_THROW_ON_ERROR);
        }

        return $maj;
    }

    /** Le format attendu d'une valeur Sirene avant recopie (`FORMATS`, `DENOMINATION_MAX`). */
    public static function valeurValide(string $colonne, string $valeur): bool
    {
        if (! mb_check_encoding($valeur, 'UTF-8')) {
            return false;
        }
        if ($colonne === 'denomination') {
            return mb_strlen($valeur) <= self::DENOMINATION_MAX && preg_match('/[\x00-\x1F\x7F]/', $valeur) !== 1;
        }

        return ! isset(self::FORMATS[$colonne]) || preg_match(self::FORMATS[$colonne], $valeur) === 1;
    }

    /** @param  array<array-key, mixed>  $origines */
    private function remplacable(array $origines, string $colonne): bool
    {
        $origine = $origines[$colonne] ?? null;

        return $origine === null || in_array($origine, self::ORIGINES_REMPLACABLES, true);
    }

    /**
     * Les unités absentes de l'espace : celles du périmètre de l'import sont
     * créées. Le siège (adresse) est lu sur la voie `/siret`, par paquets.
     *
     * @param  array<string, array<string, mixed>>  $absentes  par SIREN
     */
    private function creer(array $absentes): void
    {
        $sirets = [];
        foreach ($absentes as $siren => $u) {
            $p = is_array($u['periodesUniteLegale'][0] ?? null) ? $u['periodesUniteLegale'][0] : [];
            $cj = (string) ($p['categorieJuridiqueUniteLegale'] ?? '');
            $nic = (string) ($p['nicSiegeUniteLegale'] ?? '');
            // Premier tri sur l'unité (évite une requête `/siret` inutile) ;
            // le périmètre complet est jugé sur le siège. Les familles de la
            // décision du 04/10/2026 (`FamillesInsee`) : 5, 7 et 8 toutes, 6
            // et 9 avec salariés — jamais 1.
            if (! HttpInseeClient::estDiffusible($u)
                || ($p['etatAdministratifUniteLegale'] ?? null) !== 'A'
                || ! FamillesInsee::admise($cj, $u['trancheEffectifsUniteLegale'] ?? null)
                || preg_match('/^\d{5}$/', $nic) !== 1) {
                continue;
            }
            $sirets[] = $siren . $nic;
        }
        if ($sirets === []) {
            return;
        }

        $perimetre = array_flip($this->departements());
        $lignes = [];
        foreach ($this->insee->etablissementsParSiret($sirets) as $etab) {
            if (! HttpInseeClient::estDansPerimetreFamilles($etab)) {
                continue;
            }
            $donnees = HttpInseeClient::donneesEtablissement($etab);
            $departement = LigneFicheInsee::departementDeCommune($donnees->insee);
            if ($donnees->siren === '' || $departement === null || ! isset($perimetre[$departement])) {
                $this->bilan['hors_perimetre']++;

                continue;
            }
            $ligne = LigneFicheInsee::depuis($donnees, $this->workspaceId, $departement);
            $ligne['insee_verifiee_le'] = $this->maintenant;
            $ligne['field_origins'] = json_encode(
                array_fill_keys(['denomination', 'naf', 'legal_form', 'effectif_range'], self::ORIGINE),
                JSON_THROW_ON_ERROR,
            );
            $lignes[] = $ligne;
        }
        if ($lignes === []) {
            return;
        }

        // Jamais par-dessus une fiche existante, même à la corbeille.
        if ($this->essai) {
            $this->bilan['creations'] += count($lignes);

            return;
        }
        try {
            $this->bilan['creations'] += DB::transaction(fn (): int => DB::table('companies')->insertOrIgnore($lignes));
        } catch (QueryException) {
            // Une ligne refusée ne fait pas échouer la page (réserve 4) : on
            // réessaie ligne par ligne, chacune dans son point de sauvegarde.
            foreach ($lignes as $ligne) {
                try {
                    $this->bilan['creations'] += DB::transaction(fn (): int => DB::table('companies')->insertOrIgnore([$ligne]));
                } catch (QueryException) {
                    $this->bilan['lignes_ignorees']++;
                }
            }
        }
    }

    /**
     * Le périmètre géographique : les départements de l'IMPORT INSEE déjà
     * présents dans l'espace (`discovery_source = 'insee'`) — une fiche de
     * provenance tierce (fédérations, annuaires…) n'ouvre JAMAIS un
     * département aux créations (avis exactitude R3). Parcours « en saut » de
     * `idx_companies_dept` (`workspace_id, department_code`) : une sonde
     * d'index par département, jamais les 4,35 M de lignes ; les fiches
     * INSEE étant l'immense majorité, la première d'un département est
     * trouvée en quelques lignes.
     *
     * @return list<string>
     */
    private function departements(): array
    {
        return $this->departements ??= self::departementsInsee($this->workspaceId);
    }

    /**
     * Les départements de l'IMPORT INSEE présents dans un espace (voir
     * `departements()`), aussi le périmètre géographique de
     * `crm:insee:importer-familles`. À appeler sous le contexte de l'espace.
     *
     * @return list<string>
     */
    public static function departementsInsee(string $workspaceId): array
    {
        $lignes = DB::select(
            'WITH RECURSIVE d AS (
                (SELECT department_code FROM companies
                  WHERE workspace_id = ? AND department_code IS NOT NULL AND discovery_source = ?
                  ORDER BY department_code LIMIT 1)
                UNION ALL
                SELECT (SELECT c.department_code FROM companies c
                         WHERE c.workspace_id = ? AND c.department_code > d.department_code
                           AND c.discovery_source = ?
                         ORDER BY c.department_code LIMIT 1)
                  FROM d WHERE d.department_code IS NOT NULL
            )
            SELECT department_code FROM d WHERE department_code IS NOT NULL',
            [$workspaceId, self::ORIGINE, $workspaceId, self::ORIGINE],
        );

        return array_values(array_map(static fn ($l): string => (string) $l->department_code, $lignes));
    }
}
