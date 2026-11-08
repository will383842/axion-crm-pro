<?php

use App\Envoi\Chauffe\BudgetChauffe;
use App\Envoi\Chauffe\CourbeChauffe;
use App\Envoi\Chauffe\DepotChauffe;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

function espaceIp(): string
{
    $id = (string) Str::uuid();
    DB::table('workspaces')->insert(['id' => $id, 'name' => 'Ip', 'slug' => 'ip-' . substr($id, 0, 8), 'created_at' => now(), 'updated_at' => now()]);

    return $id;
}

test('K8 la chauffe de l\'IP est séparée : ses jours n\'avancent pas ceux du domaine, et ne gonflent pas les totaux', function () {
    $ws = espaceIp();
    $d = app(DepotChauffe::class);
    $jour = new DateTimeImmutable('2026-12-01', new DateTimeZone('Europe/Paris'));
    for ($i = 1; $i <= 8; $i++) {
        $d->incrementer($ws, 'ip-ref-1', $jour->modify("-{$i} days"), 'autre', 'envoyes', 10, BudgetChauffe::RESSOURCE_IP);
    }

    expect($d->volumesParJour($ws, 'ip-ref-1', $jour, BudgetChauffe::RESSOURCE_IP))->toHaveCount(8)
        ->and($d->volumesParJour($ws, 'ip-ref-1', $jour))->toBe([])
        ->and($d->totaux7Jours($ws, $jour)['envoyes'])->toBe(0);

    $b = BudgetChauffe::duJour(
        CourbeChauffeJours::de($d->volumesParJour($ws, 'ip-ref-1', $jour, BudgetChauffe::RESSOURCE_IP)),
        [],
        $jour,
    );
    expect($b->budget)->toBe(10)->and($b->limitant)->toBe(BudgetChauffe::RESSOURCE_DOMAINE);
});

test('K8 un type de ressource inconnu est refusé', function () {
    $d = app(DepotChauffe::class);
    $d->incrementer(espaceIp(), 'x', new DateTimeImmutable('2026-12-01'), 'autre', 'envoyes', 1, 'boite');
})->throws(InvalidArgumentException::class);

final class CourbeChauffeJours
{
    /** @param array<string,int> $volumes @return list<DateTimeImmutable> */
    public static function de(array $volumes): array
    {
        return CourbeChauffe::joursEffectifs($volumes);
    }
}
