<?php

/**
 * GARDE C18-008 — SITE JUMEAU : le chemin de collecte NODE.
 *
 * Le correctif C18-008 deja en place (commit 0ac9578) a donne un LECTEUR au
 * drapeau `cancelled:scraper-run:{id}` : `LaunchZoneScrapingJob` le relit, et
 * une campagne annulee n'engendre plus d'entreprises. Mais ce lecteur ne
 * couvre que les sources traitees EN PHP (`insee`, `france_travail`).
 *
 * Mesure du 2026-08-21 sur ce depot :
 *     grep -rni "cancel\|annul" workers/src --include=*.ts   ->  0 fichier
 * Les onze files `axion:scrape:*` (cf. `workers/src/bridge/queues.ts`) etaient
 * donc integralement insensibles a l'arret d'urgence. Pire, la cause etait
 * STRUCTURELLE et pas seulement un lecteur manquant : `DispatchScrapeJob`
 * deposait un `run_id` fabrique par `bin2hex(random_bytes(8))`, sans aucun
 * rapport avec la ligne `scraper_runs` creee juste avant par
 * `LaunchZoneScrapingJob`. Meme un lecteur cote Node n'aurait eu aucune cle a
 * ouvrir : il ne connaissait pas le numero du run.
 *
 * Ce que ce fichier tient :
 *  1) le payload depose sur `axion:scrape:<source>` NOMME la ligne
 *     `scraper_runs` — sans quoi rien n'est annulable cote Node ;
 *  2) le moteur des workers (`workers/src/scrapers/base.ts`) LIT bien ce
 *     drapeau, avec le MEME prefixe que celui qu'ecrivent les deux
 *     controleurs PHP ;
 *  3) le RESIDU non ferme est compte : les dispatches Node qui ne portent
 *     aucun numero de run (chemin `WaterfallOrchestrator`) restent
 *     inarretables, et leur nombre est fige ici.
 *
 * Temoin de couverture : chaque balayage de fichiers verifie d'abord qu'il
 * VOIT quelque chose. Un chemin faux rend zero fichier, et zero fichier rend
 * vert n'importe quelle assertion « aucune occurrence ».
 */

use App\Contracts\InseeClient;
use App\Jobs\LaunchZoneScrapingJob;
use App\Models\ScraperRun;
use App\Models\ScrapingCampaign;
use App\Models\User;
use App\Models\Workspace;
use App\Services\FranceTravail\FranceTravailDiscoveryClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/** Racine de l'arbre `workers/`, voisin de `backend/` dans le depot comme dans le conteneur. */
function nodeRacineWorkers(): string
{
    return base_path('../workers');
}

/**
 * Tous les `.ts` de `workers/src`, chemins absolus.
 *
 * @return array<int, string>
 */
function nodeFichiersSources(string $racine): array
{
    $dossier = $racine . '/src';
    if (! is_dir($dossier)) {
        return [];
    }
    $out = [];
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dossier, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $fichier) {
        /** @var SplFileInfo $fichier */
        if ($fichier->isFile() && $fichier->getExtension() === 'ts') {
            $out[] = $fichier->getPathname();
        }
    }
    sort($out);

    return $out;
}

function nodeMakeUser(): array
{
    $workspace = Workspace::create([
        'id' => (string) Str::uuid(),
        'slug' => 'node-' . Str::random(6),
        'name' => 'WS node',
    ]);
    $user = User::create([
        'id' => (string) Str::uuid(),
        'email' => 'node' . Str::random(4) . '@test.local',
        'name' => 'User node',
        'password_hash' => Hash::make('SomePass!1234'),
        'current_workspace_id' => $workspace->id,
        'first_login_completed_at' => now(),
    ]);

    return [$user, $workspace];
}

/**
 * Joue `LaunchZoneScrapingJob` sur une source NODE, MOCK_SCRAPERS coupe, et
 * rend tout ce qui a ete depose sur les files `axion:scrape:*`.
 *
 * @return array<int, array{file: string, payload: array<string, mixed>}>
 */
function nodeDepotsDeLaFile(Workspace $w, ?int $campaignId, string $source = 'google_maps'): array
{
    // `env()` lit le referentiel Dotenv, alimente par $_SERVER et $_ENV : c'est
    // la seule voie qui change ce que voit `env('MOCK_SCRAPERS')` a chaud.
    $avantServer = $_SERVER['MOCK_SCRAPERS'] ?? null;
    $avantEnv = $_ENV['MOCK_SCRAPERS'] ?? null;
    $_SERVER['MOCK_SCRAPERS'] = 'false';
    $_ENV['MOCK_SCRAPERS'] = 'false';
    putenv('MOCK_SCRAPERS=false');

    // Les depots sont retenus PAR l'objet : une promotion `&$depots` dans un
    // constructeur ne propage pas la reference, et la garde aurait mesure un
    // tableau vide en croyant mesurer la file.
    $faussetteConnexion = new class
    {
        /** @var array<int, array{file: string, payload: array<string, mixed>}> */
        public array $depots = [];

        public function lpush(string $file, string $json): int
        {
            $this->depots[] = ['file' => $file, 'payload' => json_decode($json, true)];

            return count($this->depots);
        }
    };

    Redis::shouldReceive('connection')->andReturn($faussetteConnexion);
    // La boucle d'upsert ne tourne pas (la source Node ne rend rien en direct),
    // mais on borne quand meme la lecture du drapeau : pas de vrai Redis en test.
    Redis::shouldReceive('get')->andReturnNull();
    Redis::shouldReceive('setex')->andReturnTrue();

    try {
        $job = new LaunchZoneScrapingJob(
            workspaceId: (string) $w->id,
            department: '75',
            naf: null,
            sizeCategory: null,
            limit: 50,
            campaignId: $campaignId,
            source: $source,
            enrich: false,
        );
        $job->handle(
            app(InseeClient::class),
            Mockery::mock(FranceTravailDiscoveryClient::class),
        );
    } finally {
        if ($avantServer === null) {
            unset($_SERVER['MOCK_SCRAPERS']);
        } else {
            $_SERVER['MOCK_SCRAPERS'] = $avantServer;
        }
        if ($avantEnv === null) {
            unset($_ENV['MOCK_SCRAPERS']);
            putenv('MOCK_SCRAPERS');
        } else {
            $_ENV['MOCK_SCRAPERS'] = $avantEnv;
            putenv('MOCK_SCRAPERS=' . $avantEnv);
        }
    }

    return $faussetteConnexion->depots;
}

// ===========================================================================
// 1) Le job depose sur la file doit NOMMER la ligne scraper_runs
// ===========================================================================

test('C18-008 jumeau — le job Node depose sur la file porte le numero du scraper_run', function () {
    // PAS de Queue::fake() ici : `DispatchScrapeJob` DOIT s executer (file
    // `sync`) pour que son payload arrive jusqu a la faussette Redis. Avec
    // `Queue::fake()` le dispatch est intercepte, rien n est depose, et la
    // garde mesurait un tableau vide en croyant mesurer la file.
    [$u, $w] = nodeMakeUser();
    $c = ScrapingCampaign::create([
        'workspace_id' => $w->id,
        'created_by' => $u->id,
        'name' => 'Campagne node',
        'status' => 'running',
        'sources' => ['google_maps'],
        'zones' => [['type' => 'department', 'code' => '75']],
        'max_companies' => 1000,
        'companies_created' => 0,
        'max_duration_minutes' => 180,
        'runs_total' => 1,
        'runs_completed' => 0,
        'started_at' => now(),
    ]);

    $depots = nodeDepotsDeLaFile($w, $c->id);

    // TEMOIN DE COUVERTURE : sans depot, « le depot porte le numero » serait
    // vrai pour rien du tout.
    $this->assertCount(1, $depots, 'Un depot exactement doit avoir ete pousse sur axion:scrape:google-maps.');
    $this->assertSame('axion:scrape:google-maps', $depots[0]['file']);

    $run = ScraperRun::where('workspace_id', $w->id)->latest('id')->first();
    $this->assertNotNull($run, 'Le run doit exister : sans run, il n y a rien a annuler.');

    // ROUGE attendu avant correctif : la cle `scraper_run_id` n existe nulle
    // part dans le payload — le worker Node n a aucun numero a mettre derriere
    // le prefixe `cancelled:scraper-run:`, et ne peut donc rien annuler.
    //
    // Le numero passe par `context` et non par un argument propre de
    // `DispatchScrapeJob` : ce fichier-la est en cours de modification par un
    // autre lot (C18-011, prefixe de la connexion Redis du pont), et
    // `context` est deja transporte tel quel jusqu au worker.
    $this->assertArrayHasKey('context', $depots[0]['payload']);
    $this->assertArrayHasKey('scraper_run_id', $depots[0]['payload']['context']);
    $this->assertSame((int) $run->id, (int) $depots[0]['payload']['context']['scraper_run_id']);
});

test('C18-008 jumeau TEMOIN — `run_id` reste le jeton synthetique, il ne designe AUCUN run', function () {
    [$u, $w] = nodeMakeUser();

    $depots = nodeDepotsDeLaFile($w, null, 'pages_jaunes');

    $this->assertCount(1, $depots);
    $run = ScraperRun::where('workspace_id', $w->id)->latest('id')->first();
    $this->assertNotNull($run);

    // Ce temoin dit POURQUOI une seconde cle etait necessaire : `run_id` est un
    // jeton opaque de 16 hexa fabrique par DispatchScrapeJob, et il ne vaudra
    // jamais l identifiant d une ligne `scraper_runs`. Si un jour quelqu un
    // reutilise `run_id` pour ca, ce temoin rougira et il faudra le relire.
    $runId = (string) $depots[0]['payload']['run_id'];
    $this->assertMatchesRegularExpression('/^[0-9a-f]{16}$/', $runId);
    $this->assertNotSame((string) $run->id, $runId);
});

// ===========================================================================
// 2) Le moteur des workers doit LIRE ce drapeau
// ===========================================================================

test('C18-008 jumeau — le moteur des workers Node lit le drapeau d annulation', function () {
    $racine = nodeRacineWorkers();
    $fichiers = nodeFichiersSources($racine);

    // TEMOIN DE COUVERTURE : un chemin faux rend zero fichier, et « aucune
    // occurrence » serait alors vrai sans rien avoir lu.
    $this->assertGreaterThan(
        20,
        count($fichiers),
        'Balayage vide ou quasi vide : le chemin ' . $racine . '/src est faux, la garde ne mesure rien.',
    );

    $prefixe = LaunchZoneScrapingJob::CLE_ANNULATION; // 'cancelled:scraper-run:'
    $lecteurs = [];
    foreach ($fichiers as $f) {
        $contenu = (string) file_get_contents($f);
        if (str_contains($contenu, $prefixe)) {
            $lecteurs[] = str_replace($racine . '/', '', str_replace('\\', '/', $f));
        }
    }

    // ROUGE attendu avant correctif : 0 lecteur (mesure du 2026-08-21,
    // `grep -rn "cancelled:scraper-run" workers/` -> aucun resultat).
    $this->assertNotEmpty(
        $lecteurs,
        'Aucun fichier de workers/src ne connait le prefixe ' . $prefixe
        . ' : les onze files axion:scrape:* sont insensibles a l arret d urgence.',
    );
    $this->assertContains('src/scrapers/base.ts', $lecteurs);
});

// ===========================================================================
// 3) Le RESIDU : ce qui reste inarretable, compte et fige
// ===========================================================================

test('C18-008 residu — les dispatches Node SANS numero de run sont comptes et figes a 1', function () {
    $appels = [];
    foreach (['app/Jobs/LaunchZoneScrapingJob.php', 'app/Services/Waterfall/WaterfallOrchestrator.php'] as $rel) {
        $chemin = base_path($rel);
        $this->assertFileExists($chemin, 'Chemin faux : la garde ne mesurerait rien.');
        $lignes = explode("\n", (string) file_get_contents($chemin));
        foreach ($lignes as $no => $ligne) {
            if (! str_contains($ligne, 'DispatchScrapeJob::dispatch')) {
                continue;
            }
            // Fenetre de 14 lignes a partir de l appel : l appel de
            // `LaunchZoneScrapingJob` est ecrit en arguments nommes sur
            // plusieurs lignes. On ne regarde donc pas le fichier entier, qui
            // rendrait vrai n importe quelle mention du mot ailleurs.
            $fenetre = implode("\n", array_slice($lignes, $no, 14));
            $appels[] = [$rel . ':' . ($no + 1), str_contains($fenetre, 'scraper_run_id')];
        }
    }

    // TEMOIN DE COUVERTURE : si le balayage ne voit aucun appel, l assertion
    // « exactement 1 sans numero » serait fausse pour la mauvaise raison.
    $this->assertCount(2, $appels, 'Deux sites appellent DispatchScrapeJob dans ce depot.');

    $sansNumero = array_values(array_filter($appels, fn (array $a) => $a[1] === false));

    // Ce chiffre est celui du 2026-08-21, et il est fige EXPRES.
    //
    // `WaterfallOrchestrator::step4_dispatch_node_scrapes()` pousse trois
    // scrapes Node (pages-jaunes, website, google-search) par entreprise
    // enrichie, et il n existe AUCUNE ligne `scraper_runs` a ce moment-la :
    // `ScrapedRecordIngestService::recordRun()` la cree seulement a
    // l INGESTION du resultat, sans `campaign_id`. Un scrape de ce chemin est
    // donc structurellement inarretable — il n a pas de numero a annuler —, et
    // le rendre arretable demande de creer le run AVANT le dispatch : c est une
    // modification de conception, pas un correctif, et elle touche
    // `ScraperResultController` qui est hors de ce lot.
    //
    // Si ce compte passe a 0, c est que quelqu un a ferme le residu : mets a
    // jour ce chiffre et le journal. S il monte, un nouveau chemin inarretable
    // a ete ouvert.
    $this->assertCount(
        1,
        $sansNumero,
        'Sites de dispatch Node sans numero de run : ' . json_encode(array_column($sansNumero, 0)),
    );
    $this->assertStringContainsString('WaterfallOrchestrator.php', $sansNumero[0][0]);
});

test('C18-007 residu — un run ingere depuis Node ne porte AUCUN campaign_id, le quota ne le voit pas', function () {
    // Deuxieme moitie du meme defaut structurel, cote C18-007 cette fois.
    //
    // `LaunchZoneScrapingJob` incremente `scraping_campaigns.companies_created`
    // lui-meme, et le moniteur rattrape par
    // `COUNT(DISTINCT company_id) ... WHERE campaign_id = ?`. Or les runs nes
    // d'un resultat Node sont crees par
    // `ScrapedRecordIngestService::recordRun()`, dont l'insertion ne renseigne
    // PAS `campaign_id` : ni le compteur ni le rattrapage ne les voient. Une
    // campagne dont les sources sont `google_maps` / `pages_jaunes` ne consomme
    // donc jamais son plafond `max_companies`.
    //
    // Le fermer suppose de porter le `campaign_id` du dispatch jusqu'a
    // l'ingestion, c'est-a-dire de toucher `ScraperResultController` et le
    // contrat du payload — hors de ce lot. Le chiffre du 2026-08-21 est fige
    // ici : ZERO mention de `campaign_id` dans cette insertion.
    $chemin = base_path('app/Crm/Scraping/ScrapedRecordIngestService.php');
    $this->assertFileExists($chemin);
    $source = (string) file_get_contents($chemin);

    $debut = mb_strpos($source, 'private function recordRun(');
    $this->assertNotFalse($debut, 'recordRun() a ete renomme : la garde ne mesure plus rien.');

    $corps = mb_substr($source, $debut, 1200);

    // TEMOINS DE COUVERTURE : on a bien attrape l'insertion, et une insertion
    // qui renseigne DEJA des colonnes — sinon « campaign_id absent » serait
    // vrai pour une fenetre vide.
    $this->assertStringContainsString("DB::table('scraper_runs')->insertOrIgnore(", $corps);
    $this->assertStringContainsString("'workspace_id' =>", $corps);
    $this->assertStringContainsString("'company_id' =>", $corps);

    $this->assertStringNotContainsString(
        "'campaign_id'",
        $corps,
        'Un campaign_id est apparu dans recordRun() : le residu C18-007 est peut-etre ferme, '
        . 'verifie que le quota le compte vraiment et mets a jour cette garde.',
    );
});
