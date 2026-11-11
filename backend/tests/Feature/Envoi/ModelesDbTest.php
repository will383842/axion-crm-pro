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

test('R08 modèles : restaurer = nouvelle version, archiver = statut + archive_le, jamais de suppression', function () {
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
        ->and(modEchec(fn () => DB::statement('TRUNCATE envoi_modeles CASCADE')))->toBe('P0001')
        ->and(modEchec(fn () => DB::table('envoi_modeles')->where('id', $id)->update(['nom' => 'renommé'])))->toBe('P0001');
    // restaure_de : clé étrangère vers une version du MÊME modèle
    expect(modEchec(fn () => DB::table('envoi_modele_versions')->insert(['workspace_id' => $ws, 'modele_id' => $id, 'numero' => 9, 'objet' => 'o', 'corps' => 'c', 'auteur' => 'r', 'restaure_de' => 42])))->toBe('23503');
    $d->archiver($ws, $id);
    expect(DB::table('envoi_modeles')->where('id', $id)->value('archive_le'))->not->toBeNull()
        ->and(DB::table('envoi_modeles')->where('id', $id)->value('statut'))->toBe('archive')
        ->and(fn () => $d->nouvelleVersion($ws, $id, 'o', 'c', 'r'))->toThrow(ModeleRefuse::class, 'archivé')
        ->and(modEchec(fn () => DB::table('envoi_modeles')->where('id', $id)->update(['archive_le' => null, 'statut' => 'brouillon'])))->toBe('P0001');
});

test('R08 modèles : cycle du contrat (brouillon, a_relire, valide, archive) et version validée', function () {
    $ws = modEspace();
    $d = app(DepotModeles::class);
    $id = $d->creer($ws, 'Modèle fictif', 'Objet 1', 'Corps 1', 'responsable');
    $etat = fn () => DB::table('envoi_modeles')->where('id', $id)->first();
    expect(fn () => $d->changerStatut($ws, $id, 'valide', 1))->toThrow(ModeleRefuse::class, 'interdite');   // saut brouillon -> valide
    $d->changerStatut($ws, $id, 'a_relire');
    expect(fn () => $d->changerStatut($ws, $id, 'valide'))->toThrow(ModeleRefuse::class, 'obligatoire');
    expect(modEchec(fn () => $d->changerStatut($ws, $id, 'valide', 7)))->toBe('23503');                      // version inexistante
    $d->changerStatut($ws, $id, 'valide', 1);
    expect($etat()->statut)->toBe('valide')->and($etat()->version_validee)->toBe(1);
    expect(modEchec(fn () => DB::table('envoi_modeles')->where('id', $id)->update(['version_validee' => 2])))->toBe('P0001');   // immuable
    $d->nouvelleVersion($ws, $id, 'Objet 2', 'Corps 2', 'responsable');                                      // nouveau contenu : retour brouillon
    expect($etat()->statut)->toBe('brouillon')->and($etat()->version_validee)->toBeNull();
    // archive_le = now() de la base, jamais une date libre
    expect(modEchec(fn () => DB::table('envoi_modeles')->where('id', $id)->update(['statut' => 'archive', 'archive_le' => '2020-01-01 00:00:00+00'])))->toBe('P0001');
    foreach (['objet' => [str_repeat('o', 301), 'c'], 'corps' => ['o', str_repeat('c', 20001)]] as $champ => [$o, $c]) {
        expect(fn () => $d->nouvelleVersion($ws, $id, $o, $c, 'r'))->toThrow(ModeleRefuse::class, $champ);
    }
});

test('R08 modèles : la matrice PHP et la base disent la même chose pour les 12 couples', function () {
    $ws = modEspace();
    $d = app(DepotModeles::class);
    $statuts = array_keys(DepotModeles::TRANSITIONS);
    $ids = [];
    foreach ($statuts as $st) {
        $ids[$st] = $d->creer($ws, 'M ' . $st, 'o', 'c', 'r');
    }
    DB::statement('ALTER TABLE envoi_modeles DISABLE TRIGGER envoi_modeles_garde');   // semis du propriétaire, rétabli aussitôt
    foreach ($ids as $st => $id) {
        DB::table('envoi_modeles')->where('id', $id)->update(['statut' => $st, 'version_validee' => $st === 'valide' ? 1 : null, 'archive_le' => $st === 'archive' ? now() : null]);
    }
    DB::statement('ALTER TABLE envoi_modeles ENABLE TRIGGER envoi_modeles_garde');
    foreach ($statuts as $de) {
        foreach ($statuts as $vers) {
            if ($de === $vers) {
                continue;
            }
            $cible = ['statut' => $vers, 'version_validee' => $vers === 'valide' || ($vers === 'archive' && $de === 'valide') ? 1 : null, 'archive_le' => $vers === 'archive' ? DB::raw('now()') : null];
            expect(modEchec(fn () => DB::table('envoi_modeles')->where('id', $ids[$de])->update($cible)) === null)->toBe(DepotModeles::autorisee($de, $vers), "{$de} -> {$vers}");
        }
    }
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
    // un espace n'écrit pas dans l'autre (WITH CHECK de la RLS)
    foreach (['envoi_modeles' => ['id' => (string) Str::uuid(), 'workspace_id' => $a, 'nom' => 'x', 'cree_par' => 'y']] as $t => $ligne) {
        expect(modEchec(fn () => DB::table($t)->insert($ligne)))->toBe('42501');   // contexte = B, ligne de A
    }
    DB::statement('RESET ROLE');
});
