<?php

namespace App\Console\Commands;

use App\Crm\Presse\LecturePageAccueil;
use App\Crm\Presse\SiteMedia;
use App\Crm\Sites\CurseurTraitement;
use App\Crm\Sites\SiteFiable;
use App\Crm\Sites\VerificationSite;
use App\Services\Domain\DomainFinderService;
use App\Support\WorkspaceContext;
use Carbon\CarbonInterface;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use stdClass;
use Throwable;

/**
 * VÉRIFIER LES SITES DEVINÉS, PAR LOTS (lot N6, 03/10/2026).
 *
 * ≈ 824 000 fiches portent un site DEVINÉ, tenu pour « non vérifié » depuis
 * #305 (`SiteFiable`). Cette commande cherche, pour chacune, une preuve FORTE
 * — le SIREN de l'entreprise sur la page d'accueil ou la page de mentions
 * légales — et pose le marqueur `metadata.site_entreprise` que `SiteFiable`
 * lit déjà : `verifie`, `non-conforme`, `injoignable` ou `robots-interdit`
 * (règles : `VerificationSite`). Sans SIREN sur la page, le site reste NON
 * VÉRIFIÉ : il n'est jamais effacé, aucune ligne n'est supprimée, aucune
 * autre colonne n'est écrite (ni `updated_at`).
 *
 * ── PRUDENCE (serveur à 2 CPU, sites de tiers) ───────────────────────────
 *
 *   - séquentielle, par paquets (`--paquet`, 40) ; aucune file Horizon ;
 *   - lecture par `LecturePageAccueil` : robots.txt respecté (lu une fois par
 *     origine), garde SSRF (`SsrfGuard`, IP épinglée, chaque redirection
 *     revérifiée), ports 80/443, corps ≤ 1,5 Mo coupé en cours de transfert,
 *     concurrence faible (`--concurrence`, 3), délai par domaine
 *     (`--delai-domaine-ms`, 1 500), délai d'expiration court (`--timeout`, 5 s) ;
 *   - une page partagée par des milliers de fiches (france.fr) n'est lue
 *     qu'une fois : seuls quelques SIREN en sont gardés, jamais le texte ;
 *   - refus de démarrer hors fenêtre (mardi → samedi, 08:00-19:00, heure de
 *     Paris, jamais les 1er, 2 et 3 du mois) sauf `--forcer` ; arrêt propre
 *     entre deux paquets à `--jusqua` (19:00 au plus sans `--forcer`) ;
 *   - curseur PERSISTANT (`curseurs_traitements`) écrit dans la transaction
 *     du paquet : la reprise est exacte ; un par périmètre (`--audience`) ;
 *   - une seule exécution à la fois (verrou consultatif Postgres) ;
 *   - `--dry-run` : les sites sont lus, chaque paquet est ANNULÉ (marqueurs
 *     ET curseur) ;
 *   - rapport : des COMPTEURS seulement (ni domaine, ni SIREN, ni nom) et
 *     le comptage des fiches avant = après.
 *
 * ⛔ NE PAS inscrire au calendrier (`routes/console.php`) : lancement à la
 * main, après un essai à blanc de 200 fiches validé par Will.
 */
class CrmEntreprisesVerifierSites extends Command
{
    protected $signature = 'crm:entreprises:verifier-sites
                            {--dry-run : Essai à blanc : les sites sont lus, rien n\'est écrit (ni marqueur, ni curseur)}
                            {--limite= : Ne traiter que N fiches au plus}
                            {--audience= : Seulement les fiches de l\'audience N (priorité : 1)}
                            {--jusqua= : Arrêt propre à HH:MM, heure de Paris (19:00 au plus sans --forcer)}
                            {--forcer : Passer outre la fenêtre de lancement (essai)}
                            {--depuis-debut : Repartir du début (le curseur de ce périmètre revient à 0)}
                            {--paquet=40 : Fiches par paquet et par transaction (1 à 200)}
                            {--concurrence=3 : Requêtes HTTP simultanées au plus (1 à 6)}
                            {--delai-domaine-ms=1500 : Attente minimale entre deux requêtes d\'un même domaine}
                            {--timeout=5 : Délai d\'attente d\'une requête, en secondes (1 à 10)}';

    protected $description = 'Vérifie les sites devinés par le SIREN (accueil ou mentions légales), par lots, avec reprise au curseur. Ne supprime rien.';

    public const USER_AGENT = 'AxionCRM-VerificationSites/1.0 (+https://axion-ia.com; contact@axion-ia.com)';

    /** Adresses lues gardées en mémoire (statut, quelques SIREN, lien de mentions) au plus. */
    private const CACHE_MAX = 20000;

    private const STATUT_ERREUR = 'erreur';

    /** Libellés du rapport, dans l'ordre d'affichage. */
    private const LIBELLES = [
        'verifies' => 'vérifiés',
        'verifies_accueil' => '  dont SIREN sur l\'accueil',
        'verifies_mentions' => '  dont SIREN dans les mentions légales',
        'non_conformes' => 'non conformes (SIREN absent, restent non vérifiés)',
        'injoignables' => 'injoignables',
        'ignores_robots' => 'ignorés (robots.txt)',
        'erreurs' => 'erreurs (non marquées)',
        'ecartes' => 'écartés (fiche modifiée pendant la lecture)',
        'fiches_lues' => 'fiches lues',
        'paquets' => 'paquets validés',
    ];

    /** @var array<string, int> */
    private array $bilan = [];

    /** @var array<string, array{statut: string, sirens: list<string>, mentions: ?string, finale: string}> */
    private array $cache = [];

    private string $workspaceId = '';

    public function handle(DomainFinderService $finder): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $forcer = (bool) $this->option('forcer');
        $limite = $this->entier('limite', 1, PHP_INT_MAX);
        $audience = $this->entier('audience', 1, PHP_INT_MAX);
        $paquet = $this->entier('paquet', 1, 200);
        $concurrence = $this->entier('concurrence', 1, 6);
        $delai = $this->entier('delai-domaine-ms', 0, 60000);
        $timeout = $this->entier('timeout', 1, 10);
        $jusqua = $this->option('jusqua');
        if ($limite === false || $audience === false || $paquet === false || $paquet === null || $concurrence === false
            || $concurrence === null || $delai === false || $delai === null || $timeout === false || $timeout === null
            || ($jusqua !== null && preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $jusqua) !== 1)) {
            $this->error('Options invalides (--limite ≥ 1, --paquet 1 à 200, --concurrence 1 à 6, --timeout 1 à 10, --jusqua HH:MM).');

            return self::FAILURE;
        }

        $maintenant = Carbon::now(VerificationSite::FUSEAU);
        if (! $forcer && ($raison = VerificationSite::horsFenetre($maintenant)) !== null) {
            $this->error("Refus de démarrer : hors fenêtre ({$raison}). --forcer pour un essai.");

            return self::FAILURE;
        }
        $fin = $this->heureDArret($maintenant, $jusqua, $forcer);

        $slug = (string) config('crm.ingest.business_workspace', 'axion-ia');
        $workspaceId = DB::table('workspaces')->where('slug', $slug)->whereNull('deleted_at')->value('id');
        if ($workspaceId === null) {
            $this->error('Espace business introuvable.');

            return self::FAILURE;
        }
        $this->workspaceId = (string) $workspaceId;

        return WorkspaceContext::run($this->workspaceId, function () use ($finder, $dryRun, $limite, $audience, $paquet, $concurrence, $delai, $timeout, $fin): int {
            if ($audience !== null && ! DB::table('email_audiences')->where('id', $audience)
                ->where('workspace_id', $this->workspaceId)->whereNull('deleted_at')->exists()) {
                $this->error("Audience {$audience} introuvable dans l'espace business.");

                return self::FAILURE;
            }
            $verrou = 'crm:entreprises:verifier-sites:' . $this->workspaceId;
            if (! (bool) DB::selectOne('SELECT pg_try_advisory_lock(hashtext(?)) AS ok', [$verrou])->ok) {
                $this->error('Une autre exécution est en cours : refus.');

                return self::FAILURE;
            }
            try {
                return $this->parcourir($finder, $dryRun, $limite, $audience, $paquet, new LecturePageAccueil($concurrence, $timeout, $delai, null, self::USER_AGENT), $fin);
            } finally {
                DB::select('SELECT pg_advisory_unlock(hashtext(?))', [$verrou]);
            }
        });
    }

    private function parcourir(DomainFinderService $finder, bool $dryRun, ?int $limite, ?int $audience, int $paquet, LecturePageAccueil $lecteur, ?CarbonInterface $fin): int
    {
        $cle = VerificationSite::cleCurseur($audience);
        $this->bilan = array_fill_keys(array_keys(self::LIBELLES), 0);
        $this->cache = [];
        $avant = $this->compterFiches();

        if ((bool) $this->option('depuis-debut') && ! $dryRun) {
            CurseurTraitement::remettreAZero($this->workspaceId, $cle);
        }
        $depart = (bool) $this->option('depuis-debut') ? 0 : (CurseurTraitement::lire($this->workspaceId, $cle) ?? 0);
        $curseur = $depart;
        $arretHeure = false;
        $termine = false;
        $interruption = null;
        try {
            while (true) {
                if ($fin !== null && Carbon::now(VerificationSite::FUSEAU)->greaterThanOrEqualTo($fin)) {
                    $arretHeure = true;
                    break;
                }
                $combien = $limite === null ? $paquet : min($paquet, $limite - $this->bilan['fiches_lues']);
                if ($combien <= 0) {
                    break;
                }
                [$sql, $liaisons] = VerificationSite::selectionSql($this->workspaceId, $curseur, $combien, $audience);
                $fiches = DB::select($sql, $liaisons);
                if ($fiches === []) {
                    $termine = true;
                    break;
                }
                $this->traiterPaquet(array_values($fiches), $finder, $lecteur, $cle, $dryRun);
                $curseur = (int) $fiches[count($fiches) - 1]->id;
            }
        } catch (Throwable $e) {
            $interruption = $e;
        }
        $apres = $this->compterFiches();

        Log::info('crm.entreprises.verifier_sites', $this->bilan + [
            'dry_run' => $dryRun, 'audience' => $audience, 'curseur' => $curseur, 'lignes_avant' => $avant, 'lignes_apres' => $apres,
        ]);
        $this->info($dryRun ? '[À BLANC] les sites ont été lus, rien n\'a été écrit (ni marqueur, ni curseur).' : 'Vérification appliquée.');
        $lignes = [];
        foreach (self::LIBELLES as $k => $libelle) {
            $lignes[] = [$libelle, $this->bilan[$k]];
        }
        $lignes[] = ['lignes avant (fiches de l\'espace)', $avant];
        $lignes[] = ['lignes après (fiches de l\'espace)', $apres];
        $this->table(['compteur', 'nombre'], $lignes);
        $this->line("Curseur : fiche {$depart} → fiche {$curseur}" . ($audience !== null ? " (audience {$audience})" : '') . '.');
        if ($arretHeure && $fin !== null) {
            $this->warn('Arrêt à ' . $fin->format('H:i') . " (heure de Paris) : {$this->bilan['paquets']} paquet(s) validé(s), reprise au curseur au prochain lancement.");
        } elseif ($termine) {
            $this->line('Parcours terminé pour ce périmètre (--depuis-debut pour le reprendre).');
        }

        if ($avant !== $apres) {
            $this->error("Comptage des fiches DIFFÉRENT : {$avant} avant, {$apres} après. Ce traitement ne crée ni ne supprime rien : à examiner.");

            return self::FAILURE;
        }
        $this->line("Comptage des fiches : identique ({$avant} avant, {$apres} après).");

        if ($interruption !== null) {
            $this->error('INTERROMPU : ' . $interruption::class . ". Les paquets validés restent ; reprise au curseur (fiche {$curseur}).");
            Log::error('crm:entreprises:verifier-sites interrompu', ['exception' => $interruption]);

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * L'heure d'arrêt du jour (Paris) : `--jusqua`, plafonnée à la fin de
     * fenêtre sans `--forcer` ; aucune avec `--forcer` sans `--jusqua`.
     */
    private function heureDArret(CarbonInterface $maintenant, ?string $jusqua, bool $forcer): ?CarbonInterface
    {
        $finFenetre = $maintenant->copy()->setTimeFromTimeString(VerificationSite::HEURE_FIN);
        if ($jusqua === null) {
            return $forcer ? null : $finFenetre;
        }
        $demandee = $maintenant->copy()->setTimeFromTimeString($jusqua);

        return ! $forcer && $demandee->greaterThan($finFenetre) ? $finFenetre : $demandee;
    }

    private function compterFiches(): int
    {
        return DB::table('companies')->where('workspace_id', $this->workspaceId)->count();
    }

    /** @param  list<stdClass>  $fiches */
    private function traiterPaquet(array $fiches, DomainFinderService $finder, LecturePageAccueil $lecteur, string $cle, bool $dryRun): void
    {
        $delta = [];

        // 1. La page d'accueil (réseau HORS transaction).
        $aLire = [];
        foreach ($fiches as $f) {
            $this->compter($delta, 'fiches_lues');
            $f->siren = (string) preg_replace('/\D/', '', (string) $f->siren);
            $f->cible = LecturePageAccueil::cible((string) $f->website);
            $f->decision = null;
            $f->mentions = null;
            if ($f->cible === null) {
                $f->decision = [SiteMedia::INJOIGNABLE, null, null, VerificationSite::MOTIF_ADRESSE];
            } else {
                $aLire[] = $f->cible;
            }
        }
        $this->lire($aLire, $lecteur, $finder);

        $aLire = [];
        foreach ($fiches as $f) {
            if ($f->decision !== null || $f->cible === null) {
                continue;
            }
            $lu = $this->cache[$f->cible] ?? ['statut' => self::STATUT_ERREUR, 'sirens' => [], 'mentions' => null, 'finale' => $f->cible];
            $f->decision = match ($lu['statut']) {
                LecturePageAccueil::STATUT_LU => in_array($f->siren, $lu['sirens'], true)
                    ? [SiteMedia::VERIFIE, $f->cible, VerificationSite::PREUVE_ACCUEIL, null] : null,
                LecturePageAccueil::STATUT_ROBOTS => [SiteMedia::ROBOTS_INTERDIT, $f->cible, null, null],
                LecturePageAccueil::STATUT_ILLISIBLE => [SiteMedia::INJOIGNABLE, $f->cible, null, VerificationSite::MOTIF_ILLISIBLE],
                self::STATUT_ERREUR => false,
                default => [SiteMedia::INJOIGNABLE, $f->cible, null, null],
            };
            if ($f->decision === null) {
                // 2. Accueil lu sans le SIREN : les mentions légales.
                $base = VerificationSite::memeSite($lu['finale'], $f->cible) ? $lu['finale'] : $f->cible;
                $f->mentions = $lu['mentions'] ?? VerificationSite::mentionsParDefaut($base);
                if ($f->mentions === null) {
                    $f->decision = [SiteMedia::NON_CONFORME, $f->cible, null, null];
                } else {
                    $aLire[] = $f->mentions;
                }
            }
        }
        $this->lire($aLire, $lecteur, $finder);
        foreach ($fiches as $f) {
            if ($f->decision === null && $f->mentions !== null) {
                $lu = $this->cache[$f->mentions] ?? null;
                $f->decision = $lu !== null && $lu['statut'] === LecturePageAccueil::STATUT_LU && in_array($f->siren, $lu['sirens'], true)
                    ? [SiteMedia::VERIFIE, $f->cible, VerificationSite::PREUVE_MENTIONS, null]
                    : [SiteMedia::NON_CONFORME, $f->cible, null, null];
            }
        }

        // 3. Écrire, dans UNE transaction par paquet, avec le curseur.
        DB::beginTransaction();
        try {
            DB::statement("SET LOCAL app.conserver_updated_at = 'on'");
            foreach ($fiches as $f) {
                if (! is_array($f->decision)) {
                    $this->compter($delta, 'erreurs');

                    continue;
                }
                try {
                    $n = DB::transaction(fn (): int => $this->ecrire($f));
                } catch (Throwable $e) {
                    Log::warning('crm:entreprises:verifier-sites écriture en erreur', ['fiche' => (int) $f->id, 'exception' => $e::class]);
                    $this->compter($delta, 'erreurs');

                    continue;
                }
                if ($n === 0) {
                    $this->compter($delta, 'ecartes');

                    continue;
                }
                [$statut, , $preuve] = $f->decision;
                match ($statut) {
                    SiteMedia::VERIFIE => $this->compter($delta, $preuve === VerificationSite::PREUVE_MENTIONS ? 'verifies_mentions' : 'verifies_accueil'),
                    SiteMedia::NON_CONFORME => $this->compter($delta, 'non_conformes'),
                    SiteMedia::ROBOTS_INTERDIT => $this->compter($delta, 'ignores_robots'),
                    default => $this->compter($delta, 'injoignables'),
                };
            }
            CurseurTraitement::ecrire($this->workspaceId, $cle, (int) $fiches[count($fiches) - 1]->id);
            $dryRun ? DB::rollBack() : DB::commit();
        } catch (Throwable $e) {
            DB::rollBack();

            throw $e;
        }
        $delta['verifies'] = ($delta['verifies_accueil'] ?? 0) + ($delta['verifies_mentions'] ?? 0);
        foreach ($delta as $k => $n) {
            $this->bilan[$k] = ($this->bilan[$k] ?? 0) + $n;
        }
        $this->bilan['paquets']++;
    }

    /**
     * Pose le marqueur — et RIEN d'autre : seule la clé `site_entreprise` de
     * `metadata` change. Seulement si la fiche est toujours vivante, au même
     * site, et toujours « non vérifiée » (jamais d'écrasement d'un `verifie`).
     */
    private function ecrire(stdClass $f): int
    {
        [$statut, $url, $preuve, $motif] = $f->decision;
        $valeur = ['statut' => $statut, 'url' => $url]
            + ($preuve !== null ? ['preuve' => $preuve] : [])
            + ($motif !== null ? ['motif' => $motif] : [])
            + ['le' => Carbon::now(VerificationSite::FUSEAU)->toDateString(), 'v' => VerificationSite::VERSION];

        return DB::update(
            "UPDATE companies
                SET metadata = COALESCE(metadata, '{}'::jsonb) || jsonb_build_object(?::text, ?::jsonb)
              WHERE id = ? AND workspace_id = ? AND deleted_at IS NULL AND website = ?
                AND " . SiteFiable::nonVerifieSql('companies'),
            [SiteFiable::CLE, json_encode($valeur, JSON_THROW_ON_ERROR), (int) $f->id, $this->workspaceId, (string) $f->website],
        );
    }

    /**
     * Lit les adresses pas encore en mémoire, et n'en garde que le statut,
     * les SIREN prouvés et le lien de mentions légales. Une lecture groupée
     * qui lève est refaite adresse par adresse ; celle qui lève encore est
     * `erreur` (fiche comptée, non marquée).
     *
     * @param  list<string>  $cibles
     */
    private function lire(array $cibles, LecturePageAccueil $lecteur, DomainFinderService $finder): void
    {
        $nouvelles = array_values(array_filter(array_unique($cibles), fn (string $c): bool => ! isset($this->cache[$c])));
        if ($nouvelles === []) {
            return;
        }
        if (count($this->cache) + count($nouvelles) > self::CACHE_MAX) {
            $this->cache = [];
        }
        try {
            $lus = $lecteur->lire($nouvelles, true);
        } catch (Throwable $e) {
            Log::warning('crm:entreprises:verifier-sites lecture groupée en erreur, relecture une à une', ['exception' => $e::class]);
            $lus = [];
            foreach ($nouvelles as $cible) {
                try {
                    $lus += $lecteur->lire([$cible], true);
                } catch (Throwable $e) {
                    Log::warning('crm:entreprises:verifier-sites adresse en erreur', ['exception' => $e::class]);
                }
            }
        }
        foreach ($nouvelles as $cible) {
            $lu = $lus[$cible] ?? null;
            if ($lu === null) {
                $this->cache[$cible] = ['statut' => self::STATUT_ERREUR, 'sirens' => [], 'mentions' => null, 'finale' => $cible];

                continue;
            }
            $corps = $lu['corps'] ?? '';
            $finale = $lu['finale'] ?? $cible;
            $estLu = $lu['statut'] === LecturePageAccueil::STATUT_LU;
            $this->cache[$cible] = [
                'statut' => $lu['statut'],
                'sirens' => $estLu ? $finder->sirensDansPage($corps) : [],
                'mentions' => $estLu ? VerificationSite::lienMentions(DomainFinderService::tronquer($corps), $finale) : null,
                'finale' => $finale,
            ];
        }
    }

    /** @param  array<string, int>  $delta */
    private function compter(array &$delta, string $cle): void
    {
        $delta[$cle] = ($delta[$cle] ?? 0) + 1;
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
