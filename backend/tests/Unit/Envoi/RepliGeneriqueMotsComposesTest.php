<?php

use App\Envoi\Campagnes\RepliGenerique;

/*
 * REPLI-MOT (relectures de #743 et #751) — les termes exclus sont cherchés EN SOUS-CHAÎNE dans la partie locale : une adresse
 * de presse déguisée (`contact.relationspresse`) ne sert pas de repli. Adresses fictives (example.test). Aucune base.
 */

test('REPLI-MOT : un terme exclu collé à un autre mot est refusé', function (string $email) {
    expect(RepliGenerique::admise($email))->toBeFalse();
})->with([
    'contact.relationspresse@x.example.test', 'info-servicejuridique@x.example.test', 'contact_equipemarketing@x.example.test',
    'accueil.mediatheque@x.example.test', 'contact.supportclient@x.example.test', 'info.pressecommunication@x.example.test',
    'contact2.relations-presse@x.example.test',
]);

test('REPLI-MOT : « contact@ » et les formes simples restent admises', function (string $email) {
    expect(RepliGenerique::admise($email))->toBeTrue();
})->with([
    'contact@x.example.test', 'info@x.example.test', 'contact.paris@x.example.test', 'accueil-lyon@x.example.test', 'contact7@x.example.test',
]);

test('REPLI-MOT : choisir ignore une adresse de presse déguisée', function () {
    $c = fn (int $id, string $email) => ['contact_id' => $id, 'email' => $email];
    expect(RepliGenerique::choisir([$c(1, 'contact.relationspresse@x.example.test')]))->toBeNull()
        ->and(RepliGenerique::choisir([$c(1, 'contact.relationspresse@x.example.test'), $c(2, 'info@x.example.test')]))->toBe($c(2, 'info@x.example.test'));
});
