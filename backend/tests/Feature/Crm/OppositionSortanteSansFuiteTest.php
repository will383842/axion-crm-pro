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
function sansFuiteCapter(string $sqlstate, string $empreinte, ?Closure $action = null): array
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
    $echec = sansFuiteEchec($sqlstate, $empreinte);
    DB::shouldReceive('transaction')->andThrow($echec);
    $action ??= static fn (string $e) => expect((new OppositionSortante)->signalerSansBloquer($e, 'business', 'registre_manuel', 'registre_manuel'))->toBeNull();
    $action($empreinte);

    return [$faux->signales, $journal];
}

dataset('sqlstates', ['délai dépassé' => ['57014'], 'erreur ordinaire' => ['23505']]);

/** Contrôle commun : 1 seule exception signalée, neuve, sans précédente, sans fuite, y compris dans les arguments de la trace. */
function sansFuiteVerifier(array $signales, array $journal, string $sqlstate, string $empreinte): void
{
    expect($signales)->toHaveCount(1);
    $e = $signales[0];
    expect($e)->not->toBeInstanceOf(QueryException::class)
        ->and($e->getPrevious())->toBeNull();
    // getTraceAsString() coupe déjà les arguments : on contrôle getTrace() (arguments inclus) et le journal.
    $trace = print_r(array_map(static fn (array $f): array => $f['args'] ?? [], $e->getTrace()), true);
    $tout = $e->getMessage() . ' ' . $trace . ' ' . implode(' ', $journal);
    expect($tout)->not->toContain($empreinte)
        ->not->toContain('a@clients.example.test')
        ->not->toContain('pg_advisory')
        ->not->toContain('select ')
        ->and($e->getMessage())->toContain($sqlstate)->toContain(substr($empreinte, 0, 12));
}

it('signalerSansBloquer ne laisse fuir ni empreinte complète, ni adresse, ni SQL, ni précédente', function (string $sqlstate) {
    $empreinte = hash('sha256', 'a@clients.example.test');
    [$signales, $journal] = sansFuiteCapter($sqlstate, $empreinte);
    sansFuiteVerifier($signales, $journal, $sqlstate, $empreinte);
})->with('sqlstates');

it('TerminerEffetsOpposition ne signale jamais la QueryException brute', function (string $sqlstate) {
    $empreinte = hash('sha256', 'a@clients.example.test');
    $ws = (string) Str::uuid();
    // La transaction d'opposition (verrou, signalement sortant) échoue avec une QueryException brute.
    [$signales, $journal] = sansFuiteCapter($sqlstate, $empreinte, function (string $e) use ($ws) {
        DB::shouldReceive('connection')->andReturn(Mockery::mock(ConnectionInterface::class)->shouldIgnoreMissing());
        $effets = new TerminerEffetsOpposition(static function (): void {});
        expect($effets->pour($ws, 1))->toBeFalse();
    });
    expect($signales)->toHaveCount(1);
    expect($signales[0])->not->toBeInstanceOf(QueryException::class)->and($signales[0]->getPrevious())->toBeNull();
    $tout = $signales[0]->getMessage() . ' ' . print_r(array_map(static fn (array $f): array => $f['args'] ?? [], $signales[0]->getTrace()), true);
    expect($tout)->not->toContain($empreinte)->not->toContain('pg_advisory')->not->toContain('select ')
        ->and($signales[0]->getMessage())->toContain($sqlstate);
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
    expect($signales)->toHaveCount(1);
    expect($signales[0])->not->toBeInstanceOf(QueryException::class)->and($signales[0]->getPrevious())->toBeNull();
    $tout = $signales[0]->getMessage() . ' ' . print_r(array_map(static fn (array $f): array => $f['args'] ?? [], $signales[0]->getTrace()), true);
    expect($tout)->not->toContain($empreinte)->not->toContain('pg_advisory')->not->toContain('select ')
        ->and($signales[0]->getMessage())->toContain($sqlstate)->toContain(substr($empreinte, 0, 12));
})->with('sqlstates');
