<?php

namespace App\Console\Commands;

use App\Crm\FichesProtegees;
use App\Crm\Presse\LecturePageAccueil;
use App\Crm\Presse\SiteMedia;
use App\Crm\Sites\CandidatsSite;
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
use Illuminate\Support\Sleep;
use stdClass;
use Throwable;

/**
 * CHERCHER LE VRAI SITE, PROUVÉ PAR LE SIREN (suite du lot N6, 04/10/2026).
 *
 * Commande SŒUR de `crm:entreprises:verifier-sites`, sur les fiches que
 * celle-ci a jugées (règles : `CandidatsSite`) :
 *
 *   1. INJOIGNABLE → réessai si dû (3 au plus, à 3 jours d'intervalle,
 *      variantes www / sans www, https / http). SIREN trouvé → `verifie`
 *      (même site) ; page lue sans SIREN → non conforme, étape 2 ; toujours
 *      injoignable → compteur `reessais` + date `reessai_le` ; au 3e échec,
 *      étape 2.
 *   2. NON CONFORME → domaines CANDIDATS (dénomination, enseigne, sigle ;
 *      ≤ 6), lus un par un, jusqu'au premier PROUVÉ par le SIREN (accueil ou
 *      mentions légales, arrivée sur le même domaine). Prouvé →
 *      `companies.website` = le candidat, marqueur `trouve-verifie` qui GARDE
 *      l'ancien site deviné (`ancien`). Aucun → marqueur complété de
 *      `candidats` : la fiche reste SANS SITE VÉRIFIÉ, rien n'est inventé.
 *
 * Seules `companies.metadata.site_entreprise` et, pour un candidat prouvé,
 * `companies.website` sont écrites ; ni `website_method`, ni `updated_at`,
 * aucune ligne supprimée. Jamais remplacé : site de provenance connue
 * (`field_origins`), fiche protégée (`FichesProtegees`), site changé pendant
 * la lecture (garde d'écriture).
 *
 * ── PRUDENCE : LE RYTHME DE N6, PAS PLUS ─────────────────────────────────
 *
 *   - même lecteur (`LecturePageAccueil` : robots.txt, garde SSRF, corps
 *     ≤ 1,5 Mo, redirections revérifiées), mêmes options et mêmes bornes
 *     (`--concurrence` 3, `--delai-domaine-ms` 1 500, `--timeout` 5 s) ;
 *   - le MÊME verrou consultatif que `crm:entreprises:verifier-sites` : les
 *     deux commandes ne tournent jamais en même temps (la concurrence ne
 *     s'additionne pas) ;
 *   - la même fenêtre de lancement (`VerificationSite::horsFenetre`) ;
 *   - curseur persistant (un par périmètre) écrit dans la transaction du
 *     paquet ; en fin de parcours il revient à 0 : le lancement suivant
 *     recommence, et retrouve les injoignables dont le réessai est dû ;
 *   - `--dry-run` : sites lus, AUCUNE écriture (ni UPDATE, ni transaction,
 *     ni curseur) : chaque écriture est remplacée par un `SELECT count(*)`
 *     au même WHERE ;
 *   - rapport chiffré, sans domaine ni SIREN ; comptage avant = après.
 *
 * ⛔ NE PAS inscrire au calendrier (`routes/console.php`) : lancement à la
 * main, après un essai à blanc validé par Will.
 */
class CrmEntreprisesChercherSiteProuve extends Command
{
    protected $signature = 'crm:entreprises:chercher-site-prouve
                            {--dry-run : Essai à blanc : les sites sont lus, rien n\'est écrit (ni marqueur, ni site, ni curseur)}
                            {--limite= : Ne traiter que N fiches au plus}
                            {--audience= : Seulement les fiches de l\'audience N}
                            {--jusqua= : Arrêt propre à HH:MM, heure de Paris (fin de fenêtre au plus sans --forcer)}
                            {--forcer : Passer outre la fenêtre de lancement (essai)}
                            {--depuis-debut : Repartir du début (le curseur de ce périmètre revient à 0)}
                            {--paquet=40 : Fiches par paquet et par transaction (1 à 200)}
                            {--concurrence=3 : Requêtes HTTP simultanées au plus (1 à 6)}
                            {--delai-domaine-ms=1500 : Attente minimale entre deux requêtes d\'un même domaine (1000 à 60000)}
                            {--timeout=5 : Délai d\'attente d\'une requête, en secondes (1 à 10)}';

    protected $description = 'Sites non conformes : cherche le vrai site par domaines candidats prouvés par le SIREN ; sites injoignables : 3 réessais espacés. Ne supprime rien.';

    /** Le verrou de `crm:entreprises:verifier-sites` : jamais les deux à la fois. */
    private const VERROU = 'crm:entreprises:verifier-sites:';

    private const CACHE_MAX = 20000;

    private const DELAI_MIN_MS = 1000;

    private const STATUT_ERREUR = 'erreur';

    /** Libellés du rapport, dans l'ordre d'affichage. */
    private const LIBELLES = [
        'fiches_lues' => 'fiches lues',
        'reessais' => 'réessais d\'injoignables',
        'reessais_reussis' => '  dont vérifiés au réessai (SIREN trouvé, même site)',
        'reessais_sans_siren' => '  dont lus sans SIREN (→ candidats)',
        'reessais_echoues' => '  dont encore injoignables',
        'reessais_robots' => '  dont ignorés (robots.txt)',
        'reessais_pas_dus' => 'injoignables pas encore dus (3 jours entre deux réessais)',
        'bascules' => 'injoignables basculés vers les candidats (3 réessais)',
        'recherches' => 'recherches de candidats',
        'candidats_lus' => '  domaines candidats lus',
        'prouves' => '  prouvés par candidat (nouveau site vérifié, ancien gardé en trace)',
        'prouves_accueil' => '    dont SIREN sur l\'accueil',
        'prouves_mentions' => '    dont SIREN dans les mentions légales',
        'sans_site' => '  toujours sans site vérifié',
        'sans_candidat' => '    dont aucun candidat constructible',
        'proteges' => 'sites saisis, de source connue ou fiches protégées : jamais remplacés',
        'erreurs' => 'erreurs (non marquées)',
        'ecartes' => 'écartés (fiche modifiée pendant la lecture)',
        'paquets' => 'paquets validés',
    ];

    /** @var array<string, int> */
    private array $bilan = [];

    /** @var array<string, array{statut: string, sirens: list<string>, mentions: ?string, finale: string}> */
    private array $cache = [];

    private string $workspaceId = '';

    private string $aujourdhui = '';

    public function handle(DomainFinderService $finder): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $forcer = (bool) $this->option('forcer');
        $limite = $this->entier('limite', 1, PHP_INT_MAX);
        $audience = $this->entier('audience', 1, PHP_INT_MAX);
        $paquet = $this->entier('paquet', 1, 200);
        $concurrence = $this->entier('concurrence', 1, 6);
        $delai = $this->entier('delai-domaine-ms', self::DELAI_MIN_MS, 60000);
        $timeout = $this->entier('timeout', 1, 10);
        $jusqua = $this->option('jusqua');
        if ($limite === false || $audience === false || $paquet === false || $paquet === null || $concurrence === false
            || $concurrence === null || $delai === false || $delai === null || $timeout === false || $timeout === null
            || ($jusqua !== null && (! is_string($jusqua) || preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $jusqua) !== 1))) {
            $this->error('Options invalides (--limite ≥ 1, --paquet 1 à 200, --concurrence 1 à 6, --delai-domaine-ms 1000 à 60000, --timeout 1 à 10, --jusqua HH:MM).');

            return self::FAILURE;
        }

        $maintenant = Carbon::now(VerificationSite::FUSEAU);
        if (! $forcer && ($raison = VerificationSite::horsFenetre($maintenant)) !== null) {
            $this->error("Refus de démarrer : hors fenêtre ({$raison}). --forcer pour un essai.");

            return self::FAILURE;
        }
        $fin = $this->heureDArret($maintenant, $jusqua, $forcer);
        $this->aujourdhui = $maintenant->toDateString();

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
            $verrou = self::VERROU . $this->workspaceId;
            if (! (bool) DB::selectOne('SELECT pg_try_advisory_lock(hashtext(?)) AS ok', [$verrou])->ok) {
                $this->error('Une autre exécution (vérification ou recherche de sites) est en cours : refus.');

                return self::FAILURE;
            }
            try {
                $dormir = static function (int $ms): void {
                    if ($ms > 0) {
                        Sleep::usleep($ms * 1000);
                    }
                };

                return $this->parcourir($finder, $dryRun, $limite, $audience, $paquet, new LecturePageAccueil($concurrence, $timeout, $delai, $dormir, CrmEntreprisesVerifierSites::USER_AGENT), $fin);
            } finally {
                DB::select('SELECT pg_advisory_unlock(hashtext(?))', [$verrou]);
            }
        });
    }

    private function parcourir(DomainFinderService $finder, bool $dryRun, ?int $limite, ?int $audience, int $paquet, LecturePageAccueil $lecteur, ?CarbonInterface $fin): int
    {
        $cle = CandidatsSite::cleCurseur($audience);
        $this->bilan = array_fill_keys(array_keys(self::LIBELLES), 0);
        $this->cache = [];

        if ((bool) $this->option('depuis-debut') && ! $dryRun) {
            CurseurTraitement::remettreAZero($this->workspaceId, $cle);
        }
        $depart = (bool) $this->option('depuis-debut') ? 0 : (CurseurTraitement::lire($this->workspaceId, $cle) ?? 0);
        $plafond = (int) (DB::selectOne('SELECT max(id) AS m FROM companies WHERE workspace_id = ?', [$this->workspaceId])->m ?? 0);
        $avant = $this->compterFiches($depart, $plafond);
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
                [$sql, $liaisons] = CandidatsSite::selectionSql($this->workspaceId, $curseur, $combien, $audience);
                $fiches = DB::select($sql, $liaisons);
                if ($fiches === []) {
                    $termine = true;
                    break;
                }
                $this->traiterPaquet(array_values($fiches), $finder, $lecteur, $cle, $dryRun);
                $curseur = (int) $fiches[count($fiches) - 1]->id;
            }
            // Fin de parcours : le lancement suivant recommence au début, et y
            // retrouve les injoignables dont le réessai est devenu dû.
            if ($termine && ! $dryRun && $curseur > 0) {
                CurseurTraitement::remettreAZero($this->workspaceId, $cle);
            }
        } catch (Throwable $e) {
            $interruption = $e;
        }
        $apres = $this->compterFiches($depart, $plafond);

        Log::info('crm.entreprises.chercher_site_prouve', $this->bilan + [
            'dry_run' => $dryRun, 'audience' => $audience, 'curseur' => $curseur, 'lignes_avant' => $avant['toutes'], 'lignes_apres' => $apres['toutes'],
            'vivantes_avant' => $avant['vivantes'], 'vivantes_apres' => $apres['vivantes'],
        ]);
        $this->info($dryRun ? '[À BLANC] les sites ont été lus, rien n\'a été écrit (ni marqueur, ni site, ni curseur).' : 'Recherche appliquée.');
        $lignes = [];
        foreach (self::LIBELLES as $k => $libelle) {
            $lignes[] = [$libelle, $this->bilan[$k]];
        }
        $lignes[] = ['lignes avant (plage parcourue, corbeille comprise)', $avant['toutes']];
        $lignes[] = ['lignes après (plage parcourue, corbeille comprise)', $apres['toutes']];
        $lignes[] = ['fiches vivantes avant', $avant['vivantes']];
        $lignes[] = ['fiches vivantes après', $apres['vivantes']];
        $this->table(['compteur', 'nombre'], $lignes);
        $this->line("Curseur : fiche {$depart} → fiche {$curseur}" . ($audience !== null ? " (audience {$audience})" : '') . '.');
        if ($arretHeure && $fin !== null) {
            $this->warn('Arrêt à ' . $fin->format('H:i') . " (heure de Paris) : {$this->bilan['paquets']} paquet(s) validé(s), reprise au curseur au prochain lancement.");
        } elseif ($termine) {
            $this->line('Parcours terminé pour ce périmètre : le prochain lancement repart du début (réessais dus).');
        }

        if ($avant !== $apres) {
            $this->error("Comptage des fiches DIFFÉRENT : {$avant['toutes']} avant, {$apres['toutes']} après ({$avant['vivantes']} → {$apres['vivantes']} vivantes). Ce traitement ne crée, ne supprime ni ne met à la corbeille rien : à examiner.");

            return self::FAILURE;
        }
        $this->line("Comptage des fiches : identique ({$avant['toutes']} avant, {$apres['toutes']} après ; {$apres['vivantes']} vivantes).");

        if ($interruption !== null) {
            $this->error('INTERROMPU : ' . $interruption::class . ". Les paquets validés restent ; reprise au curseur (fiche {$curseur}).");
            Log::error('crm:entreprises:chercher-site-prouve interrompu', ['exception' => $interruption::class, 'curseur' => $curseur]);

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /** L'heure d'arrêt du jour (Paris) — même règle que `crm:entreprises:verifier-sites`. */
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

    /** @return array{toutes: int, vivantes: int} */
    private function compterFiches(int $depart, int $plafond): array
    {
        $n = DB::table('companies')->where('workspace_id', $this->workspaceId)
            ->where('id', '>', $depart)->where('id', '<=', $plafond)
            ->selectRaw('count(*) AS toutes, count(*) FILTER (WHERE deleted_at IS NULL) AS vivantes')
            ->first();

        return ['toutes' => (int) ($n->toutes ?? 0), 'vivantes' => (int) ($n->vivantes ?? 0)];
    }

    /** @param  list<stdClass>  $fiches */
    private function traiterPaquet(array $fiches, DomainFinderService $finder, LecturePageAccueil $lecteur, string $cle, bool $dryRun): void
    {
        $delta = [];
        $aujourdhui = Carbon::parse($this->aujourdhui, VerificationSite::FUSEAU);

        // 0. Ce que chaque fiche demande.
        $aReessayer = [];
        $aChercher = [];
        foreach ($fiches as $f) {
            $this->compter($delta, 'fiches_lues');
            $f->siren = (string) preg_replace('/\D/', '', (string) $f->siren);
            $f->cible = LecturePageAccueil::cible((string) $f->website);
            $f->reessais = is_numeric($f->reessais_avant) ? max(0, (int) $f->reessais_avant) : 0;
            $f->ecriture = null;
            $f->compteurs = [];
            // Base du marqueur final si un réessai le change (null : marqueur N6 gardé).
            $f->apresReessai = null;
            $f->bascule = false;
            if ($f->statut_avant === SiteMedia::INJOIGNABLE && $f->reessais < CandidatsSite::REESSAIS_MAX) {
                if (! CandidatsSite::reessaiDu($f->reessais, $f->reessai_le_avant ?? $f->le_avant, $aujourdhui)) {
                    $this->compter($delta, 'reessais_pas_dus');

                    continue;
                }
                if ($f->cible === null) {
                    // Adresse illisible : rien à réessayer, on passe aux candidats.
                    $f->reessais = CandidatsSite::REESSAIS_MAX;
                    $f->bascule = true;
                    $f->compteursReessai = ['bascules'];
                    $aChercher[] = $f;

                    continue;
                }
                $aReessayer[] = $f;

                continue;
            }
            $aChercher[] = $f;
        }

        // 1. Réessais (réseau HORS transaction).
        foreach ($this->reessayer($aReessayer, $lecteur, $finder) as $f) {
            $aChercher[] = $f;
        }

        // 2. Candidats.
        $this->chercher($aChercher, $lecteur, $finder);

        // 3. Écrire, dans UNE transaction par paquet, avec le curseur. À blanc :
        // AUCUNE écriture, ni transaction — un `SELECT count(*)` au même WHERE.
        if (! $dryRun) {
            DB::beginTransaction();
        }
        try {
            if (! $dryRun) {
                DB::statement("SET LOCAL app.conserver_updated_at = 'on'");
            }
            foreach ($fiches as $f) {
                if ($f->ecriture === null) {
                    // Rien à écrire (fiche protégée sans réessai) : le constat compte.
                    foreach ($f->compteurs as $c) {
                        $this->compter($delta, $c);
                    }

                    continue;
                }
                if ($f->ecriture === false) {
                    $this->compter($delta, 'erreurs');

                    continue;
                }
                try {
                    $n = $dryRun ? $this->compterEligibles($f) : DB::transaction(fn (): int => $this->ecrire($f));
                } catch (Throwable $e) {
                    Log::warning('crm:entreprises:chercher-site-prouve écriture en erreur', ['fiche' => (int) $f->id, 'exception' => $e::class]);
                    $this->compter($delta, 'erreurs');

                    continue;
                }
                if ($n === 0) {
                    $this->compter($delta, 'ecartes');

                    continue;
                }
                foreach ($f->compteurs as $c) {
                    $this->compter($delta, $c);
                }
            }
            if (! $dryRun) {
                CurseurTraitement::ecrire($this->workspaceId, $cle, (int) $fiches[count($fiches) - 1]->id);
                DB::commit();
            }
        } catch (Throwable $e) {
            if (! $dryRun) {
                DB::rollBack();
            }

            throw $e;
        }
        foreach ($delta as $k => $n) {
            $this->bilan[$k] = ($this->bilan[$k] ?? 0) + $n;
        }
        $this->bilan['paquets']++;
    }

    /**
     * Réessaie les injoignables dus : variante après variante, jusqu'à la
     * première lue. Rend les fiches qui passent aux candidats (lues sans
     * SIREN, ou 3e réessai sans réponse) ; les autres reçoivent leur écriture.
     *
     * @param  list<stdClass>  $fiches
     * @return list<stdClass>
     */
    private function reessayer(array $fiches, LecturePageAccueil $lecteur, DomainFinderService $finder): array
    {
        $suite = [];
        $enCours = [];
        foreach ($fiches as $f) {
            $f->variantes = CandidatsSite::variantesReessai((string) $f->cible);
            $f->lue = null;
            $f->robots = false;
            $enCours[] = $f;
        }
        for ($rang = 0; $rang < 3 && $enCours !== []; $rang++) {
            $aLire = [];
            foreach ($enCours as $f) {
                if (isset($f->variantes[$rang])) {
                    $aLire[] = $f->variantes[$rang];
                }
            }
            $this->lire($aLire, $lecteur, $finder);
            $restants = [];
            foreach ($enCours as $f) {
                $v = $f->variantes[$rang] ?? null;
                $lu = $v === null ? null : ($this->cache[$v] ?? null);
                if ($lu !== null && $lu['statut'] === LecturePageAccueil::STATUT_LU) {
                    $f->lue = $v;
                } elseif ($lu !== null && $lu['statut'] === LecturePageAccueil::STATUT_ROBOTS) {
                    $f->robots = true;
                } elseif ($v !== null) {
                    $restants[] = $f;
                }
            }
            $enCours = $restants;
        }

        $lues = [];
        foreach ($fiches as $f) {
            $f->reessais++;
            $reessai = ['reessais' => $f->reessais, 'reessai_le' => $this->aujourdhui];
            if ($f->lue !== null) {
                $lues[] = $f;

                continue;
            }
            if ($f->robots) {
                $f->ecriture = ['remplacer', ['statut' => SiteMedia::ROBOTS_INTERDIT, 'url' => $f->cible] + $reessai];
                $f->compteurs = ['reessais', 'reessais_robots'];

                continue;
            }
            // Toujours injoignable.
            if ($f->reessais >= CandidatsSite::REESSAIS_MAX) {
                $f->bascule = true;
                $f->compteursReessai = ['reessais', 'reessais_echoues', 'bascules'];
                $suite[] = $f;

                continue;
            }
            $f->ecriture = ['fusionner', $reessai];
            $f->compteurs = ['reessais', 'reessais_echoues'];
        }

        // Pages lues : la preuve, comme N6 (accueil, puis mentions légales).
        $preuves = $this->prouver(array_map(static fn (stdClass $f): array => [$f, (string) $f->lue], $lues), $lecteur, $finder);
        foreach ($lues as $i => $f) {
            $reessai = ['reessais' => $f->reessais, 'reessai_le' => $this->aujourdhui];
            [$preuve, $motif] = $preuves[$i];
            if ($preuve === false) {
                $f->ecriture = false;

                continue;
            }
            if ($preuve !== null) {
                $f->ecriture = ['remplacer', ['statut' => SiteMedia::VERIFIE, 'url' => $f->lue, 'preuve' => $preuve] + $reessai];
                $f->compteurs = ['reessais', 'reessais_reussis'];

                continue;
            }
            // Lue sans SIREN : non conforme, et l'on cherche le vrai site.
            $f->apresReessai = ['statut' => SiteMedia::NON_CONFORME, 'url' => $f->cible] + ($motif !== null ? ['motif' => $motif] : []) + $reessai;
            $f->compteursReessai = ['reessais', 'reessais_sans_siren'];
            $suite[] = $f;
        }

        return $suite;
    }

    /**
     * Cherche le vrai site des fiches données : candidat après candidat
     * (un tour par rang, `LecturePageAccueil` garde la politesse par
     * domaine), jusqu'au premier prouvé.
     *
     * @param  list<stdClass>  $fiches
     */
    private function chercher(array $fiches, LecturePageAccueil $lecteur, DomainFinderService $finder): void
    {
        $enCours = [];
        foreach ($fiches as $f) {
            $f->compteursReessai ??= [];
            $f->candidats = [];
            $f->essayes = 0;
            $f->trouve = null;
            if ((bool) $f->site_de_source || (bool) $f->protegee) {
                // Jamais remplacé. Le résultat d'un réessai reste écrit.
                $this->sansCandidat($f, false);
                $f->compteurs = [...$f->compteurs, 'proteges'];

                continue;
            }
            $f->candidats = array_map(
                static fn (string $hote): string => 'https://' . $hote . '/',
                CandidatsSite::domaines($f->denomination, $f->enseigne, $f->sigle, (string) $f->website),
            );
            if ($f->candidats === []) {
                $this->sansCandidat($f, true);
                $f->compteurs = [...$f->compteurs, 'recherches', 'sans_site', 'sans_candidat'];

                continue;
            }
            $enCours[] = $f;
        }

        for ($rang = 0; $rang < CandidatsSite::MAX && $enCours !== []; $rang++) {
            $tour = [];
            foreach ($enCours as $f) {
                if (isset($f->candidats[$rang])) {
                    $tour[] = $f;
                    $f->essayes++;
                }
            }
            $this->lire(array_map(static fn (stdClass $f): string => $f->candidats[$rang], $tour), $lecteur, $finder);
            $aProuver = [];
            foreach ($tour as $f) {
                $lu = $this->cache[$f->candidats[$rang]] ?? null;
                if ($lu !== null && $lu['statut'] === LecturePageAccueil::STATUT_LU) {
                    $aProuver[] = $f;
                }
            }
            $preuves = $this->prouver(array_map(static fn (stdClass $f): array => [$f, $f->candidats[$rang]], $aProuver), $lecteur, $finder);
            foreach ($aProuver as $i => $f) {
                if (is_string($preuves[$i][0])) {
                    $f->trouve = [$f->candidats[$rang], $preuves[$i][0]];
                }
            }
            $enCours = array_values(array_filter(
                $enCours,
                static fn (stdClass $f): bool => $f->trouve === null && isset($f->candidats[$rang + 1]),
            ));
        }

        foreach ($fiches as $f) {
            if ($f->candidats === []) {
                continue;
            }
            if ($f->trouve === null) {
                $this->sansCandidat($f, true);
                $f->compteurs = [...$f->compteurs, 'recherches', 'sans_site'];

                continue;
            }
            [$url, $preuve] = $f->trouve;
            // L'ancien site deviné, GARDÉ : adresse, jugement (celui du réessai
            // s'il y en a eu un), nombre de réessais.
            $jugement = $f->apresReessai ?? ['statut' => $f->statut_avant] + ($f->motif_avant !== null ? ['motif' => $f->motif_avant] : []);
            $ancien = ['url' => (string) $f->website] + array_intersect_key($jugement, array_flip(['statut', 'motif']))
                + ($f->reessais > 0 ? ['reessais' => $f->reessais] : []);
            $f->ecriture = ['candidat', [
                'statut' => SiteMedia::TROUVE_VERIFIE,
                'url' => $url,
                'preuve' => $preuve,
                'origine' => CandidatsSite::ORIGINE,
                'ancien' => $ancien,
            ], $url];
            $f->compteurs = [...$f->compteursReessai, 'recherches', 'prouves', $preuve === VerificationSite::PREUVE_MENTIONS ? 'prouves_mentions' : 'prouves_accueil'];
        }
    }

    /**
     * Aucun candidat prouvé (ou fiche protégée, `$cherche` faux) : le
     * marqueur garde ce qu'il disait — complété du résultat d'un réessai, et
     * de `candidats` quand une recherche a eu lieu. Rien n'est inventé.
     */
    private function sansCandidat(stdClass $f, bool $cherche): void
    {
        $f->compteurs = $f->compteursReessai;
        $candidats = $cherche ? ['candidats' => ['essayes' => (int) $f->essayes, 'le' => $this->aujourdhui, 'v' => CandidatsSite::VERSION]] : [];
        if ($f->apresReessai !== null) {
            $f->ecriture = ['remplacer', $f->apresReessai + $candidats];
        } elseif ($f->compteursReessai !== [] || $candidats !== []) {
            $reessai = $f->compteursReessai !== [] ? ['reessais' => $f->reessais, 'reessai_le' => $this->aujourdhui] : [];
            $f->ecriture = ['fusionner', $reessai + $candidats];
        }
    }

    /**
     * La preuve de N6 pour chaque page déjà lue : SIREN sur l'accueil
     * (arrivée sur le même domaine), sinon sur les mentions légales (lien du
     * même site, à défaut `/mentions-legales`). Rend, pour chaque entrée,
     * [preuve|null|false (erreur), motif de refus|null].
     *
     * @param  list<array{0: stdClass, 1: string}>  $entrees  [fiche, adresse lue]
     * @return list<array{0: string|false|null, 1: ?string}>
     */
    private function prouver(array $entrees, LecturePageAccueil $lecteur, DomainFinderService $finder): array
    {
        $sorties = [];
        $mentions = [];
        foreach ($entrees as $i => [$f, $cible]) {
            $lu = $this->cache[$cible] ?? null;
            if ($lu === null || $lu['statut'] === self::STATUT_ERREUR) {
                $sorties[$i] = [false, null];

                continue;
            }
            $motif = VerificationSite::motifArrivee($cible, $lu['finale']);
            if ($motif !== null) {
                $sorties[$i] = [null, $motif];

                continue;
            }
            if (in_array($f->siren, $lu['sirens'], true)) {
                $sorties[$i] = [VerificationSite::PREUVE_ACCUEIL, null];

                continue;
            }
            $base = VerificationSite::memeSite($lu['finale'], $cible) ? $lu['finale'] : $cible;
            $m = $lu['mentions'] ?? VerificationSite::mentionsParDefaut($base);
            if ($m === null) {
                $sorties[$i] = [null, null];

                continue;
            }
            $mentions[$i] = $m;
        }
        $this->lire(array_values($mentions), $lecteur, $finder);
        foreach ($mentions as $i => $m) {
            [$f, $cible] = $entrees[$i];
            $lu = $this->cache[$m] ?? null;
            $motif = null;
            $prouve = false;
            if ($lu !== null && $lu['statut'] === LecturePageAccueil::STATUT_LU) {
                // Mentions redirigées vers un autre domaine : jamais une preuve.
                $motif = VerificationSite::motifArrivee($cible, $lu['finale']);
                $prouve = $motif === null && in_array($f->siren, $lu['sirens'], true);
            }
            $sorties[$i] = [$prouve ? VerificationSite::PREUVE_MENTIONS : null, $motif];
        }
        ksort($sorties);

        return array_values($sorties);
    }

    /**
     * Écrit — et RIEN d'autre : la clé `site_entreprise` de `metadata`, plus
     * `website` pour un candidat prouvé. Seulement si la fiche est toujours
     * vivante, au même site, toujours non vérifiée, au même marqueur ; pour
     * un candidat, en plus : site sans provenance connue, fiche non protégée.
     */
    private function ecrire(stdClass $f): int
    {
        [$mode, $valeur] = $f->ecriture;
        $valeur += ['le' => $this->aujourdhui, 'v' => VerificationSite::VERSION];
        $json = json_encode($valeur, JSON_THROW_ON_ERROR);
        $marqueur = $mode === 'fusionner'
            ? "COALESCE(metadata -> '" . SiteFiable::CLE . "', '{}'::jsonb) || ?::jsonb"
            : '?::jsonb';
        $site = $mode === 'candidat' ? 'website = ?, ' : '';
        $liaisons = $mode === 'candidat' ? [$f->ecriture[2]] : [];

        return DB::update(
            "UPDATE companies
                SET {$site}metadata = COALESCE(metadata, '{}'::jsonb) || jsonb_build_object(?::text, {$marqueur})
              WHERE " . self::gardeSql($mode === 'candidat'),
            [...$liaisons, SiteFiable::CLE, $json, ...$this->gardeLiaisons($f)],
        );
    }

    /** À blanc : la fiche serait-elle écrite ? Même WHERE que `ecrire`, rien d'écrit. */
    private function compterEligibles(stdClass $f): int
    {
        return (int) DB::selectOne(
            'SELECT count(*) AS n FROM companies WHERE ' . self::gardeSql($f->ecriture[0] === 'candidat'),
            $this->gardeLiaisons($f),
        )->n;
    }

    private static function gardeSql(bool $candidat): string
    {
        return 'id = ? AND workspace_id = ? AND deleted_at IS NULL AND website = ? AND ' . SiteFiable::nonVerifieSql('companies')
            . " AND COALESCE(metadata -> '" . SiteFiable::CLE . "' ->> 'statut', '') = ?"
            . " AND (metadata -> '" . SiteFiable::CLE . "' -> 'candidats') IS NULL"
            . ($candidat ? " AND (field_origins -> 'website') IS NULL AND " . FichesProtegees::conditionSql('companies.id') : '');
    }

    /** @return list<int|string> */
    private function gardeLiaisons(stdClass $f): array
    {
        return [(int) $f->id, $this->workspaceId, (string) $f->website, (string) $f->statut_avant];
    }

    /**
     * Lit les adresses pas encore en mémoire et n'en garde que le statut, les
     * SIREN prouvés et le lien de mentions légales (même mécanique que
     * `crm:entreprises:verifier-sites`).
     *
     * @param  list<string>  $cibles
     */
    private function lire(array $cibles, LecturePageAccueil $lecteur, DomainFinderService $finder): void
    {
        $cibles = array_values(array_unique($cibles));
        if ($cibles === []) {
            return;
        }
        if (count($this->cache) + count($cibles) > max(1, (int) config('crm.verifier_sites.cache_max', self::CACHE_MAX))) {
            $this->cache = [];
        }
        $nouvelles = array_values(array_filter($cibles, fn (string $c): bool => ! isset($this->cache[$c])));
        if ($nouvelles === []) {
            return;
        }
        try {
            $lus = $lecteur->lire($nouvelles, true);
        } catch (Throwable $e) {
            Log::warning('crm:entreprises:chercher-site-prouve lecture groupée en erreur, relecture une à une', ['exception' => $e::class]);
            $lus = [];
            foreach ($nouvelles as $cible) {
                try {
                    $lus += $lecteur->lire([$cible], true);
                } catch (Throwable $e) {
                    Log::warning('crm:entreprises:chercher-site-prouve adresse en erreur', ['exception' => $e::class]);
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
