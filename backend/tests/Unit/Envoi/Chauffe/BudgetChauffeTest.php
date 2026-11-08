<?php

use App\Envoi\Chauffe\BudgetChauffe;
use App\Envoi\Chauffe\CourbeChauffe;
use App\Envoi\Chauffe\DepotChauffe;

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

test('K8 IP en P1 et domaine en P3 => budget 20 (budget TOTAL de l\'IP, pas le quota d\'une boîte)', function () {
    $j = aujourdhuiK8();
    $b = BudgetChauffe::duJour([], joursAvant($j, 20), $j);

    expect($b->budget)->toBe(20)
        ->and($b->budgetIp)->toBe(20)
        ->and($b->budgetDomaine)->toBe(50)
        ->and($b->limitant)->toBe(BudgetChauffe::RESSOURCE_IP);
});

test('K8 IP ancienne + domaine neuf => le budget est celui du domaine (P1 = 20)', function () {
    $j = aujourdhuiK8();
    $b = BudgetChauffe::duJour(joursAvant($j, 20), [], $j);

    expect($b->budget)->toBe(20)
        ->and($b->budgetIp)->toBe(50)
        ->and($b->budgetDomaine)->toBe(20)
        ->and($b->limitant)->toBe(BudgetChauffe::RESSOURCE_DOMAINE);
});

test('K8 budgets de ressource par palier : 20 / 35 / 50', function () {
    expect(CourbeChauffe::quotaRessource(1))->toBe(20)
        ->and(CourbeChauffe::quotaRessource(5))->toBe(20)
        ->and(CourbeChauffe::quotaRessource(6))->toBe(35)
        ->and(CourbeChauffe::quotaRessource(11))->toBe(50)
        ->and(CourbeChauffe::quotaRessource(500))->toBe(50);
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

test('K8 deux ressources de même ancienneté : le budget reste celui de la courbe (P2 = 35)', function () {
    $j = aujourdhuiK8();
    $b = BudgetChauffe::duJour(joursAvant($j, 7), joursAvant($j, 7), $j);

    expect($b->budget)->toBe(35)->and($b->budgetIp)->toBe(35)->and($b->budgetDomaine)->toBe(35);
});

test('K8 jour effectif de l\'IP = 50 % du budget de la ressource (10 en P1), pas 50 % d\'une boîte (5)', function () {
    expect(BudgetChauffe::joursEffectifs(['2026-11-01' => 5, '2026-11-02' => 9]))->toBe([])
        ->and(BudgetChauffe::joursEffectifs(['2026-11-01' => 10]))->toHaveCount(1);
    // Les jours suivants se mesurent au palier de la ressource : en P2 (35), le seuil est 18.
    $volumes = [];
    for ($i = 1; $i <= 5; $i++) {
        $volumes['2026-11-0' . $i] = 10;
    }
    $volumes['2026-11-06'] = 17;
    $volumes['2026-11-07'] = 18;
    expect(BudgetChauffe::joursEffectifs($volumes))->toHaveCount(6);
});

test('K8 les types de ressource sont ip et domaine, rien d\'autre', function () {
    expect(BudgetChauffe::TYPES)->toBe(['ip', 'domaine'])
        ->and(BudgetChauffe::typeValide('ip'))->toBeTrue()
        ->and(BudgetChauffe::typeValide('domaine'))->toBeTrue()
        ->and(BudgetChauffe::typeValide('boite'))->toBeFalse();
});

test('K8 le paramètre ressource_type des lectures du dépôt est OBLIGATOIRE (une lecture non filtrée ne passe pas)', function () {
    foreach (['volumesParJour', 'totauxDuJour', 'totaux7Jours'] as $m) {
        $p = array_values(array_filter(
            (new ReflectionMethod(DepotChauffe::class, $m))->getParameters(),
            fn (ReflectionParameter $x) => $x->getName() === 'ressourceType',
        ));
        expect($p)->toHaveCount(1)->and($p[0]->isOptional())->toBeFalse();
    }
});
