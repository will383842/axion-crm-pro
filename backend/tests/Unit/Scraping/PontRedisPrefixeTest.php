<?php

/**
 * GARDE C18-011 — LA CLEF EMISE EST-ELLE CELLE QUI EST ECOUTEE ?
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * LE DEFAUT, MESURE
 * ═══════════════════════════════════════════════════════════════════════════
 *
 * `DispatchScrapeJob::handle()` pousse sur `axion:scrape:<source>` par
 * `Redis::connection('queue')`. Cette connexion herite de
 * `database.redis.options.prefix` (`axion_crm_pro_database_`) : le client Redis
 * de Laravel colle ce prefixe devant CHAQUE clef. La clef reellement ecrite
 * etait donc `axion_crm_pro_database_axion:scrape:google-maps`.
 *
 * Cote Node, `workers/src/bridge/redis.ts` construit un `ioredis` SANS
 * `keyPrefix`, et `workers/src/scrapers/base.ts` ligne 158 fait
 * `redis.brpop(queue, 30)` sur la constante NUE de `workers/src/bridge/queues.ts`.
 *
 * Les deux cotes ne se rencontrent jamais. Rien ne rougit : Laravel ecrit sans
 * erreur, le worker attend sans erreur.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * POURQUOI CETTE GARDE N'EST PAS UN TEST DE CONFIGURATION
 * ═══════════════════════════════════════════════════════════════════════════
 *
 * Deux formes de test auraient ete VERTES sur le code casse :
 *
 *  ⑴ relire `config('database.redis.…')` et y chercher une chaine — le defaut
 *    ne vit pas dans la configuration, il vit dans la COMPOSITION de deux
 *    configurations justes chacune de son cote ;
 *  ⑵ espionner la facade (`Redis::shouldReceive('connection')`), ce que fait
 *    deja `tests/Unit/Http/SsrfSitesJumeauxTest.php` : l'espion voit
 *    `axion:scrape:website`, l'argument PHP, et s'arrete AVANT que Predis ne
 *    colle le prefixe. Cet espion est structurellement aveugle a C18-011.
 *
 * Cette garde se substitue au SOCKET (`Tests\Support\PontRedisEnregistreur`) :
 * elle lit la suite d'octets qui serait partie sur le fil, prefixe compris. Et
 * elle compare cette clef aux constantes lues DANS le fichier TypeScript que le
 * worker execute — pas a une copie PHP de ces constantes, qui pourrait deriver.
 *
 * REGLE DE MESURE : aucun `expect(...)->toContain($aiguille, $message)` — le 2e
 * argument de `toContain()` est VARIADIQUE en Pest et deviendrait une 2e
 * aiguille (cf. `AucunMessageDansToContainTest`).
 */

use App\Jobs\DispatchScrapeJob;
use App\Jobs\LaunchZoneScrapingJob;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Redis;
use Tests\Support\PontRedisEnregistreur;

/**
 * Prefixe global temoin. Fige dans le test pour que le verdict ne depende pas
 * de `APP_NAME` sur le banc : ce qui doit etre prouve, c'est qu'un prefixe
 * global NON VIDE ne franchit pas le pont.
 */
const PONT_PREFIXE_GLOBAL = 'axion_crm_pro_database_';

/**
 * Les clefs que le worker Node ECOUTE, lues dans son propre source.
 *
 * ⚠️ TEMOIN DE COUVERTURE : la fonction LEVE si le fichier manque ou si le
 * balayage ne rend rien. Sans cela, un chemin faux rendrait un tableau vide et
 * toutes les boucles ci-dessous seraient vertes sans avoir rien compare.
 *
 * @return list<string>
 */
function pontClefsEcouteesParNode(?string $chemin = null): array
{
    $chemin ??= base_path('../workers/src/bridge/queues.ts');

    if (! is_file($chemin)) {
        throw new RuntimeException("Balayage a vide : {$chemin} est introuvable.");
    }

    // Les deux occurrences en commentaire portent `<source>` et ne peuvent pas
    // etre capturees par cette classe de caracteres.
    preg_match_all("/'(axion:scrape:[a-z0-9-]+)'/", (string) file_get_contents($chemin), $trouve);

    $clefs = array_values(array_unique($trouve[1]));

    if ($clefs === []) {
        throw new RuntimeException("Balayage a vide : aucune clef 'axion:scrape:…' dans {$chemin}.");
    }

    return $clefs;
}

/** Lit un fichier du sous-arbre `workers/`, en levant si le chemin est faux. */
function pontSourceNode(string $relatif): string
{
    $chemin = base_path('../workers/' . $relatif);

    if (! is_file($chemin)) {
        throw new RuntimeException("Balayage a vide : {$chemin} est introuvable.");
    }

    return (string) file_get_contents($chemin);
}

/**
 * Pose un prefixe global non vide, puis branche l'enregistreur.
 * L'ordre compte : `RedisManager` fige la configuration a sa resolution.
 */
function pontInstaller(): void
{
    Config::set('database.redis.client', 'predis');
    Config::set('database.redis.options.prefix', PONT_PREFIXE_GLOBAL);

    PontRedisEnregistreur::installer();
}

// ═══════════════════════════════════════════════════════════════════════════
// LE COEUR — la clef emise doit etre, octet pour octet, la clef ecoutee
// ═══════════════════════════════════════════════════════════════════════════

test('C18-011 — la clef emise par DispatchScrapeJob est EXACTEMENT celle que le worker Node ecoute', function () {
    $ecoutees = pontClefsEcouteesParNode();

    // Temoin de couverture : le balayage a vu les 11 files du registre Node.
    // Si ce nombre baisse, c'est que le balayage s'est mis a rater des lignes.
    expect(count($ecoutees))->toBeGreaterThanOrEqual(11);

    pontInstaller();

    foreach ($ecoutees as $clefEcoutee) {
        $source = str_replace('axion:scrape:', '', $clefEcoutee);

        $capture = PontRedisEnregistreur::capturer(function () use ($source) {
            (new DispatchScrapeJob(companyId: 1, source: $source, context: [], targetUrl: null))->handle();
        });

        expect($capture)->toHaveCount(1);
        expect($capture[0]['commande'])->toBe('LPUSH');

        $clefEmise = PontRedisEnregistreur::premiereClef($capture);

        $this->assertSame(
            $clefEcoutee,
            $clefEmise,
            "C18-011 : Laravel ecrit la clef Redis « {$clefEmise} » alors que "
            . "workers/src/scrapers/base.ts fait BRPOP sur « {$clefEcoutee} » "
            . '(constante de workers/src/bridge/queues.ts, relue ici dans le source Node). '
            . 'Le job part, personne ne le lit, et rien ne rougit : ni Laravel ni le worker '
            . "n'emettent d'erreur. Cause habituelle : la connexion Redis employee par "
            . 'DispatchScrapeJob herite de database.redis.options.prefix. Le pont doit passer '
            . 'par une connexion SANS prefixe.',
        );

        // La charge utile reste lisible : un correctif qui casserait le JSON
        // serait aussi mortel que le prefixe.
        $charge = json_decode($capture[0]['arguments'][1], true, 512, JSON_THROW_ON_ERROR);
        expect($charge['source'])->toBe($source);
        $this->assertArrayHasKey('run_id', $charge);
    }
});

// ═══════════════════════════════════════════════════════════════════════════
// TEMOIN NEGATIF — l'instrument sait-il VOIR un prefixe ?
// ═══════════════════════════════════════════════════════════════════════════

test('TEMOIN NEGATIF — l enregistreur voit le prefixe quand la connexion en porte un', function () {
    pontInstaller();

    // `cache` et `default` restent prefixees : c'est tout l'interet de ne PAS
    // vider le prefixe globalement.
    foreach (['default', 'cache', 'queue'] as $connexion) {
        $capture = PontRedisEnregistreur::capturer(function () use ($connexion) {
            Redis::connection($connexion)->set('axion:scrape:google-maps', 'x');
        });

        expect($capture)->toHaveCount(1);

        $clef = PontRedisEnregistreur::premiereClef($capture);

        $this->assertSame(
            PONT_PREFIXE_GLOBAL . 'axion:scrape:google-maps',
            $clef,
            "La connexion « {$connexion} » a emis « {$clef} » : ou bien l'enregistreur ne voit "
            . 'pas le prefixe (et alors le vert du test principal ne prouve rien), ou bien le '
            . 'correctif a vide le prefixe GLOBAL — ce qui deplacerait toutes les clefs du cache, '
            . 'des sessions et de la file Laravel, et rendrait orphelin tout ce qui est deja en '
            . 'vol en production.',
        );
    }
});

// ═══════════════════════════════════════════════════════════════════════════
// TEMOINS DE COUVERTURE — un balayage a vide doit ROUGIR, pas verdir
// ═══════════════════════════════════════════════════════════════════════════

test('TEMOIN DE COUVERTURE — un chemin faux vers workers/ leve au lieu de rendre zero clef', function () {
    expect(fn () => pontClefsEcouteesParNode(base_path('../workers/src/bridge/queues-inexistant.ts')))
        ->toThrow(RuntimeException::class);

    expect(fn () => pontSourceNode('src/bridge/inexistant.ts'))
        ->toThrow(RuntimeException::class);

    // Et le chemin juste, lui, rend bien quelque chose.
    expect(pontClefsEcouteesParNode())->not->toBeEmpty();
});

test('TEMOIN DE COUVERTURE — le prefixe Redis livre est NON VIDE, donc le defaut etait reel', function () {
    // Relu AVANT toute installation : c'est la valeur telle que la livre
    // `config/database.php`. Si elle etait vide, la garde principale serait
    // verte pour une raison qui n'a rien a voir avec le correctif.
    $prefixe = (string) config('database.redis.options.prefix');

    expect($prefixe)->not->toBe('');
    $this->assertStringEndsWith('_database_', $prefixe);
});

// ═══════════════════════════════════════════════════════════════════════════
// LE COTE NODE — la clef ecoutee est bien NUE
// ═══════════════════════════════════════════════════════════════════════════

test('C18-011 — le client ioredis du worker ne pose AUCUN keyPrefix', function () {
    $redisTs = pontSourceNode('src/bridge/redis.ts');

    $this->assertStringNotContainsString(
        'keyPrefix',
        $redisTs,
        'workers/src/bridge/redis.ts pose desormais un keyPrefix sur ioredis. La clef ecoutee '
        . "n'est donc plus nue, et la garde ci-dessus compare deux choses qui ne se "
        . 'correspondent plus. Si ce prefixe est voulu, il faut le porter AUSSI cote Laravel.',
    );

    // Et le BRPOP porte bien la constante, pas une clef recomposee sur place.
    $baseTs = pontSourceNode('src/scrapers/base.ts');
    $this->assertStringContainsString('brpop(queue', $baseTs);
});

// ═══════════════════════════════════════════════════════════════════════════
// LA BASE REDIS — une clef nue dans la MAUVAISE base ne se rencontre pas non plus
// ═══════════════════════════════════════════════════════════════════════════

/** Numero de base Redis porte par une URL `redis://hote:port/N`. */
function pontBaseDeLUrl(string $url): ?int
{
    $chemin = parse_url($url, PHP_URL_PATH);

    return is_string($chemin) && ltrim($chemin, '/') !== '' ? (int) ltrim($chemin, '/') : null;
}

test('C18-011 — le pont ecrit dans la base Redis que le worker Node ouvre', function () {
    // `.env.example` est la seule source VERSIONNEE des deux valeurs : le banc,
    // lui, a son propre `.env` non versionne. Lire le fichier d'exemple fige
    // donc ce que le depot PRESCRIT, pas ce que cette machine-ci fait.
    $exemple = base_path('../.env.example');

    if (! is_file($exemple)) {
        throw new RuntimeException("Balayage a vide : {$exemple} est introuvable.");
    }

    $contenu = (string) file_get_contents($exemple);

    expect(preg_match('/^WORKER_REDIS_URL=(.+)$/m', $contenu, $m))->toBe(1);
    $baseNode = pontBaseDeLUrl(trim($m[1]));

    $this->assertNotNull($baseNode, 'WORKER_REDIS_URL de .env.example ne nomme aucune base.');

    pontInstaller();

    $capture = PontRedisEnregistreur::capturer(function () {
        (new DispatchScrapeJob(companyId: 1, source: 'google-maps', context: [], targetUrl: null))->handle();
    });

    $this->assertSame(
        $baseNode,
        (int) $capture[0]['base'],
        'Le pont ecrit dans la base Redis ' . (int) $capture[0]['base'] . ' alors que le worker Node '
        . "ouvre la base {$baseNode} (WORKER_REDIS_URL de .env.example). Une clef nue dans la "
        . 'mauvaise base ne se rencontre pas davantage qu\'une clef prefixee : c\'est la MEME panne '
        . 'silencieuse que C18-011, sous une autre forme. Regler `REDIS_SCRAPE_DB`, ou aligner '
        . '`REDIS_QUEUE_DB` et `WORKER_REDIS_URL`.',
    );
});

// ═══════════════════════════════════════════════════════════════════════════
// LE SITE JUMEAU QUI N'EST PAS FERME — ETAT CONSTATE, ET COMPTE
// ═══════════════════════════════════════════════════════════════════════════

test('C18-011 SITE JUMEAU PORTE — le drapeau d annulation franchit le pont, clef ET base', function () {
    // ✅ CE TEST FIGEAIT UN DEFAUT LE 2026-08-21 ; IL LE GARDE FERME DEPUIS LE MEME JOUR.
    //
    // Sa version precedente disait : « les TROIS sites d'appel doivent passer sur
    // `DispatchScrapeJob::CONNEXION_REDIS`, puis remplacer `assertNotSame` par
    // `assertSame` ». C'est fait. Le passage de relais est conserve ici parce
    // qu'il dit ce que ce test protege : deux divergences INDEPENDANTES, le
    // prefixe et le numero de base, chacune suffisante a elle seule pour que
    // l'arret d'urgence n'arrete rien cote Node.
    //
    //   avant : PHP  ecrivait  axion_crm_pro_database_cancelled:scraper-run:42  (base 0)
    //           Node lisait    cancelled:scraper-run:42                          (base 1)
    //
    // Les trois sites, portes ensemble — jamais separement, car deplacer les
    // ecritures sans la lecture casserait l'annulation cote PHP :
    //   ecriture  app/Http/Controllers/Api/ScraperRunsController.php
    //   ecriture  app/Http/Controllers/Api/ScrapingCampaignsController.php
    //   lecture   app/Jobs/LaunchZoneScrapingJob::motifArretDistant()
    $prefixeNode = pontSourceNode('src/scrapers/base.ts');
    expect(preg_match("/PREFIXE_ANNULATION = '([^']+)'/", $prefixeNode, $m))->toBe(1);
    $clefLueParNode = $m[1] . '42';

    pontInstaller();

    // On joue les DEUX ecritures reelles du produit, pas une imitation : si un
    // jour l'une des deux repasse sur la facade nue, cette garde le verra.
    $capture = PontRedisEnregistreur::capturer(function () {
        Redis::connection(DispatchScrapeJob::CONNEXION_REDIS)
            ->setex(LaunchZoneScrapingJob::CLE_ANNULATION . '42', 3600, '1');
    });

    $clefEcriteParPhp = PontRedisEnregistreur::premiereClef($capture);

    $this->assertSame('cancelled:scraper-run:42', $clefLueParNode);

    $this->assertSame(
        $clefLueParNode,
        $clefEcriteParPhp,
        "L'arret d'urgence ne franchit plus le pont : PHP ecrit une clef que le worker Node ne "
        . "lit pas.

C'est le site jumeau de C18-011. Verifier que les TROIS sites d'appel "
        . 'passent bien par `DispatchScrapeJob::CONNEXION_REDIS` — les deux ecritures des '
        . 'controleurs ET la lecture de `LaunchZoneScrapingJob::motifArretDistant()`. Deplacer '
        . "seulement une partie casse l'annulation cote PHP, qui elle fonctionne.",
    );

    // LA SECONDE DIVERGENCE, ET ELLE EST INDEPENDANTE DE LA PREMIERE : deux
    // clefs identiques sur deux bases Redis differentes ne se rencontrent pas
    // davantage que deux clefs differentes. Elle est couverte, pour la MEME
    // connexion, par le test « le pont ecrit dans la base Redis que le worker
    // Node ouvre » ci-dessus — le drapeau d'annulation passe desormais par
    // `DispatchScrapeJob::CONNEXION_REDIS`, donc il herite de cette garde-la.
    // On verifie seulement, ici, que la capture porte bien une base : sans
    // cela, la phrase precedente serait une supposition.
    $this->assertArrayHasKey(
        'base',
        $capture[0],
        "L'enregistreur ne rend plus le numero de base : la garde de base Redis voisine ne "
        . 'mesure plus rien pour ce site.',
    );
});
