<?php

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

/*
 * Lot G3-0 — schéma de la double authentification par courriel : colonne users.double_auth_methode,
 * tables codes_connexion_courriel et appareils_confiance. Migration seule : rien ne les lit.
 * Les écritures sont rejouées SOUS LE RÔLE APPLICATIF (SET LOCAL ROLE) ET SOUS LE PROPRIÉTAIRE.
 * Données fictives seulement (adresses en example.test).
 */
uses(TestCase::class, RefreshDatabase::class);

const DAC_TABLES = ['codes_connexion_courriel', 'appareils_confiance'];
const DAC_MODES = ['app', 'proprio'];
const DAC_MIGRATION = 'database/migrations/2026_12_08_000200_double_auth_courriel.php';

function dacUtilisateur(?string $methode = null): string
{
    $id = (string) Str::uuid();
    DB::table('users')->insert(['id' => $id, 'email' => 'dac-' . substr($id, 0, 8) . '@example.test', 'name' => 'Compte fictif']);
    if ($methode !== null) {
        DB::table('users')->where('id', $id)->update(['double_auth_methode' => $methode]);
    }

    return $id;
}

function dacCode(string $user): array
{
    return ['user_id' => $user, 'objet' => 'connexion', 'code_hash' => hash('sha256', 'fictif'), 'expire_le' => now()->addMinutes(10)];
}

function dacAppareil(string $user): array
{
    return ['user_id' => $user, 'jeton_hash' => hash('sha256', Str::uuid()->toString()), 'nom' => 'Navigateur fictif', 'expire_le' => now()->addDays(30)];
}

/** Joue $f sous le mode (« app » = rôle applicatif non propriétaire, « proprio » = propriétaire) ; rend le SQLSTATE (null = accepté). */
function dacCodeSql(string $mode, Closure $f): ?string
{
    try {
        DB::transaction(function () use ($mode, $f) {
            if ($mode === 'app') {
                DB::statement('SET LOCAL ROLE "' . str_replace('"', '""', (string) config('database.connections.pgsql_app.username')) . '"');
            }
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

it('G3-0 la colonne existe, NULL partout, et le CHECK refuse sms', function () {
    expect(Schema::hasColumn('users', 'double_auth_methode'))->toBeTrue();
    $u = dacUtilisateur();
    expect(DB::table('users')->where('id', $u)->value('double_auth_methode'))->toBeNull()
        ->and(DB::table('users')->whereNotNull('double_auth_methode')->count())->toBe(0);
    foreach (['totp', 'email'] as $ok) {
        expect(dacCodeSql('proprio', fn () => DB::table('users')->where('id', $u)->update(['double_auth_methode' => $ok])))->toBeNull();
    }
    expect(dacCodeSql('proprio', fn () => DB::table('users')->where('id', $u)->update(['double_auth_methode' => 'sms'])))->toBe('23514');
});

it('G3-0 les deux tables et leurs index partiels existent avec le prédicat exact', function () {
    foreach (DAC_TABLES as $t) {
        expect(Schema::hasTable($t))->toBeTrue();
    }
    $def = fn (string $i) => (string) DB::selectOne('SELECT indexdef FROM pg_indexes WHERE schemaname = ? AND indexname = ?', ['public', $i])?->indexdef;
    $codes = $def('idx_codes_courriel_actifs');
    expect($codes)->toContain('ON public.codes_connexion_courriel')
        ->and($codes)->toContain('(user_id, objet, cree_le DESC)')
        ->and($codes)->toContain('WHERE ((consomme_le IS NULL) AND (remplace_le IS NULL))');
    $app = $def('idx_appareils_confiance_actifs');
    expect($app)->toContain('ON public.appareils_confiance')
        ->and($app)->toContain('(user_id, ajoute_le DESC)')
        ->and($app)->toContain('WHERE (retire_le IS NULL)');
    // jeton_hash UNIQUE
    $u = dacUtilisateur();
    $a = dacAppareil($u);
    DB::table('appareils_confiance')->insert($a);
    expect(dacCodeSql('proprio', fn () => DB::table('appareils_confiance')->insert($a)))->toBe('23505');
});

it('G3-0 contraintes : objet limité à connexion et geste, essais à 0 par défaut', function () {
    $u = dacUtilisateur();
    expect(dacCodeSql('proprio', fn () => DB::table('codes_connexion_courriel')->insert(['objet' => 'autre'] + dacCode($u))))->toBe('23514')
        ->and(dacCodeSql('proprio', fn () => DB::table('codes_connexion_courriel')->insert(['objet' => 'geste'] + dacCode($u))))->toBeNull();
    DB::table('codes_connexion_courriel')->insert(dacCode($u));
    expect((int) DB::table('codes_connexion_courriel')->where('user_id', $u)->value('essais'))->toBe(0);
});

foreach (DAC_MODES as $mode) {
    it("G3-0 [$mode] INSERT et UPDATE permis ; DELETE et TRUNCATE refusés (42501 sous le rôle applicatif)", function () use ($mode) {
        $u = dacUtilisateur();
        $code = (string) Str::uuid();
        $app = (string) Str::uuid();
        DB::table('codes_connexion_courriel')->insert(['id' => $code] + dacCode($u));
        DB::table('appareils_confiance')->insert(['id' => $app] + dacAppareil($u));

        expect(dacCodeSql($mode, fn () => DB::table('codes_connexion_courriel')->insert(dacCode($u))))->toBeNull()
            ->and(dacCodeSql($mode, fn () => DB::table('appareils_confiance')->insert(dacAppareil($u))))->toBeNull()
            ->and(dacCodeSql($mode, fn () => DB::table('codes_connexion_courriel')->where('id', $code)->update(['essais' => 1, 'consomme_le' => now()])))->toBeNull()
            ->and(dacCodeSql($mode, fn () => DB::table('appareils_confiance')->where('id', $app)->update(['retire_le' => now(), 'vu_le' => now()])))->toBeNull();

        // Sous le propriétaire (superutilisateur en test) la barrière n'est pas le droit : seul le rôle applicatif est testé ici.
        if ($mode === 'app') {
            foreach (DAC_TABLES as $t) {
                expect(dacCodeSql($mode, fn () => DB::table($t)->delete()))->toBe('42501')
                    ->and(dacCodeSql($mode, fn () => DB::statement("TRUNCATE TABLE {$t}")))->toBe('42501');
            }
        }
        // Les lignes sont toujours là.
        expect(DB::table('codes_connexion_courriel')->where('id', $code)->exists())->toBeTrue()
            ->and(DB::table('appareils_confiance')->where('id', $app)->exists())->toBeTrue();
    });
}

it('G3-0 structure identique sous les deux rôles : droits de table du rôle applicatif', function () {
    $role = (string) config('database.connections.pgsql_app.username');
    foreach (DAC_TABLES as $t) {
        $priv = fn (string $p) => (bool) DB::selectOne('SELECT has_table_privilege(?, ?, ?) AS ok', [$role, 'public.' . $t, $p])->ok;
        expect($priv('SELECT'))->toBeTrue()->and($priv('INSERT'))->toBeTrue()->and($priv('UPDATE'))->toBeTrue()
            ->and($priv('DELETE'))->toBeFalse()->and($priv('TRUNCATE'))->toBeFalse();
    }
});

it('G3-0 ON DELETE RESTRICT : effacer un compte qui a un appareil ou un code lève 23503', function () {
    $u = dacUtilisateur();
    DB::table('appareils_confiance')->insert(dacAppareil($u));
    expect(dacCodeSql('proprio', fn () => DB::table('users')->where('id', $u)->delete()))->toBe('23503');
    $v = dacUtilisateur();
    DB::table('codes_connexion_courriel')->insert(dacCode($v));
    expect(dacCodeSql('proprio', fn () => DB::table('users')->where('id', $v)->delete()))->toBe('23503');
    expect(DB::table('users')->whereIn('id', [$u, $v])->count())->toBe(2);
});

it('G3-0 aucune colonne identifiante ni JSON dans les deux tables', function () {
    $interdites = ['email', 'subject_email', 'from_address', 'to_addresses', 'phone', 'email_hash', 'person_key'];
    $cols = DB::select("SELECT table_name, column_name, data_type FROM information_schema.columns WHERE table_schema = 'public' AND table_name IN ('codes_connexion_courriel', 'appareils_confiance')");
    expect($cols)->not->toBeEmpty();
    foreach ($cols as $c) {
        expect($interdites)->not->toContain($c->column_name)
            ->and(in_array($c->data_type, ['json', 'jsonb'], true))->toBeFalse();
    }
});

it('G3-0 down() inerte : rien n\'est retiré, la ligne users reste intacte, up() rejouable', function () {
    $u = dacUtilisateur('email');
    $migration = require base_path(DAC_MIGRATION);
    $migration->down();
    expect(Schema::hasTable('codes_connexion_courriel'))->toBeTrue()
        ->and(Schema::hasTable('appareils_confiance'))->toBeTrue()
        ->and(Schema::hasColumn('users', 'double_auth_methode'))->toBeTrue()
        ->and(DB::table('users')->where('id', $u)->value('double_auth_methode'))->toBe('email');
    $migration->up();
    $migration->up(); // rejouable (gardes hasColumn / hasTable)
    expect(Schema::hasTable('appareils_confiance'))->toBeTrue();
});

it('G3-0 la migration est additive : ni up() ni down() ne contiennent DROP, DELETE, TRUNCATE ou UPDATE', function () {
    $src = (string) file_get_contents(base_path(DAC_MIGRATION));
    $code = preg_replace('#/\*.*?\*/|//[^\n]*#s', '', explode('function up', $src)[1]);
    expect($code)->not->toMatch('/\b(DROP|DELETE\s+FROM|TRUNCATE\s+TABLE|UPDATE\s+\w+\s+SET)\b/i');
    expect(explode('function down', $src)[1])->not->toMatch('/DB::|Schema::/');
});
