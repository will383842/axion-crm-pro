<?php

use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/**
 * S1-B — schéma de fin d'envoi, partie B : essais à blanc (L8-ESSAI), adaptateur par domaine (K17-AIG),
 * décisions de feu vert (K17-FV), injections locales (L8-COMPO). Les lignes sont écrites par `pgsql_owner`
 * en auto-commit (le rôle applicatif est une autre session) et retirées dans `finally`.
 * Aucun envoi, aucune adresse réelle : seuls des noms en `*.example.test`.
 */
const S1B_MIGRATIONS = [
    '2026_12_03_000030_envoi_essais_blanc.php',
    '2026_12_03_000040_envoi_domaines_adaptateur.php',
    '2026_12_03_000050_envoi_feu_vert_decisions.php',
    '2026_12_03_000080_envoi_injections_locales.php',
];

function s1bOwner(): Connection
{
    return DB::connection('pgsql_owner');
}

function s1bApp(): Connection
{
    return DB::connection('pgsql_app');
}

function s1bContexte(Connection $c, ?string $ws): void
{
    $c->select('SELECT set_config(?, ?, false)', ['app.current_workspace_id', (string) $ws]);
}

/** @return array{0: string, 1: string} */
function s1bDeuxEspaces(): array
{
    $ids = [(string) Str::uuid(), (string) Str::uuid()];
    foreach ($ids as $id) {
        s1bOwner()->table('workspaces')->insert(['id' => $id, 'name' => 'S1B', 'slug' => 's1b-' . substr($id, 0, 8), 'created_at' => now(), 'updated_at' => now()]);
    }

    return [$ids[0], $ids[1]];
}

/** @param list<string> $ids */
function s1bNettoyer(array $ids): void
{
    $o = s1bOwner();
    s1bContexte($o, null);
    foreach (['envoi_messages_envoyes', 'envoi_injections_locales', 'envoi_injections_smtp', 'envoi_essais_blanc', 'envoi_feu_vert_decisions', 'envoi_journal_commandes'] as $t) {
        if (DB::selectOne('SELECT to_regclass(?) AS t', ['public.' . $t])->t === null) {
            continue;
        }
        $o->transaction(function () use ($o, $t, $ids): void {
            $o->statement("ALTER TABLE {$t} DISABLE TRIGGER USER");
            $o->table($t)->whereIn('workspace_id', $ids)->delete();
            $o->statement("ALTER TABLE {$t} ENABLE TRIGGER USER");
        });
    }
    $o->table('envoi_domaines')->whereIn('workspace_id', $ids)->delete();
    $o->table('workspaces')->whereIn('id', $ids)->delete();
}

function s1bDomaine(string $ws, string $nom = 'd.example.test'): int
{
    return (int) s1bOwner()->table('envoi_domaines')->insertGetId(['workspace_id' => $ws, 'nom' => $nom, 'achete_le' => '2026-01-01']);
}

function s1bRefuse(Closure $f, string $fragment = ''): void
{
    try {
        $f();
    } catch (QueryException $e) {
        expect($e->getMessage())->toContain($fragment);

        return;
    }
    throw new RuntimeException('Refus attendu, aucune erreur levée.');
}

function s1bAdaptateur(string $ws, string $nom): string
{
    return (string) s1bOwner()->table('envoi_domaines')->where('workspace_id', $ws)->where('nom', $nom)->value('adaptateur');
}

afterEach(function () {
    s1bApp()->disconnect();
    s1bOwner()->disconnect();
});

// ── Ajout seul et RLS sur les trois nouvelles tables ─────────────────────────

test('essais, décisions et injections locales : UPDATE, DELETE et TRUNCATE refusés même par le propriétaire', function () {
    [$a, $b] = s1bDeuxEspaces();
    try {
        $o = s1bOwner();
        $o->table('envoi_essais_blanc')->insert(['workspace_id' => $a, 'auteur' => 'cli', 'debut' => now(), 'fin' => now(), 'reussi' => true, 'etapes' => '[]']);
        $o->table('envoi_feu_vert_decisions')->insert(['workspace_id' => $a, 'decision' => 'accorde', 'auteur' => 'cli', 'motif' => 'ok', 'empreinte_liste' => str_repeat('a', 64), 'points' => '{}']);
        $o->table('envoi_injections_locales')->insert(['workspace_id' => $a, 'cle_idempotence' => 's1b-1', 'adaptateur' => 'faux']);
        foreach (['envoi_essais_blanc' => 'auteur', 'envoi_feu_vert_decisions' => 'auteur', 'envoi_injections_locales' => 'adaptateur'] as $table => $col) {
            s1bRefuse(fn () => $o->statement("UPDATE {$table} SET {$col} = {$col}"), 'ajout seulement');
            s1bRefuse(fn () => $o->statement("DELETE FROM {$table}"), 'ajout seulement');
            s1bRefuse(fn () => $o->statement("TRUNCATE {$table}"), 'ajout seulement');
            expect($o->table($table)->where('workspace_id', $a)->count())->toBe(1);
        }
    } finally {
        s1bNettoyer([$a, $b]);
    }
});

test('RLS : un espace ne voit pas l\'autre sur les trois nouvelles tables (connexion applicative)', function () {
    [$a, $b] = s1bDeuxEspaces();
    try {
        $o = s1bOwner();
        foreach ([$a, $b] as $ws) {
            $o->table('envoi_essais_blanc')->insert(['workspace_id' => $ws, 'auteur' => 'cli', 'debut' => now(), 'fin' => now(), 'reussi' => false, 'etapes' => '[]']);
            $o->table('envoi_feu_vert_decisions')->insert(['workspace_id' => $ws, 'decision' => 'retire', 'auteur' => 'cli', 'motif' => 'ok', 'empreinte_liste' => str_repeat('b', 64), 'points' => '{}']);
            $o->table('envoi_injections_locales')->insert(['workspace_id' => $ws, 'cle_idempotence' => 's1b-rls', 'adaptateur' => 'faux']);
        }
        foreach (['envoi_essais_blanc', 'envoi_feu_vert_decisions', 'envoi_injections_locales'] as $table) {
            $t = $o->selectOne('SELECT relrowsecurity AS actif, relforcerowsecurity AS force FROM pg_class WHERE oid = ?::regclass', ['public.' . $table]);
            expect((bool) $t->actif)->toBeTrue("{$table} : RLS inactive")->and((bool) $t->force)->toBeTrue("{$table} : RLS non forcée");
            s1bContexte(s1bApp(), $a);
            expect(s1bApp()->table($table)->pluck('workspace_id')->unique()->values()->all())->toBe([$a]);
            s1bContexte(s1bApp(), '');
            expect(s1bApp()->table($table)->count())->toBe(0);
        }
    } finally {
        s1bNettoyer([$a, $b]);
    }
});

test('un essai ou une décision de feu vert dont un texte contient @ est refusé', function () {
    [$a, $b] = s1bDeuxEspaces();
    try {
        $o = s1bOwner();
        $essai = fn (array $m) => $o->table('envoi_essais_blanc')->insert($m + ['workspace_id' => $a, 'auteur' => 'cli', 'debut' => now(), 'fin' => now(), 'reussi' => true, 'etapes' => '[]']);
        $decision = fn (array $m) => $o->table('envoi_feu_vert_decisions')->insert($m + ['workspace_id' => $a, 'decision' => 'accorde', 'auteur' => 'cli', 'motif' => 'ok', 'empreinte_liste' => str_repeat('a', 64), 'points' => '{}']);
        $essai([]);
        $decision([]);
        s1bRefuse(fn () => $essai(['auteur' => 'x@example.test']), 'check');
        s1bRefuse(fn () => $essai(['etapes' => '[{"a":"x@example.test"}]']), 'check');
        s1bRefuse(fn () => $decision(['motif' => 'x@example.test']), 'check');
        s1bRefuse(fn () => $decision(['auteur' => 'x@example.test']), 'check');
        s1bRefuse(fn () => $decision(['decision' => 'autre']), 'check');
    } finally {
        s1bNettoyer([$a, $b]);
    }
});

// ── Adaptateur par domaine (D11) ─────────────────────────────────────────────

test('adaptateur vaut faux pour tout domaine, existant ou nouveau ; la colonne refuse une autre valeur', function () {
    [$a, $b] = s1bDeuxEspaces();
    try {
        s1bDomaine($a);
        $col = DB::selectOne("SELECT is_nullable, column_default FROM information_schema.columns WHERE table_name = 'envoi_domaines' AND column_name = 'adaptateur'");
        expect($col)->not->toBeNull()->and($col->is_nullable)->toBe('NO')->and((string) $col->column_default)->toContain('faux');
        expect(s1bOwner()->table('envoi_domaines')->where('adaptateur', '<>', 'faux')->count())->toBe(0);
        s1bRefuse(fn () => s1bOwner()->table('envoi_domaines')->insert(['workspace_id' => $a, 'nom' => 'x.example.test', 'achete_le' => '2026-01-01', 'adaptateur' => 'autre']));
    } finally {
        s1bNettoyer([$a, $b]);
    }
});

test('un INSERT direct avec kumo ou smtp est refusé, même par le propriétaire', function () {
    [$a, $b] = s1bDeuxEspaces();
    try {
        foreach (['kumo', 'smtp'] as $adaptateur) {
            s1bRefuse(fn () => s1bOwner()->table('envoi_domaines')->insert(['workspace_id' => $a, 'nom' => "{$adaptateur}.example.test", 'achete_le' => '2026-01-01', 'adaptateur' => $adaptateur]), 'envoi_domaines :');
        }
        s1bOwner()->table('envoi_domaines')->insert(['workspace_id' => $a, 'nom' => 'faux.example.test', 'achete_le' => '2026-01-01', 'adaptateur' => 'faux']);
        expect(s1bOwner()->table('envoi_domaines')->where('workspace_id', $a)->count())->toBe(1);
    } finally {
        s1bNettoyer([$a, $b]);
    }
});

test('un UPDATE direct de adaptateur est refusé, même par le propriétaire ; la valeur ne bouge pas', function () {
    [$a, $b] = s1bDeuxEspaces();
    try {
        s1bDomaine($a);
        foreach (['kumo', 'smtp'] as $adaptateur) {
            s1bRefuse(fn () => s1bOwner()->statement('UPDATE envoi_domaines SET adaptateur = ? WHERE workspace_id = ?', [$adaptateur, $a]), 'envoi_domaines :');
        }
        // Même avec la variable de session posée à la main hors de la fonction : la variable n'est pas une barrière côté rôle, mais le propriétaire
        // ne peut pas non plus s'en servir pour changer la colonne sans passer par la fonction journalisée (la variable est remise à off).
        expect(s1bAdaptateur($a, 'd.example.test'))->toBe('faux');
    } finally {
        s1bNettoyer([$a, $b]);
    }
});

test('la fonction journalisée change adaptateur, journalise, et rend la variable de session à off', function () {
    [$a, $b] = s1bDeuxEspaces();
    try {
        $o = s1bOwner();
        s1bDomaine($a);
        s1bContexte($o, $a);
        expect((bool) $o->selectOne('SELECT envoi_domaine_adaptateur_changer(?, ?, ?, ?) AS r', [$a, 'd.example.test', 'kumo', 'cli'])->r)->toBeTrue();
        expect(s1bAdaptateur($a, 'd.example.test'))->toBe('kumo');
        $j = $o->table('envoi_journal_commandes')->where('workspace_id', $a)->where('commande', 'domaine-adaptateur')->get();
        expect($j)->toHaveCount(1);
        $ligne = $j->first();
        expect($ligne?->auteur)->toBe('cli')->and((string) $ligne?->details)->toContain('kumo');
        expect((string) $o->selectOne("SELECT current_setting('envoi.domaine_adaptateur', true) AS v")->v)->not->toBe('on');
        // Un UPDATE direct juste après reste refusé.
        s1bRefuse(fn () => $o->statement("UPDATE envoi_domaines SET adaptateur = 'smtp' WHERE workspace_id = ?", [$a]), 'envoi_domaines :');
        // Valeur hors liste et domaine inconnu : refus, rien d'écrit.
        s1bRefuse(fn () => $o->selectOne('SELECT envoi_domaine_adaptateur_changer(?, ?, ?, ?)', [$a, 'd.example.test', 'autre', 'cli']), 'envoi_domaines :');
        s1bRefuse(fn () => $o->selectOne('SELECT envoi_domaine_adaptateur_changer(?, ?, ?, ?)', [$a, 'inconnu.example.test', 'kumo', 'cli']), 'envoi_domaines :');
        expect($o->table('envoi_journal_commandes')->where('workspace_id', $a)->where('commande', 'domaine-adaptateur')->count())->toBe(1);
    } finally {
        s1bNettoyer([$a, $b]);
    }
});

test('la fonction journalisée : sans aucun contexte (nouvelle connexion) ou pour un autre espace ⇒ refus, rien d\'écrit', function () {
    [$a, $b] = s1bDeuxEspaces();
    try {
        s1bDomaine($a);
        s1bDomaine($b);
        s1bApp()->disconnect();
        s1bRefuse(fn () => s1bApp()->selectOne('SELECT envoi_domaine_adaptateur_changer(?, ?, ?, ?)', [$a, 'd.example.test', 'kumo', 'cli']), 'envoi_domaines :');
        s1bContexte(s1bApp(), '');
        s1bRefuse(fn () => s1bApp()->selectOne('SELECT envoi_domaine_adaptateur_changer(?, ?, ?, ?)', [$a, 'd.example.test', 'kumo', 'cli']), 'envoi_domaines :');
        s1bContexte(s1bApp(), $a);
        s1bRefuse(fn () => s1bApp()->selectOne('SELECT envoi_domaine_adaptateur_changer(?, ?, ?, ?)', [$b, 'd.example.test', 'kumo', 'cli']), 'envoi_domaines :');
        expect(s1bAdaptateur($a, 'd.example.test'))->toBe('faux')->and(s1bAdaptateur($b, 'd.example.test'))->toBe('faux')
            ->and(s1bOwner()->table('envoi_journal_commandes')->whereIn('workspace_id', [$a, $b])->where('commande', 'domaine-adaptateur')->count())->toBe(0);
        // Le rôle applicatif ne peut pas changer la colonne directement non plus.
        s1bRefuse(fn () => s1bApp()->statement("UPDATE envoi_domaines SET adaptateur = 'kumo'"), 'permission denied');
    } finally {
        s1bNettoyer([$a, $b]);
    }
});

test('envoi_adaptateurs_reels_portes : sur la connexion applicative sans contexte, rend kumo et rien d\'autre', function () {
    [$a, $b] = s1bDeuxEspaces();
    try {
        $o = s1bOwner();
        s1bDomaine($a, 'k.example.test');
        s1bDomaine($a, 'f.example.test');
        s1bDomaine($b, 'g.example.test');
        s1bContexte($o, $a);
        $o->selectOne('SELECT envoi_domaine_adaptateur_changer(?, ?, ?, ?)', [$a, 'k.example.test', 'kumo', 'cli']);
        s1bApp()->disconnect();
        $noms = array_map(fn ($l) => $l->envoi_adaptateurs_reels_portes, s1bApp()->select('SELECT envoi_adaptateurs_reels_portes()'));
        expect($noms)->toBe(['kumo']);
        // Témoin : une lecture directe sans contexte rend 0 ligne (RLS forcée), d'où la fonction.
        expect(s1bApp()->table('envoi_domaines')->count())->toBe(0);
        // La fonction ne rend que des noms d'adaptateur, jamais autre chose, et PUBLIC ne peut pas l'appeler.
        $f = DB::selectOne("SELECT prosecdef, proconfig::text AS cfg, proacl::text AS acl FROM pg_proc WHERE proname = 'envoi_adaptateurs_reels_portes'");
        expect((bool) $f->prosecdef)->toBeTrue()->and((string) $f->cfg)->toContain('search_path=pg_catalog, public')->and((string) $f->acl)->not->toMatch('/[{,]=X\//');
    } finally {
        s1bNettoyer([$a, $b]);
    }
});

/** Met à jour une colonne de `envoi_domaines` par la connexion applicative ; vrai si le droit le permet. */
function s1bMajColonne(string $ws, string $colonne): bool
{
    s1bContexte(s1bApp(), $ws);
    try {
        s1bApp()->statement("UPDATE envoi_domaines SET {$colonne} = now() WHERE workspace_id = ?", [$ws]);

        return true;
    } catch (QueryException) {
        return false;
    }
}

test('garde avec témoin : les mises à jour des autres colonnes passent encore, y compris par le rôle applicatif ; retirer une colonne du GRANT rougit', function () {
    [$a, $b] = s1bDeuxEspaces();
    $role = '"' . str_replace('"', '""', (string) config('database.connections.pgsql_app.username', 'axion_app')) . '"';
    try {
        s1bDomaine($a);
        $colonnes = ['spf_verifie_le', 'dkim_verifie_le', 'dmarc_verifie_le'];
        foreach ($colonnes as $c) {
            expect(s1bMajColonne($a, $c))->toBeTrue("{$c} : la mise à jour doit passer");
        }
        // Le propriétaire met toujours à jour les autres colonnes (déclencheur sans effet sur elles).
        s1bOwner()->statement("UPDATE envoi_domaines SET flux = 'prospection' WHERE workspace_id = ?", [$a]);
        // Le droit de table est retiré : seul un UPDATE de colonne listée passe.
        expect((bool) s1bOwner()->selectOne('SELECT has_table_privilege(?, ?, ?) AS r', [trim($role, '"'), 'public.envoi_domaines', 'UPDATE'])->r)->toBeFalse();
        expect((bool) s1bOwner()->selectOne('SELECT has_column_privilege(?, ?, ?, ?) AS r', [trim($role, '"'), 'public.envoi_domaines', 'adaptateur', 'UPDATE'])->r)->toBeFalse();
        // Témoin : on retire une colonne du GRANT, la garde rougit.
        try {
            s1bOwner()->statement("REVOKE UPDATE (spf_verifie_le) ON public.envoi_domaines FROM {$role}");
            expect(s1bMajColonne($a, 'spf_verifie_le'))->toBeFalse();
        } finally {
            s1bOwner()->statement("GRANT UPDATE (spf_verifie_le) ON public.envoi_domaines TO {$role}");
        }
        expect(s1bMajColonne($a, 'spf_verifie_le'))->toBeTrue();
    } finally {
        s1bNettoyer([$a, $b]);
    }
});

// ── Injections locales et envoi_verifie_id_injection ─────────────────────────

function s1bRegistre(Connection $c, string $ws, string $idInjection, string $marque): void
{
    $c->table('envoi_messages_envoyes')->insert([
        'workspace_id' => $ws, 'message_id_empreinte' => hash('sha256', 's1b-' . $marque), 'id_injection' => $idInjection,
        'empreinte_destinataire' => hash('sha256', 's1b-dest-' . $marque),
    ]);
}

test('envoi_verifie_id_injection accepte une injection locale du même espace, la refuse pour un autre, refuse l\'inconnu', function () {
    [$a, $b] = s1bDeuxEspaces();
    try {
        $o = s1bOwner();
        $local = (string) $o->table('envoi_injections_locales')->insertGetId(['workspace_id' => $a, 'cle_idempotence' => 's1b-loc', 'adaptateur' => 'faux'], 'id_injection');
        s1bRegistre($o, $a, $local, 'ok'); // même espace : accepté
        s1bRefuse(fn () => s1bRegistre($o, $b, $local, 'autre-espace'), 'id_injection inconnu');
        s1bRefuse(fn () => s1bRegistre($o, $a, (string) Str::uuid(), 'inconnu'), 'id_injection inconnu');
        expect($o->table('envoi_messages_envoyes')->whereIn('workspace_id', [$a, $b])->count())->toBe(1);
        // Par la connexion applicative (déclencheur SECURITY INVOKER : le droit SELECT sur la table locale est nécessaire).
        s1bContexte(s1bApp(), $a);
        s1bRegistre(s1bApp(), $a, $local, 'app');
        expect(s1bApp()->table('envoi_messages_envoyes')->count())->toBe(2);
    } finally {
        s1bNettoyer([$a, $b]);
    }
});

test('envoi_injections_locales : unique par espace et clé, droits SELECT + INSERT seulement, un identifiant est rendu', function () {
    [$a, $b] = s1bDeuxEspaces();
    try {
        $o = s1bOwner();
        $o->table('envoi_injections_locales')->insert(['workspace_id' => $a, 'cle_idempotence' => 's1b-u', 'adaptateur' => 'faux']);
        $o->table('envoi_injections_locales')->insert(['workspace_id' => $b, 'cle_idempotence' => 's1b-u', 'adaptateur' => 'faux']);
        s1bRefuse(fn () => $o->table('envoi_injections_locales')->insert(['workspace_id' => $a, 'cle_idempotence' => 's1b-u', 'adaptateur' => 'faux']), 'unique');
        expect($o->table('envoi_injections_locales')->where('workspace_id', $a)->value('id_injection'))->toMatch('/^[0-9a-f-]{36}$/');
        $role = (string) config('database.connections.pgsql_app.username');
        foreach (['UPDATE', 'DELETE', 'TRUNCATE'] as $droit) {
            expect((bool) $o->selectOne('SELECT has_table_privilege(?, ?, ?) AS r', [$role, 'public.envoi_injections_locales', $droit])->r)->toBeFalse("{$droit} accordé");
        }
        foreach (['SELECT', 'INSERT'] as $droit) {
            expect((bool) $o->selectOne('SELECT has_table_privilege(?, ?, ?) AS r', [$role, 'public.envoi_injections_locales', $droit])->r)->toBeTrue("{$droit} refusé");
        }
    } finally {
        s1bNettoyer([$a, $b]);
    }
});

// ── Transverse : fonctions et migrations ─────────────────────────────────────

test('toute fonction créée ou remplacée par S1-B a un search_path fixe ; les SECURITY DEFINER ne sont pas appelables par PUBLIC', function () {
    $noms = ['envoi_domaine_adaptateur_changer', 'envoi_adaptateurs_reels_portes', 'envoi_domaines_adaptateur_garde', 'envoi_verifie_id_injection',
        'envoi_essais_blanc_ajout_seul', 'envoi_feu_vert_decisions_ajout_seul', 'envoi_injections_locales_ajout_seul'];
    foreach ($noms as $nom) {
        $f = DB::selectOne('SELECT prosecdef, proconfig::text AS cfg, proacl::text AS acl FROM pg_proc WHERE proname = ?', [$nom]);
        expect($f)->not->toBeNull("{$nom} absente");
        expect((string) $f->cfg)->toContain('search_path=pg_catalog, public');
        if ($f->prosecdef) {
            expect((string) $f->acl)->not->toMatch('/[{,]=X\//');
        }
    }
    expect((string) DB::selectOne("SELECT proconfig::text AS c FROM pg_proc WHERE proname = 'envoi_domaine_adaptateur_changer'")->c)->toContain('envoi.domaine_adaptateur=on');
});

test('chaque up() de S1-B rejoué deux fois ne casse rien et ne touche aucune ligne', function () {
    // Tout se passe sur la connexion du test (transaction annulée à la fin) : les ALTER TABLE rejoués verrouillent `envoi_domaines`
    // jusqu'à la fin de la transaction, une autre connexion attendrait indéfiniment.
    $ws = (string) Str::uuid();
    DB::table('workspaces')->insert(['id' => $ws, 'name' => 'S1B', 'slug' => 's1b-' . substr($ws, 0, 8), 'created_at' => now(), 'updated_at' => now()]);
    DB::table('envoi_domaines')->insert(['workspace_id' => $ws, 'nom' => 'd.example.test', 'achete_le' => '2026-01-01']);
    $avant = DB::selectOne('SELECT count(*) AS t FROM envoi_domaines')->t;
    foreach (S1B_MIGRATIONS as $fichier) {
        $m = require database_path('migrations/' . $fichier);
        $m->up();
        $m->up();
        $m->down();
    }
    expect(DB::selectOne('SELECT count(*) AS t FROM envoi_domaines')->t)->toBe($avant)
        ->and(DB::table('envoi_domaines')->where('workspace_id', $ws)->value('adaptateur'))->toBe('faux');
});
