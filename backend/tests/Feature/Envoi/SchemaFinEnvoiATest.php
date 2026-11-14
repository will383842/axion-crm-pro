<?php

use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/**
 * S1-A — schéma de fin d'envoi, partie A : butée de plafond par espace (D7), conservation des tentatives (D16),
 * gestes de séquence (D6). Les lignes sont écrites par `pgsql_owner` en auto-commit (le rôle applicatif est une
 * autre session) et retirées dans `finally`. Aucun envoi, aucune adresse : seuls des compteurs et des identifiants.
 */
const S1A_MIGRATIONS = [
    '2026_12_03_000010_envoi_compteur_espace.php',
    '2026_12_03_000060_envoi_tentatives_retention.php',
    '2026_12_03_000070_envoi_sequences_gestes.php',
];

function s1aOwner(): Connection
{
    return DB::connection('pgsql_owner');
}

function s1aApp(): Connection
{
    return DB::connection('pgsql_app');
}

function s1aContexte(Connection $c, ?string $ws): void
{
    $c->select('SELECT set_config(?, ?, false)', ['app.current_workspace_id', (string) $ws]);
}

/** @return array{0: string, 1: string} */
function s1aDeuxEspaces(): array
{
    $ids = [(string) Str::uuid(), (string) Str::uuid()];
    foreach ($ids as $id) {
        s1aOwner()->table('workspaces')->insert(['id' => $id, 'name' => 'S1A', 'slug' => 's1a-' . substr($id, 0, 8), 'created_at' => now(), 'updated_at' => now()]);
    }

    return [$ids[0], $ids[1]];
}

/** @param list<string> $ids */
function s1aNettoyer(array $ids, ?string $jour = null): void
{
    $o = s1aOwner();
    s1aContexte($o, null);
    foreach (['envoi_sequences_gestes', 'envoi_tentatives_jours', 'envoi_tentatives'] as $t) {
        $o->transaction(function () use ($o, $t, $ids): void {
            $o->statement("ALTER TABLE {$t} DISABLE TRIGGER USER");
            $o->table($t)->whereIn('workspace_id', $ids)->delete();
            $o->statement("ALTER TABLE {$t} ENABLE TRIGGER USER");
        });
    }
    $o->table('envoi_compteur_espace')->whereIn('workspace_id', $ids)->delete();
    $o->table('envoi_sequences')->whereIn('workspace_id', $ids)->delete();
    if ($jour !== null) {
        $o->table('envoi_compteur_global')->where('jour', $jour)->delete();
    }
    $o->table('workspaces')->whereIn('id', $ids)->delete();
}

function s1aJourLointain(): string
{
    return (new DateTimeImmutable('2100-01-01'))->modify('+' . random_int(0, 3000) . ' days')->format('Y-m-d');
}

function s1aTentative(string $ws, string $cree, string $issue = 'acceptee', ?int $code = null): void
{
    s1aOwner()->table('envoi_tentatives')->insert([
        'workspace_id' => $ws, 'fournisseur' => 'gmail', 'issue' => $issue, 'code_smtp' => $code,
        'cle_idempotence' => 's1a-' . Str::uuid(), 'cree_le' => $cree,
    ]);
}

function s1aRefuse(Closure $f, string $fragment = ''): void
{
    try {
        $f();
    } catch (QueryException $e) {
        expect($e->getMessage())->toContain($fragment);

        return;
    }
    throw new RuntimeException('Refus attendu, aucune erreur levée.');
}

afterEach(function () {
    s1aApp()->disconnect();
    s1aOwner()->disconnect();
});

// ── D7 — envoi_compteur_espace ───────────────────────────────────────────────

test('compteur : la 301e place est refusée même avec un plafond demandé de 1000', function () {
    [$a, $b] = s1aDeuxEspaces();
    $jour = s1aJourLointain();
    try {
        s1aContexte(s1aOwner(), $a);
        $ok = 0;
        for ($i = 0; $i < 305; $i++) {
            $ok += s1aOwner()->selectOne('SELECT envoi_compteur_espace_reserver(?, ?, 1000) AS r', [$a, $jour])->r ? 1 : 0;
        }
        expect($ok)->toBe(300)
            ->and((int) s1aOwner()->table('envoi_compteur_espace')->where('workspace_id', $a)->value('n'))->toBe(300);
    } finally {
        s1aNettoyer([$a, $b]);
    }
});

test('compteur : un plafond inférieur à 1 rend faux sans écrire aucune ligne', function () {
    [$a, $b] = s1aDeuxEspaces();
    $jour = s1aJourLointain();
    try {
        s1aContexte(s1aOwner(), $a);
        foreach ([0, -5] as $plafond) {
            expect((bool) s1aOwner()->selectOne('SELECT envoi_compteur_espace_reserver(?, ?, ?) AS r', [$a, $jour, $plafond])->r)->toBeFalse();
        }
        expect(s1aOwner()->table('envoi_compteur_espace')->where('workspace_id', $a)->count())->toBe(0);
    } finally {
        s1aNettoyer([$a, $b]);
    }
});

test('compteur : refus si p_ws diffère du contexte, et sans aucun contexte ; rien n\'est écrit', function () {
    [$a, $b] = s1aDeuxEspaces();
    $jour = s1aJourLointain();
    try {
        // Autre espace que le contexte.
        s1aContexte(s1aApp(), $a);
        expect((bool) s1aApp()->selectOne('SELECT envoi_compteur_espace_reserver(?, ?, 10) AS r', [$b, $jour])->r)->toBeFalse();
        // Nouvelle connexion : aucun contexte posé (valeur NULL).
        s1aApp()->disconnect();
        expect((bool) s1aApp()->selectOne('SELECT envoi_compteur_espace_reserver(?, ?, 10) AS r', [$a, $jour])->r)->toBeFalse();
        // Contexte vide.
        s1aContexte(s1aApp(), '');
        expect((bool) s1aApp()->selectOne('SELECT envoi_compteur_espace_reserver(?, ?, 10) AS r', [$a, $jour])->r)->toBeFalse();
        expect(s1aOwner()->table('envoi_compteur_espace')->whereIn('workspace_id', [$a, $b])->count())->toBe(0);
    } finally {
        s1aNettoyer([$a, $b]);
    }
});

test('compteur : le rôle applicatif a SELECT seul sur la table ; EXECUTE réservé (pas PUBLIC) ; aucune clé étrangère', function () {
    $role = (string) config('database.connections.pgsql_app.username');
    foreach (['INSERT', 'UPDATE', 'DELETE', 'TRUNCATE'] as $droit) {
        expect((bool) s1aOwner()->selectOne('SELECT has_table_privilege(?, ?, ?) AS r', [$role, 'public.envoi_compteur_espace', $droit])->r)->toBeFalse("{$droit} accordé");
    }
    expect((bool) s1aOwner()->selectOne('SELECT has_table_privilege(?, ?, ?) AS r', [$role, 'public.envoi_compteur_espace', 'SELECT'])->r)->toBeTrue();
    $sig = 'public.envoi_compteur_espace_reserver(uuid, date, integer)';
    expect((bool) s1aOwner()->selectOne('SELECT has_function_privilege(?, ?, ?) AS r', [$role, $sig, 'EXECUTE'])->r)->toBeTrue();
    $acl = (string) s1aOwner()->selectOne('SELECT COALESCE(proacl::text, \'PUBLIC\') AS a FROM pg_proc WHERE proname = ?', ['envoi_compteur_espace_reserver'])->a;
    expect($acl)->not->toContain('PUBLIC')->and($acl)->not->toMatch('/[{,]=X\//');
    expect(s1aOwner()->selectOne("SELECT count(*) AS n FROM pg_constraint WHERE conrelid = 'public.envoi_compteur_espace'::regclass AND contype = 'f'")->n)->toBe(0);
});

test('compteur : deux processus concurrents n\'obtiennent jamais plus de places que le plafond', function () {
    [$a, $b] = s1aDeuxEspaces();
    $jour = s1aJourLointain();
    try {
        $c = config('database.connections.pgsql_app');
        $script = '$p=new PDO("pgsql:host=".$argv[1].";port=".$argv[2].";dbname=".$argv[3],$argv[4],$argv[5]);'
            . '$p->prepare("SELECT set_config(\'app.current_workspace_id\', ?, false)")->execute([$argv[6]]);'
            . 'while(microtime(true)<(float)$argv[9]){usleep(500);}'
            . '$s=$p->prepare("SELECT envoi_compteur_espace_reserver(?::uuid, ?::date, ?::int) AS r");$s->execute([$argv[6],$argv[7],(int)$argv[8]]);'
            . 'echo $s->fetchColumn()?"1":"0";';
        $depart = microtime(true) + 2.5;
        $procs = [];
        for ($i = 0; $i < 8; $i++) {
            $cmd = [PHP_BINARY, '-r', $script, '--', (string) $c['host'], (string) $c['port'], (string) $c['database'], (string) $c['username'], (string) $c['password'], $a, $jour, '3', (string) $depart];
            $p = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            expect(is_resource($p))->toBeTrue();
            $procs[] = [$p, $pipes];
        }
        $places = 0;
        foreach ($procs as [$p, $pipes]) {
            $places += (int) trim((string) stream_get_contents($pipes[1]));
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($p);
        }
        expect($places)->toBe(3)
            ->and((int) s1aOwner()->table('envoi_compteur_espace')->where('workspace_id', $a)->value('n'))->toBe(3);
    } finally {
        s1aNettoyer([$a, $b]);
    }
});

test('libération : envoi_injection_liberer décrémente aussi le compteur d\'espace, une fois, jamais sous 0', function () {
    [$a, $b] = s1aDeuxEspaces();
    $jour = s1aJourLointain();
    $o = s1aOwner();
    try {
        s1aContexte($o, $a);
        $o->table('envoi_compteur_global')->insert(['jour' => $jour, 'n' => 2]);
        $o->table('envoi_compteur_espace')->insert(['workspace_id' => $a, 'jour' => $jour, 'n' => 2]);
        $o->table('envoi_compteur_espace')->insert(['workspace_id' => $b, 'jour' => $jour, 'n' => 2]);
        foreach (['s1a-r1', 's1a-r2'] as $cle) {
            $o->table('envoi_journal_commandes')->insert([
                'workspace_id' => $a, 'commande' => 'injection', 'auteur' => 'passerelle', 'details' => '{}',
                'jour' => $jour, 'cle_idempotence' => $cle . '-' . $a, 'statut' => 'reservee',
            ]);
        }
        $n = fn (string $ws) => (int) $o->table('envoi_compteur_espace')->where('workspace_id', $ws)->where('jour', $jour)->value('n');

        expect((bool) $o->selectOne('SELECT envoi_injection_liberer(?, ?) AS r', [$a, 's1a-r1-' . $a])->r)->toBeTrue();
        expect($n($a))->toBe(1)->and($n($b))->toBe(2);
        expect((bool) $o->selectOne('SELECT envoi_injection_liberer(?, ?) AS r', [$a, 's1a-r1-' . $a])->r)->toBeFalse();
        expect($n($a))->toBe(1);
        // Compteur déjà à 0 : le plancher tient.
        $o->table('envoi_compteur_espace')->where('workspace_id', $a)->update(['n' => 0]);
        expect((bool) $o->selectOne('SELECT envoi_injection_liberer(?, ?) AS r', [$a, 's1a-r2-' . $a])->r)->toBeTrue();
        expect($n($a))->toBe(0);
    } finally {
        s1aNettoyer([$a, $b], $jour);
    }
});

// ── D16 — conservation de envoi_tentatives ───────────────────────────────────

test('tentatives : UPDATE, DELETE et TRUNCATE refusés (ligne récente ou ancienne, même par le propriétaire)', function () {
    [$a, $b] = s1aDeuxEspaces();
    try {
        s1aTentative($a, now()->toDateTimeString());
        s1aTentative($a, now()->subDays(500)->toDateTimeString());
        s1aRefuse(fn () => s1aOwner()->statement("UPDATE envoi_tentatives SET issue = 'refusee' WHERE workspace_id = ?", [$a]), 'ajout seulement');
        s1aRefuse(fn () => s1aOwner()->statement('DELETE FROM envoi_tentatives WHERE workspace_id = ?', [$a]), 'ajout seulement');
        s1aRefuse(fn () => s1aOwner()->statement('TRUNCATE envoi_tentatives'), 'ajout seulement');
        expect(s1aOwner()->table('envoi_tentatives')->where('workspace_id', $a)->count())->toBe(2);
    } finally {
        s1aNettoyer([$a, $b]);
    }
});

test('tentatives : la fonction agrège puis retire les jours entiers de plus de 400 jours, pour l\'espace appelé seulement', function () {
    [$a, $b] = s1aDeuxEspaces();
    try {
        $o = s1aOwner();
        $avant = $o->selectOne("SELECT ((now() AT TIME ZONE 'Europe/Paris')::date - 400) AS d")->d;
        $ancien = $o->selectOne("SELECT (?::date - 3)::timestamp AT TIME ZONE 'Europe/Paris' + interval '12 hours' AS t", [$avant])->t;
        foreach ([$a, $b] as $ws) {
            s1aTentative($ws, $ancien, 'acceptee');
            s1aTentative($ws, $ancien, 'acceptee');
            s1aTentative($ws, $ancien, 'refusee', 550);
            s1aTentative($ws, now()->toDateTimeString());
        }
        s1aContexte($o, $a);
        $o->transaction(function () use ($o, $a, $b, $avant): void {
            expect((int) $o->selectOne('SELECT envoi_tentatives_archiver(?, ?) AS n', [$a, $avant])->n)->toBe(3);
            // La variable de session est retombée à la sortie : un DELETE direct juste après reste refusé.
            expect((string) $o->selectOne("SELECT current_setting('envoi.archivage_tentatives', true) AS v")->v)->not->toBe('on');
            s1aRefuse(fn () => $o->transaction(fn () => $o->statement('DELETE FROM envoi_tentatives WHERE workspace_id = ?', [$b])), 'ajout seulement');
        });
        expect($o->table('envoi_tentatives')->where('workspace_id', $a)->count())->toBe(1)
            ->and($o->table('envoi_tentatives')->where('workspace_id', $b)->count())->toBe(4)
            ->and($o->table('envoi_tentatives_jours')->where('workspace_id', $b)->count())->toBe(0);
        $jours = $o->table('envoi_tentatives_jours')->where('workspace_id', $a)->orderBy('issue')->get();
        expect($jours)->toHaveCount(2)
            ->and((int) $jours[0]->nombre)->toBe(2)->and($jours[0]->issue)->toBe('acceptee')->and($jours[0]->code_smtp)->toBeNull()
            ->and((int) $jours[1]->nombre)->toBe(1)->and((int) $jours[1]->code_smtp)->toBe(550);
    } finally {
        s1aNettoyer([$a, $b]);
    }
});

test('tentatives : la fonction refuse un autre espace, l\'absence de contexte et une borne trop récente ; rien n\'est supprimé', function () {
    [$a, $b] = s1aDeuxEspaces();
    try {
        $o = s1aOwner();
        $avant = $o->selectOne("SELECT ((now() AT TIME ZONE 'Europe/Paris')::date - 400) AS d")->d;
        $recent = $o->selectOne("SELECT ((now() AT TIME ZONE 'Europe/Paris')::date - 399) AS d")->d;
        s1aTentative($b, now()->subDays(500)->toDateTimeString());
        s1aContexte($o, $a);
        s1aRefuse(fn () => $o->selectOne('SELECT envoi_tentatives_archiver(?, ?)', [$b, $avant]));
        s1aRefuse(fn () => $o->selectOne('SELECT envoi_tentatives_archiver(?, ?)', [$a, $recent]));
        s1aApp()->disconnect();
        s1aRefuse(fn () => s1aApp()->selectOne('SELECT envoi_tentatives_archiver(?, ?)', [$b, $avant]));
        expect($o->table('envoi_tentatives')->where('workspace_id', $b)->count())->toBe(1)
            ->and($o->table('envoi_tentatives_jours')->whereIn('workspace_id', [$a, $b])->count())->toBe(0);
    } finally {
        s1aNettoyer([$a, $b]);
    }
});

test('tentatives : la borne ne dépend pas du fuseau de la session', function () {
    $resultats = [];
    foreach (['UTC', 'Pacific/Auckland'] as $fuseau) {
        [$a, $b] = s1aDeuxEspaces();
        try {
            $o = s1aOwner();
            $o->statement("SET TIME ZONE '{$fuseau}'");
            $avant = $o->selectOne("SELECT ((now() AT TIME ZONE 'Europe/Paris')::date - 400) AS d")->d;
            // Dernière minute du jour précédent (archivée) et première minute du jour de borne (conservée), heure de Paris.
            $veille = $o->selectOne("SELECT (?::timestamp - interval '1 minute') AT TIME ZONE 'Europe/Paris' AS t", [$avant])->t;
            $debut = $o->selectOne("SELECT ?::timestamp AT TIME ZONE 'Europe/Paris' AS t", [$avant])->t;
            s1aTentative($a, $veille);
            s1aTentative($a, $debut);
            s1aContexte($o, $a);
            $resultats[$fuseau] = (int) $o->selectOne('SELECT envoi_tentatives_archiver(?, ?) AS n', [$a, $avant])->n;
        } finally {
            $o->statement('RESET TIME ZONE');
            s1aNettoyer([$a, $b]);
        }
    }
    expect($resultats)->toBe(['UTC' => 1, 'Pacific/Auckland' => 1]);
});

test('tentatives : rôle applicatif + variable posée + DELETE direct ⇒ permission refusée', function () {
    [$a, $b] = s1aDeuxEspaces();
    try {
        s1aTentative($a, now()->subDays(500)->toDateTimeString());
        s1aContexte(s1aApp(), $a);
        s1aRefuse(fn () => s1aApp()->transaction(function () {
            s1aApp()->statement("SET LOCAL envoi.archivage_tentatives = 'on'");
            s1aApp()->statement('DELETE FROM envoi_tentatives');
        }), 'permission denied');
        expect(s1aOwner()->table('envoi_tentatives')->where('workspace_id', $a)->count())->toBe(1);
    } finally {
        s1aNettoyer([$a, $b]);
    }
});

test('jours : ajout seul, clé unique même avec code_smtp NULL, un espace ne voit pas l\'autre', function () {
    [$a, $b] = s1aDeuxEspaces();
    try {
        $o = s1aOwner();
        $ligne = fn (string $ws, ?int $code) => ['workspace_id' => $ws, 'jour' => '2024-01-02', 'fournisseur' => 'gmail', 'issue' => 'acceptee', 'code_smtp' => $code, 'nombre' => 2];
        $o->table('envoi_tentatives_jours')->insert($ligne($a, null));
        $o->table('envoi_tentatives_jours')->insert($ligne($b, null));
        s1aRefuse(fn () => $o->table('envoi_tentatives_jours')->insert($ligne($a, null)), 'unique');
        s1aRefuse(fn () => $o->statement('UPDATE envoi_tentatives_jours SET nombre = 9 WHERE workspace_id = ?', [$a]), 'ajout seulement');
        s1aRefuse(fn () => $o->statement('DELETE FROM envoi_tentatives_jours WHERE workspace_id = ?', [$a]), 'ajout seulement');
        s1aRefuse(fn () => $o->statement('TRUNCATE envoi_tentatives_jours'), 'ajout seulement');
        s1aContexte(s1aApp(), $a);
        expect(s1aApp()->table('envoi_tentatives_jours')->pluck('workspace_id')->unique()->values()->all())->toBe([$a]);
    } finally {
        s1aNettoyer([$a, $b]);
    }
});

// ── D6 — envoi_sequences_gestes ──────────────────────────────────────────────

test('gestes : ajout seul, RLS par espace, motif sans @', function () {
    [$a, $b] = s1aDeuxEspaces();
    try {
        $o = s1aOwner();
        $seqA = (string) Str::uuid();
        $seqB = (string) Str::uuid();
        $o->table('envoi_sequences')->insert([['id' => $seqA, 'workspace_id' => $a, 'nom' => 'S1A'], ['id' => $seqB, 'workspace_id' => $b, 'nom' => 'S1A']]);
        $geste = fn (string $ws, string $seq, string $g = 'pause', ?string $motif = 'test') => ['workspace_id' => $ws, 'sequence_id' => $seq, 'geste' => $g, 'auteur' => 'cli', 'motif' => $motif];
        $o->table('envoi_sequences_gestes')->insert([$geste($a, $seqA), $geste($b, $seqB)]);
        s1aRefuse(fn () => $o->table('envoi_sequences_gestes')->insert($geste($a, $seqA, 'autre')));
        s1aRefuse(fn () => $o->table('envoi_sequences_gestes')->insert($geste($a, $seqA, 'pause', 'x@example.test')));
        s1aRefuse(fn () => $o->table('envoi_sequences_gestes')->insert($geste($a, $seqB)), 'foreign key');
        s1aRefuse(fn () => $o->statement("UPDATE envoi_sequences_gestes SET geste = 'reprise'"), 'ajout seulement');
        s1aRefuse(fn () => $o->statement('DELETE FROM envoi_sequences_gestes'), 'ajout seulement');
        s1aRefuse(fn () => $o->statement('TRUNCATE envoi_sequences_gestes'), 'ajout seulement');
        s1aContexte(s1aApp(), $a);
        expect(s1aApp()->table('envoi_sequences_gestes')->pluck('workspace_id')->unique()->values()->all())->toBe([$a]);
        s1aContexte(s1aApp(), '');
        expect(s1aApp()->table('envoi_sequences_gestes')->count())->toBe(0);
    } finally {
        s1aNettoyer([$a, $b]);
    }
});

// ── Transverse : fonctions et migrations ─────────────────────────────────────

test('toute fonction créée ou remplacée par S1-A a un search_path fixe ; les SECURITY DEFINER ne sont pas appelables par PUBLIC', function () {
    $noms = ['envoi_compteur_espace_reserver', 'envoi_tentatives_archiver', 'envoi_tentatives_ajout_seul', 'envoi_injection_liberer', 'envoi_sequences_gestes_ajout_seul'];
    foreach ($noms as $nom) {
        $f = DB::selectOne('SELECT prosecdef, proconfig::text AS cfg, proacl::text AS acl FROM pg_proc WHERE proname = ?', [$nom]);
        expect($f)->not->toBeNull("{$nom} absente");
        expect((string) $f->cfg)->toContain('search_path=pg_catalog, public');
        if ($f->prosecdef) {
            expect((string) $f->acl)->not->toMatch('/[{,]=X\//');
        }
    }
    expect((string) DB::selectOne("SELECT proconfig::text AS c FROM pg_proc WHERE proname = 'envoi_tentatives_archiver'")->c)->toContain('envoi.archivage_tentatives=on');
});

test('chaque up() de S1-A rejoué deux fois ne casse rien et ne touche aucune ligne', function () {
    $avant = DB::selectOne('SELECT count(*) AS t FROM envoi_tentatives')->t;
    foreach (S1A_MIGRATIONS as $fichier) {
        $m = require database_path('migrations/' . $fichier);
        $m->up();
        $m->up();
        $m->down();
    }
    expect(DB::selectOne('SELECT count(*) AS t FROM envoi_tentatives')->t)->toBe($avant);
});
