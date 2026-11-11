<?php

use App\Envoi\Chauffe\BudgetChauffe;
use App\Envoi\Chauffe\CourbeChauffe;

/** L8-F07a — paliers P4 à P8, reculs, goulot. Pur calcul : aucune base, aucune route. */
function jourF07a(string $d): DateTimeImmutable
{
    return new DateTimeImmutable($d, new DateTimeZone('Europe/Paris'));
}

/** @return list<DateTimeImmutable> $n jours consécutifs finissant la veille de $jour */
function veillesF07a(DateTimeImmutable $jour, int $n): array
{
    $l = [];
    for ($i = $n; $i >= 1; $i--) {
        $l[] = $jour->modify("-{$i} days");
    }

    return $l;
}

test('F07a table P1 à P8 : P1-P3 inchangés, P8 = plafond serveur 300, jamais au-delà', function () {
    $attendu = [1 => ['P1', 20], 5 => ['P1', 20], 6 => ['P2', 35], 10 => ['P2', 35], 11 => ['P3', 50], 15 => ['P3', 50],
        16 => ['P4', 100], 20 => ['P4', 100], 21 => ['P5', 150], 26 => ['P6', 200], 31 => ['P7', 250], 36 => ['P8', 300], 5000 => ['P8', 300]];
    foreach ($attendu as $jc => [$palier, $total]) {
        expect(CourbeChauffe::palierEtendu($jc))->toBe($palier)->and(CourbeChauffe::quotaRessourceEtendu($jc))->toBe($total);
    }
    expect(CourbeChauffe::quotaBoiteEtendu(36))->toBe(150)->and(CourbeChauffe::PLAFOND_BOITE_JOUR)->toBe(150);
    // la table vivante (P1-P3) n'est pas modifiée : aucun changement de comportement avant F07b
    expect(CourbeChauffe::quotaRessource(500))->toBe(50);
});

test('F07a bornes : jour de chauffe 5 => P1, 6 => P2 ; 10 => P2, 11 => P3', function () {
    $j = jourF07a('2026-12-01');
    foreach ([[4, 'P1'], [5, 'P1'], [6, 'P2'], [9, 'P2'], [10, 'P2'], [11, 'P3']] as [$n, $palier]) {
        $jc = CourbeChauffe::jourDeChauffeAvecReculs(veillesF07a($j, $n), [], $j);
        expect($jc)->toBe($n + 1);
        // jours effectifs N => jour de chauffe N+1 : 5 jours passés = 6e jour = P2 ; ici on lit le palier du jour d'AUJOURD'HUI
        expect(CourbeChauffe::palierEtendu($jc))->toBe($n >= 5 ? ($n >= 10 ? 'P3' : 'P2') : 'P1');
    }
});

test('F07a recul : UN palier vers le bas, 3 jours effectifs de probation, puis la montée reprend', function () {
    $effectifs = veillesF07a(jourF07a('2026-12-12'), 12); // 12 jours effectifs finissant le 11/12 : jour de chauffe 13 = P3
    $recul = jourF07a('2026-12-12');
    // le jour du recul, on est ramené au premier jour de P2
    expect(CourbeChauffe::jourDeChauffeAvecReculs($effectifs, [$recul], $recul))->toBe(6);
    // 3 jours effectifs verts : le compteur ne bouge pas (6 reste 6)
    $verts = array_merge($effectifs, [jourF07a('2026-12-12'), jourF07a('2026-12-13'), jourF07a('2026-12-14')]);
    expect(CourbeChauffe::jourDeChauffeAvecReculs($verts, [$recul], jourF07a('2026-12-15')))->toBe(6);
    // 4e jour effectif : la montée reprend, 6 => 7
    $verts[] = jourF07a('2026-12-15');
    expect(CourbeChauffe::jourDeChauffeAvecReculs($verts, [$recul], jourF07a('2026-12-16')))->toBe(7);
});

test('F07a un recul en P2 ramène à P1 ; deux reculs de jours différents reculent deux fois', function () {
    $e = veillesF07a(jourF07a('2026-12-10'), 8); // jour 9 le 10/12 : P2
    expect(CourbeChauffe::jourDeChauffeAvecReculs($e, [jourF07a('2026-12-10')], jourF07a('2026-12-10')))->toBe(1);
    $e = veillesF07a(jourF07a('2026-12-10'), 12); // P3 le 10/12
    $deux = [jourF07a('2026-12-10'), jourF07a('2026-12-11')];
    expect(CourbeChauffe::jourDeChauffeAvecReculs($e, $deux, jourF07a('2026-12-11')))->toBe(1);
});

test('F07a un recul du futur ne compte pas', function () {
    $j = jourF07a('2026-12-01');
    expect(CourbeChauffe::jourDeChauffeAvecReculs(veillesF07a($j, 12), [jourF07a('2026-12-02')], $j))->toBe(13);
});

test('F07a le recul de l\'IP ne touche pas le domaine (budget et goulot)', function () {
    $j = jourF07a('2026-12-01');
    $age = veillesF07a($j, 19); // 19 jours passés = 20e jour = P4 des deux côtés
    $sans = BudgetChauffe::duJourComplet($age, [], $age, [], $j, 4);
    expect($sans->budget)->toBe(100)->and($sans->courbeIp)->toBe(100)->and($sans->courbeDomaine)->toBe(100);
    $avec = BudgetChauffe::duJourComplet($age, [$j], $age, [], $j, 4);
    expect($avec->courbeIp)->toBe(50)->and($avec->courbeDomaine)->toBe(100)->and($avec->budget)->toBe(50)->and($avec->goulot)->toBe('ip');
});

test('F07a goulot : IP en P3 bridée par un domaine en P1 = comportement voulu, affiché comme goulot', function () {
    $j = jourF07a('2026-12-01');
    $b = BudgetChauffe::duJourComplet(veillesF07a($j, 11), [], [], [], $j, 4);
    expect($b->courbeIp)->toBe(50)->and($b->courbeDomaine)->toBe(20)->and($b->budget)->toBe(20)->and($b->goulot)->toBe('domaine');
});

test('F07a goulot : plafond des boîtes (150 chacune) et plafond serveur (300) ; capacité de réponse = null, jamais inventée', function () {
    $j = jourF07a('2026-12-01');
    $vieux = veillesF07a($j, 40); // P8 : 300 des deux côtés
    $b = BudgetChauffe::duJourComplet($vieux, [], $vieux, [], $j, 1);
    expect($b->plafondBoites)->toBe(150)->and($b->budget)->toBe(150)->and($b->goulot)->toBe('boites')->and($b->capaciteReponse)->toBeNull();
    $c = BudgetChauffe::duJourComplet($vieux, [], $vieux, [], $j, 5);
    expect($c->plafondBoites)->toBe(750)->and($c->plafondServeur)->toBe(300)->and($c->budget)->toBe(300)->and($c->goulot)->toBe('serveur');
    // égalité : le premier terme dans l'ordre serveur, boites, ip, domaine est nommé
    $d = BudgetChauffe::duJourComplet(veillesF07a($j, 5), [], veillesF07a($j, 5), [], $j, 2);
    expect($d->budget)->toBe(35)->and($d->goulot)->toBe('ip');
});

test('F07a aucune opération « avancer » n\'existe', function () {
    foreach (['avancer', 'monter', 'promouvoir'] as $nom) {
        expect(method_exists(CourbeChauffe::class, $nom))->toBeFalse()->and(method_exists(BudgetChauffe::class, $nom))->toBeFalse();
    }
    // aucune méthode PUBLIQUE de Chauffe\* dont le nom contient avancer/monter
    $fichiers = glob(dirname(__DIR__, 4) . '/app/Envoi/Chauffe/*.php');
    expect($fichiers)->not->toBeEmpty();
    foreach ($fichiers as $f) {
        $classe = 'App\\Envoi\\Chauffe\\' . basename($f, '.php');
        if (! class_exists($classe) && ! interface_exists($classe)) {
            continue;
        }
        foreach ((new ReflectionClass($classe))->getMethods(ReflectionMethod::IS_PUBLIC) as $m) {
            expect(stripos($m->getName(), 'avancer'))->toBeFalse("{$classe}::{$m->getName()}")
                ->and(stripos($m->getName(), 'monter'))->toBeFalse("{$classe}::{$m->getName()}");
        }
    }
});

/** @return array<string, int> $n jours consécutifs à partir de $debut, tous au même volume */
function volumesF07a2(string $debut, int $n, int $volume): array
{
    $v = [];
    for ($i = 0; $i < $n; $i++) {
        $v[jourF07a($debut)->modify("+{$i} days")->format('Y-m-d')] = $volume;
    }

    return $v;
}

test('F07a2 (a) en P4 le seuil d\'un jour effectif est 50 % de 100 = 50, pas 25', function () {
    // 15 jours à 10 (P1-P3 : seuils 10, 18/2=9, 25... on envoie 25 pour être sûr) puis jour 16 (P4) à 25 puis à 50
    $v = volumesF07a2('2026-12-01', 15, 25);
    $v['2026-12-16'] = 25;
    $v['2026-12-17'] = 50;
    $e = CourbeChauffe::joursEffectifs($v, 0.5, true, []);
    $jours = array_map(fn ($d) => $d->format('Y-m-d'), $e);
    expect($jours)->not->toContain('2026-12-16')->and($jours)->toContain('2026-12-17')->and($jours)->toHaveCount(16);
});

test('F07a2 (b) P3 jour 13, deux reculs jusqu\'en P1 : le seuil redescend à 10, la remontée n\'est plus gelée', function () {
    $v = volumesF07a2('2026-12-01', 12, 25);   // 12 jours effectifs : jour 13 le 13/12 (P3)
    $reculs = [jourF07a('2026-12-13'), jourF07a('2026-12-14')];   // P3 -> P2 -> P1
    $v['2026-12-15'] = 10;                      // P1 : budget 20, seuil 10
    $e = CourbeChauffe::joursEffectifs($v, 0.5, true, $reculs);
    expect(array_map(fn ($d) => $d->format('Y-m-d'), $e))->toContain('2026-12-15');
    // ancienne règle (null) : conserve son comportement en service
    expect(CourbeChauffe::joursEffectifs(['2026-12-01' => 10], 0.5, true))->toHaveCount(1);
});

test('F07a2 l\'inactivité PENDANT la période d\'essai la fait repartir à 3 jours', function () {
    $recul = jourF07a('2026-12-12');
    $effectifs = veillesF07a($recul, 12);                       // P3 -> recul -> jour 6 (P2)
    $essai = [jourF07a('2026-12-12')];                          // 1 jour vert consommé (reste 2)
    // sans inactivité : 2 jours verts restants, le compteur reste à 6 jusqu'au 14, monte le 15
    expect(CourbeChauffe::jourDeChauffeAvecReculs(array_merge($effectifs, $essai, [jourF07a('2026-12-13'), jourF07a('2026-12-14')]), [$recul], jourF07a('2026-12-15')))->toBe(6);
    // 7 jours d'inactivité (12 -> 19) : recul P2 -> P1 (jour 1) ET essai de nouveau de 3 jours
    $apres = array_merge($effectifs, $essai, [jourF07a('2026-12-19'), jourF07a('2026-12-20'), jourF07a('2026-12-21')]);
    expect(CourbeChauffe::jourDeChauffeAvecReculs($apres, [$recul], jourF07a('2026-12-22')))->toBe(1);
    $apres[] = jourF07a('2026-12-22');
    expect(CourbeChauffe::jourDeChauffeAvecReculs($apres, [$recul], jourF07a('2026-12-23')))->toBe(2);
});
