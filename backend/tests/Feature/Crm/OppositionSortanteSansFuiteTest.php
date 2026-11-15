<?php

use App\Crm\Outbound\OppositionSortante;
use App\Envoi\Desinscription\TerminerEffetsOpposition;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\QueryException;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class);

/**
 * `signalerSansBloquer()` ne doit jamais envoyer la QueryException brute (valeurs liées : empreinte complète,
 * clé de verrou advisory) au journal, à stderr ni à Sentry, ni via une exception précédente.
 */
function sansFuiteEchec(string $sqlstate, string $empreinte): QueryException
{
    $pdo = new PDOException('SQLSTATE[' . $sqlstate . ']: erreur sur ' . $empreinte);
    $pdo->errorInfo = [$sqlstate, 7, 'erreur sur ' . $empreinte];

    return new QueryException(
        'pgsql',
        'select pg_advisory_xact_lock(hashtextextended(?, 0)) /* a@clients.example.test */',
        ['crm_outbound|consent_optout|business|' . $empreinte, $empreinte, 'a@clients.example.test'],
        $pdo,
    );
}

/**
 * @param  null|Closure(string): mixed  $action  geste à lancer pendant la panne (reçoit l'empreinte) ; défaut : signalerSansBloquer
 * @return array{0: list<Throwable>, 1: list<string>}
 */
function sansFuiteCapter(string $sqlstate, string $empreinte, ?Closure $action = null, bool $transactionLeve = true): array
{
    $signales = [];
    $journal = [];
    $faux = new class($signales) implements ExceptionHandler
    {
        /** @param list<Throwable> $signales */
        public function __construct(public array &$signales) {}

        public function report(Throwable $e): void
        {
            $this->signales[] = $e;
        }

        public function shouldReport(Throwable $e): bool
        {
            return true;
        }

        public function render($request, Throwable $e)
        {
            throw $e;
        }

        public function renderForConsole($output, Throwable $e): void {}
    };
    app()->instance(ExceptionHandler::class, $faux);
    Event::listen(MessageLogged::class, function (MessageLogged $m) use (&$journal) {
        $journal[] = $m->level . ' ' . $m->message . ' ' . json_encode($m->context);
    });
    // Les arguments d'appel des traces doivent être ignorés (comme en production, voir Dockerfile.laravel) :
    // forcé ici pour que le test ne dépende pas du php.ini de la machine, puis restauré.
    $iniAvant = ini_get('zend.exception_ignore_args');
    ini_set('zend.exception_ignore_args', '1');
    try {
        $echec = sansFuiteEchec($sqlstate, $empreinte);
        if ($transactionLeve) {
            DB::shouldReceive('transaction')->andThrow($echec);
        }
        $action ??= static fn (string $e) => expect((new OppositionSortante)->signalerSansBloquer($e, 'business', 'registre_manuel', 'registre_manuel'))->toBeNull();
        $action($empreinte);
    } finally {
        ini_set('zend.exception_ignore_args', $iniAvant === false ? '' : (string) $iniAvant);
    }

    return [$faux->signales, $journal];
}

dataset('sqlstates', ['délai dépassé' => ['57014'], 'erreur ordinaire' => ['23505']]);

/** Contrôle commun : 1 seule exception signalée, neuve, sans précédente, sans fuite (message, trace, journal). */
function sansFuiteVerifier(array $signales, array $journal, string $sqlstate, string $empreinte): void
{
    expect($signales)->toHaveCount(1);
    $e = $signales[0];
    expect($e)->not->toBeInstanceOf(QueryException::class)
        ->and($e->getPrevious())->toBeNull();
    // Précondition : arguments de trace ignorés (forcé par sansFuiteCapter). Sans cela, le premier cadre
    // (exceptionSansFuite($e, $empreinte)) exposerait la QueryException entière : la vraie protection en production
    // est zend.exception_ignore_args=On de Dockerfile.laravel, gardée par un test dédié ci-dessous.
    foreach ($e->getTrace() as $cadre) {
        // Les cadres include/require gardent toujours leur argument (un chemin de fichier, jamais de donnée).
        if (! in_array($cadre['function'] ?? '', ['include', 'include_once', 'require', 'require_once'], true)) {
            expect(array_key_exists('args', $cadre))->toBeFalse();
        }
    }
    $tout = $e->getMessage() . ' ' . $e->getTraceAsString() . ' ' . implode(' ', $journal);
    expect($tout)->not->toContain($empreinte)
        ->not->toContain('a@clients.example.test')
        ->not->toContain('pg_advisory')
        ->not->toContain('select ')
        ->and($e->getMessage())->toContain($sqlstate)->toContain(substr($empreinte, 0, 12));
}

it('Dockerfile.laravel garde zend.exception_ignore_args=On (arguments de trace jamais envoyés)', function () {
    $dockerfile = dirname(base_path()) . '/Dockerfile.laravel';
    expect(is_file($dockerfile))->toBeTrue();
    expect(file_get_contents($dockerfile))->toContain('zend.exception_ignore_args=On');
});

it('signalerSansBloquer ne laisse fuir ni empreinte complète, ni adresse, ni SQL, ni précédente', function (string $sqlstate) {
    $empreinte = hash('sha256', 'a@clients.example.test');
    [$signales, $journal] = sansFuiteCapter($sqlstate, $empreinte);
    sansFuiteVerifier($signales, $journal, $sqlstate, $empreinte);
})->with('sqlstates');

it('TerminerEffetsOpposition ne signale jamais la QueryException brute', function (string $sqlstate) {
    $empreinte = hash('sha256', 'a@clients.example.test');
    $ws = (string) Str::uuid();
    // La transaction EXÉCUTE la fermeture (l'empreinte est lue), puis l'émission de l'événement sortant échoue.
    [$signales, $journal] = sansFuiteCapter($sqlstate, $empreinte, function (string $e) use ($ws, $sqlstate) {
        DB::shouldReceive('connection')->andReturn(Mockery::mock(ConnectionInterface::class)->shouldIgnoreMissing());
        DB::shouldReceive('transaction')->andReturnUsing(static fn (Closure $fn) => $fn());
        DB::shouldReceive('table')->andReturnUsing(static fn () => new class($e)
        {
            public function __construct(private string $empreinte) {}

            public function __call($n, $a)
            {
                return $this;
            }

            public function first(): object
            {
                return (object) ['id' => 1, 'effets_termines_le' => null, 'empreinte_adresse' => $this->empreinte, 'lien_id' => 1, 'canal' => 'email'];
            }

            public function value(): string
            {
                return 'business';
            }
        });
        $effets = new TerminerEffetsOpposition(static function () use ($sqlstate, $e): void {
            throw sansFuiteEchec($sqlstate, $e);
        });
        expect($effets->pour($ws, 1))->toBeFalse();
    }, false);
    sansFuiteVerifier($signales, $journal, $sqlstate, $empreinte);
})->with('sqlstates');

it('crm:outbound-rattraper ne signale jamais la QueryException brute', function (string $sqlstate) {
    $empreinte = hash('sha256', 'a@clients.example.test');
    // Une ligne d'opposition d'origine CRM sans événement sortant : le rattrapage appelle signaler().
    [$signales, $journal] = sansFuiteCapter($sqlstate, $empreinte, function (string $e) {
        DB::shouldReceive('table')->andReturnUsing(static fn () => new class
        {
            public function __call($n, $a)
            {
                return $this;
            }

            public function cursor(): array
            {
                return [(object) ['scope' => 'business', 'email_hash' => hash('sha256', 'a@clients.example.test'), 'source' => 'registre_manuel']];
            }

            public function get(): Collection
            {
                return collect();
            }
        });
        DB::shouldReceive('raw')->andReturn('x');
        Artisan::call('crm:outbound-rattraper', ['--depuis' => '2000-01-01', '--appliquer' => true]);
    });
    sansFuiteVerifier($signales, $journal, $sqlstate, $empreinte);
})->with('sqlstates');
