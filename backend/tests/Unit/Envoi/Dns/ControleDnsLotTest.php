<?php

use App\Envoi\Dns\ControleDnsLot;
use App\Envoi\Dns\ControleQuotidien;
use App\Envoi\Dns\DepotControlesDns;
use App\Envoi\Dns\RapportControle;
use Tests\Unit\Envoi\DepotMemoire;
use Tests\Unit\Envoi\Dns\FauxResolveurDns;

require_once __DIR__ . '/FauxResolveurDns.php';
require_once __DIR__ . '/../DepotMemoire.php';

final class DepotQuiCasseSurLePremier implements DepotControlesDns
{
    public int $appels = 0;

    /** @var list<string> */
    public array $enregistres = [];

    public function enregistrer(string $workspaceId, RapportControle $rapport, DateTimeImmutable $quand): void
    {
        $this->appels++;
        if ($this->appels === 1) {
            throw new RuntimeException('base indisponible secret-a.example.test');
        }
        $this->enregistres[] = $rapport->domaine;
    }

    public function marquerVerifie(string $workspaceId, string $domaine, DateTimeImmutable $quand): void {}
}

function lotConfig(): array
{
    $c = [];
    foreach (['a', 'b'] as $x) {
        $c["$x.example.test"] = ['selecteur' => 'sel1', 'helo' => "mx.$x.example.test", 'ip' => '192.0.2.10'];
    }

    return $c;
}

test('une erreur sur le 1er domaine n arrête pas le lot : le 2e est contrôlé, échec compté, nom absent des avertissements', function () {
    $depot = new DepotQuiCasseSurLePremier;
    $controle = new ControleQuotidien(new FauxResolveurDns, $depot, new DepotMemoire, fn () => new DateTimeImmutable('2026-11-11 06:00:00'));
    $avert = [];
    $lot = new ControleDnsLot($controle, lotConfig(), fn () => ['ws1'], fn () => ['a.example.test', 'b.example.test'], function (string $m) use (&$avert) {
        $avert[] = $m;
    }, fn () => null);

    $echecs = $lot->executer();

    expect($echecs)->toBe(1)
        ->and($depot->enregistres)->toBe(['b.example.test'])
        ->and($avert)->not->toBeEmpty();
    foreach ($avert as $m) {
        expect($m)->not->toContain('example.test');
    }
});

test('une erreur de lecture des domaines d un espace compte un échec et passe à l espace suivant', function () {
    $depot = new DepotQuiCasseSurLePremier;
    $depot->appels = 1; // ne casse plus
    $controle = new ControleQuotidien(new FauxResolveurDns, $depot, new DepotMemoire, fn () => new DateTimeImmutable('2026-11-11 06:00:00'));
    $lire = function (string $ws) {
        if ($ws === 'ws1') {
            throw new RuntimeException('lecture impossible');
        }

        return ['b.example.test'];
    };
    $lot = new ControleDnsLot($controle, lotConfig(), fn () => ['ws1', 'ws2'], $lire, fn () => null, fn () => null);

    expect($lot->executer())->toBe(1)->and($depot->enregistres)->toBe(['b.example.test']);
});
