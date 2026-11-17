<?php

use App\Support\WorkspaceContext;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/*
 * Lot RR-0 — migration seule : canal d'opposition `releve` et geste `annulation`. Chaque test de sécurité est joué SOUS LE RÔLE
 * APPLICATIF (pgsql_app, RLS) ET SOUS LE PROPRIÉTAIRE. Données fictives seulement (aucune adresse réelle, aucune personne).
 */
uses(TestCase::class, RefreshDatabase::class);

const RR0_MODES = ['app', 'proprio'];
const RR0_MIGRATION = '2026_12_11_000100_envoi_retours_reactions.php';

function rr0Espace(): string
{
    $id = (string) Str::uuid();
    DB::table('workspaces')->insert(['id' => $id, 'name' => 'Rr0', 'slug' => 'rr0-' . substr($id, 0, 8), 'created_at' => now(), 'updated_at' => now()]);

    return $id;
}

/** Le SQLSTATE de $f joué dans une transaction annulée (null = accepté). */
function rr0Echec(Closure $f): ?string
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

/** « app » = rôle applicatif non propriétaire sous RLS ; « proprio » = propriétaire. */
function rr0Code(string $mode, string $ws, Closure $f): ?string
{
    return rr0Echec(function () use ($mode, $ws, $f) {
        if ($mode === 'app') {
            WorkspaceContext::set($ws);
            DB::statement('SET LOCAL ROLE "' . str_replace('"', '""', (string) config('database.connections.pgsql_app.username')) . '"');
        }
        $f();
        if ($mode === 'app') {
            DB::statement('RESET ROLE');
        }
    });
}

/** Lien de désinscription fictif (la clé composite exigée par envoi_oppositions) ; rend son id. */
function rr0Lien(string $ws): int
{
    return DB::table('envoi_liens_desinscription')->insertGetId(['workspace_id' => $ws, 'empreinte_adresse' => hash('sha256', Str::random(20)), 'portee' => 'business']);
}

function rr0Opposition(string $ws, int $lien, string $canal): void
{
    DB::table('envoi_oppositions')->insert(['workspace_id' => $ws, 'lien_id' => $lien, 'empreinte_adresse' => hash('sha256', Str::random(20)), 'canal' => $canal]);
}

/** Réponse fictive (événement + réponse chiffrée factice) posée par le propriétaire ; rend l'id de la réponse. */
function rr0Reponse(string $ws): int
{
    $evenement = DB::table('envoi_evenements')->insertGetId([
        'workspace_id' => $ws, 'boite' => (string) Str::uuid(), 'uid_validity' => 1, 'uid' => random_int(1, 1_000_000),
        'empreinte_message' => hash('sha256', Str::random(20)), 'classe' => 'reponse_humaine', 'statut' => 'a_rattacher',
        'empreinte_expediteur' => hash('sha256', Str::random(20)), 'recu_le' => now(),
    ]);

    return (int) DB::table('envoi_reponses')->insertGetId([
        'workspace_id' => $ws, 'evenement_id' => $evenement, 'objet_chiffre' => 'x', 'extrait_chiffre' => 'x', 'expediteur_chiffre' => 'x',
        'empreinte_expediteur' => hash('sha256', Str::random(20)), 'recue_le' => now(), 'conserver_jusqu_au' => now()->addYear()->toDateString(),
    ]);
}

function rr0Geste(string $ws, int $reponse, string $geste, ?int $contact = null): void
{
    DB::table('envoi_reponses_classements')->insert([
        'workspace_id' => $ws, 'reponse_id' => $reponse, 'geste' => $geste, 'classement' => 'interesse', 'confiance' => 100,
        'auteur' => 'humain', 'contact_id' => $contact,
    ]);
}

test('S1 canal d\'opposition : `releve` accepté, un canal inconnu refusé (23514), rôle applicatif ET propriétaire', function () {
    foreach (RR0_MODES as $mode) {
        $ws = rr0Espace();
        $lien = rr0Lien($ws);
        expect(rr0Code($mode, $ws, fn () => rr0Opposition($ws, $lien, 'releve')))->toBeNull("[$mode] releve")
            ->and(rr0Code($mode, $ws, fn () => rr0Opposition($ws, $lien, 'imap')))->toBe('23514', "[$mode] imap");
        // Les canaux anciens restent admis.
        foreach (['page', 'one_click', 'reponse'] as $canal) {
            expect(rr0Code($mode, $ws, fn () => rr0Opposition($ws, $lien, $canal)))->toBeNull("[$mode] $canal");
        }
    }
});

test('S2 geste : `annulation` accepté, `effacement` refusé, annulation avec contact refusée (23514), app ET propriétaire', function () {
    foreach (RR0_MODES as $mode) {
        $ws = rr0Espace();
        $reponse = rr0Reponse($ws);
        expect(rr0Code($mode, $ws, fn () => rr0Geste($ws, $reponse, 'annulation')))->toBeNull("[$mode] annulation")
            ->and(rr0Code($mode, $ws, fn () => rr0Geste($ws, $reponse, 'effacement')))->toBe('23514', "[$mode] effacement")
            ->and(rr0Code($mode, $ws, fn () => rr0Geste($ws, $reponse, 'annulation', 42)))->toBe('23514', "[$mode] annulation+contact");
        // Les gestes anciens restent admis.
        expect(rr0Code($mode, $ws, fn () => rr0Geste($ws, $reponse, 'classement')))->toBeNull("[$mode] classement")
            ->and(rr0Code($mode, $ws, fn () => rr0Geste($ws, $reponse, 'fiche', 42)))->toBeNull("[$mode] fiche");
    }
});

test('S3 un seul CHECK sur `geste` et un seul sur `canal` ; ajout seul intact sur une annulation (app ET propriétaire)', function () {
    $checks = fn (string $table, string $colonne) => DB::select(
        "SELECT conname FROM pg_constraint WHERE conrelid = ?::regclass AND contype = 'c' AND pg_get_constraintdef(oid) LIKE ?",
        [$table, '%' . $colonne . ' = ANY%'],
    );
    expect($checks('envoi_reponses_classements', 'geste'))->toHaveCount(1)
        ->and($checks('envoi_oppositions', 'canal'))->toHaveCount(1);
    expect(DB::selectOne("SELECT pg_get_constraintdef(oid) AS d FROM pg_constraint WHERE conname = 'envoi_reponses_classements_geste_check'")->d)->toContain('annulation');
    expect(DB::selectOne("SELECT pg_get_constraintdef(oid) AS d FROM pg_constraint WHERE conname = 'envoi_oppositions_canal_check'")->d)->toContain('releve');

    foreach (RR0_MODES as $mode) {
        $ws = rr0Espace();
        $reponse = rr0Reponse($ws);
        $modifier = rr0Code($mode, $ws, function () use ($ws, $reponse) {
            rr0Geste($ws, $reponse, 'annulation');
            DB::table('envoi_reponses_classements')->where('reponse_id', $reponse)->update(['confiance' => 1]);
        });
        $effacer = rr0Code($mode, $ws, function () use ($ws, $reponse) {
            rr0Geste($ws, $reponse, 'annulation');
            DB::table('envoi_reponses_classements')->where('reponse_id', $reponse)->delete();
        });
        // Propriétaire : déclencheur (P0001) ; rôle applicatif : droit retiré (42501). Dans les deux cas refusé.
        expect($modifier)->toBeIn(['P0001', '42501'], "[$mode] update")
            ->and($effacer)->toBeIn(['P0001', '42501'], "[$mode] delete");
    }
});

test('S4 up() rejouable deux fois sans erreur, contraintes toujours uniques', function () {
    $migration = require database_path('migrations/' . RR0_MIGRATION);
    $migration->up();
    $migration->up();
    expect(DB::selectOne("SELECT count(*) AS n FROM pg_constraint WHERE conname IN ('envoi_reponses_classements_geste_check','envoi_oppositions_canal_check')")->n)->toBe(2);
    // down() inerte : il ne retire rien.
    $migration->down();
    $ws = rr0Espace();
    expect(rr0Code('proprio', $ws, fn () => rr0Geste($ws, rr0Reponse($ws), 'annulation')))->toBeNull();
});
