<?php

use App\Envoi\Campagnes\RepliGenerique;
use App\Support\WorkspaceContext;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/*
 * Lot L1 (PLAN ADRESSES GÉNÉRALES, #680) — lecture des réglages : R0 (interrupteur, échec fermé) et R4 (plafond bas).
 * Ce test ne dépend pas de la migration L0a : il lit seulement `envoi_reglages`, qui existe déjà. Réglages posés par le propriétaire.
 */
uses(TestCase::class, RefreshDatabase::class);

function rglEspace(): string
{
    $id = (string) Str::uuid();
    DB::table('workspaces')->insert(['id' => $id, 'name' => 'Rgl', 'slug' => 'rgl-' . substr($id, 0, 8), 'created_at' => now(), 'updated_at' => now()]);

    return $id;
}

function rglPoser(string $ws, string $cle, string $valeur): void
{
    WorkspaceContext::run($ws, fn () => DB::table('envoi_reglages')->updateOrInsert(['workspace_id' => $ws, 'cle' => $cle], ['valeur' => $valeur, 'auteur' => 'fondateur']));
}

function rglPoserBrut(string $ws, string $cle, string $valeur): void
{
    // Valeur hors des gardes L0a posée exprès : la LECTURE doit rester en échec fermé (défense en profondeur).
    DB::statement('ALTER TABLE envoi_reglages DISABLE TRIGGER envoi_reglages_repli_generique');
    try {
        rglPoser($ws, $cle, $valeur);
    } finally {
        DB::statement('ALTER TABLE envoi_reglages ENABLE TRIGGER envoi_reglages_repli_generique');
    }
}

/*
 * Valeur invalide : la garde en base (déclencheur de la migration L0a) la refuse à tous les rôles. L'écriture est jouée dans un point
 * de sauvegarde pour que la transaction du test survive au refus (sinon 25P02). Le déclencheur n'est jamais désactivé.
 */
function rglRefusee(string $ws, string $cle, string $valeur): void
{
    $refus = false;
    WorkspaceContext::run($ws, function () use ($ws, $cle, $valeur, &$refus) {
        try {
            DB::transaction(fn () => DB::table('envoi_reglages')->updateOrInsert(['workspace_id' => $ws, 'cle' => $cle], ['valeur' => $valeur, 'auteur' => 'fondateur']));
        } catch (QueryException) {
            $refus = true;
        }
    });
    expect($refus)->toBeTrue("la base doit refuser « $valeur » pour $cle");
    expect(DB::table('envoi_reglages')->where('workspace_id', $ws)->where('cle', $cle)->where('valeur', $valeur)->exists())->toBeFalse();
}

test('R0 : éteint par défaut, allumé seulement par la valeur exacte « true » de l’espace', function () {
    $ws = rglEspace();
    $autre = rglEspace();
    expect(RepliGenerique::ouvert($ws))->toBeFalse();
    rglPoser($autre, 'envoi.repli_generique', 'true');
    expect(RepliGenerique::ouvert($ws))->toBeFalse()->and(RepliGenerique::ouvert($autre))->toBeTrue();
    rglPoser($ws, 'envoi.repli_generique', 'false');
    expect(RepliGenerique::ouvert($ws))->toBeFalse();
    rglPoser($ws, 'envoi.repli_generique', 'true');
    expect(RepliGenerique::ouvert($ws))->toBeTrue();
});

test('R0 : toute valeur autre que « true » / « false » est refusée par la base, le réglage lu reste fermé', function () {
    $ws = rglEspace();
    foreach (['1', 'TRUE', 'oui', '', ' true', 'true '] as $v) {
        rglRefusee($ws, 'envoi.repli_generique', $v);
        expect(RepliGenerique::ouvert($ws))->toBeFalse("valeur « $v »");
    }
    rglPoser($ws, 'envoi.repli_generique', 'true');
    rglRefusee($ws, 'envoi.repli_generique', 'oui');
    expect(RepliGenerique::ouvert($ws))->toBeTrue();
});

test('R0 : valeur illisible posée hors garde (déclencheur coupé dans la transaction du test) ⇒ lecteur éteint', function () {
    $ws = rglEspace();
    foreach (['1', 'TRUE', 'oui', '', ' true', 'true '] as $v) {
        rglPoserBrut($ws, 'envoi.repli_generique', $v);
        expect(RepliGenerique::ouvert($ws))->toBeFalse("valeur « $v »");
    }
});

test('R0 : identifiant d’espace invalide ou erreur SQL de lecture ⇒ éteint (échec fermé)', function () {
    $ws = rglEspace();
    rglPoser($ws, 'envoi.repli_generique', 'true');
    expect(RepliGenerique::ouvert('pas-un-uuid'))->toBeFalse();
    $lu = null;
    try {
        DB::transaction(function () use ($ws, &$lu) {
            DB::statement('ALTER TABLE envoi_reglages RENAME TO envoi_reglages_absente');
            $lu = RepliGenerique::ouvert($ws);
            throw new RuntimeException('__annule__');
        });
    } catch (RuntimeException) {
    }
    expect($lu)->toBeFalse()->and(RepliGenerique::ouvert($ws))->toBeTrue();
});

test('R0 : invisible par la RLS (rôle applicatif, autre espace) ⇒ éteint', function () {
    $ws = rglEspace();
    $autre = rglEspace();
    rglPoser($ws, 'envoi.repli_generique', 'true');
    $vu = null;
    try {
        DB::transaction(function () use ($autre, &$vu) {
            DB::statement('SET LOCAL ROLE "' . str_replace('"', '""', (string) config('database.connections.pgsql_app.username')) . '"');
            $vu = RepliGenerique::ouvert($autre);
            throw new RuntimeException('__annule__');
        });
    } catch (RuntimeException) {
    }
    expect($vu)->toBeFalse();
});

test('R4 : plafond absent ⇒ 3 ; 0 à 5 respectés ; 9 ⇒ 5 ; plafond de l’espace en borne', function () {
    $ws = rglEspace();
    expect(RepliGenerique::plafondJour($ws, 20))->toBe(3);
    foreach (['0' => 0, '1' => 1, '4' => 4, '5' => 5] as $v => $attendu) {
        rglPoser($ws, 'envoi.repli_generique_plafond_jour', (string) $v);
        expect(RepliGenerique::plafondJour($ws, 20))->toBe($attendu, "réglage $v");
    }
    foreach (['9' => 5, '300' => 5] as $v => $attendu) {
        rglPoserBrut($ws, 'envoi.repli_generique_plafond_jour', (string) $v);
        expect(RepliGenerique::plafondJour($ws, 20))->toBe($attendu, "réglage $v");
    }
    rglPoser($ws, 'envoi.repli_generique_plafond_jour', '5');
    expect(RepliGenerique::plafondJour($ws, 2))->toBe(2)->and(RepliGenerique::plafondJour($ws, 0))->toBe(0)->and(RepliGenerique::plafondJour($ws, -4))->toBe(0);
    DB::table('envoi_reglages')->where('workspace_id', $ws)->delete();
    expect(RepliGenerique::plafondJour($ws, 2))->toBe(2)->and(RepliGenerique::plafondJour($ws, 1))->toBe(1);
});

test('R4 : tout plafond autre qu’un entier de 0 à 5 est refusé par la base ; le plafond lu reste au défaut', function () {
    $ws = rglEspace();
    foreach (['9', '300', 'abc', '', '3.5', '-1', 'null', '[3]'] as $v) {
        rglRefusee($ws, 'envoi.repli_generique_plafond_jour', $v);
        expect(RepliGenerique::plafondJour($ws, 20))->toBe(RepliGenerique::PLAFOND_DEFAUT, "valeur « $v »");
    }
    rglPoser($ws, 'envoi.repli_generique_plafond_jour', '2');
    rglRefusee($ws, 'envoi.repli_generique_plafond_jour', '9');
    expect(RepliGenerique::plafondJour($ws, 20))->toBe(2);
});

test('R4 : valeur illisible posée hors garde ⇒ lecteur à 0', function () {
    $ws = rglEspace();
    foreach (['abc', '', '3.5', '-1', 'null', '[3]'] as $v) {
        rglPoserBrut($ws, 'envoi.repli_generique_plafond_jour', $v);
        expect(RepliGenerique::plafondJour($ws, 20))->toBe(0, "valeur « $v »");
    }
});

test('R4 : espace invalide ⇒ 0 ; erreur SQL ⇒ 0', function () {
    $ws = rglEspace();
    expect(RepliGenerique::plafondJour('pas-un-uuid', 20))->toBe(0);
    $lu = null;
    try {
        DB::transaction(function () use ($ws, &$lu) {
            DB::statement('ALTER TABLE envoi_reglages RENAME TO envoi_reglages_absente');
            $lu = RepliGenerique::plafondJour($ws, 20);
            throw new RuntimeException('__annule__');
        });
    } catch (RuntimeException) {
    }
    expect($lu)->toBe(0);
});

test('inerte : la règle ne lit que envoi_reglages et ne crée aucune ligne', function () {
    $ws = rglEspace();
    RepliGenerique::ouvert($ws);
    RepliGenerique::plafondJour($ws, 20);
    expect(DB::table('envoi_reglages')->where('workspace_id', $ws)->count())->toBe(0);
});
