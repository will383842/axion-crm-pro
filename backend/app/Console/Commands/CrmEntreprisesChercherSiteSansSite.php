<?php

namespace App\Console\Commands;

use App\Crm\FichesProtegees;
use App\Crm\Presse\LecturePageAccueil;
use App\Crm\Presse\SiteMedia;
use App\Crm\Sites\CandidatsSite;
use App\Crm\Sites\GardeDisque;
use App\Crm\Sites\PerimetreEnrichissement;
use App\Crm\Sites\PreuveSiren;
use App\Crm\Sites\SiteFiable;
use App\Crm\Sites\VerificationSite;
use App\Services\Domain\DomainFinderService;
use App\Support\WorkspaceContext;
use Carbon\CarbonInterface;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;
use stdClass;
use Throwable;

/**
 * SITE POUR UNE FICHE SANS SITE (PR 4) — gratuit, sans waterfall.
 *
 * Fiches d'un lot (`PerimetreEnrichissement`, 1 à 7) : domaines candidats tirés
 * de la dénomination, de l'enseigne et du sigle (`CandidatsSite::domaines`),
 * retenus SEULEMENT par la preuve du SIREN (`PreuveSiren`).
 *
 *   - prouvé : `website`, `website_method` = `candidat-prouve` (jamais `guess`),
 *     `field_origins` / `field_origins_at` (`website`), marqueurs
 *     `site_entreprise` et `recherche_site` ; `enriched_at` et `updated_at`
 *     ne bougent pas ;
 *   - aucun : marqueur `recherche_site` {etape: candidats, resultat: aucun} ;
 *     la fiche n'est plus reprise ;
 *   - fiche devenue protégée ou dotée d'un site entre la lecture et
 *     l'écriture : écartée (même WHERE que la lecture).
 *
 * Prudence : même verrou que `verifier-sites` et `chercher-site-prouve`, même
 * fenêtre de lancement, `GardeDisque` avant chaque paquet (code 4), paquets de
 * 40 fiches, une transaction par paquet. `--dry-run` : sites lus, AUCUNE
 * écriture (un `SELECT count(*)` au même WHERE). Rapport chiffré, sans domaine
 * ni SIREN.
 *
 * ⛔ Pas au calendrier : lancement à la main.
 */
class CrmEntreprisesChercherSiteSansSite extends Command
{
    protected $signature = 'crm:entreprises:chercher-site-sans-site
                            {--lot= : Lot 1 à 7 (obligatoire)}
                            {--dry-run : Essai à blanc : sites lus, rien n\'est écrit}
                            {--limite= : Ne traiter que N fiches au plus}
                            {--jusqua= : Arrêt propre à HH:MM, heure de Paris}
                            {--forcer : Passer outre la fenêtre de lancement (essai)}
                            {--paquet=40 : Fiches par paquet et par transaction (1 à 200)}
                            {--delai-domaine-ms=1500 : Attente entre deux requêtes d\'un même domaine (1000 à 60000)}
                            {--timeout=5 : Délai d\'attente d\'une requête, en secondes (1 à 10)}';

    protected $description = 'Fiches sans site d\'un lot : cherche le site par domaines candidats prouvés par le SIREN. Gratuit, ne supprime rien.';

    /** Le verrou de `crm:entreprises:verifier-sites` : jamais deux traitements de sites à la fois. */
    private const VERROU = 'crm:entreprises:verifier-sites:';

    private const METHODE = 'candidat-prouve';

    private const ORIGINE_CHAMP = 'candidat';

    private const LIBELLES = [
        'fiches_lues' => 'fiches lues',
        'prouvees' => 'sites prouvés par le SIREN',
        'sans_site' => 'toujours sans site (aucun candidat prouvé)',
        'ecartees' => 'écartées (fiche protégée ou site rempli pendant la lecture)',
        'erreurs' => 'erreurs (non marquées)',
        'paquets' => 'paquets validés',
    ];

    /** @var array<string, int> */
    private array $bilan = [];

    private string $workspaceId = '';

    private string $aujourdhui = '';

    public function handle(DomainFinderService $finder): int
    {
        $lot = $this->entier('lot', 1, 7);
        $limite = $this->entier('limite', 1, PHP_INT_MAX);
        $paquet = $this->entier('paquet', 1, 200);
        $delai = $this->entier('delai-domaine-ms', 1000, 60000);
        $timeout = $this->entier('timeout', 1, 10);
        $jusqua = $this->option('jusqua');
        if (! is_int($lot) || $limite === false || ! is_int($paquet) || ! is_int($delai) || ! is_int($timeout)
            || ($jusqua !== null && preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', (string) $jusqua) !== 1)) {
            $this->error('Options invalides (--lot 1 à 7 obligatoire, --limite ≥ 1, --paquet 1 à 200, --delai-domaine-ms 1000 à 60000, --timeout 1 à 10, --jusqua HH:MM).');

            return self::FAILURE;
        }
        $forcer = (bool) $this->option('forcer');
        $maintenant = Carbon::now(VerificationSite::FUSEAU);
        if (! $forcer && ($raison = VerificationSite::horsFenetre($maintenant)) !== null) {
            $this->error("Refus de démarrer : hors fenêtre ({$raison}). --forcer pour un essai.");

            return self::FAILURE;
        }
        $this->aujourdhui = $maintenant->toDateString();
        $workspaceId = DB::table('workspaces')->where('slug', (string) config('crm.ingest.business_workspace', 'axion-ia'))
            ->whereNull('deleted_at')->value('id');
        if ($workspaceId === null) {
            $this->error('Espace business introuvable.');

            return self::FAILURE;
        }
        $this->workspaceId = (string) $workspaceId;
        $fin = $this->heureDArret($maintenant, $jusqua === null ? null : (string) $jusqua, $forcer);

        return WorkspaceContext::run($this->workspaceId, function () use ($finder, $lot, $limite, $paquet, $delai, $timeout, $fin): int {
            $verrou = self::VERROU . $this->workspaceId;
            if (! (bool) DB::selectOne('SELECT pg_try_advisory_lock(hashtext(?)) AS ok', [$verrou])->ok) {
                $this->error('Une autre exécution (vérification ou recherche de sites) est en cours : refus.');

                return self::FAILURE;
            }
            try {
                $lecteur = new LecturePageAccueil(1, $timeout, $delai, static function (int $ms): void {
                    if ($ms > 0) {
                        Sleep::usleep($ms * 1000);
                    }
                }, CrmEntreprisesVerifierSites::USER_AGENT);

                return $this->parcourir($lot, $limite, $paquet, new PreuveSiren($lecteur, $finder), $fin);
            } finally {
                DB::select('SELECT pg_advisory_unlock(hashtext(?))', [$verrou]);
            }
        });
    }

    private function parcourir(int $lot, ?int $limite, int $paquet, PreuveSiren $preuve, ?CarbonInterface $fin): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $disque = app()->bound(GardeDisque::class) ? app(GardeDisque::class) : GardeDisque::depuisConfig();
        $this->bilan = array_fill_keys(array_keys(self::LIBELLES), 0);
        $avant = $this->compterFiches();
        $vues = [];
        $code = self::SUCCESS;
        try {
            while (true) {
                if ($fin !== null && Carbon::now(VerificationSite::FUSEAU)->greaterThanOrEqualTo($fin)) {
                    $this->warn('Arrêt à l\'heure demandée : reprise au prochain lancement.');
                    break;
                }
                if ($disque->doitArreter()) {
                    $this->error('Arrêt : disque utilisé à plus de ' . GardeDisque::SEUIL_POURCENT . ' % (ou illisible).');
                    Log::warning('crm:entreprises:chercher-site-sans-site arrêt disque');
                    $code = GardeDisque::CODE_SORTIE;
                    break;
                }
                $combien = $limite === null ? $paquet : min($paquet, $limite - $this->bilan['fiches_lues']);
                if ($combien <= 0) {
                    break;
                }
                // Les fiches déjà vues (écartées, ou à blanc) ne sont pas marquées : on les saute.
                [$sql, $liaisons] = PerimetreEnrichissement::selectionSql($this->workspaceId, $lot, count($vues) + $combien);
                $fiches = array_values(array_filter(DB::select($sql, $liaisons), static fn (stdClass $f): bool => ! isset($vues[(int) $f->id])));
                $fiches = array_slice($fiches, 0, $combien);
                if ($fiches === []) {
                    break;
                }
                foreach ($fiches as $f) {
                    $vues[(int) $f->id] = true;
                }
                $this->traiterPaquet($fiches, $lot, $preuve, $dryRun);
            }
        } catch (Throwable $e) {
            $this->error('INTERROMPU : ' . $e::class . '. Les paquets validés restent.');
            Log::error('crm:entreprises:chercher-site-sans-site interrompu', ['exception' => $e::class]);
            $code = self::FAILURE;
        }
        $apres = $this->compterFiches();
        Log::info('crm.entreprises.chercher_site_sans_site', $this->bilan + ['lot' => $lot, 'dry_run' => $dryRun, 'lignes_avant' => $avant, 'lignes_apres' => $apres]);
        $this->info($dryRun ? '[À BLANC] les sites ont été lus, rien n\'a été écrit.' : 'Recherche appliquée.');
        $this->table(['compteur', 'nombre'], array_map(fn (string $k, string $l): array => [$l, $this->bilan[$k]], array_keys(self::LIBELLES), self::LIBELLES));
        if ($avant !== $apres) {
            $this->error("Comptage des fiches DIFFÉRENT : {$avant} avant, {$apres} après. À examiner.");

            return self::FAILURE;
        }
        $this->line("Comptage des fiches : identique ({$avant} avant, {$apres} après).");

        return $code;
    }

    /** @param  list<stdClass>  $fiches */
    private function traiterPaquet(array $fiches, int $lot, PreuveSiren $preuve, bool $dryRun): void
    {
        $sigles = DB::table('companies')->where('workspace_id', $this->workspaceId)
            ->whereIn('id', array_map(static fn (stdClass $f): int => (int) $f->id, $fiches))
            ->selectRaw("id, metadata ->> 'sigle' AS sigle")->pluck('sigle', 'id');
        $trouves = [];
        foreach ($fiches as $f) {
            $this->bilan['fiches_lues']++;
            $siren = (string) preg_replace('/\D/', '', (string) $f->siren);
            $trouves[(int) $f->id] = null;
            foreach (CandidatsSite::domaines($f->denomination, $f->enseigne, $sigles[$f->id] ?? null, null) as $hote) {
                $url = 'https://' . $hote . '/';
                if (($p = $preuve->prouver($siren, $url)) !== null) {
                    $trouves[(int) $f->id] = [$url, $p];
                    break;
                }
            }
        }
        // Une transaction par paquet ; à blanc : aucune écriture, un count au même WHERE.
        if (! $dryRun) {
            DB::beginTransaction();
            DB::statement("SET LOCAL app.conserver_updated_at = 'on'");
        }
        try {
            foreach ($trouves as $id => $trouve) {
                try {
                    $n = $dryRun ? $this->compterEligibles($id) : DB::transaction(fn (): int => $this->ecrire($id, $lot, $trouve));
                } catch (Throwable $e) {
                    Log::warning('crm:entreprises:chercher-site-sans-site écriture en erreur', ['fiche' => $id, 'exception' => $e::class]);
                    $this->bilan['erreurs']++;

                    continue;
                }
                $this->bilan[$n === 0 ? 'ecartees' : ($trouve === null ? 'sans_site' : 'prouvees')]++;
            }
            if (! $dryRun) {
                DB::commit();
            }
        } catch (Throwable $e) {
            if (! $dryRun) {
                DB::rollBack();
            }

            throw $e;
        }
        $this->bilan['paquets']++;
    }

    /**
     * Écrit (5.8) : prouvé → `website` et ses marqueurs ; sinon le seul
     * marqueur d'échec. Rien n'est supprimé, `enriched_at` n'est pas touché.
     *
     * @param  array{0: string, 1: string}|null  $trouve  [adresse, preuve]
     */
    private function ecrire(int $id, int $lot, ?array $trouve): int
    {
        $recherche = ['etape' => 'candidats', 'resultat' => $trouve === null ? 'aucun' : 'prouve', 'lot' => $lot, 'le' => $this->aujourdhui, 'v' => CandidatsSite::VERSION];
        if ($trouve === null) {
            return DB::update(
                "UPDATE companies SET metadata = COALESCE(metadata, '{}'::jsonb) || jsonb_build_object(?::text, ?::jsonb) WHERE " . self::gardeSql(),
                [PerimetreEnrichissement::MARQUEUR, json_encode($recherche, JSON_THROW_ON_ERROR), ...$this->gardeLiaisons($id)],
            );
        }
        $site = ['statut' => SiteMedia::TROUVE_VERIFIE, 'url' => $trouve[0], 'preuve' => $trouve[1], 'origine' => CandidatsSite::ORIGINE, 'le' => $this->aujourdhui, 'v' => VerificationSite::VERSION];

        return DB::update(
            "UPDATE companies
                SET website = ?, website_method = ?,
                    field_origins = COALESCE(field_origins, '{}'::jsonb) || jsonb_build_object('website', ?::text),
                    field_origins_at = COALESCE(field_origins_at, '{}'::jsonb) || jsonb_build_object('website', ?::text),
                    metadata = COALESCE(metadata, '{}'::jsonb) || jsonb_build_object(?::text, ?::jsonb, ?::text, ?::jsonb)
              WHERE " . self::gardeSql(),
            [
                $trouve[0], self::METHODE, self::ORIGINE_CHAMP, Carbon::now('UTC')->toIso8601String(),
                SiteFiable::CLE, json_encode($site, JSON_THROW_ON_ERROR), PerimetreEnrichissement::MARQUEUR, json_encode($recherche, JSON_THROW_ON_ERROR),
                ...$this->gardeLiaisons($id),
            ],
        );
    }

    private function compterEligibles(int $id): int
    {
        return (int) DB::selectOne('SELECT count(*) AS n FROM companies WHERE ' . self::gardeSql(), $this->gardeLiaisons($id))->n;
    }

    /** Fiche vivante, jamais passée au waterfall, toujours sans site, pas encore cherchée, non protégée. */
    private static function gardeSql(): string
    {
        return "id = ? AND workspace_id = ? AND deleted_at IS NULL AND enriched_at IS NULL
                AND (website IS NULL OR btrim(website) = '')
                AND (metadata -> '" . PerimetreEnrichissement::MARQUEUR . "') IS NULL
                AND " . FichesProtegees::conditionSql('companies.id', null, 'companies.workspace_id');
    }

    /** @return list<int|string> */
    private function gardeLiaisons(int $id): array
    {
        return [$id, $this->workspaceId];
    }

    private function compterFiches(): int
    {
        return (int) DB::table('companies')->where('workspace_id', $this->workspaceId)->count();
    }

    /** L'heure d'arrêt (Paris) : celle demandée, au plus la fin de fenêtre sans --forcer. */
    private function heureDArret(CarbonInterface $maintenant, ?string $jusqua, bool $forcer): ?CarbonInterface
    {
        $finFenetre = $maintenant->copy()->setTimeFromTimeString(VerificationSite::HEURE_FIN);
        if ($jusqua === null) {
            return $forcer ? null : $finFenetre;
        }
        $demandee = $maintenant->copy()->setTimeFromTimeString($jusqua);
        if ($forcer && $demandee->lessThanOrEqualTo($maintenant)) {
            $demandee = $demandee->addDay();
        }

        return ! $forcer && $demandee->greaterThan($finFenetre) ? $finFenetre : $demandee;
    }

    /** Entier dans [$min, $max] ; null si absent ; false si invalide. */
    private function entier(string $nom, int $min, int $max): int|false|null
    {
        $valeur = $this->option($nom);
        if ($valeur === null || $valeur === '') {
            return null;
        }
        if (! is_string($valeur) || preg_match('/^\d{1,18}$/', $valeur) !== 1) {
            return false;
        }
        $n = (int) $valeur;

        return $n >= $min && $n <= $max ? $n : false;
    }
}
