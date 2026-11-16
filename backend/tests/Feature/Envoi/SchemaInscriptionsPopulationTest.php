<?php

use App\Envoi\Reglages\DepotReglages;
use App\Envoi\Sequence\Fiche;
use App\Envoi\Sequence\Inscriptions;
use App\Envoi\Sequence\LecteurFiche;
use App\Support\WorkspaceContext;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/*
 * Lot P2-A — inscriptions typées (migration SEULE, INERTE). Chaque test de sécurité est rejoué SOUS LE RÔLE APPLICATIF
 * (SET LOCAL ROLE + WorkspaceContext) ET SOUS LE PROPRIÉTAIRE. Données fictives (example.test), aucune personne réelle.
 */
uses(TestCase::class, RefreshDatabase::class);

const IPP_MODES = ['app', 'proprio'];
const IPP_CLE = 'envoi.population_personnes';

function ippEspace(): string
{
    $id = (string) Str::uuid();
    DB::table('workspaces')->insert(['id' => $id, 'name' => 'Ipp', 'slug' => 'ipp-' . substr($id, 0, 8), 'created_at' => now(), 'updated_at' => now()]);

    return $id;
}

function ippSequence(string $ws): string
{
    $id = (string) Str::uuid();
    DB::table('envoi_sequences')->insert(['id' => $id, 'workspace_id' => $ws, 'nom' => 'Séquence fictive']);

    return $id;
}

/** Ligne d'inscription : « entreprises » par défaut ; $plus remplace ou ajoute des colonnes. */
function ippLigne(string $ws, string $seq, array $plus = []): array
{
    return $plus + ['workspace_id' => $ws, 'sequence_id' => $seq, 'contact_id' => random_int(1, 10 ** 9), 'entreprise_id' => 7,
        'empreinte_adresse' => hash('sha256', Str::uuid()->toString()), 'prochaine_echeance' => now(), 'version_figee' => '{}'];
}

function ippPersonne(string $ws, string $seq, array $plus = []): array
{
    return ippLigne($ws, $seq, $plus + ['population' => 'personnes', 'personne_id' => random_int(1, 10 ** 9), 'contact_id' => null, 'entreprise_id' => null]);
}

/** Le SQLSTATE de $f joué dans un point de sauvegarde, annulé ensuite (null = accepté). */
function ippCode(Closure $f): ?string
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

function ippSous(string $mode, string $ws, Closure $f): ?string
{
    return ippCode(function () use ($mode, $ws, $f) {
        if ($mode === 'app') {
            WorkspaceContext::set($ws);
            DB::statement('SET LOCAL ROLE "' . str_replace('"', '""', (string) config('database.connections.pgsql_app.username')) . '"');
        }
        $f();
    });
}

/** Réglage posé par le propriétaire (le seul autorisé). */
function ippOuvrir(string $ws, string $valeur = 'true'): void
{
    WorkspaceContext::set($ws);
    DB::table('envoi_reglages')->insert(['workspace_id' => $ws, 'cle' => IPP_CLE, 'valeur' => $valeur, 'auteur' => 'fondateur']);
}

test('structure : colonnes, défaut, nullables, CHECK validés, déclencheurs, search_path, index unique partiel valide', function () {
    $col = fn (string $c) => DB::selectOne("SELECT column_default, is_nullable FROM information_schema.columns WHERE table_name = 'envoi_inscriptions' AND column_name = ?", [$c]);
    expect($col('population')->column_default)->toContain('entreprises')->and($col('population')->is_nullable)->toBe('NO')
        ->and($col('personne_id')->is_nullable)->toBe('YES')
        ->and($col('contact_id')->is_nullable)->toBe('YES')->and($col('entreprise_id')->is_nullable)->toBe('YES');
    foreach (['envoi_inscriptions_population_valeur', 'envoi_inscriptions_population_coherence'] as $c) {
        expect(DB::selectOne('SELECT convalidated FROM pg_constraint WHERE conname = ?', [$c])->convalidated)->toBeTrue();
    }
    foreach (['envoi_inscriptions_population_garde' => 'envoi_inscriptions', 'envoi_reglages_interrupteur_population' => 'envoi_reglages'] as $t => $table) {
        expect(DB::selectOne('SELECT 1 AS e FROM pg_trigger WHERE tgname = ? AND tgrelid = ?::regclass', [$t, $table]))->not->toBeNull();
        $conf = DB::selectOne('SELECT proconfig::text AS c, prosecdef FROM pg_proc WHERE proname = ?', [$t])->c;
        expect(str_ends_with($conf, 'pg_temp}') || str_ends_with($conf, 'pg_temp"}'))->toBeTrue()->and(DB::selectOne('SELECT prosecdef FROM pg_proc WHERE proname = ?', [$t])->prosecdef)->toBeFalse();
    }
    $i = DB::selectOne("SELECT i.indisvalid, i.indisunique, pg_get_expr(i.indpred, i.indrelid) AS pred FROM pg_index i JOIN pg_class c ON c.oid = i.indexrelid WHERE c.relname = 'envoi_inscriptions_personne_unique'");
    expect($i->indisvalid)->toBeTrue()->and($i->indisunique)->toBeTrue()->and($i->pred)->toContain('personne_id IS NOT NULL');
});

test('ancien chemin : Inscriptions::inscrire sans population écrit une ligne « entreprises » (rôle applicatif ET propriétaire)', function () {
    foreach (IPP_MODES as $mode) {
        $ws = ippEspace();
        $seq = ippSequence($ws);
        DB::table('envoi_sequence_etapes')->insert(['workspace_id' => $ws, 'sequence_id' => $seq, 'rang' => 1, 'sujet' => 's', 'corps' => 'c']);
        $lecteur = new class implements LecteurFiche
        {
            public function lire(string $workspaceId, int $contactId): ?Fiche
            {
                return new Fiche($contactId, 42, 'a@ipp.example.test', 'FR', null, null, null, null, []);
            }
        };
        $id = '';
        $code = ippSous($mode, $ws, function () use ($lecteur, $ws, $seq, &$id) {
            $id = (new Inscriptions($lecteur))->inscrire($ws, $seq, 5, new DateTimeImmutable('2026-11-16 10:00', new DateTimeZone('Europe/Paris')));
            $l = DB::table('envoi_inscriptions')->where('id', $id)->first();
            if ($id === '' || $l->population !== 'entreprises' || $l->personne_id !== null || (int) $l->contact_id !== 5) {
                throw new RuntimeException('ligne inattendue');
            }
        });
        expect($code)->toBeNull($mode);
    }
});

test('CHECK : population inconnue ou incohérente → 23514 (rôle applicatif ET propriétaire)', function () {
    foreach (IPP_MODES as $mode) {
        $ws = ippEspace();
        $seq = ippSequence($ws);
        ippOuvrir($ws);
        $cas = [
            'contact NULL' => ippLigne($ws, $seq, ['contact_id' => null]),
            'entreprise NULL' => ippLigne($ws, $seq, ['entreprise_id' => null]),
            'entreprises + personne' => ippLigne($ws, $seq, ['personne_id' => 9]),
            'personnes + contact' => ippPersonne($ws, $seq, ['contact_id' => 3]),
            'personnes + entreprise' => ippPersonne($ws, $seq, ['entreprise_id' => 3]),
            'personnes sans personne' => ippPersonne($ws, $seq, ['personne_id' => null]),
            'population xyz' => ippLigne($ws, $seq, ['population' => 'xyz']),
        ];
        foreach ($cas as $nom => $ligne) {
            expect(ippSous($mode, $ws, fn () => DB::table('envoi_inscriptions')->insert($ligne)))->toBe('23514', "$mode / $nom");
        }
    }
});

test('interrupteur : « personnes » refusée sans réglage vrai, accepté avec (rôle applicatif ET propriétaire)', function () {
    foreach (IPP_MODES as $mode) {
        $ws = ippEspace();
        $autre = ippEspace();
        $seq = ippSequence($ws);
        $ins = fn () => DB::table('envoi_inscriptions')->insert(ippPersonne($ws, $seq));
        expect(ippSous($mode, $ws, $ins))->toBe('P0001', "$mode / absent");
        ippOuvrir($autre);
        expect(ippSous($mode, $ws, $ins))->toBe('P0001', "$mode / réglage d'un autre espace");
        ippOuvrir($ws, 'false');
        expect(ippSous($mode, $ws, $ins))->toBe('P0001', "$mode / faux");
        DB::table('envoi_reglages')->where('workspace_id', $ws)->where('cle', IPP_CLE)->update(['valeur' => 'true']);
        expect(ippSous($mode, $ws, $ins))->toBeNull("$mode / vrai");
    }
});

test('interrupteur : contexte d’espace absent → refus (échec fermé par la RLS)', function () {
    $ws = ippEspace();
    $seq = ippSequence($ws);
    ippOuvrir($ws);
    $ligne = ippPersonne($ws, $seq);
    $app = (string) config('database.connections.pgsql_app.username');
    // Rôle applicatif sans contexte : la RLS cache le réglage (et la table) → refus.
    expect(ippCode(function () use ($ligne, $app) {
        DB::statement("SET LOCAL ROLE \"{$app}\"");
        DB::statement("SELECT set_config('app.current_workspace_id', '', true)");
        DB::table('envoi_inscriptions')->insert($ligne);
    }))->toBeIn(['P0001', '42501']);
    // Propriétaire sans contexte : fermé s'il est soumis à la RLS ; un superutilisateur la contourne (voir docs/envoi/lots/P2.md).
    if (! DB::selectOne('SELECT rolsuper FROM pg_roles WHERE rolname = current_user')->rolsuper) {
        expect(ippCode(function () use ($ligne) {
            DB::statement("SELECT set_config('app.current_workspace_id', '', true)");
            DB::table('envoi_inscriptions')->insert($ligne);
        }))->toBe('P0001');
    }
});

test('table temporaire homonyme envoi_reglages (rôle applicatif) : toujours refusé', function () {
    $ws = ippEspace();
    $seq = ippSequence($ws);
    $ligne = ippPersonne($ws, $seq);
    expect(ippSous('app', $ws, function () use ($ws, $ligne) {
        DB::statement('CREATE TEMP TABLE envoi_reglages (workspace_id uuid, cle text, valeur text)');
        DB::statement('INSERT INTO pg_temp.envoi_reglages VALUES (?, ?, ?)', [$ws, IPP_CLE, 'true']);
        DB::table('envoi_inscriptions')->insert($ligne);
    }))->toBe('P0001');
});

test('figé : population, personne, contact, entreprise ne changent plus ; statut et échéance oui', function () {
    foreach (IPP_MODES as $mode) {
        $ws = ippEspace();
        $seq = ippSequence($ws);
        $id = (string) Str::uuid();
        DB::table('envoi_inscriptions')->insert(ippLigne($ws, $seq, ['id' => $id]));
        foreach (['contact_id' => 99, 'entreprise_id' => 99, 'personne_id' => 99, 'population' => 'personnes'] as $col => $v) {
            expect(ippSous($mode, $ws, fn () => DB::table('envoi_inscriptions')->where('id', $id)->update([$col => $v])))->toBe('P0001', "$mode / $col");
        }
        expect(ippSous($mode, $ws, fn () => DB::table('envoi_inscriptions')->where('id', $id)->update(['statut' => 'terminee', 'prochaine_echeance' => now()])))->toBeNull($mode);
    }
});

test('garde de la clé : le rôle applicatif ne pose pas l’interrupteur ; le propriétaire pose true/false seulement', function () {
    $ws = ippEspace();
    $ins = fn (string $cle, string $valeur = 'true') => fn () => DB::table('envoi_reglages')->insert(['workspace_id' => $ws, 'cle' => $cle, 'valeur' => $valeur, 'auteur' => 'x']);
    expect(ippSous('app', $ws, $ins(IPP_CLE)))->toBe('P0001');
    DB::table('envoi_reglages')->insert(['workspace_id' => $ws, 'cle' => 'regles.autre', 'valeur' => '1', 'auteur' => 'x']);
    expect(ippSous('app', $ws, fn () => DB::table('envoi_reglages')->where('cle', 'regles.autre')->update(['cle' => IPP_CLE])))->toBe('P0001')
        ->and(ippSous('app', $ws, fn () => app(DepotReglages::class)->poser($ws, 'regles.plafond_jour', '10', 'x')))->toBeNull();
    expect(ippSous('proprio', $ws, $ins(IPP_CLE, 'true')))->toBeNull()
        ->and(ippSous('proprio', $ws, $ins(IPP_CLE, 'false')))->toBeNull()
        ->and(ippSous('proprio', $ws, $ins(IPP_CLE, 'oui')))->toBe('P0001');
    // Le propriétaire pose, puis le rôle applicatif ne le modifie plus.
    ippOuvrir($ws, 'false');
    expect(ippSous('app', $ws, fn () => DB::table('envoi_reglages')->where('cle', IPP_CLE)->update(['valeur' => 'true'])))->toBe('P0001');
});

test('inertie : aucune ligne « personnes », aucun réglage, aucune occurrence dans app/', function () {
    expect(DB::table('envoi_inscriptions')->where('population', 'personnes')->count())->toBe(0)
        ->and(DB::table('envoi_reglages')->where('cle', IPP_CLE)->count())->toBe(0);
    $trouves = [];
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path(), FilesystemIterator::SKIP_DOTS)) as $f) {
        if ($f->isFile() && str_contains((string) file_get_contents($f->getPathname()), IPP_CLE)) {
            $trouves[] = $f->getPathname();
        }
    }
    expect($trouves)->toBe([]);
});

test('rejouable : les trois migrations rejouées ne changent rien et ne créent aucune ligne', function () {
    $avant = DB::table('envoi_inscriptions')->count();
    foreach (['000100_envoi_inscriptions_population', '000110_envoi_inscriptions_personne_index', '000120_envoi_reglages_interrupteur_population'] as $m) {
        $migration = require database_path("migrations/2026_12_05_{$m}.php");
        $migration->up();
        $migration->up();
        $migration->down();
    }
    expect(DB::table('envoi_inscriptions')->count())->toBe($avant)
        ->and(DB::selectOne("SELECT count(*) AS n FROM pg_constraint WHERE conname LIKE 'envoi_inscriptions_population_%'")->n)->toBe(2);
});

test('unicité par personne : deux inscriptions de la même personne dans la même séquence → 23505', function () {
    $ws = ippEspace();
    $seq = ippSequence($ws);
    ippOuvrir($ws);
    $ligne = ippPersonne($ws, $seq, ['personne_id' => 123]);
    expect(ippCode(function () use ($ligne, $ws, $seq) {
        DB::table('envoi_inscriptions')->insert($ligne);
        DB::table('envoi_inscriptions')->insert(ippPersonne($ws, $seq, ['personne_id' => 123]));
    }))->toBe('23505');
});
