<?php

/** Envoi X1-L4-A — étalement pur des échéances (aucune base, aucune horloge interne). */

use App\Envoi\Campagnes\EtalementEcheances;
use App\Envoi\Sequence\FenetreHoraire;

function etParis(string $d): DateTimeImmutable
{
    return new DateTimeImmutable($d, new DateTimeZone('Europe/Paris'));
}

/** @return list<string> */
function etCles(int $n): array
{
    return array_map(fn (int $i): string => "c1|k{$i}", range(1, $n));
}

/** @param array<string,DateTimeImmutable> $r @return array<string,int> */
function etParJour(array $r): array
{
    $out = [];
    foreach ($r as $d) {
        $j = $d->setTimezone(new DateTimeZone('Europe/Paris'))->format('Y-m-d');
        $out[$j] = ($out[$j] ?? 0) + 1;
    }
    ksort($out);

    return $out;
}

it('étale 50 clés à 20 par jour ouvré, dans la fenêtre, jamais à heure pile', function () {
    $f = new FenetreHoraire(8, 19);
    $r = EtalementEcheances::repartir($f, etParis('2026-03-02 07:00'), etParis('2026-03-02 00:00'), 20, [], etCles(50), 'g');
    expect(etParJour($r))->toBe(['2026-03-02' => 20, '2026-03-03' => 20, '2026-03-04' => 10]);
    foreach ($r as $d) {
        $l = $d->setTimezone(new DateTimeZone('Europe/Paris'));
        expect((int) $l->format('G'))->toBeGreaterThanOrEqual(8)->toBeLessThan(19);
        expect($l->format('i:s'))->not->toBe('00:00');
    }
    expect(count(array_unique(array_map(fn ($d) => $d->getTimestamp(), $r))))->toBe(50);
});

it('tient compte des inscriptions déjà prévues ce jour-là', function () {
    $r = EtalementEcheances::repartir(new FenetreHoraire(8, 19), etParis('2026-03-02 07:00'), etParis('2026-03-02 07:00'), 20, ['2026-03-02' => 18], etCles(2), 'g');
    expect(etParJour($r))->toBe(['2026-03-02' => 2]);
    $r = EtalementEcheances::repartir(new FenetreHoraire(8, 19), etParis('2026-03-02 07:00'), etParis('2026-03-02 07:00'), 20, ['2026-03-02' => 18], etCles(4), 'g');
    expect(etParJour($r))->toBe(['2026-03-02' => 2, '2026-03-03' => 2]);
    expect(EtalementEcheances::capacite(new FenetreHoraire(8, 19), etParis('2026-03-02 07:00'), etParis('2026-03-02 07:00'), 20, ['2026-03-02' => 99]))->toBe(19 * 20);
});

it('vendredi tard : rien le vendredi ni le week-end, lundi d\'abord', function () {
    $r = EtalementEcheances::repartir(new FenetreHoraire(8, 19), etParis('2026-03-06 18:58'), etParis('2026-03-06 18:58'), 20, [], etCles(5), 'g');
    expect(array_keys(etParJour($r)))->toBe(['2026-03-09']);
});

it('saute le jour férié (1er mai)', function () {
    $r = EtalementEcheances::repartir(new FenetreHoraire(8, 19), etParis('2026-04-30 18:58'), etParis('2026-04-30 18:58'), 20, [], etCles(5), 'g');
    expect(array_keys(etParJour($r)))->toBe(['2026-05-04']);
});

it('reste dans la fenêtre locale après le changement d\'heure d\'octobre', function () {
    $r = EtalementEcheances::repartir(new FenetreHoraire(8, 19), etParis('2026-10-24 12:00'), etParis('2026-10-24 12:00'), 20, [], etCles(10), 'g');
    expect(array_keys(etParJour($r)))->toBe(['2026-10-26']);
    foreach ($r as $d) {
        $l = $d->setTimezone(new DateTimeZone('Europe/Paris'));
        expect($l->format('P'))->toBe('+01:00');
        expect((int) $l->format('G'))->toBeGreaterThanOrEqual(8)->toBeLessThan(19);
    }
});

it('respecte un départ différé', function () {
    $r = EtalementEcheances::repartir(new FenetreHoraire(8, 19), etParis('2026-03-02 07:00'), etParis('2026-03-05 00:00'), 20, [], etCles(3), 'g');
    expect(array_keys(etParJour($r)))->toBe(['2026-03-05']);
});

it('écarte irrégulièrement les échéances', function () {
    $f = new FenetreHoraire(8, 19);
    $r = EtalementEcheances::repartir($f, etParis('2026-03-02 07:00'), etParis('2026-03-02 07:00'), 20, [], etCles(20), 'g');
    $ts = array_map(fn ($d) => $d->getTimestamp(), $r);
    sort($ts);
    $ecarts = [];
    for ($i = 1; $i < count($ts); $i++) {
        $ecarts[] = $ts[$i] - $ts[$i - 1];
    }
    expect(count(array_unique($ecarts)))->toBeGreaterThan(1);
    expect(min($ecarts))->toBeGreaterThan((11 * 3600 / 20) * 0.2);
});

it('est déterministe, et la graine change le résultat', function () {
    $a = fn (string $g) => EtalementEcheances::repartir(new FenetreHoraire(8, 19), etParis('2026-03-02 07:00'), etParis('2026-03-02 07:00'), 20, [], etCles(30), $g);
    expect($a('x'))->toEqual($a('x'));
    expect($a('x'))->not->toEqual($a('y'));
});

it('plafonne à 20 jours ouvrés d\'horizon', function () {
    $f = new FenetreHoraire(8, 19);
    $r = EtalementEcheances::repartir($f, etParis('2026-03-02 07:00'), etParis('2026-03-02 07:00'), 20, [], etCles(1000), 'g');
    expect($r)->toHaveCount(400);
    expect(EtalementEcheances::capacite($f, etParis('2026-03-02 07:00'), etParis('2026-03-02 07:00'), 20, []))->toBe(400);
});

it('refuse un plafond nul ou négatif', function () {
    EtalementEcheances::repartir(new FenetreHoraire(8, 19), etParis('2026-03-02 07:00'), etParis('2026-03-02 07:00'), 0, [], etCles(1), 'g');
})->throws(InvalidArgumentException::class);

it('n\'étale jamais au-delà du plafond absolu de 300 par jour', function () {
    $r = EtalementEcheances::repartir(new FenetreHoraire(8, 19), etParis('2026-03-02 07:00'), etParis('2026-03-02 07:00'), 5000, [], etCles(400), 'g');
    expect(etParJour($r))->toBe(['2026-03-02' => 300, '2026-03-03' => 100]);
});

it('ne place jamais une échéance à moins de 5 minutes de maintenant', function () {
    $maintenant = etParis('2026-03-02 08:00');
    foreach (range(1, 300) as $i) {
        $r = EtalementEcheances::repartir(new FenetreHoraire(8, 19), $maintenant, $maintenant, 20, [], ['c|k'], "g{$i}");
        expect($r['c|k']->getTimestamp())->toBeGreaterThanOrEqual($maintenant->modify('+5 minutes')->getTimestamp());
    }
});

it('capacite est une borne basse : repartir place au moins capacite clés', function () {
    $f = new FenetreHoraire(8, 19);
    foreach (range(0, 659) as $m) {
        $now = etParis('2026-03-02 08:00')->modify("+{$m} minutes");
        $cap = EtalementEcheances::capacite($f, $now, $now, 20, []);
        foreach (['g', 'h', 'z'] as $g) {
            expect(count(EtalementEcheances::repartir($f, $now, $now, 20, [], etCles($cap), $g)))->toBeGreaterThanOrEqual($cap);
        }
    }
});

it('un créneau qui tombe exactement sur une heure ronde est décalé (jamais HH:00:00)', function () {
    $m = new ReflectionMethod(EtalementEcheances::class, 'creneau');
    $d = $m->invoke(null, new FenetreHoraire(8, 19), etParis('2026-03-02 00:00'), 0, 20, 0.0);
    expect($d->setTimezone(new DateTimeZone('Europe/Paris'))->format('H:i:s'))->not->toEndWith(':00:00');
});
