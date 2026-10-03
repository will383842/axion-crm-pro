<?php

namespace App\Crm\Insee;

use App\Crm\FichesProtegees;
use App\Crm\Referentiels\Classement;
use App\Crm\Referentiels\NomenclatureNaf;
use App\Services\Insee\HttpInseeClient;
use App\Support\WorkspaceContext;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * LA MISE À JOUR MENSUELLE INSEE (lot N8, 03/10/2026) —
 * `crm:insee:mise-a-jour-mensuelle`.
 *
 * Interroge Sirene sur `dateDernierTraitementUniteLegale` depuis une date et
 * reporte TOUTES les modifications sur les fiches d'un espace :
 *
 *  - CRÉATION   une unité absente de l'espace, dont le siège entre dans le
 *               périmètre de l'import initial (`HttpInseeClient::
 *               estDansPerimetreImport` : siège actif, diffusible, société
 *               5xxx) et dans un département DÉJÀ présent dans l'espace ;
 *               ligne construite par `LigneFicheInsee` (celle de
 *               `prospection:collect`). Jamais par-dessus une fiche
 *               existante (`ON CONFLICT DO NOTHING`).
 *  - FERMETURE  état administratif `C` : la fiche est MARQUÉE
 *               (`insee_ferme_le`) et sort de la prospection
 *               (`archived_no_email` / `entreprise_radiee`, la règle déjà
 *               respectée par le triage). Une réouverture (`A`) lève le
 *               marquage.
 *  - NON DIFFUSIBLE statut `P` ou `N` : la fiche est MARQUÉE
 *               (`insee_non_diffusible_le`), sort de la prospection
 *               (`archive_reason = non_diffusible`) et de toute campagne
 *               (motif `non_diffusible` d'`EligibiliteAdresse`). Aucun champ
 *               n'est recopié d'une unité opposée (les `[ND]`).
 *  - MODIFICATION dénomination, activité (et son classement), forme
 *               juridique, tranche d'effectif (et la taille) — SAUF :
 *               une fiche PROTÉGÉE (`FichesProtegees`) n'est jamais touchée ;
 *               un champ dont `field_origins` dit autre chose que
 *               `ORIGINES_REMPLACABLES` (saisie manuelle `declared`, import
 *               de fédérations…) est gardé.
 *
 * RIEN N'EST JAMAIS SUPPRIMÉ : aucune ligne de ce fichier n'émet de DELETE,
 * ni ne pose `deleted_at`.
 *
 * Les fiches « non vérifiées INSEE » (`insee_verifiee_le` NULL) de
 * PROVENANCE TIERS passent EN PREMIER (`fichesPrioritaires`), par paquets
 * de SIREN, avant le flux des modifications.
 *
 * Traitement SÉQUENTIEL et léger (serveur à 2 CPU) : une page Sirene à la
 * fois, une requête de lecture indexée par page, une transaction par page.
 * Le curseur Sirene est mémorisé dans `insee_mises_a_jour` à chaque page :
 * une coupure, `--limite` ou la durée maximale laissent un passage
 * `en_cours`, que le passage suivant REPREND.
 */
final class MiseAJourMensuelle
{
    /** Rattrapage initial : la veille de l'import initial. */
    public const DEPUIS_INITIAL = '2026-07-06';

    public const MOTIF_NON_DIFFUSIBLE = 'non_diffusible';

    public const MOTIF_FERMETURE = 'entreprise_radiee';

    public const ORIGINE = 'insee';

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

    /** Compteurs du bilan, dans l'ordre d'affichage. */
    public const COMPTEURS = [
        'creations', 'modifications', 'fermetures', 'non_diffusibles', 'reouvertures',
        'prioritaires', 'inconnues_sirene', 'unites_lues', 'pages', 'champs_preserves', 'protegees_preservees', 'hors_perimetre',
    ];

    /** @var array<string, int> */
    private array $bilan = [];

    /** @var array<string, int> bilan déjà journalisé d'un passage repris */
    private array $bilanAnterieur = [];

    private bool $essai = false;

    private int $limite = 0;

    private ?float $echeance = null;

    private string $workspaceId = '';

    private ?string $maintenant = null;

    /** @var list<string>|null */
    private ?array $departements = null;

    public function __construct(private readonly HttpInseeClient $insee) {}

    /**
     * Le jour et l'heure de la planification : le PREMIER jour du mois, à
     * partir du 4, qui tombe du mardi au samedi (jamais les 1er, 2 et 3),
     * entre 08:00 et 19:00, heure de Paris. Exactement un jour par mois — il
     * tombe toujours entre le 4 et le 6.
     */
    public static function estJourPlanifie(CarbonInterface $instant): bool
    {
        $t = CarbonImmutable::instance($instant)->setTimezone(self::FUSEAU);
        if ($t->hour < 8 || $t->hour >= 19 || $t->day < 4 || ! self::ouvre($t)) {
            return false;
        }
        for ($jour = 4; $jour < $t->day; $jour++) {
            if (self::ouvre($t->setDay($jour))) {
                return false;
            }
        }

        return true;
    }

    private static function ouvre(CarbonImmutable $jour): bool
    {
        return $jour->dayOfWeekIso >= 2 && $jour->dayOfWeekIso <= 6;
    }

    /**
     * @param  ?string  $depuis  AAAA-MM-JJ ; null = reprise du passage inachevé, sinon dernière exécution réussie, sinon `DEPUIS_INITIAL`
     * @param  ?callable(string): void  $journal  reçoit l'avancement (une ligne par page)
     * @param  ?list<string>  $departements  périmètre imposé (sinon : les départements présents dans l'espace)
     * @return array{statut: string, depuis: string, reprise: bool, bilan: array<string, int>}
     */
    public function executer(
        string $workspaceId,
        ?string $depuis,
        bool $essai = false,
        int $limite = 0,
        int $dureeMaxMinutes = 0,
        ?callable $journal = null,
        ?array $departements = null,
    ): array {
        $this->workspaceId = $workspaceId;
        $this->essai = $essai;
        $this->limite = max(0, $limite);
        $this->echeance = $dureeMaxMinutes > 0 ? microtime(true) + $dureeMaxMinutes * 60 : null;
        $this->bilan = array_fill_keys(self::COMPTEURS, 0);
        $this->bilanAnterieur = [];
        $this->departements = $departements;
        $this->maintenant = now()->toIso8601String();

        return WorkspaceContext::run($workspaceId, function () use ($depuis, $journal): array {
            [$passageId, $depuisRetenu, $curseur, $reprise] = $this->ouvrirPassage($depuis);

            try {
                $termine = $this->derouler($passageId, $depuisRetenu, $curseur, $journal);
            } catch (\Throwable $e) {
                if ($passageId !== null) {
                    DB::table('insee_mises_a_jour')->where('id', $passageId)->where('workspace_id', $this->workspaceId)->update([
                        'statut' => 'echouee', 'erreur' => mb_substr($e->getMessage(), 0, 2000),
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
            ];
        });
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
            $unites = $page['unites'];
            $reste = $this->limite > 0 ? $this->limite - $this->bilan['unites_lues'] : null;
            $partielle = $reste !== null && count($unites) > $reste;
            if ($partielle) {
                $unites = array_slice($unites, 0, max(0, $reste));
            }
            // Page entière : on reprendra à la SUIVANTE ; page coupée par la
            // limite : on la relira (le traitement est idempotent).
            $aReprendre = $partielle ? $page['curseur'] : ($page['suivant'] ?? $page['curseur']);

            $this->transaction(function () use ($unites, $passageId, $aReprendre): void {
                $this->traiterUnites($unites, true);
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

            if ($page['suivant'] === null && ! $partielle) {
                return true;
            }
            if ($partielle || $this->arret()) {
                return false;
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
            $unites = $this->insee->unitesParSiren($paquet);
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
            || ($this->echeance !== null && microtime(true) >= $this->echeance);
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
    private function traiterUnites(array $unites, bool $avecCreations): void
    {
        $parSiren = [];
        foreach ($unites as $u) {
            $siren = trim((string) ($u['siren'] ?? ''));
            if (preg_match('/^\d{9}$/', $siren) === 1) {
                $parSiren[$siren] = $u;
            }
        }
        $this->bilan['unites_lues'] += count($unites);
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
            $this->traiterFiche($f, $parSiren[$siren], isset($protegees[(int) $f->id]));
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
     * @param  array<string, mixed>  $u
     */
    private function traiterFiche(\stdClass $f, array $u, bool $protegee): void
    {
        $p = is_array($u['periodesUniteLegale'][0] ?? null) ? $u['periodesUniteLegale'][0] : [];
        $diffusible = ($u['statutDiffusionUniteLegale'] ?? 'O') === 'O';
        $etat = $p['etatAdministratifUniteLegale'] ?? $u['etatAdministratifUniteLegale'] ?? null;
        $aujourdhui = CarbonImmutable::parse((string) $this->maintenant)->setTimezone(self::FUSEAU)->toDateString();
        $hors = $protegee || $f->deleted_at !== null;
        $maj = [];

        if (! $diffusible) {
            // Opposée à la diffusion : MARQUÉE et sortie de la prospection.
            // Aucun champ n'est lu dans une unité masquée « [ND] ».
            if ($f->insee_non_diffusible_le === null) {
                $maj['insee_non_diffusible_le'] = $aujourdhui;
                $this->bilan['non_diffusibles']++;
                if (! $hors) {
                    $maj['prospection_status'] = 'archived_no_email';
                    $maj['archive_reason'] = self::MOTIF_NON_DIFFUSIBLE;
                }
            }
        } elseif ($etat === 'C') {
            // Fermée : MARQUÉE et sortie de la prospection ; ses champs sont
            // figés à la fermeture.
            if ($f->insee_ferme_le === null) {
                $debut = is_string($p['dateDebut'] ?? null) && HttpInseeClient::estDateIso($p['dateDebut']) ? $p['dateDebut'] : $aujourdhui;
                $maj['insee_ferme_le'] = $debut;
                $this->bilan['fermetures']++;
                if (! $hors && $f->archive_reason !== self::MOTIF_NON_DIFFUSIBLE) {
                    $maj['prospection_status'] = 'archived_no_email';
                    $maj['archive_reason'] = self::MOTIF_FERMETURE;
                }
            }
        } else {
            if ($etat === 'A' && $f->insee_ferme_le !== null) {
                // Réouverte : le marquage est levé, la prospection reprend.
                $maj['insee_ferme_le'] = null;
                $this->bilan['reouvertures']++;
                if (! $hors && $f->archive_reason === self::MOTIF_FERMETURE) {
                    $maj['prospection_status'] = 'pending';
                    $maj['archive_reason'] = null;
                }
            }
            if ($protegee) {
                $this->bilan['protegees_preservees']++;
            } elseif ($f->deleted_at === null) {
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
        DB::table('companies')->where('id', $f->id)->where('workspace_id', $this->workspaceId)->update($maj);
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
            // le périmètre complet est jugé sur le siège.
            if (($u['statutDiffusionUniteLegale'] ?? 'O') !== 'O'
                || ($p['etatAdministratifUniteLegale'] ?? null) !== 'A'
                || $cj === '' || $cj[0] !== '5'
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
            if (! HttpInseeClient::estDansPerimetreImport($etab)) {
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
        $this->bilan['creations'] += $this->essai ? count($lignes) : DB::table('companies')->insertOrIgnore($lignes);
    }

    /**
     * Le périmètre géographique : les départements déjà présents dans
     * l'espace (celui de l'import initial). Parcours « en saut » de
     * `idx_companies_dept` (`workspace_id, department_code`) : une sonde
     * d'index par département, jamais les 4,35 M de lignes.
     *
     * @return list<string>
     */
    private function departements(): array
    {
        if ($this->departements !== null) {
            return $this->departements;
        }
        $lignes = DB::select(
            'WITH RECURSIVE d AS (
                (SELECT department_code FROM companies
                  WHERE workspace_id = ? AND department_code IS NOT NULL
                  ORDER BY department_code LIMIT 1)
                UNION ALL
                SELECT (SELECT c.department_code FROM companies c
                         WHERE c.workspace_id = ? AND c.department_code > d.department_code
                         ORDER BY c.department_code LIMIT 1)
                  FROM d WHERE d.department_code IS NOT NULL
            )
            SELECT department_code FROM d WHERE department_code IS NOT NULL',
            [$this->workspaceId, $this->workspaceId],
        );

        return $this->departements = array_values(array_map(static fn ($l): string => (string) $l->department_code, $lignes));
    }
}
