<?php

/**
 * C3-4 / E6 — garde de conformité de sortie : fonction pure, aucun réseau, aucune base. Domaines en `*.example.test`.
 */

use App\Envoi\Exceptions\EnvoiRefuse;
use App\Envoi\Serveur\GardeConformiteSortie;

function gcsSortieMessage(array $retirer = [], array $remplacer = [], string $enPlus = ''): string
{
    $e = [
        'From' => 'from@x.example.test',
        'List-Unsubscribe' => '<https://d.x.example.test/u/abc>, <mailto:u@x.example.test>',
        'List-Unsubscribe-Post' => 'List-Unsubscribe=One-Click',
        'Subject' => 'Sujet',
    ];
    foreach ($retirer as $n) {
        unset($e[$n]);
    }
    $t = '';
    foreach (array_replace($e, $remplacer) as $n => $v) {
        $t .= "{$n}: {$v}\r\n";
    }

    return $t . $enPlus . "\r\nCorps";
}

function gcsSortieRefus(string $rfc): void
{
    try {
        (new GardeConformiteSortie)->verifier($rfc);
    } catch (EnvoiRefuse $e) {
        expect($e->getMessage())->toBe(EnvoiRefuse::conditionNonRemplie('conformite_sortie')->getMessage());

        return;
    }
    throw new RuntimeException('EnvoiRefuse attendue.');
}

test('message conforme ⇒ accepté', function () {
    (new GardeConformiteSortie)->verifier(gcsSortieMessage());
    expect(true)->toBeTrue();
});

test('en-tête plié sur plusieurs lignes et noms en casse libre ⇒ accepté', function () {
    $rfc = "from: a@x.example.test\r\nlist-unsubscribe: <https://d.x.example.test/u/abc>,\r\n <mailto:u@x.example.test>\r\nLIST-UNSUBSCRIBE-POST: List-Unsubscribe=One-Click\r\n\r\nCorps";
    (new GardeConformiteSortie)->verifier($rfc);
    expect(true)->toBeTrue();
});

test('refus : List-Unsubscribe absent, sans https, sans mailto, vide', function (string $cas) {
    $rfc = match ($cas) {
        'absent' => gcsSortieMessage(['List-Unsubscribe']),
        'sans_https' => gcsSortieMessage([], ['List-Unsubscribe' => '<http://d.x.example.test/u>, <mailto:u@x.example.test>']),
        'sans_mailto' => gcsSortieMessage([], ['List-Unsubscribe' => '<https://d.x.example.test/u>']),
        'mailto_vide' => gcsSortieMessage([], ['List-Unsubscribe' => '<https://d.x.example.test/u>, <mailto:>']),
        'https_vide' => gcsSortieMessage([], ['List-Unsubscribe' => '<https://>, <mailto:u@x.example.test>']),
    };
    gcsSortieRefus($rfc);
})->with(['absent', 'sans_https', 'sans_mailto', 'mailto_vide', 'https_vide']);

test('refus : One-Click absent ou différent', function (string $cas) {
    gcsSortieRefus($cas === 'absent' ? gcsSortieMessage(['List-Unsubscribe-Post']) : str_replace('One-Click', 'Autre', gcsSortieMessage()));
})->with(['absent', 'autre']);

test('refus : un en-tête X-Envoi-* (casse libre) ou un List-Unsubscribe en double', function (string $cas) {
    gcsSortieRefus(match ($cas) {
        'x_envoi' => gcsSortieMessage([], [], "X-Envoi-Jeton: abc\r\n"),
        'x_envoi_casse' => gcsSortieMessage([], [], "x-envoi-source: abc\r\n"),
        'double' => gcsSortieMessage([], [], "List-Unsubscribe: <https://z.example.test>, <mailto:z@x.example.test>\r\n"),
    });
})->with(['x_envoi', 'x_envoi_casse', 'double']);

test('un en-tête présent seulement dans le CORPS ne compte pas (témoin : le corps n\'est jamais lu comme en-tête)', function () {
    gcsSortieRefus("From: a@x.example.test\r\nSubject: S\r\n\r\nList-Unsubscribe: <https://d.x.example.test>, <mailto:u@x.example.test>\r\nList-Unsubscribe-Post: List-Unsubscribe=One-Click");
});

test('refus : message vide ou sans ligne vide de fin d\'en-têtes', function () {
    gcsSortieRefus('');
    gcsSortieRefus('Subject: S');
});
