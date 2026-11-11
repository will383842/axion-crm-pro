<?php

use App\Envoi\Campagnes\DepotModeles;
use App\Envoi\Exceptions\ModeleRefuse;
use App\Support\WorkspaceContext;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

function modEspace(): string
{
    $id = (string) Str::uuid();
    DB::table('workspaces')->insert(['id' => $id, 'name' => 'Md', 'slug' => 'md-' . substr($id, 0, 8), 'created_at' => now(), 'updated_at' => now()]);

    return $id;
}

/** Exécute dans un point de sauvegarde et rend le code SQLSTATE de l'échec (null = accepté, annulé ensuite). */
function modEchec(Closure $f): ?string
{
    try {
        DB::transaction(function () use ($f) {
            $f();
            throw new RuntimeException('__annule__');
        });
    } catch (QueryException $e) {
        return (string) $e->getCode();
    } catch (RuntimeException $e) {
        return $e->getMessage() === '__annule__' ? null : 'autre:' . $e->getMessage();
    }

    return 'inattendu';
}

test('R08 modèles : restaurer = nouvelle version, archiver = archive_le, jamais de suppression', function () {
    $ws = modEspace();
    $d = app(DepotModeles::class);
    $id = $d->creer($ws, 'Modèle fictif', 'Objet 1', 'Corps 1', 'responsable');
    expect($d->nouvelleVersion($ws, $id, 'Objet 2', 'Corps 2', 'responsable'))->toBe(2)
        ->and($d->restaurer($ws, $id, 1, 'responsable'))->toBe(3);
    $v = $d->versions($ws, $id);
    expect($v)->toHaveCount(3)->and($v[0]->objet)->toBe('Objet 1')->and($v[0]->restaure_de)->toBe(1)->and($v[1]->objet)->toBe('Objet 2');
    expect(modEchec(fn () => DB::table('envoi_modele_versions')->where('modele_id', $id)->update(['corps' => 'x'])))->toBe('P0001')
        ->and(modEchec(fn () => DB::table('envoi_modele_versions')->where('modele_id', $id)->delete()))->toBe('P0001')
        ->and(modEchec(fn () => DB::table('envoi_modeles')->where('id', $id)->delete()))->toBe('P0001')
        ->and(modEchec(fn () => DB::table('envoi_modeles')->where('id', $id)->update(['nom' => 'renommé'])))->toBe('P0001');
    $d->archiver($ws, $id);
    expect(DB::table('envoi_modeles')->where('id', $id)->value('archive_le'))->not->toBeNull()
        ->and(fn () => $d->nouvelleVersion($ws, $id, 'o', 'c', 'r'))->toThrow(ModeleRefuse::class, 'archivé')
        ->and(modEchec(fn () => DB::table('envoi_modeles')->where('id', $id)->update(['archive_le' => null])))->toBe('P0001');
});

test('R08 modèles : RLS, un espace ne lit jamais les modèles de l\'autre', function () {
    [$a, $b] = [modEspace(), modEspace()];
    app(DepotModeles::class)->creer($a, 'Modèle fictif', 'Objet', 'Corps', 'responsable');
    $role = '"' . str_replace('"', '""', (string) config('database.connections.pgsql_app.username')) . '"';
    DB::statement('SET LOCAL ROLE ' . $role);
    foreach (['envoi_modeles', 'envoi_modele_versions'] as $t) {
        WorkspaceContext::set($a);
        $vuA = DB::table($t)->count();
        WorkspaceContext::set($b);
        expect([$vuA, DB::table($t)->count()])->toBe([1, 0], $t);
    }
    DB::statement('RESET ROLE');
});
