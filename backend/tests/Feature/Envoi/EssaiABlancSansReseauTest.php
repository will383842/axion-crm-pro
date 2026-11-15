<?php

/**
 * Envoi L8-ESSAI — l'essai à blanc n'ouvre AUCUN réseau : aucune requête HTTP, aucun flux http(s) (garde de flux remplacée par une
 * espionne qui rougit au premier appel). Données fictives (`*.example.test`).
 */

use App\Envoi\Orchestration\EssaiABlanc;
use App\Support\WorkspaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Support\DoublonsFixtures as F;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/** Espionne de flux : toute ouverture est comptée, puis refusée. */
final class EspionneFlux
{
    public static int $ouvertures = 0;

    /** @var resource|null */
    public $context;

    public function stream_open(string $path, string $mode, int $options, ?string &$opened): bool
    {
        self::$ouvertures++;

        return false;
    }

    /** @return array<string, mixed>|false */
    public function url_stat(string $path, int $flags): array|false
    {
        self::$ouvertures++;

        return false;
    }
}

afterEach(function () {
    foreach (['http', 'https'] as $schema) {
        @stream_wrapper_restore($schema);
    }
    try {
        WorkspaceContext::clear();
    } catch (Throwable) {
    }
});

test('aucune requête HTTP et aucun flux réseau pendant l\'essai', function () {
    Http::fake();
    EspionneFlux::$ouvertures = 0;
    foreach (['http', 'https'] as $schema) {
        stream_wrapper_unregister($schema);
        stream_wrapper_register($schema, EspionneFlux::class);
    }

    $rapport = app(EssaiABlanc::class)->executer(F::espace('zz-essai-reseau'), 'test-essai');

    Http::assertNothingSent();
    expect(EspionneFlux::$ouvertures)->toBe(0)->and($rapport->reussi())->toBeTrue();
});

test('une requête HTTP perdue pendant l\'essai fait rougir : les requêtes non prévues sont interdites', function () {
    $essai = new EssaiABlanc(function () {
        Http::get('https://exemple.example.test/'); // levée par `preventStrayRequests`, posée par l'essai

        return new App\Envoi\Simulateur\FauxServeurEnvoi;
    });

    expect(fn () => $essai->executer(F::espace('zz-essai-reseau'), 'test-essai'))->toThrow(Throwable::class);
});
