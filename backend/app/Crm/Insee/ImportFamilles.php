<?php

namespace App\Crm\Insee;

use App\Services\Insee\HttpInseeClient;
use App\Support\WorkspaceContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;

/**
 * L'IMPORT DES FAMILLES INSEE (décision du propriétaire du 04/10/2026) —
 * `crm:insee:importer-familles`.
 *
 * Le CRM ne contenait que des sociétés commerciales (catégorie juridique 5).
 * Cet import lit Sirene famille par famille (`FamillesInsee` : 7 et 8
 * toutes, 6 et 9 avec salariés, 5 en rattrapage des absents) et CRÉE les
 * fiches des SIREN absents de l'espace — rien d'autre :
 *
 *  - une fiche existante (même à la corbeille) n'est JAMAIS modifiée : les
 *    SIREN déjà présents sont écartés AVANT toute écriture (lecture indexée
 *    par `(workspace_id, siren)`), et l'insertion est un `ON CONFLICT DO
 *    NOTHING` sur la clé unique `(workspace_id, siren)` ;
 *  - RIEN n'est jamais supprimé : aucune ligne de ce fichier n'émet de
 *    DELETE ni ne pose `deleted_at` ;
 *  - jamais la catégorie 1 (entrepreneurs individuels) ni une unité non
 *    diffusible (unité OU siège) : revérifié ici, quelle que soit la réponse
 *    de Sirene ;
 *  - la ligne est celle de `prospection:collect` et de la mise à jour
 *    mensuelle (`LigneFicheInsee` : forme juridique, effectifs, taille, NAF
 *    et secteur, adresse du siège…), `discovery_source = 'insee'`, et porte
 *    le lot (`metadata.lot_import = FamillesInsee::LOT`) pour la retrouver :
 *    `WHERE metadata->>'lot_import' = 'insee-familles-2026-10'` ;
 *  - périmètre géographique : les départements de l'import INSEE déjà
 *    présents dans l'espace (`MiseAJourMensuelle::departementsInsee`), comme
 *    les créations de la mise à jour mensuelle.
 *
 * Reprise : le curseur Sirene est mémorisé dans `insee_imports_familles` À
 * CHAQUE PAGE, dans la transaction qui écrit ses fiches (une page validée =
 * son curseur validé). Un arrêt à l'heure (`--jusqua`), une limite, la garde
 * mémoire ou une coupure laissent le passage `en_cours` (ou `echouee`) : le
 * lancement suivant de la même famille le REPREND. Un essai à blanc n'écrit
 * RIEN, pas même le journal.
 *
 * Mémoire CONSTANTE (leçon de l'incident du 03/10/2026) : une page Sirene à
 * la fois (`PageSirene`, libérée avant la suivante), un bilan fait de
 * compteurs seulement, l'occupation journalisée à chaque page, et une garde
 * qui arrête PROPREMENT le passage avant la limite PHP.
 *
 * Quota INSEE : ≈ 28 requêtes par minute (`HttpInseeClient::
 * avecDelaiEntreRequetes`, défaut 2 100 ms), attente de 20 s sur 429.
 */
final class ImportFamilles
{
    /** Compteurs du bilan, dans l'ordre d'affichage. */
    public const COMPTEURS = [
        'unites_lues', 'pages', 'a_creer', 'creees', 'deja_presentes',
        'ignorees_individuelles', 'ignorees_autre_famille', 'ignorees_non_diffusibles', 'ignorees_inactives',
        'ignorees_sans_salaries', 'ignorees_siege', 'hors_perimetre', 'lignes_ignorees',
    ];

    /** Part de `memory_limit` au-delà de laquelle le passage s'arrête proprement. */
    public const SEUIL_MEMOIRE = MiseAJourMensuelle::SEUIL_MEMOIRE;

    /** @var array<string, int> */
    private array $bilan = [];

    /** @var array<string, int> bilan déjà journalisé d'un passage repris */
    private array $bilanAnterieur = [];

    private ?int $plafondMemoire = null;

    private bool $arretMemoire = false;

    private bool $arretHeure = false;

    private bool $essai = false;

    private int $limite = 0;

    private ?CarbonImmutable $jusqua = null;

    private int $pauseMs = 0;

    private string $workspaceId = '';

    private string $famille = '';

    private string $maintenant = '';

    /** @var array<string, int>|null départements du périmètre (clés) */
    private ?array $perimetre = null;

    public function __construct(private readonly HttpInseeClient $insee) {}

    /** Plafond de la garde mémoire en octets (0 : sans garde ; null : `SEUIL_MEMOIRE` de `memory_limit`). */
    public function avecPlafondMemoire(?int $octets): static
    {
        $this->plafondMemoire = $octets === null ? null : max(0, $octets);

        return $this;
    }

    public function plafondMemoire(): int
    {
        if ($this->plafondMemoire !== null) {
            return $this->plafondMemoire;
        }
        $limite = MiseAJourMensuelle::octets((string) ini_get('memory_limit'));

        return $limite > 0 ? (int) ($limite * self::SEUIL_MEMOIRE) : 0;
    }

    /**
     * @param  ?CarbonImmutable  $jusqua  heure d'arrêt (le passage s'arrête proprement après la page en cours)
     * @param  ?callable(string): void  $journal  une ligne par page
     * @param  ?list<string>  $departements  périmètre imposé (sinon : les départements INSEE de l'espace)
     * @return array{statut: string, reprise: bool, bilan: array<string, int>, bilan_passage: array<string, int>, fiches_avant: int, fiches_debut: int, fiches_apres: int, arret_memoire: bool, arret_heure: bool, departements: int}
     */
    public function executer(
        string $workspaceId,
        string $famille,
        bool $essai = false,
        int $limite = 0,
        ?CarbonImmutable $jusqua = null,
        ?callable $journal = null,
        ?array $departements = null,
        int $pauseMs = 0,
    ): array {
        if (! FamillesInsee::estFamille($famille)) {
            throw new \InvalidArgumentException("Famille INSEE non importable : « {$famille} ».");
        }
        $this->workspaceId = $workspaceId;
        $this->famille = $famille;
        $this->essai = $essai;
        $this->limite = max(0, $limite);
        $this->jusqua = $jusqua;
        $this->pauseMs = max(0, $pauseMs);
        $this->bilan = array_fill_keys(self::COMPTEURS, 0);
        $this->bilanAnterieur = [];
        $this->arretMemoire = false;
        $this->arretHeure = false;
        $this->maintenant = now()->toIso8601String();
        $this->perimetre = $departements !== null ? array_flip($departements) : null;

        return WorkspaceContext::run($workspaceId, function () use ($journal): array {
            $this->perimetre ??= array_flip(MiseAJourMensuelle::departementsInsee($this->workspaceId));
            $fichesDebut = $this->compterFiches();
            [$passageId, $curseur, $reprise, $fichesAvant] = $this->ouvrirPassage($fichesDebut);

            try {
                $termine = $this->derouler($passageId, $curseur, $journal);
            } catch (\Throwable $e) {
                if ($passageId !== null) {
                    DB::table('insee_imports_familles')->where('id', $passageId)->where('workspace_id', $this->workspaceId)->update([
                        'statut' => 'echouee', 'erreur' => MiseAJourMensuelle::erreurJournalisable($e),
                        'bilan' => json_encode($this->bilanDuPassage()), 'maj_le' => now(),
                    ]);
                }
                throw $e;
            }

            $fichesApres = $this->compterFiches();
            if ($passageId !== null) {
                DB::table('insee_imports_familles')->where('id', $passageId)->where('workspace_id', $this->workspaceId)->update([
                    'statut' => $termine ? 'reussie' : 'en_cours', 'erreur' => null,
                    'fiches_apres' => $fichesApres,
                    'bilan' => json_encode($this->bilanDuPassage()), 'maj_le' => now(),
                    'terminee_le' => $termine ? now() : null,
                ]);
            }
            Log::info('[INSEE] import des familles : passage', [
                'famille' => $this->famille, 'essai' => $this->essai, 'termine' => $termine,
                'bilan' => $this->bilan, 'memoire_pic' => memory_get_peak_usage(false),
            ]);

            return [
                'statut' => $termine ? 'reussie' : 'en_cours',
                'reprise' => $reprise,
                'bilan' => $this->bilan,
                'bilan_passage' => $this->bilanDuPassage(),
                'fiches_avant' => $fichesAvant,
                'fiches_debut' => $fichesDebut,
                'fiches_apres' => $fichesApres,
                'arret_memoire' => $this->arretMemoire,
                'arret_heure' => $this->arretHeure,
                'departements' => count($this->perimetre ?? []),
            ];
        });
    }

    /** Les fiches de l'espace, corbeille comprise (rien n'est supprimé : après = avant + créées). */
    private function compterFiches(): int
    {
        $compte = DB::table('companies')->where('workspace_id', $this->workspaceId)
            ->selectRaw('count(*) AS toutes, count(deleted_at) AS corbeille')
            ->first();

        return (int) ($compte->toutes ?? 0);
    }

    /**
     * Le passage à mener : le dernier passage inachevé de la famille et du
     * lot (reprise à son curseur), sinon un nouveau depuis `*`. Un essai à
     * blanc n'écrit pas de journal et part toujours de `*`.
     *
     * @return array{0: ?int, 1: string, 2: bool, 3: int} [id, curseur, reprise, fiches avant]
     */
    private function ouvrirPassage(int $fichesDebut): array
    {
        if ($this->essai) {
            return [null, '*', false, $fichesDebut];
        }
        $dernier = DB::table('insee_imports_familles')
            ->where('workspace_id', $this->workspaceId)->where('famille', $this->famille)->where('lot', FamillesInsee::LOT)
            ->orderByDesc('id')->first(['id', 'statut', 'curseur', 'bilan', 'fiches_avant']);

        if ($dernier !== null && in_array($dernier->statut, ['en_cours', 'echouee'], true)) {
            $anterieur = json_decode(is_string($dernier->bilan) ? $dernier->bilan : '{}', true);
            foreach (is_array($anterieur) ? $anterieur : [] as $cle => $n) {
                if (in_array($cle, self::COMPTEURS, true) && is_int($n)) {
                    $this->bilanAnterieur[$cle] = $n;
                }
            }
            DB::table('insee_imports_familles')->where('id', $dernier->id)->where('workspace_id', $this->workspaceId)
                ->update(['statut' => 'en_cours', 'erreur' => null, 'maj_le' => now()]);

            return [(int) $dernier->id, (string) $dernier->curseur, true, (int) ($dernier->fiches_avant ?? $fichesDebut)];
        }

        $id = (int) DB::table('insee_imports_familles')->insertGetId([
            'workspace_id' => $this->workspaceId, 'famille' => $this->famille, 'lot' => FamillesInsee::LOT,
            'statut' => 'en_cours', 'curseur' => '*', 'fiches_avant' => $fichesDebut,
            'demarree_le' => now(), 'maj_le' => now(),
        ]);

        return [$id, '*', false, $fichesDebut];
    }

    /**
     * Les pages de la famille, à partir du curseur. Vrai si le flux a été lu
     * jusqu'au bout.
     *
     * @param  ?callable(string): void  $journal
     */
    private function derouler(?int $passageId, string $curseur, ?callable $journal): bool
    {
        if ($this->arret()) {
            return false;
        }

        foreach ($this->insee->iterateFamille($this->famille, $curseur) as $page) {
            $unites = $page->unites;
            $reste = $this->limite > 0 ? $this->limite - $this->bilan['unites_lues'] : null;
            $partielle = $reste !== null && count($unites) > $reste;
            if ($partielle) {
                $unites = array_slice($unites, 0, max(0, $reste));
            }
            // Page entière : on reprendra à la SUIVANTE ; page coupée par la
            // limite : on la relira (seuls les absents sont créés : rejouer
            // une page ne crée rien deux fois).
            $aReprendre = $partielle ? $page->curseur : ($page->suivant ?? $page->curseur);
            $derniere = $page->suivant === null;
            $page->liberer();
            unset($page);

            $creeesAvant = $this->bilan['creees'];
            $lignes = $this->lignesACreer($unites);
            $this->bilan['unites_lues'] += count($unites);
            unset($unites);

            $this->transaction(function () use ($lignes, $passageId, $aReprendre): void {
                $this->inserer($lignes);
                $this->bilan['pages']++;
                if ($passageId !== null) {
                    DB::table('insee_imports_familles')->where('id', $passageId)->where('workspace_id', $this->workspaceId)->update([
                        'curseur' => $aReprendre, 'pages' => DB::raw('pages + 1'),
                        'bilan' => json_encode($this->bilanDuPassage()), 'maj_le' => now(),
                    ]);
                }
            });
            unset($lignes);
            gc_collect_cycles();

            if ($journal !== null) {
                $journal(sprintf(
                    '  page %d — %d unité(s) lue(s), %d à créer, %d créée(s), %d déjà présente(s), %d ignorée(s) — mémoire %.1F Mo',
                    $this->bilan['pages'],
                    $this->bilan['unites_lues'],
                    $this->bilan['a_creer'],
                    $this->bilan['creees'],
                    $this->bilan['deja_presentes'],
                    $this->ignorees(),
                    memory_get_usage(false) / 1048576,
                ));
            }

            if ($derniere && ! $partielle) {
                return true;
            }
            if ($partielle || $this->arret()) {
                return false;
            }
            if (! $this->essai && $this->pauseMs > 0 && $this->bilan['creees'] > $creeesAvant) {
                Sleep::usleep($this->pauseMs * 1000);
            }
        }

        return true;
    }

    /** Les unités écartées, tous motifs confondus. */
    public function ignorees(): int
    {
        return $this->bilan['ignorees_individuelles'] + $this->bilan['ignorees_autre_famille']
            + $this->bilan['ignorees_non_diffusibles'] + $this->bilan['ignorees_inactives']
            + $this->bilan['ignorees_sans_salaries'] + $this->bilan['ignorees_siege']
            + $this->bilan['hors_perimetre'] + $this->bilan['lignes_ignorees'];
    }

    /**
     * Les lignes `companies` à créer pour une page : les unités du périmètre
     * (revérifié ici, Sirene ne fait pas foi), ABSENTES de l'espace, dont le
     * siège est dans le périmètre et dans un département de l'espace.
     *
     * @param  list<array<string, mixed>>  $unites
     * @return list<array<string, mixed>>
     */
    private function lignesACreer(array $unites): array
    {
        $candidates = [];
        foreach ($unites as $u) {
            $siren = is_scalar($u['siren'] ?? null) ? trim((string) $u['siren']) : '';
            $p = is_array($u['periodesUniteLegale'][0] ?? null) ? $u['periodesUniteLegale'][0] : [];
            $cj = $p['categorieJuridiqueUniteLegale'] ?? null;
            $famille = FamillesInsee::familleDe($cj);
            $nic = (string) ($p['nicSiegeUniteLegale'] ?? '');

            $motif = match (true) {
                preg_match('/^\d{9}$/', $siren) !== 1 => 'lignes_ignorees',
                // JAMAIS un entrepreneur individuel, par décision.
                $famille === '1' => 'ignorees_individuelles',
                $famille !== $this->famille => 'ignorees_autre_famille',
                // JAMAIS une unité opposée à la diffusion (C19-010).
                ! HttpInseeClient::estDiffusible($u) => 'ignorees_non_diffusibles',
                ($p['etatAdministratifUniteLegale'] ?? null) !== 'A' => 'ignorees_inactives',
                ! FamillesInsee::admise($cj, $u['trancheEffectifsUniteLegale'] ?? null, $this->famille) => 'ignorees_sans_salaries',
                preg_match('/^\d{5}$/', $nic) !== 1 => 'ignorees_siege',
                default => null,
            };
            if ($motif !== null) {
                $this->bilan[$motif]++;

                continue;
            }
            $candidates[$siren] = $siren . $nic;
        }
        if ($candidates === []) {
            return [];
        }

        // Les SIREN DÉJÀ dans l'espace — corbeille comprise, volontairement
        // (`deleted_at` lu et ignoré) : jamais touchés, jamais recréés.
        $presents = DB::table('companies')
            ->where('workspace_id', $this->workspaceId)
            ->whereIn('siren', array_map('strval', array_keys($candidates)))
            ->get(['siren', 'deleted_at']);
        foreach ($presents as $fiche) {
            $siren = trim((string) $fiche->siren);
            if (isset($candidates[$siren])) {
                unset($candidates[$siren]);
                $this->bilan['deja_presentes']++;
            }
        }
        if ($candidates === []) {
            return [];
        }

        $sieges = $this->insee->etablissementsParSiret(array_values($candidates));
        $lignes = [];
        foreach ($candidates as $siret) {
            $etab = $sieges[$siret] ?? null;
            // Le siège porte son propre statut de diffusion (C19-010) : un
            // siège opposé, fermé, absent ou hors famille n'est pas créé.
            if (! is_array($etab) || ! HttpInseeClient::estDansPerimetreFamilles($etab, $this->famille)) {
                $this->bilan['ignorees_siege']++;

                continue;
            }
            $donnees = HttpInseeClient::donneesEtablissement($etab);
            $departement = LigneFicheInsee::departementDeCommune($donnees->insee);
            if ($donnees->siren === '' || $departement === null || ! isset($this->perimetre[$departement])) {
                $this->bilan['hors_perimetre']++;

                continue;
            }
            $ligne = LigneFicheInsee::depuis($donnees, $this->workspaceId, $departement);
            $metadata = json_decode((string) $ligne['metadata'], true);
            $ligne['metadata'] = json_encode(
                (is_array($metadata) ? $metadata : []) + ['lot_import' => FamillesInsee::LOT],
                JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
            );
            $ligne['insee_verifiee_le'] = $this->maintenant;
            $ligne['field_origins'] = json_encode(
                array_fill_keys(['denomination', 'naf', 'legal_form', 'effectif_range'], MiseAJourMensuelle::ORIGINE),
                JSON_THROW_ON_ERROR,
            );
            $lignes[] = $ligne;
        }
        unset($sieges);
        $this->bilan['a_creer'] += count($lignes);

        return $lignes;
    }

    /**
     * Insertion SANS JAMAIS écraser : `ON CONFLICT DO NOTHING`. Une ligne
     * refusée ne fait pas échouer la page : réessai ligne par ligne, chacune
     * dans son point de sauvegarde.
     *
     * @param  list<array<string, mixed>>  $lignes
     */
    private function inserer(array $lignes): void
    {
        if ($this->essai || $lignes === []) {
            return;
        }
        try {
            $this->bilan['creees'] += DB::transaction(fn (): int => DB::table('companies')->insertOrIgnore($lignes));
        } catch (QueryException) {
            foreach ($lignes as $ligne) {
                try {
                    $this->bilan['creees'] += DB::transaction(fn (): int => DB::table('companies')->insertOrIgnore([$ligne]));
                } catch (QueryException) {
                    $this->bilan['lignes_ignorees']++;
                }
            }
        }
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

    /** @return array<string, int> */
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
        if ($this->limite > 0 && $this->bilan['unites_lues'] >= $this->limite) {
            return true;
        }
        if ($this->jusqua !== null && now()->greaterThanOrEqualTo($this->jusqua)) {
            $this->arretHeure = true;

            return true;
        }

        return $this->memoireProcheDeLaLimite();
    }

    private function memoireProcheDeLaLimite(): bool
    {
        $plafond = $this->plafondMemoire();
        if ($plafond <= 0 || memory_get_usage(false) < $plafond) {
            return false;
        }
        gc_collect_cycles();
        if (memory_get_usage(false) < $plafond) {
            return false;
        }
        if (! $this->arretMemoire) {
            $this->arretMemoire = true;
            Log::warning('[INSEE] import des familles : arrêt propre, mémoire proche de la limite', [
                'octets' => memory_get_usage(false), 'plafond' => $plafond, 'pages' => $this->bilan['pages'],
            ]);
        }

        return true;
    }
}
