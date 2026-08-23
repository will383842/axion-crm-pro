<?php

/**
 * GARDE — B11-002 / B17-010 (S0) : « des jobs de file s'executent SANS contexte
 * d'espace, alors que `Queue::looping` l'efface entre deux jobs ».
 *
 * ── LE MECANISME, RELU DANS LE CODE AVANT D'ECRIRE UNE LIGNE ────────────────
 *
 * `AppServiceProvider::boot()` enregistre `Queue::looping(fn () =>
 * WorkspaceContext::clear())`. C'est VOULU : un worker Horizon garde sa
 * connexion Postgres ouverte entre deux jobs, et sans ce nettoyage le contexte
 * d'un job fuirait sur le suivant. La contrepartie est que chaque job doit
 * RE-POSER le sien. Mesure du 2026-08-21 :
 *
 *   $ grep -l RunsInWorkspace app/Jobs/*.php
 *     app/Jobs/EnrichCompanyJob.php          <- un seul des six
 *   $ grep -rn "EnrichCompanyJob::dispatch" app/
 *     4 sites, tous a UN argument             <- son `$workspaceId` valait null
 *
 * Ce n'etait donc pas 5 jobs sur 6 sans contexte : c'etait 6 sur 6. Le constat
 * SOUS-COMPTE, et cette garde le dit en le mesurant.
 *
 * ── CE QUE CA COUTE, ET POURQUOI LE CLASSEMENT « CONCEPTION » TIENT ─────────
 *
 * Le contexte n'a AUJOURD'HUI aucun consommateur actif : la RLS Postgres est
 * contournee tant que `CRM_DB_APP_ROLE_ENABLED` est a false (le role `axion`
 * est SUPERUSER + BYPASSRLS), et la ceinture applicative dort tant que
 * `CRM_STRICT_WORKSPACE_SCOPE` est a false. Rien ne fuit ce matin. Le defaut
 * est celui d'une CONCEPTION : le jour ou l'un des deux drapeaux est arme, les
 * six jobs deviennent faux d'un coup. La policy posee par
 * `2026_08_14_000001_harden_workspace_isolation::strictPredicate()` est
 *
 *     workspace_id::TEXT = NULLIF(current_setting('app.current_workspace_id', true), '')
 *
 * — sans contexte elle rend NULL, donc FAUX : le job ne LIT rien et chacune de
 * ses ECRITURES est refusee. Ce n'est pas « il voit tout », c'est « il ne voit
 * rien », et cinq de ces six jobs font suivre leur `find()` a vide d'un
 * `return` silencieux.
 *
 * ── COMMENT CETTE GARDE MESURE, ET CE QU'ELLE REFUSE DE MESURER ─────────────
 *
 * Elle ne verifie PAS qu'une methode est appelee. Elle fait tourner chaque job
 * SUR LE ROLE APPLICATIF `axion_app` — celui qui est reellement soumis a la RLS
 * — apres avoir declenche le VRAI evenement `Looping` de la file, et elle
 * regarde CE QUE LE JOB ECRIT ET CE QU'IL LIT :
 *
 *   - la campagne a-t-elle vu ses compteurs bouger ?
 *   - le `scraper_run` et les `companies` existent-ils, et dans QUEL espace ?
 *   - le `audience_members` a-t-il ete peuple ?
 *   - l'orchestrateur a-t-il recu une entreprise, et laquelle ?
 *
 * Chaque scenario porte son TEMOIN NEGATIF : le meme job, sans espace sur sa
 * charge, ne doit rien produire. Et deux enumerations, faites par le CATALOGUE
 * (le contenu de `app/Jobs/`) et jamais par une liste ecrite a la main, tiennent
 * la porte pour les jobs et les points de dispatch a venir.
 *
 * ⚠️ Ce fichier n'arme AUCUN drapeau global : il bascule la connexion PAR
 * DEFAUT sur `pgsql_app` le temps d'un job, exactement comme
 * `RafraichissementMatriceCouvertureTest` le fait deja. `CRM_STRICT_WORKSPACE_SCOPE`
 * n'est pas touche — le constat voisin B11-001 dit pourquoi (26 taches
 * planifiees tournent sans contexte).
 */

use App\Contracts\InseeClient;
use App\Data\Sources\InseeCompanyData;
use App\Jobs\Concerns\RunsInWorkspace;
use App\Jobs\DispatchScrapeJob;
use App\Jobs\EnrichCompanyJob;
use App\Jobs\LaunchCampaignJob;
use App\Jobs\LaunchZoneScrapingJob;
use App\Jobs\MonitorCampaignProgressJob;
use App\Jobs\RefreshAudienceChunkJob;
use App\Models\Company;
use App\Models\ScraperRun;
use App\Models\ScrapingCampaign;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Audiences\AudienceBuilderService;
use App\Services\FranceTravail\FranceTravailDiscoveryClient;
use App\Services\Waterfall\WaterfallOrchestrator;
use App\Support\WorkspaceContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\QueryException;
use Illuminate\Queue\Events\Looping;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class);

// ===========================================================================
// Outillage — prefixe `cej` : les fichiers de test de Pest partagent UN SEUL
// espace de noms, et une fonction globale en double tue toute la campagne
// (garde `AucuneFonctionGlobaleEnDoubleTest` du depot).
// ===========================================================================

/**
 * Le jeu d'essai est ecrit sur la connexion par defaut EN AUTO-COMMIT (ce
 * fichier n'utilise pas RefreshDatabase) : c'est la seule facon qu'il soit
 * visible depuis `pgsql_app`, qui est une AUTRE session Postgres.
 * `cejNettoyer()` est donc obligatoire, pas une politesse.
 *
 * @return array{ws_a: string, ws_b: string, user: string, campagne: int, campagne_b: int, entreprise: int, entreprise_b: int, audience: int}
 */
function cejSemer(): array
{
    $wsA = Workspace::create(['id' => (string) Str::uuid(), 'slug' => 'cej-a-' . Str::random(8), 'name' => 'CEJ A']);
    $wsB = Workspace::create(['id' => (string) Str::uuid(), 'slug' => 'cej-b-' . Str::random(8), 'name' => 'CEJ B']);

    $user = User::create([
        'id' => (string) Str::uuid(),
        'email' => 'cej' . Str::random(6) . '@test.local',
        'name' => 'CEJ',
        'password_hash' => Hash::make('SomePass!1234'),
        'current_workspace_id' => $wsA->id,
        'first_login_completed_at' => now(),
    ]);

    $campagne = cejCampagne($user->id, $wsA->id);
    $campagneB = cejCampagne($user->id, $wsB->id);

    $entreprise = Company::create([
        'workspace_id' => $wsA->id,
        'siren' => cejSiren(),
        'denomination' => 'CEJ ENTREPRISE A',
        'department_code' => '75',
    ]);
    $entrepriseB = Company::create([
        'workspace_id' => $wsB->id,
        'siren' => cejSiren(),
        'denomination' => 'CEJ ENTREPRISE B',
        'department_code' => '75',
    ]);

    $audience = DB::table('email_audiences')->insertGetId([
        'workspace_id' => $wsA->id,
        'name' => 'CEJ audience',
        'criteria' => '{}',
        'is_active' => true,
        'auto_refresh' => true,
        'member_count' => 0,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return [
        'ws_a' => (string) $wsA->id,
        'ws_b' => (string) $wsB->id,
        'user' => (string) $user->id,
        'campagne' => (int) $campagne->id,
        'campagne_b' => (int) $campagneB->id,
        'entreprise' => (int) $entreprise->id,
        'entreprise_b' => (int) $entrepriseB->id,
        'audience' => (int) $audience,
    ];
}

function cejCampagne(string $userId, string $workspaceId): ScrapingCampaign
{
    return ScrapingCampaign::create([
        'workspace_id' => $workspaceId,
        'created_by' => $userId,
        'name' => 'CEJ campagne',
        'status' => 'running',
        'sources' => ['insee'],
        'zones' => [['type' => 'department', 'code' => '75']],
        'max_companies' => 1000,
        'companies_created' => 0,
        'max_duration_minutes' => 180,
        'max_requests_per_minute' => 60,
        'runs_total' => 4,
        'runs_completed' => 0,
        'started_at' => now(),
    ]);
}

function cejSiren(): string
{
    return str_pad((string) random_int(0, 999999999), 9, '0', STR_PAD_LEFT);
}

/**
 * Nettoyage en ordre de cles etrangeres, par le role PROPRIETAIRE (les lignes
 * ont pu etre ecrites par le role applicatif, et la RLS ne doit pas nous
 * empecher de les reprendre).
 */
function cejNettoyer(array $g): void
{
    $o = DB::connection('pgsql_owner');
    $espaces = [$g['ws_a'], $g['ws_b']];

    foreach (['audience_members', 'email_audiences', 'scraper_runs', 'contacts', 'companies', 'scraping_campaigns'] as $table) {
        $o->table($table)->whereIn('workspace_id', $espaces)->delete();
    }
    $o->table('users')->where('id', $g['user'])->delete();
    $o->table('workspaces')->whereIn('id', $espaces)->delete();
}

/**
 * Joue $fn avec la connexion PAR DEFAUT bascule sur le role applicatif
 * non-proprietaire, c'est-a-dire dans les conditions exactes de la production
 * une fois `CRM_DB_APP_ROLE_ENABLED` arme. Meme geste que
 * `tests/Feature/Database/RafraichissementMatriceCouvertureTest.php` — on
 * etend, on ne reinvente pas.
 *
 * @template T
 *
 * @param  callable(): T  $fn
 * @return T
 */
function cejSousRoleApplicatif(callable $fn)
{
    config(['database.default' => 'pgsql_app']);
    DB::purge('pgsql_app');

    try {
        return $fn();
    } finally {
        config(['database.default' => 'pgsql']);
        DB::purge('pgsql_app');
    }
}

/**
 * Declenche le VRAI crochet de la file, pas une imitation : `Queue::looping()`
 * enregistre un ecouteur de `Illuminate\Queue\Events\Looping`. Si quelqu'un
 * retire un jour l'enregistrement de `AppServiceProvider`, le premier test de
 * ce fichier rougit.
 */
function cejBoucleDuWorker(): void
{
    event(new Looping('sync', 'default'));
}

/**
 * Efface les drapeaux d'annulation `cancelled:scraper-run:<id>` restes dans
 * Redis.
 *
 * Ce n'est pas une precaution decorative. `LaunchZoneScrapingJob` lit ce
 * drapeau au tour 0 de sa boucle, avec l'IDENTIFIANT du run qu'il vient de
 * creer ; le drapeau est pose avec un TTL de 3600 s et n'est efface par
 * personne. Or `RefreshDatabase` remet la sequence `scraper_runs_id_seq` a
 * zero entre deux fichiers de test : un drapeau laisse par
 * `ArretCollecteEtQuotaTest` pour le run n.3 fait passer MON run n.3 en
 * « cancelled », de facon intermittente. Mesure du 2026-08-21 : ce fichier a
 * echoue une fois sur trois executions completes, sur `status = cancelled`,
 * apres avoir joue les suites d'arret de collecte dans la meme base.
 *
 * (Le meme mecanisme existe en production, ou les identifiants ne sont pas
 * reutilises — sauf restauration de base. C'est note dans le rendu du lot,
 * hors perimetre B11-002.)
 */
function cejPurgerDrapeauxAnnulation(): void
{
    try {
        $redis = Redis::connection(DispatchScrapeJob::CONNEXION_REDIS);
        foreach ((array) $redis->keys(LaunchZoneScrapingJob::CLE_ANNULATION . '*') as $cle) {
            $redis->del($cle);
        }
    } catch (Throwable) {
        // Redis indisponible : le scenario concerne le dira lui-meme.
    }
}

/** Valeur de la variable de session Postgres sur la connexion courante. */
function cejReglagePg(): string
{
    return (string) DB::scalar("SELECT COALESCE(current_setting('app.current_workspace_id', true), '')");
}

/**
 * Catalogue des jobs de file : le CONTENU de `app/Jobs/`, jamais une liste
 * ecrite a la main.
 *
 * @return array<class-string, string>
 */
function cejCatalogueDesJobs(): array
{
    $catalogue = [];
    foreach ((array) glob(app_path('Jobs') . '/*.php') as $fichier) {
        $classe = 'App\\Jobs\\' . basename((string) $fichier, '.php');
        if (! class_exists($classe)) {
            continue;
        }
        $reflet = new ReflectionClass($classe);
        if (! $reflet->implementsInterface(ShouldQueue::class) || $reflet->isAbstract()) {
            continue;
        }
        $catalogue[$classe] = (string) $fichier;
    }

    return $catalogue;
}

/**
 * Jobs dispenses de poser un contexte, avec la MESURE qui le justifie. Toute
 * entree pointant sur une classe absente du catalogue fait rougir la garde :
 * une dispense perimee est une dispense qui ment.
 *
 * @return array<class-string, string>
 */
function cejDispenses(): array
{
    return [
        DispatchScrapeJob::class => 'ne touche AUCUNE table. Mesure du 2026-08-21 : `grep -n "DB::\|::query\|Model" '
            . 'app/Jobs/DispatchScrapeJob.php app/Services/Http/SsrfGuard.php` -> 0 resultat. '
            . 'Il verifie une URL en memoire puis pousse un JSON sur une liste Redis NUE (`axion:scrape:<source>`). '
            . "L'espace n'apparait pas non plus dans la charge lue cote Node : l'y ajouter changerait le contrat "
            . "du pont PHP->Node, ce qui n'est pas le geste de ce lot.",
    ];
}

/**
 * Fichiers de `app/` susceptibles de mettre un job en file.
 *
 * ⚠️ `scandir` RECURSIF, ET NON `RecursiveDirectoryIterator`. Mesure du
 * 2026-08-21, dans le conteneur `a35r` :
 *
 *   app/Console/Commands, scandir() / glob() / find ....... 56 fichiers
 *   app/Console/Commands, RecursiveDirectoryIterator ...... 14
 *   app/ entier .......................................... 293 contre 251
 *
 * Le montage de Docker Desktop pour Windows ne rend pas tout le repertoire a cet
 * iterateur-la — stable sur trois passages, ce n'est pas un hasard. Or TROIS des
 * treize points de mise en file de ce constat vivent precisement dans
 * `app/Console/Commands` (`RescrapeArchives`, `RetryGooglePlaces`,
 * `StartScheduledCampaigns`). Les enumerations de ce fichier auraient pu les
 * manquer en silence et se declarer completes.
 *
 * (Verification faite : sur ce banc-ci, le tirage de 251 fichiers les contenait
 * toutes les trois — l'enumeration disait donc vrai. Mais elle le disait par
 * CHANCE, et une garde qui a raison par chance n'est pas une garde.)
 *
 * @return list<string> chemins absolus, ordre stable
 */
function cejFichiersApplicatifs(): array
{
    $fichiers = cejBalayerPhp(app_path());
    sort($fichiers);

    return $fichiers;
}

/**
 * @return list<string>
 */
function cejBalayerPhp(string $dossier): array
{
    $trouves = [];

    foreach (scandir($dossier) ?: [] as $entree) {
        if ($entree === '.' || $entree === '..') {
            continue;
        }

        $chemin = $dossier . DIRECTORY_SEPARATOR . $entree;

        if (is_dir($chemin)) {
            $trouves = array_merge($trouves, cejBalayerPhp($chemin));
        } elseif (str_ends_with($entree, '.php')) {
            $trouves[] = $chemin;
        }
    }

    return $trouves;
}

/**
 * Decoupe la « phrase » PHP qui commence a $debut : du motif jusqu'au `;`
 * terminal. C'est sur elle qu'on cherche `->pourEspace(`, et pas sur le fichier
 * entier — une garde qui balaie le fichier entier trouve son motif dans un
 * COMMENTAIRE et se croit verte.
 */
/**
 * La source AMPUTEE DE SES COMMENTAIRES, numeros de ligne preserves.
 *
 * 🔴 Pourquoi. Le 2026-08-23, cette garde a designe comme fautif
 * `MonitorCampaignProgressJob.php:60` — qui est une ligne de COMMENTAIRE
 * expliquant que le re-dispatch « construit un `new self(...)` neuf ». Le
 * balayage lisait la source brute : il comptait une phrase de francais comme
 * un point de mise en file, et l'aurait comptee dans `$sitesVus` aussi, ce qui
 * gonflait son propre temoin de couverture.
 *
 * Un commentaire ne met rien en file. Le meme geste est deja employe par la
 * garde B10-016 (EffacementDouxPorteeAgent35Test) pour la meme raison.
 */
function cejSansCommentaires(string $source): string
{
    $plat = '';

    foreach (token_get_all($source) as $jeton) {
        if (is_array($jeton) && ($jeton[0] === T_COMMENT || $jeton[0] === T_DOC_COMMENT)) {
            // On remplace le commentaire par ses seuls sauts de ligne : les
            // numeros de ligne restent ceux du fichier reel.
            $plat .= str_repeat('
', substr_count($jeton[1], '
'));

            continue;
        }

        $plat .= is_array($jeton) ? $jeton[1] : $jeton;
    }

    return $plat;
}

/**
 * Le job est-il construit dans une VARIABLE a qui l'espace est donne plus loin ?
 *
 * 🔴 Pourquoi. La garde ne regardait que la phrase du `new` — une seule
 * instruction, jusqu'au premier `;`. Or le chemin d'echec de
 * `MonitorCampaignProgressJob` (constat B17-011) s'ecrit en quatre temps :
 *
 *     $suivant = new self($this->campaignId);      // <- la phrase examinee
 *     $suivant->echecsConsecutifs = $relance;
 *     $espace = $this->espaceDuJob() ?? ...;       // l'espace se CHERCHE
 *     dispatch($suivant->pourEspace($espace));     // <- et il est POSE ici
 *
 * L'espace est bel et bien nomme ; il l'est trois instructions plus loin,
 * parce qu'il faut d'abord aller le chercher. Refuser cette forme obligerait a
 * ecrire le code d'une facon qui plait a la garde plutot qu'a l'exploitant —
 * exactement l'inverse de ce qu'une garde doit produire.
 *
 * On reste STRICT sur l'essentiel : c'est bien `->pourEspace(` sur CETTE
 * variable-la qui est exige, et rien d'autre. Un `new self()` range dans une
 * variable qu'on ne dote jamais d'un espace reste fautif.
 */
function cejEspacePoseSurLaVariable(string $source, int $posApresMotif): bool
{
    // Le debut de l'instruction : on remonte au `;`, `{` ou `}` precedent.
    $debut = 0;
    foreach ([';', '{', '}'] as $borne) {
        $trouve = strrpos(substr($source, 0, $posApresMotif), $borne);
        if ($trouve !== false && $trouve + 1 > $debut) {
            $debut = $trouve + 1;
        }
    }

    $avant = substr($source, $debut, $posApresMotif - $debut);

    if (preg_match('/(\$[A-Za-z_][A-Za-z0-9_]*)\s*=\s*$/', preg_replace('/(new\s+\w+|\w+::dispatch)\s*\($/', '', trim($avant)) ?? '', $m) !== 1) {
        return false;
    }

    // La variable doit recevoir son espace APRES sa construction, dans ce meme
    // fichier. On borne a 2 000 caracteres : au-dela on n'est plus dans la
    // meme methode, et un homonyme d'une autre methode ne prouverait rien.
    $apres = substr($source, $posApresMotif, 2000);

    return str_contains($apres, $m[1] . '->pourEspace(');
}

function cejPhrase(string $source, int $debut): string
{
    $fin = strpos($source, ';', $debut);

    return $fin === false ? substr($source, $debut) : substr($source, $debut, $fin - $debut + 1);
}

// ===========================================================================
// TEMOINS — l'instrument mord-il ?
// ===========================================================================

test('TEMOIN — la boucle du worker efface REELLEMENT le contexte, cote PHP et cote Postgres', function () {
    $espace = (string) Str::uuid();
    WorkspaceContext::set($espace);

    expect(WorkspaceContext::current())->toBe($espace);
    expect(cejReglagePg())->toBe($espace);

    cejBoucleDuWorker();

    // C'est la premisse du constat : sans ce nettoyage, il n'y aurait pas de
    // defaut a reparer. S'il disparait, cette garde ne prouve plus rien.
    expect(WorkspaceContext::current())->toBeNull();
    expect(cejReglagePg())->toBe('');
});

test('TEMOIN — sans contexte, le role applicatif ne lit rien et ne peut rien ecrire', function () {
    $g = cejSemer();

    try {
        cejSousRoleApplicatif(function () use ($g) {
            cejBoucleDuWorker();

            $role = (string) DB::scalar('SELECT current_user');
            expect($role)->toBe((string) config('database.connections.pgsql_app.username'));

            // Premisse : si ce role etait superutilisateur, tout ce fichier
            // serait un vert de complaisance.
            $droits = DB::selectOne('SELECT rolsuper, rolbypassrls FROM pg_roles WHERE rolname = ?', [$role]);
            expect((bool) $droits->rolsuper)->toBeFalse();
            expect((bool) $droits->rolbypassrls)->toBeFalse();

            // LECTURE : la campagne existe, et le role ne la voit pas.
            expect(DB::table('scraping_campaigns')->where('id', $g['campagne'])->count())->toBe(0);

            // ECRITURE : refusee par le WITH CHECK, meme avec le bon workspace_id.
            $refus = null;
            try {
                DB::table('scraper_runs')->insert([
                    'workspace_id' => $g['ws_a'],
                    'campaign_id' => $g['campagne'],
                    'source' => 'insee',
                    'status' => 'running',
                    'started_at' => now(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            } catch (QueryException $e) {
                $refus = $e->getMessage();
            }
            expect($refus)->not->toBeNull();
            $this->assertStringContainsString('row-level security', (string) $refus);
        });
    } finally {
        cejNettoyer($g);
    }
});

// ===========================================================================
// ENUMERATIONS — par le catalogue, jamais par une liste ecrite a la main
// ===========================================================================

test('ENUMERATION — tout job de file pose son contexte, ou porte une dispense mesuree', function () {
    $catalogue = cejCatalogueDesJobs();

    // TEMOIN DE COUVERTURE : un balayage qui ne voit rien doit rougir, pas
    // certifier le vide. Six jobs le 2026-08-21 ; le plancher tient meme si
    // l'un d'eux est renomme.
    expect(count($catalogue))->toBeGreaterThanOrEqual(6);

    $dispenses = cejDispenses();

    // Une dispense qui pointe sur une classe disparue est une dispense qui ment.
    foreach (array_keys($dispenses) as $dispense) {
        $this->assertArrayHasKey(
            $dispense,
            $catalogue,
            "Dispense perimee : « {$dispense} » n'est plus un job de file. Retirer l'entree de cejDispenses().",
        );
    }

    $manquants = [];
    foreach ($catalogue as $classe => $fichier) {
        if (isset($dispenses[$classe])) {
            continue;
        }

        $traits = class_uses_recursive($classe);
        $source = (string) file_get_contents($fichier);
        $poseLeContexte = in_array(RunsInWorkspace::class, $traits, true)
            && str_contains($source, '$this->inWorkspace(');

        if (! $poseLeContexte) {
            $manquants[] = $classe;
        }
    }

    expect($manquants)->toBe([], sprintf(
        "Constat B11-002 : ces jobs s'executeront sans contexte d'espace, que « Queue::looping » "
        . 'efface entre deux jobs — %s. Soit ils utilisent RunsInWorkspace et appellent '
        . 'inWorkspace(), soit ils rejoignent cejDispenses() avec la mesure qui le justifie.',
        implode(', ', $manquants),
    ));
});

test('ENUMERATION — tout point de mise en file nomme un espace', function () {
    $catalogue = cejCatalogueDesJobs();
    $dispenses = cejDispenses();

    /** @var array<string, bool> $porteurDeSonEspace vrai si le constructeur prend deja un workspaceId */
    $porteurDeSonEspace = [];
    foreach ($catalogue as $classe => $_) {
        $ctor = (new ReflectionClass($classe))->getConstructor();
        $porteurDeSonEspace[$classe] = false;
        foreach ($ctor?->getParameters() ?? [] as $p) {
            if (stripos($p->getName(), 'workspace') !== false) {
                $porteurDeSonEspace[$classe] = true;
            }
        }
    }

    $sitesVus = 0;
    $fautifs = [];

    foreach (cejFichiersApplicatifs() as $fichier) {
        $source = cejSansCommentaires((string) file_get_contents($fichier));
        $relatif = str_replace(base_path() . DIRECTORY_SEPARATOR, '', $fichier);

        foreach ($catalogue as $classe => $_) {
            $court = class_basename($classe);
            $motifs = [$court . '::dispatch(', 'new ' . $court . '('];

            // Un job qui se re-dispatche lui-meme s'ecrit `self::dispatch(`
            // ou `new self(` : le motif porte sur le nom court NE LE VOIT PAS.
            // C'est exactement le genre de jumeau qu'une garde manque.
            if (str_ends_with($fichier, DIRECTORY_SEPARATOR . $court . '.php')) {
                $motifs[] = 'self::dispatch(';
                $motifs[] = 'new self(';
            }

            foreach ($motifs as $motif) {
                $pos = 0;
                while (($pos = strpos($source, $motif, $pos)) !== false) {
                    $phrase = cejPhrase($source, $pos);
                    $pos += strlen($motif);

                    if (isset($dispenses[$classe])) {
                        continue;
                    }

                    $sitesVus++;

                    $nommeUnEspace = str_contains($phrase, '->pourEspace(')
                        || ($porteurDeSonEspace[$classe] && stripos($phrase, 'workspace') !== false)
                        || cejEspacePoseSurLaVariable($source, $pos);

                    if (! $nommeUnEspace) {
                        $ligne = substr_count(substr($source, 0, $pos), "\n") + 1;
                        $fautifs[] = "{$relatif}:{$ligne} ({$court})";
                    }
                }
            }
        }
    }

    // TEMOIN DE COUVERTURE : si le balayage ne trouve plus aucun site, il ne
    // certifie rien. 14 sites le 2026-08-21.
    expect($sitesVus)->toBeGreaterThanOrEqual(10);

    expect($fautifs)->toBe([], sprintf(
        'Constat B11-002 : ces mises en file ne nomment aucun espace, le job travaillera donc '
        . 'sans contexte — %s. Chainer `->pourEspace($...->workspace_id)`.',
        implode(' | ', $fautifs),
    ));
});

// ===========================================================================
// COMPORTEMENT — ce que chaque job ECRIT et LIT, sous le role applicatif
// ===========================================================================

test('MonitorCampaignProgressJob recompte les runs de SON espace, et rien sans espace', function () {
    Queue::fake(); // il se re-dispatche : en driver `sync` ce serait infini.
    $g = cejSemer();

    ScraperRun::create([
        'workspace_id' => $g['ws_a'],
        'campaign_id' => $g['campagne'],
        'source' => 'insee',
        'status' => 'success',
        'started_at' => now()->subMinute(),
        'finished_at' => now(),
    ]);

    try {
        // TEMOIN NEGATIF — meme job, sans espace sur la charge : il ne doit
        // RIEN ecrire (et non « ecrire zero », ce qui serait pire).
        cejSousRoleApplicatif(function () use ($g) {
            cejBoucleDuWorker();
            (new MonitorCampaignProgressJob($g['campagne']))->handle();
        });
        expect((int) DB::table('scraping_campaigns')->where('id', $g['campagne'])->value('runs_completed'))->toBe(0);

        // AVEC l'espace : le compteur bouge reellement.
        cejSousRoleApplicatif(function () use ($g) {
            cejBoucleDuWorker();
            (new MonitorCampaignProgressJob($g['campagne']))->pourEspace($g['ws_a'])->handle();
        });
        expect((int) DB::table('scraping_campaigns')->where('id', $g['campagne'])->value('runs_completed'))->toBe(1);

        // La campagne voisine n'a pas bouge d'un cheveu.
        expect((int) DB::table('scraping_campaigns')->where('id', $g['campagne_b'])->value('runs_completed'))->toBe(0);
    } finally {
        cejNettoyer($g);
    }
});

test('LaunchCampaignJob cree ses runs sous SON espace, et transmet l espace a ses enfants', function () {
    Queue::fake();
    $g = cejSemer();

    try {
        cejSousRoleApplicatif(function () use ($g) {
            cejBoucleDuWorker();
            (new LaunchCampaignJob($g['campagne']))->handle();
        });
        expect((int) DB::table('scraping_campaigns')->where('id', $g['campagne'])->value('runs_total'))->toBe(4);
        Queue::assertNotPushed(LaunchZoneScrapingJob::class);

        cejSousRoleApplicatif(function () use ($g) {
            cejBoucleDuWorker();
            (new LaunchCampaignJob($g['campagne']))->pourEspace($g['ws_a'])->handle();
        });

        // `runs_total` passe de 4 (valeur semee) a 1 : la campagne porte une
        // zone et une source de decouverte. C'est bien CE job qui a ecrit.
        expect((int) DB::table('scraping_campaigns')->where('id', $g['campagne'])->value('runs_total'))->toBe(1);

        // Et l'enfant part avec l'espace : sans cela, le correctif s'arreterait
        // au premier maillon.
        Queue::assertPushed(LaunchZoneScrapingJob::class, function (LaunchZoneScrapingJob $job) use ($g) {
            return $job->espaceCible === $g['ws_a'];
        });
        Queue::assertPushed(MonitorCampaignProgressJob::class, function (MonitorCampaignProgressJob $job) use ($g) {
            return $job->espaceCible === $g['ws_a'];
        });
    } finally {
        cejNettoyer($g);
    }
});

test('LaunchZoneScrapingJob ecrit son run et ses entreprises dans SON espace', function () {
    Queue::fake();
    $g = cejSemer();

    $insee = new class implements InseeClient
    {
        public function fetchBySiren(string $siren): ?InseeCompanyData
        {
            return null;
        }

        public function searchByCriteria(array $criteria): array
        {
            $out = [];
            for ($i = 1; $i <= 2; $i++) {
                $out[] = new InseeCompanyData(
                    siren: str_pad((string) (700000000 + $i), 9, '0', STR_PAD_LEFT),
                    denomination: 'CEJ decouverte ' . $i,
                    naf: '6201Z',
                    legalForm: 'SAS',
                    effectifRange: '11',
                );
            }

            return $out;
        }

        public function iterateByCriteria(array $criteria): Generator
        {
            yield from $this->searchByCriteria($criteria);
        }
    };

    cejPurgerDrapeauxAnnulation();

    try {
        $avant = (int) DB::table('companies')->where('workspace_id', $g['ws_a'])->count();

        cejSousRoleApplicatif(function () use ($g, $insee) {
            cejBoucleDuWorker();
            (new LaunchZoneScrapingJob(
                workspaceId: $g['ws_a'],
                department: '75',
                naf: null,
                sizeCategory: null,
                limit: 10,
                campaignId: null,
                source: 'insee',
                enrich: false,
            ))->handle($insee, Mockery::mock(FranceTravailDiscoveryClient::class));
        });

        // Ce que le job a ECRIT, relu par le proprietaire : un run, dans A.
        $runs = DB::table('scraper_runs')->where('workspace_id', $g['ws_a'])->get();
        expect($runs)->toHaveCount(1);
        // `assertSame` et pas `expect()->toBe(..., $message)` : la garde
        // `toContain` du depot rappelle que les matchers de Pest sont
        // variadiques, un message passe en second argument peut devenir une
        // seconde aiguille. Ici la question ne se pose pas.
        $this->assertSame(
            'success',
            (string) $runs->first()->status,
            'Le run ne s\'est pas termine en succes. Erreur portee par la ligne : '
            . var_export($runs->first()->error, true),
        );

        $apres = (int) DB::table('companies')->where('workspace_id', $g['ws_a'])->count();
        expect($apres - $avant)->toBe(2);

        // Rien n'a debord ni ete ecrit dans l'espace voisin.
        expect((int) DB::table('scraper_runs')->where('workspace_id', $g['ws_b'])->count())->toBe(0);
        expect((int) DB::table('companies')->where('workspace_id', $g['ws_b'])->count())->toBe(1);
    } finally {
        cejNettoyer($g);
    }
});

test('RefreshAudienceChunkJob peuple son audience sous SON espace, et rien sans espace', function () {
    $g = cejSemer();

    try {
        cejSousRoleApplicatif(function () use ($g) {
            cejBoucleDuWorker();
            (new RefreshAudienceChunkJob(audienceId: $g['audience'], offset: 0, limit: 100))
                ->handle(app(AudienceBuilderService::class));
        });
        expect((int) DB::table('audience_members')->where('audience_id', $g['audience'])->count())->toBe(0);

        cejSousRoleApplicatif(function () use ($g) {
            cejBoucleDuWorker();
            (new RefreshAudienceChunkJob(audienceId: $g['audience'], offset: 0, limit: 100))
                ->pourEspace($g['ws_a'])
                ->handle(app(AudienceBuilderService::class));
        });

        $membres = DB::table('audience_members')->where('audience_id', $g['audience'])->get();
        expect($membres)->toHaveCount(1);
        expect((string) $membres->first()->workspace_id)->toBe($g['ws_a']);
        expect((int) $membres->first()->company_id)->toBe($g['entreprise']);
    } finally {
        cejNettoyer($g);
    }
});

test('EnrichCompanyJob LIT bien son entreprise, et n en lit aucune sans espace', function () {
    $g = cejSemer();

    $faux = new class extends WaterfallOrchestrator
    {
        /** @var array<int, array{int, string}> */
        public static array $vues = [];

        public function __construct()
        {
            // Pas d'appel a parent::__construct() : ce double n'a besoin
            // d'aucune des dependances de l'orchestrateur reel, on ne mesure
            // ici que ce que le JOB a reussi a LIRE avant de le lui passer.
        }

        public function enrich(Company $company): void
        {
            self::$vues[] = [(int) $company->id, (string) $company->workspace_id];
        }
    };
    $faux::$vues = [];
    app()->instance(WaterfallOrchestrator::class, $faux);

    try {
        cejSousRoleApplicatif(function () use ($g, $faux) {
            cejBoucleDuWorker();
            (new EnrichCompanyJob($g['entreprise']))->handle($faux);
        });
        expect($faux::$vues)->toBe([]);

        cejSousRoleApplicatif(function () use ($g, $faux) {
            cejBoucleDuWorker();
            (new EnrichCompanyJob($g['entreprise']))->pourEspace($g['ws_a'])->handle($faux);
        });
        expect($faux::$vues)->toBe([[$g['entreprise'], $g['ws_a']]]);

        // TEMOIN DE DISCRIMINATION : le meme job, pointe sur l'entreprise de
        // l'espace VOISIN mais lance sous l'espace A, ne lit rien du tout.
        $faux::$vues = [];
        cejSousRoleApplicatif(function () use ($g, $faux) {
            cejBoucleDuWorker();
            (new EnrichCompanyJob($g['entreprise_b']))->pourEspace($g['ws_a'])->handle($faux);
        });
        expect($faux::$vues)->toBe([]);
    } finally {
        app()->forgetInstance(WaterfallOrchestrator::class);
        cejNettoyer($g);
    }
});
