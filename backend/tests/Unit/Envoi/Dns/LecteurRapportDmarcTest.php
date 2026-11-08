<?php

use App\Envoi\Dns\LecteurRapportDmarc;
use App\Envoi\Dns\RapportDmarcIllisible;

function xmlFactice(): string
{
    return (string) file_get_contents(base_path('tests/fixtures/envoi/rapport_dmarc_factice.xml'));
}

test('lit un rapport agrégé factice : métadonnées, totaux, échecs', function () {
    $r = LecteurRapportDmarc::lire(xmlFactice());
    expect($r->emetteur)->toBe('recepteur-factice.example.test')
        ->and($r->identifiant)->toBe('rapport-factice-0001')
        ->and($r->domaine)->toBe('envoi.example.test')
        ->and($r->debut->getTimestamp())->toBe(1700000000)
        ->and($r->fin->getTimestamp())->toBe(1700086399)
        ->and($r->lignes)->toHaveCount(3)
        ->and($r->total())->toBe(50)
        ->and($r->alignes())->toBe(47)      // dkim OU spf = pass
        ->and($r->enEchec())->toBe(3)
        ->and($r->tauxEchec())->toBe(0.06);
});

test('liste les sources en échec complet', function () {
    $r = LecteurRapportDmarc::lire(xmlFactice());
    expect($r->sourcesEnEchec())->toBe(['192.0.2.99' => 3]);
});

test('refuse un XML invalide', function () {
    expect(fn () => LecteurRapportDmarc::lire('<feedback><oups'))->toThrow(RapportDmarcIllisible::class);
    expect(fn () => LecteurRapportDmarc::lire('<autre/>'))->toThrow(RapportDmarcIllisible::class);
    expect(fn () => LecteurRapportDmarc::lire(''))->toThrow(RapportDmarcIllisible::class);
});

test('refuse toute DOCTYPE / entité (XXE) sans rien lire', function () {
    $mechant = '<?xml version="1.0"?><!DOCTYPE feedback [<!ENTITY x SYSTEM "file:///etc/passwd">]><feedback><report_metadata><org_name>&x;</org_name></report_metadata></feedback>';
    expect(fn () => LecteurRapportDmarc::lire($mechant))->toThrow(RapportDmarcIllisible::class);
});

test('un compte négatif ou non numérique est refusé', function () {
    $xml = str_replace('<count>40</count>', '<count>-5</count>', xmlFactice());
    expect(fn () => LecteurRapportDmarc::lire($xml))->toThrow(RapportDmarcIllisible::class);
});
