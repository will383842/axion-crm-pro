<?php

use App\Envoi\Campagnes\RepliGenerique;

/*
 * Lot L1 (PLAN ADRESSES GÉNÉRALES, #680) — règle R1 pure : liste blanche étroite, plus étroite que QualificationEmail::type.
 * Adresses fictives (example.test). Aucune base.
 */

test('R1 : adresses générales admises', function (string $email) {
    expect(RepliGenerique::admise($email))->toBeTrue();
})->with([
    'contact@x.example.test', 'info2@x.example.test', 'accueil.paris@x.example.test', 'infos@x.example.test', 'bonjour@x.example.test',
    'hello@x.example.test', 'secretariat@x.example.test', 'office@x.example.test', 'courrier@x.example.test', 'standard@x.example.test',
    'Contact@X.Example.Test', '  info@x.example.test ', 'contact_paris@x.example.test', 'info-sud@x.example.test', 'contact7@x.example.test',
]);

test('R1 : tout autre générique, étiquette +, mot collé ou nominatif est refusé', function (string $email) {
    expect(RepliGenerique::admise($email))->toBeFalse();
})->with([
    'presse@x.example.test', 'press@x.example.test', 'media@x.example.test', 'juridique@x.example.test', 'legal@x.example.test',
    'rh@x.example.test', 'hr@x.example.test', 'jobs@x.example.test', 'careers@x.example.test', 'compta@x.example.test',
    'finance@x.example.test', 'billing@x.example.test', 'support@x.example.test', 'help@x.example.test', 'sales@x.example.test',
    'marketing@x.example.test', 'newsletter@x.example.test', 'admin@x.example.test', 'direction@x.example.test',
    'adherent@x.example.test', 'adherents@x.example.test', 'formation@x.example.test', 'noreply@x.example.test', 'postmaster@x.example.test',
    'contact+crm@x.example.test', 'contacts@x.example.test', 'informatique@x.example.test', 'jean.contact@x.example.test',
    'jean.dupont@x.example.test', 'dupont@x.example.test', 'x.example.test', '', '@x.example.test',
]);

test('choisir : l’ordre de la liste R1, puis le plus petit contact_id', function () {
    $c = fn (int $id, string $email) => ['contact_id' => $id, 'email' => $email];
    expect(RepliGenerique::choisir([$c(1, 'office@x.example.test'), $c(2, 'info@x.example.test'), $c(3, 'contact@x.example.test')]))->toBe($c(3, 'contact@x.example.test'))
        ->and(RepliGenerique::choisir([$c(9, 'contact@x.example.test'), $c(4, 'contact2@x.example.test'), $c(6, 'contact.sud@x.example.test')]))->toBe($c(4, 'contact2@x.example.test'))
        ->and(RepliGenerique::choisir([$c(5, 'presse@x.example.test'), $c(6, 'info@x.example.test')]))->toBe($c(6, 'info@x.example.test'))
        ->and(RepliGenerique::choisir([$c(5, 'presse@x.example.test'), $c(6, 'rh@x.example.test')]))->toBeNull()
        ->and(RepliGenerique::choisir([]))->toBeNull()
        ->and(RepliGenerique::choisir([['contact_id' => 1]]))->toBeNull();
});

test('choisir : l’ordre d’entrée ne change pas le résultat', function () {
    $a = ['contact_id' => 8, 'email' => 'info@x.example.test'];
    $b = ['contact_id' => 3, 'email' => 'info1@x.example.test'];
    expect(RepliGenerique::choisir([$a, $b]))->toBe(RepliGenerique::choisir([$b, $a]))->and(RepliGenerique::choisir([$a, $b]))->toBe($b);
});
