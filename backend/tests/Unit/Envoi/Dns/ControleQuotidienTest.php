<?php

use App\Envoi\Dns\ConfigurationDomaine;
use App\Envoi\Dns\ControleQuotidien;
use App\Envoi\Dns\DepotControlesDns;
use App\Envoi\Dns\RapportControle;
use Tests\Unit\Envoi\DepotMemoire;
use Tests\Unit\Envoi\Dns\FauxResolveurDns;

require_once __DIR__ . '/FauxResolveurDns.php';
require_once __DIR__ . '/../DepotMemoire.php';

const WS_DNS = '00000000-0000-4000-8000-0000000000d1';

final class DepotControlesMemoire implements DepotControlesDns
{
    /** @var list<array{string, RapportControle}> */
    public array $rapports = [];

    /** @var list<array{string, string}> */
    public array $verifies = [];

    public function enregistrer(string $workspaceId, RapportControle $rapport, DateTimeImmutable $quand): void
    {
        $this->rapports[] = [$workspaceId, $rapport];
    }

    public function marquerVerifie(string $workspaceId, string $domaine, DateTimeImmutable $quand): void
    {
        $this->verifies[] = [$workspaceId, $domaine];
    }
}

function quotidien(FauxResolveurDns $f, DepotControlesMemoire $c, DepotMemoire $j): ControleQuotidien
{
    return new ControleQuotidien($f, $c, $j, fn () => new DateTimeImmutable('2026-11-11 06:00:00'));
}

function dom(): ConfigurationDomaine
{
    return new ConfigurationDomaine('envoi.example.test', 'sel1', 'mx.envoi.example.test', '192.0.2.10');
}

test('domaine conforme : rapport enregistré, jour marqué vérifié, rien au journal', function () {
    $c = new DepotControlesMemoire;
    $j = new DepotMemoire;
    $r = quotidien(FauxResolveurDns::conforme(), $c, $j)->controler(WS_DNS, dom());
    expect($r->pret())->toBeTrue()
        ->and($c->rapports)->toHaveCount(1)
        ->and($c->verifies)->toBe([[WS_DNS, 'envoi.example.test']])
        ->and($j->journal)->toBe([]);
});

test('PTR incohérent : rapport enregistré, domaine PAS marqué vérifié (la porte le refuse), pas de pause', function () {
    $f = FauxResolveurDns::conforme();
    $f->ptr['192.0.2.10'] = ['autre.envoi.example.test'];
    $c = new DepotControlesMemoire;
    $j = new DepotMemoire;
    $r = quotidien($f, $c, $j)->controler(WS_DNS, dom());
    expect($r->pret())->toBeFalse()
        ->and($c->rapports)->toHaveCount(1)
        ->and($c->verifies)->toBe([])
        ->and($j->journal)->toBe([]);
});

test('DMARC absent : pause journalisée, pas marqué vérifié', function () {
    $f = FauxResolveurDns::conforme();
    unset($f->txt['_dmarc.envoi.example.test']);
    $c = new DepotControlesMemoire;
    $j = new DepotMemoire;
    $r = quotidien($f, $c, $j)->controler(WS_DNS, dom());
    expect($r->pause())->toBeTrue()
        ->and($c->verifies)->toBe([])
        ->and($j->journal)->toHaveCount(1)
        ->and($j->journal[0][0])->toBe('pause_domaine_dmarc_absent');
});

test('le contrôle ne lève jamais le moindre envoi et ne mesure aucune ouverture', function () {
    $src = (string) file_get_contents(base_path('app/Envoi/Dns/ControleQuotidien.php'));
    expect(strtolower($src))->not->toContain('pixel')->not->toContain('open_track')->not->toContain('ouverture');
});
