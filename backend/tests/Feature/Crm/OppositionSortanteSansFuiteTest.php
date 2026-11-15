<?php

use App\Crm\Outbound\OppositionSortante;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Database\QueryException;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
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

/** @return array{0: list<Throwable>, 1: list<string>} */
function sansFuiteCapter(string $sqlstate, string $empreinte): array
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
    $sortante = new OppositionSortante;
    expect($sortante->signalerSansBloquer($empreinte, 'business', 'registre_manuel', 'registre_manuel'))->toBeNull();

    return [$faux->signales, $journal];
}

dataset('sqlstates', ['délai dépassé' => ['57014'], 'erreur ordinaire' => ['23505']]);

it('ne laisse fuir ni empreinte complète, ni adresse, ni SQL, ni exception précédente', function (string $sqlstate) {
    $empreinte = hash('sha256', 'a@clients.example.test');
    [$signales, $journal] = sansFuiteCapter($sqlstate, $empreinte);

    expect($signales)->toHaveCount(1);
    $e = $signales[0];
    expect($e)->not->toBeInstanceOf(QueryException::class);
    for ($c = $e, $n = 0; $c !== null; $c = $c->getPrevious(), $n++) {
        expect($n)->toBe(0); // aucune exception précédente
    }
    $tout = $e->getMessage() . ' ' . $e->getTraceAsString() . ' ' . implode(' ', $journal);
    expect($tout)->not->toContain($empreinte)
        ->not->toContain('a@clients.example.test')
        ->not->toContain('pg_advisory')
        ->not->toContain('select ')
        ->toContain($sqlstate)
        ->toContain(substr($empreinte, 0, 12));
})->with('sqlstates');
