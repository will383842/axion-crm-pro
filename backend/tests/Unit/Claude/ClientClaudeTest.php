<?php

use App\Crm\Claude\ClientClaude;
use App\Crm\Claude\ErreurClaude;
use App\Crm\Claude\PrixClaude;
use App\Crm\Claude\RotationCles;
use Illuminate\Http\Client\Request;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;

const CLE_A = 'sk-test-AAAA-secret';
const CLE_B = 'sk-test-BBBB-secret';
const CLE_C = 'sk-test-CCCC-secret';
const SCHEMA = ['type' => 'object', 'required' => ['site'], 'properties' => ['site' => ['type' => 'string']]];

/** Rotation sans base : l'état est gardé en mémoire. */
function rotationMemoire(array $liste): RotationCles
{
    return new class($liste, ['compte_a' => CLE_A, 'compte_b' => CLE_B, 'compte_c' => CLE_C, 'vide' => '']) extends RotationCles
    {
        /** @var array<string,array{etat:string,depuis:Carbon}> */
        public array $etat = [];

        protected function lireEtats(): array
        {
            return $this->etat;
        }

        protected function ecrireEtat(string $alias, string $etat): void
        {
            $this->etat[$alias] = ['etat' => $etat, 'depuis' => Carbon::now('UTC')];
        }
    };
}

/** Compteur global en mémoire : somme de TOUS les postes et de toutes les clés. */
function compteur(?int $plafond = null): object
{
    return new class($plafond ?? PrixClaude::PLAFOND_TOTAL_MICRO)
    {
        public int $total = 0;

        public int $appelsReserver = 0;

        public function __construct(private readonly int $plafond) {}

        public function reserver(): Closure
        {
            return function (string $poste, int $micro): bool {
                $this->appelsReserver++;
                if ($this->total + $micro > $this->plafond) {
                    return false;
                }
                $this->total += $micro;

                return true;
            };
        }

        public function regler(): Closure
        {
            return function (string $poste, int $reserve, int $reel): void {
                $this->total += $reel - $reserve;
            };
        }
    };
}

function client(RotationCles $r, object $c): ClientClaude
{
    return new ClientClaude($r, $c->reserver(), $c->regler());
}

function ok(array $usage = ['input_tokens' => 1000, 'output_tokens' => 50]): array
{
    return ['content' => [['type' => 'text', 'text' => '{"site":"https://exemple.test"}']], 'usage' => $usage];
}

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-12-13 10:00:00', 'UTC'));
    config(['services.anthropic.modele' => 'claude-haiku-5-5', 'services.anthropic.version_api' => '2023-06-01', 'services.anthropic.domaines_bloques' => ['bloque.test']]);
    Http::preventStrayRequests();
});

afterEach(fn () => Carbon::setTestNow());

it('refuse avant tout appel quand aucun alias n\'est valide', function () {
    Http::fake();
    $c = compteur();
    expect(fn () => client(rotationMemoire(['inconnu', 'vide']), $c)->appeler('arbitrage', 'x', SCHEMA))
        ->toThrow(fn (ErreurClaude $e) => $e->type === ErreurClaude::AUCUNE_CLE);
    Http::assertNothingSent();
    expect($c->appelsReserver)->toBe(0);
});

it('bascule sur B quand A est épuisée : même fiche, coût compté une seule fois', function () {
    Http::fake(['*' => Http::sequence()
        ->push(['type' => 'error', 'error' => ['type' => 'invalid_request_error', 'message' => 'Your Credit Balance is too low']], 400)
        ->push(ok())]);
    $r = rotationMemoire(['compte_a', 'compte_b']);
    $c = compteur();

    $res = client($r, $c)->appeler('arbitrage', 'fiche 1', SCHEMA);

    expect($res['alias'])->toBe('compte_b')->and($res['cout_micro'])->toBe(1250)
        ->and($c->total)->toBe(1250)->and($c->appelsReserver)->toBe(1)
        ->and($r->etat['compte_a']['etat'])->toBe('epuisee');
    Http::assertSentCount(2);
    $cles = [];
    Http::assertSent(function (Request $q) use (&$cles) {
        $cles[] = $q->header('x-api-key')[0];

        return $q['messages'][0]['content'] === 'fiche 1';
    });
    expect($cles)->toBe([CLE_A, CLE_B]);
});

it('A puis B épuisées : C prend le relais', function () {
    Http::fake(function (Request $q) {
        return match ($q->header('x-api-key')[0]) {
            CLE_A => Http::response(['error' => ['type' => 'billing_error', 'message' => 'x']], 402),
            CLE_B => Http::response(['error' => ['type' => 'invalid_request_error', 'message' => 'credit balance too low']], 400),
            default => Http::response(ok(), 200),
        };
    });
    $r = rotationMemoire(['compte_a', 'compte_b', 'compte_c']);
    expect(client($r, compteur())->appeler('arbitrage', 'f', SCHEMA)['alias'])->toBe('compte_c');
});

it('les trois clés épuisées : code 6 et plus aucun appel', function () {
    Http::fake(['*' => Http::response(['error' => ['type' => 'billing_error', 'message' => 'x']], 402)]);
    $r2 = rotationMemoire(['compte_a', 'compte_b', 'compte_c']);
    $c = compteur();
    try {
        client($r2, $c)->appeler('arbitrage', 'f', SCHEMA);
        $this->fail('attendu : toutes épuisées');
    } catch (ErreurClaude $e) {
        expect($e->type)->toBe(ErreurClaude::TOUTES_EPUISEES);
    }
    expect(ErreurClaude::CODE_SORTIE_TOUTES_EPUISEES)->toBe(6)->and($c->total)->toBe(0);
    Http::assertSentCount(3);
    expect(fn () => client($r2, $c)->appeler('arbitrage', 'f2', SCHEMA))->toThrow(ErreurClaude::class);
    Http::assertSentCount(3);
});

it('écarte une clé invalide (401, 403) et bascule', function () {
    Http::fake(['*' => Http::sequence()->push(['error' => ['type' => 'authentication_error']], 401)->push(ok())]);
    $r = rotationMemoire(['compte_a', 'compte_b']);
    client($r, compteur())->appeler('arbitrage', 'f', SCHEMA);
    expect($r->etat['compte_a']['etat'])->toBe('invalide');
});

it('429 sur A : pas de bascule ; une clé épuisée n\'est retentée qu\'après 24 h', function () {
    Http::fake(['*' => Http::response(['error' => ['type' => 'rate_limit_error']], 429)]);
    $r = rotationMemoire(['compte_a', 'compte_b']);
    expect(fn () => client($r, compteur())->appeler('arbitrage', 'f', SCHEMA))
        ->toThrow(fn (ErreurClaude $e) => $e->type === ErreurClaude::PAUSE);
    Http::assertSentCount(1);
    expect($r->etat)->toBe([])->and($r->courante())->toBe('compte_a');

    $r->marquer('compte_a', 'epuisee');
    expect($r->courante())->toBe('compte_b');
    Carbon::setTestNow(Carbon::now('UTC')->addHours(23));
    expect($r->courante())->toBe('compte_b');
    Carbon::setTestNow(Carbon::now('UTC')->addHours(2));
    expect($r->courante())->toBe('compte_a');
    $r->marquer('compte_a', 'invalide');
    Carbon::setTestNow(Carbon::now('UTC')->addDays(30));
    expect($r->courante())->toBe('compte_b');
    $r->reactiver('compte_a');
    expect($r->courante())->toBe('compte_a');
});

it('le plafond global est la somme des trois clés', function () {
    Http::fake(['*' => Http::response(ok(), 200)]);
    $r = rotationMemoire(['compte_a', 'compte_b', 'compte_c']);
    $c = compteur(PrixClaude::plafondTotalMicro(0.004)); // 4 000 micro-dollars
    $cl = client($r, $c);

    foreach (['compte_a', 'compte_b', 'compte_c'] as $alias) {
        expect($cl->appeler('arbitrage', 'f', SCHEMA)['alias'])->toBe($alias === 'compte_a' ? 'compte_a' : $r->courante());
        $r->marquer($alias, 'epuisee');
    }
    expect($c->total)->toBe(3750);
    expect(fn () => $cl->appeler('arbitrage', 'f', SCHEMA))
        ->toThrow(fn (ErreurClaude $e) => $e->type === ErreurClaude::BUDGET);
});

it('ne met jamais la clé dans le journal ni dans une exception', function () {
    $textes = [];
    Event::listen(MessageLogged::class, function (MessageLogged $e) use (&$textes) {
        $textes[] = $e->message . json_encode($e->context);
    });
    Http::fake(['*' => Http::sequence()
        ->push(['error' => ['type' => 'authentication_error', 'message' => 'bad key ' . CLE_A]], 401)
        ->push(['error' => ['type' => 'server_error', 'message' => 'echo ' . CLE_B]], 500)]);
    try {
        client(rotationMemoire(['compte_a', 'compte_b']), compteur())->appeler('arbitrage', 'f', SCHEMA);
    } catch (ErreurClaude $e) {
        $textes[] = $e->getMessage();
    }
    expect($textes)->not->toBe([]);
    foreach ($textes as $t) {
        expect($t)->not->toContain('sk-test')->and($t)->not->toContain('echo');
    }
});

it('envoie un corps sans temperature ni réflexion, avec max_uses 1 pour le poste A', function () {
    Http::fake(['*' => Http::response(ok(), 200)]);
    $cl = client(rotationMemoire(['compte_a']), compteur());
    $cl->appeler(ClientClaude::POSTE_RECHERCHE, 'f', SCHEMA);
    $cl->appeler('arbitrage', 'f', SCHEMA);

    $corps = [];
    Http::assertSent(function (Request $q) use (&$corps) {
        $corps[] = $q->data();

        return $q->url() === 'https://api.anthropic.com/v1/messages' && $q->header('anthropic-version')[0] === '2023-06-01';
    });
    [$a, $b] = $corps;
    expect($a)->not->toHaveKey('temperature')->and($a['thinking'])->toBe(['type' => 'disabled'])
        ->and($a['max_tokens'])->toBe(300)->and($a['model'])->toBe('claude-haiku-5-5')
        ->and($a['tools'][0]['type'])->toBe('web_search_20250305')->and($a['tools'][0]['max_uses'])->toBe(1)
        ->and($a['tools'][0]['blocked_domains'])->toBe(['bloque.test'])
        ->and($a['tools'][0]['user_location'])->toBe(['type' => 'approximate', 'country' => 'FR'])
        ->and($b)->not->toHaveKey('temperature')->not->toHaveKey('tools')->and($b['max_tokens'])->toBe(100);
});

it('calcule le coût réel sur l\'usage, recherche comprise (10 000 micro-dollars)', function () {
    Http::fake(['*' => Http::response(ok(['input_tokens' => 2000, 'output_tokens' => 100, 'server_tool_use' => ['web_search_requests' => 1]]), 200)]);
    $c = compteur();
    $res = client(rotationMemoire(['compte_a']), $c)->appeler(ClientClaude::POSTE_RECHERCHE, 'f', SCHEMA);
    expect($res['cout_micro'])->toBe(2000 + 500 + 10_000)->and($res['recherches'])->toBe(1)->and($c->total)->toBe(12_500);
});

it('refuse un modèle autre que Haiku 5.5 sans aucun appel', function () {
    config(['services.anthropic.modele' => 'claude-sonnet-5-5']);
    Http::fake();
    expect(fn () => client(rotationMemoire(['compte_a']), compteur())->appeler('arbitrage', 'f', SCHEMA))
        ->toThrow(InvalidArgumentException::class);
    Http::assertNothingSent();
});

it('type l\'erreur dans le résultat de recherche et la sortie hors schéma, coût réglé quand même', function () {
    $erreur = ['content' => [['type' => 'web_search_tool_result', 'content' => ['type' => 'web_search_tool_result_error', 'error_code' => 'too_many_requests']]], 'usage' => ['input_tokens' => 10, 'output_tokens' => 1]];
    Http::fake(['*' => Http::sequence()->push($erreur)->push(['content' => [['type' => 'text', 'text' => '{"autre":1}']], 'usage' => ['input_tokens' => 10, 'output_tokens' => 1]])]);
    $c = compteur();
    $cl = client(rotationMemoire(['compte_a']), $c);
    expect(fn () => $cl->appeler('arbitrage', 'f', SCHEMA))->toThrow(fn (ErreurClaude $e) => $e->type === ErreurClaude::PAUSE);
    expect(fn () => $cl->appeler('arbitrage', 'f', SCHEMA))->toThrow(fn (ErreurClaude $e) => $e->type === ErreurClaude::SORTIE_INVALIDE);
    expect($c->total)->toBe(30);
});
