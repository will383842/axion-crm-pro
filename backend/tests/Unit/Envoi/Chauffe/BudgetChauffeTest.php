<?php

use App\Envoi\Chauffe\BudgetChauffe;
use App\Envoi\Chauffe\CourbeChauffe;

/** @return list<DateTimeImmutable> $n jours d'envoi consécutifs (volume suffisant), finissant la veille de $jour */
function joursAvant(DateTimeImmutable $jour, int $n): array
{
    $l = [];
    for ($i = $n; $i >= 1; $i--) {
        $l[] = $jour->modify("-{$i} days");
    }

    return $l;
}

function aujourdhuiK8(): DateTimeImmutable
{
    return new DateTimeImmutable('2026-12-01', new DateTimeZone('Europe/Paris'));
}

test('K8 IP neuve + domaine ancien => le budget est celui de l\'IP (palier le plus bas)', function () {
    $j = aujourdhuiK8();
    $b = BudgetChauffe::duJour([], joursAvant($j, 20), $j);

    expect($b->budget)->toBe(CourbeChauffe::quotaBoite(1))
        ->and($b->budget)->toBe(10)
        ->and($b->budgetIp)->toBe(10)
        ->and($b->budgetDomaine)->toBe(25)
        ->and($b->limitant)->toBe(BudgetChauffe::RESSOURCE_IP);
});

test('K8 IP ancienne + domaine neuf => le budget est celui du domaine', function () {
    $j = aujourdhuiK8();
    $b = BudgetChauffe::duJour(joursAvant($j, 20), [], $j);

    expect($b->budget)->toBe(10)
        ->and($b->budgetIp)->toBe(25)
        ->and($b->budgetDomaine)->toBe(10)
        ->and($b->limitant)->toBe(BudgetChauffe::RESSOURCE_DOMAINE);
});

test('K8 le minimum ne dépasse jamais l\'un des deux budgets (toutes anciennetés)', function () {
    $j = aujourdhuiK8();
    foreach ([0, 3, 5, 6, 10, 11, 25] as $ip) {
        foreach ([0, 3, 5, 6, 10, 11, 25] as $dom) {
            $b = BudgetChauffe::duJour(joursAvant($j, $ip), joursAvant($j, $dom), $j);
            expect($b->budget)->toBeLessThanOrEqual($b->budgetIp)
                ->and($b->budget)->toBeLessThanOrEqual($b->budgetDomaine)
                ->and($b->budget)->toBe(min($b->budgetIp, $b->budgetDomaine));
        }
    }
});

test('K8 deux ressources de même ancienneté : le domaine n\'élargit rien, le budget reste celui de la courbe', function () {
    $j = aujourdhuiK8();
    $b = BudgetChauffe::duJour(joursAvant($j, 7), joursAvant($j, 7), $j);

    expect($b->budget)->toBe(18)->and($b->budgetIp)->toBe(18)->and($b->budgetDomaine)->toBe(18);
});

test('K8 les types de ressource sont ip et domaine, rien d\'autre', function () {
    expect(BudgetChauffe::TYPES)->toBe(['ip', 'domaine'])
        ->and(BudgetChauffe::typeValide('ip'))->toBeTrue()
        ->and(BudgetChauffe::typeValide('domaine'))->toBeTrue()
        ->and(BudgetChauffe::typeValide('boite'))->toBeFalse();
});
