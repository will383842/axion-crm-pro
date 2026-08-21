<?php

/**
 * GARDE — LA GARDE SSRF QUI N'ETAIT JAMAIS APPELEE (constats C19-001 et C19-003).
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * CE QUE LA MESURE A MONTRE, ET POURQUOI CE FICHIER EXISTE
 * ═══════════════════════════════════════════════════════════════════════════
 *
 * `App\Services\Http\SsrfGuard` existe depuis longtemps, il est couvert par
 * 30 tests unitaires (SsrfGuardTest + SsrfGuardExtendedTest, tous verts), et
 * il refuse correctement 127.0.0.1, 169.254.169.254, 10/8, 192.168/16, etc.
 *
 * Et pourtant, mesure faite le 2026-08-20 :
 *
 *     grep -rn "SsrfGuard" backend/app  ->  5 appels
 *
 * Les CINQ portent sur `self::BASE_URL`, une CONSTANTE de classe
 * (AnnuaireEntreprises, Ban, Bodacc, FranceTravail, Insee). Autrement dit :
 * la garde n'etait appliquee qu'a des URLs que personne ne peut influencer,
 * et JAMAIS a une URL issue de la donnee. Les trois services qui consomment
 * une URL venue de la base ou d'un tiers -- MentionsLegalesScraperService,
 * DomainFinderService, ProxiedHttpClient -- ne l'appelaient pas du tout.
 * C'est le patron A-011 dans sa forme la plus pure : le correctif etait deja
 * ecrit, teste, et il n'avait ete branche nulle part.
 *
 * Second defaut, distinct et plus grave (C19-003) : meme branchee, la garde
 * ne regardait que l'URL de DEPART. Guzzle suit par defaut jusqu'a 5
 * redirections (RedirectMiddleware::$defaultSettings['max'] = 5). Il suffisait
 * donc qu'un site public reponde `302 Location: http://169.254.169.254/...`
 * pour que le backend aille lire le service de metadonnees de l'hebergeur, et
 * en persiste le corps. Cette voie ne demande aucun acces a la console : elle
 * est declenchee par le site scrape lui-meme.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * COMMENT CE BANC PROUVE QUELQUE CHOSE (et pourquoi il tient hors ligne)
 * ═══════════════════════════════════════════════════════════════════════════
 *
 * 1. TOUTES les URLs de ce fichier sont des ADRESSES IP LITTERALES. La garde
 *    ne fait alors AUCUNE resolution DNS : le verdict ne depend ni du reseau
 *    ni du resolveur du banc. Une garde de securite qui rougit quand le CI
 *    n'a pas de DNS ne mesure pas le produit, elle mesure l'atelier.
 *    93.184.216.34 est l'IP publique historique d'example.com ; ici elle ne
 *    sert que de TEMOIN « adresse publique », aucune requete ne sort (Http::fake).
 *
 * 2. MESURE PREALABLE, faite avec une sonde jetable avant d'ecrire ce fichier :
 *    `Http::fake()` SUIT REELLEMENT les 302. La raison est dans l'ordre de la
 *    pile Guzzle -- `HandlerStack::resolve()` parcourt la pile a l'envers, si
 *    bien que le PREMIER pousse est le plus EXTERIEUR. `HandlerStack::create()`
 *    pousse `allow_redirects` avant que Laravel ne pousse son stub de fake :
 *    le middleware de redirection est donc AU-DESSUS du stub, et il re-emet la
 *    requete suivante a travers toute la pile. Sonde jouee :
 *
 *        302 depart.example -> 93.184.216.34/cible  =>  status 200, body "ARRIVEE"
 *
 *    C'est ce qui rend les cas de redirection ci-dessous honnetes : le banc
 *    suit VRAIMENT la redirection, avec le VRAI middleware de production. Le
 *    temoin `TEMOIN DU BANC` ci-dessous refait cette mesure a chaque execution,
 *    pour qu'aucun de ces tests ne puisse passer au vert parce que le banc
 *    aurait cesse de suivre les redirections.
 *
 * 3. Chaque refus est double d'un TEMOIN qui prouve que le chemin nominal
 *    passe encore. Sans lui, « la cible interne n'a pas ete atteinte » serait
 *    aussi vrai d'un service completement casse.
 *
 * REGLE DE MESURE : aucun `expect()->toContain($aiguille, $message)` ici. Le
 * second argument de `toContain()` est VARIADIQUE en Pest : un message y
 * devient une valeur a chercher, et la garde rougit eternellement. On passe
 * par `assertStringContainsString` / `assertContains`.
 */

use App\Models\Company;
use App\Services\Domain\DomainFinderService;
use App\Services\Http\ProxiedHttpClient;
use App\Services\Http\SsrfGuard;
use App\Services\Legal\MentionsLegalesScraperService;
use Illuminate\Support\Facades\Http;

/**
 * IP publique (example.com). Litterale => zero DNS => verdict deterministe.
 * Aucune requete ne part reellement : tout est intercepte par Http::fake().
 */
const SSRF_IP_PUBLIQUE = '93.184.216.34';

/**
 * Les quatre familles nommees au constat. Toutes litterales.
 *  - 169.254.169.254 : metadonnees AWS/GCP, la cible de reference d'un SSRF
 *  - 127.0.0.1       : boucle locale (Redis, Postgres, Horizon...)
 *  - 10.x / 192.168.x: reseaux prives RFC 1918
 */
function ssrfAdressesInternes(): array
{
    return [
        'metadonnees AWS/GCP' => '169.254.169.254',
        'boucle locale' => '127.0.0.1',
        'prive 10/8' => '10.1.2.3',
        'prive 192.168/16' => '192.168.1.10',
    ];
}

/**
 * Corps HTML d'au moins 500 octets de texte (MIN_BODY_LENGTH du scraper), avec
 * un email reconnaissable. Si cet email ressort d'un test de refus, c'est que
 * la cible interne a bel et bien ete lue et parsee.
 */
function ssrfCorpsAvecEmail(string $email): string
{
    $remplissage = str_repeat(
        'Mentions legales de la societe. Siege social, capital, RCS, directeur de la publication. ',
        10,
    );

    return "<html><body><p>{$remplissage}</p><p>Contact : {$email}</p></body></html>";
}

// ═══════════════════════════════════════════════════════════════════════════
// 0. TEMOINS DU BANC — sans eux, tout le reste peut etre vert pour rien
// ═══════════════════════════════════════════════════════════════════════════

test('TEMOIN DU BANC — SsrfGuard dit oui a l IP publique et non aux quatre internes', function () {
    $public = SsrfGuard::check('http://' . SSRF_IP_PUBLIQUE . '/');
    expect($public['ok'])->toBeTrue(
        "L'IP temoin est refusee par la garde elle-meme : tous les temoins de ce fichier "
        . 'deviendraient impossibles a distinguer d\'un refus legitime.',
    );

    foreach (ssrfAdressesInternes() as $libelle => $ip) {
        $verdict = SsrfGuard::check("http://{$ip}/");
        expect($verdict['ok'])->toBeFalse(
            "SsrfGuard::check() accepte {$ip} ({$libelle}). La garde elle-meme est cassee ; "
            . 'inutile de verifier qui l\'appelle.',
        );
    }
});

/**
 * Ce test est le CONTRE-POIDS de la seule detente consentie au banc : un nom
 * d'hote qui ne resout pas y est tolere (sinon la suite unitaire se retrouverait
 * SUR LE RESEAU, cf. SsrfGuard::requireDnsResolution()). Il verifie que ce n'est
 * QUE le banc, et que le comportement EXPEDIE reste fail-closed.
 */
test('TEMOIN — en production, un hote qui ne resout pas est REFUSE (fail-closed)', function () {
    $ancien = getenv('SSRF_GUARD_REQUIRE_DNS');
    putenv('SSRF_GUARD_REQUIRE_DNS=1');

    try {
        expect(SsrfGuard::requireDnsResolution())->toBeTrue(
            'La variable ne reprend pas la main : le reste de ce test ne mesurerait rien.',
        );

        $verdict = SsrfGuard::check('http://hote-qui-ne-resout-pas.invalid/');

        expect($verdict['ok'])->toBeFalse(
            'Avec la resolution exigee — le comportement EXPEDIE en production — un hote sans '
            . 'enregistrement DNS doit etre refuse. La detente du banc a fuite dans le produit.',
        );
        expect($verdict['reason'])->toBe('dns_no_records');
    } finally {
        putenv($ancien === false ? 'SSRF_GUARD_REQUIRE_DNS' : "SSRF_GUARD_REQUIRE_DNS={$ancien}");
    }

    // Et sur le banc, sans la variable, le meme hote passe : c'est la detente,
    // et elle est bien LA, visible, pas cachee dans un coin.
    expect(SsrfGuard::check('http://hote-qui-ne-resout-pas.invalid/')['ok'])->toBeTrue(
        "Le banc n'accorde plus la detente DNS : une douzaine de tests du depot qui utilisent "
        . 'des domaines fictifs (alive.test, gone.test...) vont rougir sans parler de SSRF.',
    );
});

test('TEMOIN DU BANC — le banc SUIT reellement une 302, avec le middleware de production', function () {
    Http::fake([
        'depart.example/*' => Http::response('', 302, ['Location' => 'http://' . SSRF_IP_PUBLIQUE . '/cible']),
        SSRF_IP_PUBLIQUE . '/*' => Http::response('ARRIVEE', 200),
    ]);

    $reponse = Http::get('http://depart.example/');

    expect($reponse->body())->toBe(
        'ARRIVEE',
        'Http::fake() ne suit plus les redirections sur ce banc. Tous les tests C19-003 '
        . 'ci-dessous passeraient alors au vert SANS RIEN PROUVER : « la cible interne n\'a pas '
        . 'ete atteinte » serait vrai parce que AUCUNE redirection n\'est suivie, correctif ou pas.',
    );
});

// ═══════════════════════════════════════════════════════════════════════════
// 1. C19-001 — MentionsLegalesScraperService (URL = companies.website)
// ═══════════════════════════════════════════════════════════════════════════

test('C19-001 — MentionsLegales refuse une URL interne et n emet AUCUNE requete', function () {
    foreach (ssrfAdressesInternes() as $libelle => $ip) {
        Http::fake(['*' => Http::response(ssrfCorpsAvecEmail('butin@interne-atteinte.fr'), 200)]);

        $recolte = (new MentionsLegalesScraperService)->harvestFromWebsite("http://{$ip}");

        Http::assertNothingSent();

        expect($recolte['emails'])->toBe(
            [],
            "MentionsLegalesScraperService a scrape {$ip} ({$libelle}) et en a rapporte des emails. "
            . 'La garde SSRF n\'est pas branchee sur cette entree : `$company->website` vient de la '
            . 'base, il suffit d\'y ecrire une adresse interne pour faire lire l\'infrastructure.',
        );
    }
});

test('TEMOIN C19-001 — MentionsLegales scrape toujours un site public', function () {
    Http::fake([
        SSRF_IP_PUBLIQUE . '/*' => Http::response(ssrfCorpsAvecEmail('contact@site-public.fr'), 200),
    ]);

    $recolte = (new MentionsLegalesScraperService)->harvestFromWebsite('http://' . SSRF_IP_PUBLIQUE);

    $this->assertContains(
        'contact@site-public.fr',
        $recolte['emails'],
        'Le scraper ne rapporte plus rien sur une adresse PUBLIQUE : la garde a ete branchee trop '
        . 'large et le service est mort. Un refus generalise n\'est pas un correctif.',
    );
});

// ═══════════════════════════════════════════════════════════════════════════
// 2. C19-001 — DomainFinderService (URL = companies.website / signals.legal.siteweb)
// ═══════════════════════════════════════════════════════════════════════════

test('C19-001 — DomainFinder::revalidateBatch n appelle jamais une adresse interne', function () {
    foreach (ssrfAdressesInternes() as $libelle => $ip) {
        Http::fake(['*' => Http::response('<html>ok</html>', 200)]);

        $entreprise = new Company(['denomination' => 'Co', 'website' => "http://{$ip}/"]);
        $entreprise->id = 1;

        $resultat = (new DomainFinderService)->revalidateBatch([$entreprise]);

        // Le juge, c'est ceci : AUCUNE requete ne doit etre partie. La passe 3
        // tourne sur des millions de lignes, 400 requetes par salve.
        Http::assertNothingSent();

        expect($resultat)->toBe(
            [1 => false],
            "La passe 3 a rendu VIVANT pour {$ip} ({$libelle}) : une adresse interne serait "
            . 'conservee comme lead, et re-scrapee a chaque passage.',
        );
    }
});

test('TEMOIN C19-001 — revalidateBatch interroge toujours un site public et le declare vivant', function () {
    Http::fake([SSRF_IP_PUBLIQUE . '/*' => Http::response('<html>ok</html>', 200)]);

    $entreprise = new Company(['denomination' => 'Co', 'website' => 'http://' . SSRF_IP_PUBLIQUE . '/']);
    $entreprise->id = 7;

    $resultat = (new DomainFinderService)->revalidateBatch([$entreprise]);

    expect($resultat)->toBe(
        [7 => true],
        'La re-validation ne marche plus sur une adresse PUBLIQUE : la garde a ete branchee trop large.',
    );
    Http::assertSentCount(1);
});

test('C19-001 — DomainFinder::find refuse un signals.legal.siteweb interne', function () {
    Http::fake(['*' => Http::response('<html>ok</html>', 200)]);

    foreach (ssrfAdressesInternes() as $libelle => $ip) {
        $entreprise = new Company(['denomination' => 'Acme SA', 'city_name' => 'Paris']);
        $entreprise->signals = ['legal' => ['siteweb' => "http://{$ip}/admin"]];

        $trouve = (new DomainFinderService)->find($entreprise);

        expect($trouve)->toBeNull(
            "DomainFinder::find() a rendu l'adresse interne {$ip} ({$libelle}) comme site officiel. "
            . 'Cette valeur est ensuite ecrite dans `companies.website` et re-scrapee par tout le '
            . 'reste de la chaine : un seul champ empoisonne se propage a trois services.',
        );
    }
});

test('TEMOIN C19-001 — find() rend toujours un siteweb public canonicalise', function () {
    Http::fake(['*' => Http::response('<html>ok</html>', 200)]);

    $entreprise = new Company(['denomination' => 'Acme SA', 'city_name' => 'Paris']);
    $entreprise->signals = ['legal' => ['siteweb' => 'https://www.acme.fr/about']];

    expect((new DomainFinderService)->find($entreprise))->toBe(
        'https://acme.fr/',
        'La strategie 1 ne rend plus rien sur une URL publique : la garde a ete branchee trop large.',
    );
});

// ═══════════════════════════════════════════════════════════════════════════
// 3. C19-001 — ProxiedHttpClient (le 3e consommateur : URL heritee de l appelant)
// ═══════════════════════════════════════════════════════════════════════════

test('C19-001 — ProxiedHttpClient refuse une adresse interne', function () {
    foreach (ssrfAdressesInternes() as $libelle => $ip) {
        Http::fake(['*' => Http::response('METADONNEES', 200)]);

        $leve = false;
        try {
            app(ProxiedHttpClient::class)->request()->get("http://{$ip}/latest/meta-data/");
        } catch (Throwable $e) {
            $leve = true;
            $this->assertStringContainsString(
                'SSRF',
                $e->getMessage(),
                "ProxiedHttpClient a bien leve sur {$ip}, mais pas pour un motif SSRF : "
                . 'le refus vient d\'ailleurs, la garde n\'est pas prouvee.',
            );
        }

        expect($leve)->toBeTrue(
            "ProxiedHttpClient a laisse passer {$ip} ({$libelle}). C'est le client HTTP partage des "
            . 'scrapes « risques » (Pages Jaunes via proxy Webshare) : il herite d\'une URL de '
            . 'l\'appelant et ne verifiait rien.',
        );
        Http::assertNothingSent();
    }
});

test('TEMOIN C19-001 — ProxiedHttpClient laisse passer une adresse publique', function () {
    Http::fake([SSRF_IP_PUBLIQUE . '/*' => Http::response('PAGE', 200)]);

    $reponse = app(ProxiedHttpClient::class)->request()->get('http://' . SSRF_IP_PUBLIQUE . '/recherche');

    expect($reponse->body())->toBe(
        'PAGE',
        'Le client proxifie refuse desormais une adresse PUBLIQUE : le scrape Pages Jaunes est mort.',
    );
});

// ═══════════════════════════════════════════════════════════════════════════
// 4. C19-003 — la redirection vers un hote interne n est pas suivie
// ═══════════════════════════════════════════════════════════════════════════

test('C19-003 — MentionsLegales ne suit pas une 302 vers 169.254.169.254', function () {
    Http::fake([
        SSRF_IP_PUBLIQUE . '/*' => Http::response('', 302, ['Location' => 'http://169.254.169.254/latest/meta-data/']),
        '169.254.169.254/*' => Http::response(ssrfCorpsAvecEmail('butin@metadonnees-lues.fr'), 200),
    ]);

    $recolte = (new MentionsLegalesScraperService)->harvestFromWebsite('http://' . SSRF_IP_PUBLIQUE);

    Http::assertNotSent(fn ($requete) => str_contains($requete->url(), '169.254.169.254'));

    // ⚠️ `assertNotContains` et NON `expect()->not->toContain($aiguille, $message)` :
    // le 2e argument de `toContain()` est VARIADIQUE en Pest, le message y
    // deviendrait une seconde aiguille a chercher et la garde ne mesurerait plus
    // ce qu'elle annonce.
    $this->assertNotContains(
        'butin@metadonnees-lues.fr',
        $recolte['emails'],
        'Le scraper a suivi la redirection jusqu\'au service de metadonnees et en a PARSE le corps. '
        . 'La garde ne regarde que l\'URL de depart (C19-003) : un site public suffit a rediriger '
        . 'le backend vers l\'infrastructure.',
    );
});

test('C19-003 — revalidateBatch ne suit pas une 302 vers une adresse privee', function () {
    Http::fake([
        SSRF_IP_PUBLIQUE . '/*' => Http::response('', 302, ['Location' => 'http://10.1.2.3/interne']),
        '10.1.2.3/*' => Http::response('INTERNE', 200),
    ]);

    $entreprise = new Company(['denomination' => 'Co', 'website' => 'http://' . SSRF_IP_PUBLIQUE . '/']);
    $entreprise->id = 3;

    (new DomainFinderService)->revalidateBatch([$entreprise]);

    Http::assertNotSent(fn ($requete) => str_contains($requete->url(), '10.1.2.3'));
});

test('C19-003 — ProxiedHttpClient ne suit pas une 302 vers la boucle locale', function () {
    Http::fake([
        SSRF_IP_PUBLIQUE . '/*' => Http::response('', 302, ['Location' => 'http://127.0.0.1:6379/']),
        '127.0.0.1*' => Http::response('REDIS', 200),
    ]);

    try {
        app(ProxiedHttpClient::class)->request()->get('http://' . SSRF_IP_PUBLIQUE . '/recherche');
    } catch (Throwable $e) {
        // Un refus par exception est le comportement attendu : on le laisse passer,
        // c'est `assertNotSent` ci-dessous qui juge.
    }

    Http::assertNotSent(fn ($requete) => str_contains($requete->url(), '127.0.0.1'));
});

test('TEMOIN C19-003 — une 302 vers un autre hote PUBLIC est toujours suivie', function () {
    Http::fake([
        'depart-public.example/*' => Http::response('', 302, ['Location' => 'http://' . SSRF_IP_PUBLIQUE . '/arrivee']),
        SSRF_IP_PUBLIQUE . '/*' => Http::response('ARRIVEE', 200),
    ]);

    $reponse = app(ProxiedHttpClient::class)->request()->get('http://depart-public.example/');

    expect($reponse->body())->toBe(
        'ARRIVEE',
        'Le correctif C19-003 bloque desormais TOUTE redirection, y compris vers un hote public. '
        . 'Ce n\'est pas une garde SSRF, c\'est une panne : la moitie des sites francais redirigent '
        . 'http -> https ou apex -> www.',
    );
});
