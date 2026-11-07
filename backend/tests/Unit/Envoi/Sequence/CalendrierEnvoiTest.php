<?php

/** Envoi P0 L1 — jours ouvrés français, fenêtre horaire Europe/Paris, changements d'heure. Aucune base. */

use App\Envoi\Sequence\FenetreHoraire;
use App\Envoi\Sequence\JoursOuvres;

function parisLe(string $d): DateTimeImmutable
{
    return new DateTimeImmutable($d, new DateTimeZone('Europe/Paris'));
}

test('jours fériés 2026 : Pâques (lundi), Ascension et Pentecôte (lundi) calculés', function () {
    expect(JoursOuvres::feries(2026))->toBe([
        '2026-01-01', '2026-04-06', '2026-05-01', '2026-05-08', '2026-05-14', '2026-05-25',
        '2026-07-14', '2026-08-15', '2026-11-01', '2026-11-11', '2026-12-25',
    ]);
});

test('week-end et férié ne sont pas ouvrés ; le vendredi saint et le lendemain de Noël le sont', function () {
    expect(JoursOuvres::estOuvre(parisLe('2026-11-07 10:00')))->toBeFalse()   // samedi
        ->and(JoursOuvres::estOuvre(parisLe('2026-11-08 10:00')))->toBeFalse() // dimanche
        ->and(JoursOuvres::estOuvre(parisLe('2026-05-14 10:00')))->toBeFalse() // Ascension
        ->and(JoursOuvres::estOuvre(parisLe('2026-04-03 10:00')))->toBeTrue()  // vendredi saint
        ->and(JoursOuvres::estOuvre(parisLe('2026-12-26 10:00')))->toBeFalse() // samedi
        ->and(JoursOuvres::estOuvre(parisLe('2026-12-24 10:00')))->toBeTrue();
});

test('+1 jour ouvré saute week-end et fériés (8 mai, Noël, lundi de Pâques)', function () {
    $f = new FenetreHoraire(9, 17);
    expect($f->echeance(parisLe('2026-05-07 10:00'), 1)->format('Y-m-d'))->toBe('2026-05-11')
        ->and($f->echeance(parisLe('2026-12-24 10:00'), 1)->format('Y-m-d'))->toBe('2026-12-28')
        ->and($f->echeance(parisLe('2026-04-03 10:00'), 1)->format('Y-m-d'))->toBe('2026-04-07')
        ->and($f->echeance(parisLe('2026-11-06 10:00'), 0)->format('Y-m-d H:i'))->toBe('2026-11-06 10:00');
});

test('fenêtre [9h, 17h[ : bornes incluse/exclue, avant ouverture, après fermeture, week-end', function () {
    $f = new FenetreHoraire(9, 17);
    expect($f->contient(parisLe('2026-11-06 08:59')))->toBeFalse()
        ->and($f->contient(parisLe('2026-11-06 09:00')))->toBeTrue()
        ->and($f->contient(parisLe('2026-11-06 16:59')))->toBeTrue()
        ->and($f->contient(parisLe('2026-11-06 17:00')))->toBeFalse()
        ->and($f->prochain(parisLe('2026-11-06 08:00'))->format('Y-m-d H:i'))->toBe('2026-11-06 09:00')
        ->and($f->prochain(parisLe('2026-11-06 17:00'))->format('Y-m-d H:i'))->toBe('2026-11-09 09:00')
        ->and($f->prochain(parisLe('2026-11-07 11:00'))->format('Y-m-d H:i'))->toBe('2026-11-09 09:00');
});

test('changement d\'heure : 09h00 reste 09h00 à Paris (07:00 UTC en été, 08:00 UTC en hiver)', function () {
    $f = new FenetreHoraire(9, 17);
    $ete = $f->prochain(parisLe('2026-03-27 18:00')); // vendredi avant le passage à l'heure d'été (29 mars)
    $hiver = $f->prochain(parisLe('2026-10-23 18:00')); // vendredi avant le retour à l'heure d'hiver (25 octobre)
    expect($ete->format('Y-m-d H:i'))->toBe('2026-03-30 09:00')
        ->and($ete->setTimezone(new DateTimeZone('UTC'))->format('H:i'))->toBe('07:00')
        ->and($hiver->format('Y-m-d H:i'))->toBe('2026-10-26 09:00')
        ->and($hiver->setTimezone(new DateTimeZone('UTC'))->format('H:i'))->toBe('08:00');
});

test('fenêtre : une heure UTC est lue en heure de Paris', function () {
    $f = new FenetreHoraire(9, 17);
    expect($f->contient(new DateTimeImmutable('2026-03-30 06:59', new DateTimeZone('UTC'))))->toBeFalse() // 08:59 Paris
        ->and($f->contient(new DateTimeImmutable('2026-03-30 07:00', new DateTimeZone('UTC'))))->toBeTrue()  // 09:00 Paris
        ->and($f->contient(new DateTimeImmutable('2026-10-26 08:00', new DateTimeZone('UTC'))))->toBeTrue(); // 09:00 Paris
});

test('fenêtre invalide refusée', function () {
    expect(fn () => new FenetreHoraire(17, 9))->toThrow(InvalidArgumentException::class);
});
