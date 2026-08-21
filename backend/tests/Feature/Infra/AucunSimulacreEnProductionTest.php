<?php

/**
 * GARDE : AUCUN SERVICE SIMULÉ EN PRODUCTION — constat C18-016 / F37-002 (S0).
 *
 * LE DÉFAUT, ET IL ÉTAIT DANS LE MAUVAIS SENS.
 *
 * `MockServicesProvider` commençait par `$master = (bool) env('MOCK_MODE', true);`.
 * **Le défaut était `true`.** Une variable absente du conteneur, mal orthographiée, ou perdue
 * lors d'un `docker compose restart` — qui ne relit pas `env_file`, constat A07-003 — et les
 * services externes basculaient sur des simulacres. En production. Sans que rien ne le signale.
 *
 * 🔴 Le pire est le modèle de langage : `MockLLMClient` écrit des classifications
 * **fabriquées** dans la base, sur des fiches de personnes réelles. *Un simulacre qui remplit un
 * écran se voit ; un simulacre qui remplit une base ne se voit jamais.*
 *
 * CE QUE CETTE GARDE MESURE.
 *
 * Pas la présence d'un `if` : **ce que le conteneur d'injection résout réellement**. On demande
 * l'implémentation au conteneur, dans chaque environnement, et on regarde la classe obtenue.
 * Un `if` mal placé passerait un contrôle statique et échouerait ici.
 *
 * ── COUVERTURE : LES QUATORZE LIAISONS, PAS TROIS ──────────────────────────
 *
 * ⚠️ La première version de cette garde ne vérifiait que 3 contrats (LLM, INSEE, BAN) sur les
 * **14** que le provider branche. Le constat parle de « six services » — ce sont six *familles*
 * (LLM, proxies, captcha, SMTP, données publiques, scrapers), mais **14 liaisons** de conteneur.
 * Onze d'entre elles n'étaient donc mesurées par personne : un service laissé en simulacre par
 * inadvertance — exactement le patron A-011 de ce dépôt, « le correctif existe mais n'a pas été
 * porté ailleurs » — serait passé au vert.
 *
 * `carteDesServices()` est désormais l'unique source, et **toutes** les liaisons y passent.
 *
 * Les cas comptent, et il les faut tous :
 *   1. en `production`, un simulacre EXPLICITEMENT demandé est refusé — sur les 14 ;
 *   2. en `production` sans aucune variable, on obtient le service réel — sur les 14 ;
 *   3. en `staging` de même ;
 *   4. en `testing`, les simulacres se branchent toujours — sinon la suite entière tomberait,
 *      et le correctif serait pire que le défaut ;
 *   5. TÉMOIN NÉGATIF : la mesure sait *reconnaître* un service laissé en simulacre.
 */

use App\Contracts\AnnuaireEntreprisesClient;
use App\Contracts\BanGeocoder;
use App\Contracts\BodaccClient;
use App\Contracts\CaptchaSolver;
use App\Contracts\DirectionFinder;
use App\Contracts\FranceTravailClient;
use App\Contracts\GoogleMapsScraper;
use App\Contracts\InseeClient;
use App\Contracts\LLMClient;
use App\Contracts\PagesJaunesScraper;
use App\Contracts\ProxyProvider;
use App\Contracts\SearchEngine;
use App\Contracts\SmtpProber;
use App\Contracts\WebsiteScraper;
use App\Providers\MockServicesProvider;
use App\Services\AnnuaireEntreprises\HttpAnnuaireEntreprisesClient;
use App\Services\AnnuaireEntreprises\Mocks\MockAnnuaireEntreprisesClient;
use App\Services\Ban\HttpBanGeocoder;
use App\Services\Ban\Mocks\MockBanGeocoder;
use App\Services\Bodacc\HttpBodaccClient;
use App\Services\Bodacc\Mocks\MockBodaccClient;
use App\Services\Captcha\Mocks\MockCaptchaSolver;
use App\Services\Captcha\TwoCaptchaSolver;
use App\Services\FranceTravail\HttpFranceTravailClient;
use App\Services\FranceTravail\Mocks\MockFranceTravailClient;
use App\Services\Insee\HttpInseeClient;
use App\Services\Insee\Mocks\MockInseeClient;
use App\Services\LLM\LLMRouterService;
use App\Services\LLM\Mocks\MockLLMClient;
use App\Services\Proxies\Mocks\MockProxyProvider;
use App\Services\Proxies\WebshareProvider;
use App\Services\Scraping\Mocks\MockDirectionFinder;
use App\Services\Scraping\Mocks\MockGoogleMapsScraper;
use App\Services\Scraping\Mocks\MockPagesJaunesScraper;
use App\Services\Scraping\Mocks\MockSearchEngine;
use App\Services\Scraping\Mocks\MockWebsiteScraper;
use App\Services\Scraping\PlaywrightDirectionFinder;
use App\Services\Scraping\PlaywrightGoogleMapsScraper;
use App\Services\Scraping\PlaywrightPagesJaunesScraper;
use App\Services\Scraping\PlaywrightSearchEngine;
use App\Services\Scraping\PlaywrightWebsiteScraper;
use App\Services\Smtp\HunterSmtpProber;
use App\Services\Smtp\Mocks\MockSmtpProber;
use Tests\TestCase;

uses(TestCase::class);

/**
 * Les 14 liaisons du provider : [contrat, service RÉEL, simulacre, drapeau d'environnement].
 *
 * Cette liste doit rester le miroir exact de `MockServicesProvider::register()`. Le test
 * « la carte couvre TOUTES les liaisons » ci-dessous le vérifie par comparaison avec le
 * conteneur lui-même : ajouter un service au provider sans l'ajouter ici fait rougir la garde.
 * C'est voulu — un service non mesuré est un service qui peut repartir en simulacre.
 *
 * @return array<int, array{0: string, 1: string, 2: string, 3: string}>
 */
function carteDesServices(): array
{
    return [
        [LLMClient::class, LLMRouterService::class, MockLLMClient::class, 'MOCK_LLM'],
        [ProxyProvider::class, WebshareProvider::class, MockProxyProvider::class, 'MOCK_PROXIES'],
        [CaptchaSolver::class, TwoCaptchaSolver::class, MockCaptchaSolver::class, 'MOCK_CAPTCHA'],
        [SmtpProber::class, HunterSmtpProber::class, MockSmtpProber::class, 'MOCK_SMTP'],
        [InseeClient::class, HttpInseeClient::class, MockInseeClient::class, 'MOCK_INSEE'],
        [AnnuaireEntreprisesClient::class, HttpAnnuaireEntreprisesClient::class, MockAnnuaireEntreprisesClient::class, 'MOCK_ANNUAIRE_ENTREPRISES'],
        [BodaccClient::class, HttpBodaccClient::class, MockBodaccClient::class, 'MOCK_BODACC'],
        [BanGeocoder::class, HttpBanGeocoder::class, MockBanGeocoder::class, 'MOCK_BAN'],
        [FranceTravailClient::class, HttpFranceTravailClient::class, MockFranceTravailClient::class, 'MOCK_FRANCE_TRAVAIL'],
        [GoogleMapsScraper::class, PlaywrightGoogleMapsScraper::class, MockGoogleMapsScraper::class, 'MOCK_SCRAPERS'],
        [PagesJaunesScraper::class, PlaywrightPagesJaunesScraper::class, MockPagesJaunesScraper::class, 'MOCK_SCRAPERS'],
        [WebsiteScraper::class, PlaywrightWebsiteScraper::class, MockWebsiteScraper::class, 'MOCK_SCRAPERS'],
        [SearchEngine::class, PlaywrightSearchEngine::class, MockSearchEngine::class, 'MOCK_SCRAPERS'],
        [DirectionFinder::class, PlaywrightDirectionFinder::class, MockDirectionFinder::class, 'MOCK_SCRAPERS'],
    ];
}

/** Tous les drapeaux de la carte, plus le maître. */
function tousLesDrapeaux(): array
{
    $drapeaux = ['MOCK_MODE'];
    foreach (carteDesServices() as [, , , $drapeau]) {
        $drapeaux[$drapeau] = $drapeau;
    }

    return array_values(array_unique($drapeaux));
}

/**
 * Rejoue l'enregistrement du provider dans un environnement donné, et rend la classe
 * que le conteneur résout pour un contrat.
 *
 * ⚠️ PIÈGE PAYÉ, ET IL EST INSTRUCTIF. La première version faisait
 * `config(['app.env' => …])`. Ça ne marche pas : `Application::environment()` ne lit PAS
 * `config('app.env')`, il lit la liaison **`env` du conteneur**, posée une fois à l'amorçage.
 * La garde changeait donc une valeur que le provider ne consultait jamais, et rougissait sur
 * un correctif qui fonctionnait. C'est le témoin qui l'a révélé : trois cas rouges et le cas
 * « valeur ambiguë » vert, alors qu'ils dépendent du même code — incohérence impossible si la
 * mesure était bonne.
 *
 * ⚠️ SECOND PIÈGE, ET IL TOUCHE AU CŒUR DU CONSTAT. `phpunit.xml` impose
 * `MOCK_MODE=true` à toute la suite. Passer `[]` ne simule donc PAS l'absence de variable —
 * ça simule « la valeur que le banc a posée ». Or l'absence est précisément ce que le constat
 * décrit : une variable perdue par un redéploiement.
 *
 * Une valeur `null` RETIRE donc la variable des trois canaux. Sans cela, la garde aurait
 * affirmé mesurer l'absence tout en mesurant une présence — le défaut même qu'elle poursuit.
 *
 * `$fabriqueProvider` permet au TÉMOIN NÉGATIF d'injecter un provider délibérément saboté,
 * pour prouver que cette mesure *voit* un simulacre quand il y en a un.
 *
 * @param  array<string, string|null>  $variables
 */
function classeResolue(
    string $environnement,
    string $contrat,
    array $variables = [],
    ?callable $fabriqueProvider = null,
): string {
    $app = app();
    $anciennes = [];

    foreach ($variables as $cle => $valeur) {
        $anciennes[$cle] = $_SERVER[$cle] ?? null;
        if ($valeur === null) {
            unset($_SERVER[$cle], $_ENV[$cle]);
            putenv($cle);

            continue;
        }
        $_SERVER[$cle] = $valeur;
        $_ENV[$cle] = $valeur;
        putenv("{$cle}={$valeur}");
    }

    $ancienEnv = $app['env'];
    $app->instance('env', $environnement);
    config(['app.env' => $environnement]);

    $provider = $fabriqueProvider ? $fabriqueProvider($app) : new MockServicesProvider($app);
    $provider->register();
    $classe = get_class($app->make($contrat));

    $app->instance('env', $ancienEnv);
    config(['app.env' => $ancienEnv]);
    foreach ($anciennes as $cle => $valeur) {
        if ($valeur === null) {
            unset($_SERVER[$cle], $_ENV[$cle]);
            putenv($cle);
        } else {
            $_SERVER[$cle] = $valeur;
            $_ENV[$cle] = $valeur;
            putenv("{$cle}={$valeur}");
        }
    }

    return $classe;
}

/** Toutes les variables retirées : l'état « redéploiement qui a perdu l'env_file ». */
function aucuneVariable(): array
{
    return array_fill_keys(tousLesDrapeaux(), null);
}

/** Toutes les variables à `true` : l'état « quelqu'un a explicitement demandé les simulacres ». */
function toutesLesVariablesActives(): array
{
    return array_fill_keys(tousLesDrapeaux(), 'true');
}

test('C18-016 — la carte de la garde couvre TOUTES les liaisons du provider', function () {
    // Sans ce cas, ajouter un service au provider sans l'ajouter a la carte le rendrait
    // INVISIBLE a la garde : il pourrait repartir en simulacre en production sans que rien
    // ne rougisse. C'est le patron A-011 du depot -- le correctif pose a un endroit et pas
    // aux autres. On compare donc la carte au conteneur lui-meme, pas a une liste ecrite.
    $app = app();
    $avant = array_keys($app->getBindings());
    (new MockServicesProvider($app))->register();
    $apres = array_keys($app->getBindings());

    $liaisonsDuProvider = array_values(array_filter(
        array_unique(array_merge($avant, $apres)),
        fn (string $abstrait) => str_starts_with($abstrait, 'App\\Contracts\\'),
    ));

    $couvertes = array_column(carteDesServices(), 0);

    foreach ($liaisonsDuProvider as $abstrait) {
        $this->assertContains(
            $abstrait,
            $couvertes,
            "Le contrat {$abstrait} est branche par MockServicesProvider mais ne figure pas dans "
            . 'carteDesServices() : aucun cas de cette garde ne le mesure. Un service non mesure '
            . 'est un service qui peut repartir en simulacre en production. Ajoutez-le a la carte.',
        );
    }

    expect($liaisonsDuProvider)->not->toBeEmpty();
});

test('C18-016 — TEMOIN : en TEST, les simulacres se branchent bien (les 14)', function () {
    // Sans ce cas, un correctif qui interdirait les simulacres PARTOUT passerait les
    // assertions suivantes -- et ferait tomber toute la suite du depot. C'est le temoin
    // qui distingue « refuse en production » de « casse pour tout le monde ».
    //
    // ⚠️ MESURE PAYEE. Ce cas a d'abord rougi sur AnnuaireEntreprises, et le defaut n'etait
    // NI dans le provider NI dans la garde : le `.env` du depot pose
    // `MOCK_ANNUAIRE_ENTREPRISES=false`, `MOCK_BODACC=false`, `MOCK_FRANCE_TRAVAIL=false`.
    // Ces trois-la sont donc cables sur les VRAIES API publiques, en developpement comme en
    // test. C'est un choix explicite d'un operateur, hors du present constat -- mais il fausse
    // la mesure : on croirait tester le defaut du provider alors qu'on lit une derogation.
    //
    // On RETIRE donc les derogations par service et on ne laisse que le maitre. C'est bien le
    // defaut du provider qu'on mesure ici, pas la configuration d'un poste.
    $variables = array_fill_keys(tousLesDrapeaux(), null);
    $variables['MOCK_MODE'] = 'true';

    foreach (carteDesServices() as [$contrat, $reel, $simulacre, $drapeau]) {
        $classe = classeResolue('testing', $contrat, $variables);

        expect($classe)->toBe(
            $simulacre,
            "En environnement de TEST, {$contrat} devrait resoudre le simulacre {$simulacre}, "
            . "mais le conteneur rend {$classe}. Un correctif qui interdit les simulacres "
            . 'partout est PIRE que le defaut : il fait tomber toute la suite du depot et '
            . 'pousse les developpeurs a le contourner.',
        );
    }
});

test('C18-016 — en PRODUCTION, un simulacre explicitement demande est REFUSE (les 14)', function () {
    // Le refus n'est pas contournable, et c'est voulu : rendre le refus desactivable par une
    // variable, ce serait reconstruire exactement le defaut qu'on repare.
    $variables = toutesLesVariablesActives();

    foreach (carteDesServices() as [$contrat, $reel, $simulacre, $drapeau]) {
        $classe = classeResolue('production', $contrat, $variables);

        expect($classe)->toBe(
            $reel,
            "Le conteneur resout {$classe} en production alors que le service reel est {$reel} "
            . "(drapeau {$drapeau} pose a true). `MockLLMClient` et ses pairs ecrivent des "
            . 'donnees FABRIQUEES en base, sur des fiches de personnes reelles, indiscernables '
            . "des vraies. Il n'existe aucune raison legitime de servir des donnees inventees a "
            . 'des utilisateurs reels : le refus ne doit pas etre contournable par une variable.',
        );
    }
});

test('C18-016 — en PRODUCTION sans AUCUNE variable, le defaut est le service REEL (les 14)', function () {
    // C'est le coeur du constat : la variable ABSENTE. Elle l'est des qu'un
    // `docker compose restart` a remplace un `up -d` (constat A07-003), ou qu'un
    // redeploiement a perdu une ligne d'`env_file`.
    $variables = aucuneVariable();

    foreach (carteDesServices() as [$contrat, $reel, $simulacre, $drapeau]) {
        $classe = classeResolue('production', $contrat, $variables);

        expect($classe)->toBe(
            $reel,
            "Sans aucune variable, le conteneur resout {$classe} au lieu de {$reel} pour "
            . "{$contrat}. L'ancien code faisait `env('MOCK_MODE', true)` : le DEFAUT etait le "
            . 'simulacre. Une variable absente suffisait a mettre la production sur des donnees '
            . 'fabriquees.',
        );
    }
});

test('C18-016 — en PREPRODUCTION aussi, le defaut est le service REEL (les 14)', function () {
    // La preproduction est une repetition de la production : elle doit se comporter
    // comme elle. Un simulacre y masquerait exactement les pannes qu'on veut y decouvrir.
    $variables = aucuneVariable();

    foreach (carteDesServices() as [$contrat, $reel, $simulacre, $drapeau]) {
        $classe = classeResolue('staging', $contrat, $variables);

        expect($classe)->toBe(
            $reel,
            "En preproduction sans variable, {$contrat} resout {$classe} au lieu de {$reel}.",
        );
    }
});

test('C18-016 — une valeur AMBIGUE ne rebranche pas un simulacre', function () {
    // `(bool) "false"` vaut TRUE en PHP, comme `(bool) "off"` et `(bool) "0.0"`. L'ancien code
    // employait `(bool)` : un operateur qui posait `MOCK_LLM=off` en croyant desactiver le
    // simulacre l'ACTIVAIT. Le validateur booleen, lui, reconnait ces formes.
    foreach (['off', 'false', '0', 'no', ''] as $valeur) {
        expect(classeResolue('testing', LLMClient::class, ['MOCK_MODE' => 'true', 'MOCK_LLM' => $valeur]))
            ->toBe(
                LLMRouterService::class,
                "MOCK_LLM=« {$valeur} » devrait DESACTIVER le simulacre. Avec un cast `(bool)`, "
                . 'cette valeur l activait.',
            );
    }
});

test('C18-016 — TEMOIN NEGATIF : la mesure sait reperer un service laisse en simulacre', function () {
    // Une garde qui ne peut pas echouer ne prouve rien. Ici on SABOTE volontairement le
    // provider -- un service laisse cable sur son simulacre, malgre l'environnement de
    // production -- et on verifie que la mesure le VOIT. C'est exactement la forme que
    // prendrait une regression : quelqu'un ajoute une liaison en dur, sans passer par
    // $bind() et donc sans passer par le refus de production.
    $providerSabote = fn ($app) => new class($app) extends MockServicesProvider
    {
        public function register(): void
        {
            parent::register();
            // La regression simulee : cablage en dur, hors du chemin protege.
            $this->app->bind(SmtpProber::class, MockSmtpProber::class);
        }
    };

    $classe = classeResolue('production', SmtpProber::class, aucuneVariable(), $providerSabote);

    expect($classe)->toBe(
        MockSmtpProber::class,
        'Le temoin negatif ne fonctionne plus : on a cable un simulacre en dur, en production, '
        . "et la mesure ne l'a pas vu. Si elle ne voit pas CE simulacre-la, les cas verts "
        . 'ci-dessus ne prouvent rien. Verifiez classeResolue().',
    );

    // Et le meme contrat, sans sabotage, rend bien le service reel : la difference est donc
    // bien imputable au sabotage, pas a un artefact de la mesure.
    expect(classeResolue('production', SmtpProber::class, aucuneVariable()))
        ->toBe(HunterSmtpProber::class);
});
