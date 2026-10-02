<?php

/**
 * FINITIONS P2 — le tri des médias par nom ignore les signes de tête.
 *
 * Constat de l'audit UX du 2026-10-02 : la liste des médias, triée par nom,
 * s'ouvrait sur « + Plus », « / Slash », « "Le Journal" »… La collation range
 * la ponctuation avant les lettres. Ce test rougit si le tri revient au nom
 * brut, ou si l'index qui le sert disparaît.
 *
 * Fixtures FICTIVES (dépôt public).
 */

use App\Models\User;
use App\Support\TriNomMedia;
use Database\Seeders\PermissionsAndRolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    Cache::flush();
    $this->espace = (string) Str::uuid();
    DB::table('workspaces')->insert([
        'id' => $this->espace, 'slug' => 'zz-tri-' . substr(str_replace('-', '', $this->espace), 0, 8), 'name' => 'ZZ tri médias',
        'settings' => '{}', 'cost_cap_eur' => 100, 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
    ]);
    $this->user = User::create([
        'id' => (string) Str::uuid(), 'email' => Str::uuid() . '@example.test', 'name' => 'Console',
        'password_hash' => Hash::make('PasswordTest12345!'), 'current_workspace_id' => $this->espace, 'first_login_completed_at' => now(),
    ]);
    $this->seed(PermissionsAndRolesSeeder::class);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->espace);
    $this->user->assignRole('owner');
    $this->actingAs($this->user);
});

function triMedia(string $espace, string $nom): void
{
    DB::table('media')->insert([
        'workspace_id' => $espace, 'name' => $nom, 'media_type' => 'presse_journal',
        'media_family' => 'editorial', 'source' => 'naf-extract', 'enrich_status' => 'pending',
        'created_at' => now(), 'updated_at' => now(),
    ]);
}

test('medias : un nom qui commence par un signe se range a sa premiere lettre', function () {
    foreach (['Zeta Hebdo', '+ Plus Radio', '/ Slash Info', '"Alpha" Journal', "'Bravo' Magazine", 'Charlie TV', '20 Minutes ZZ'] as $nom) {
        triMedia($this->espace, $nom);
    }

    $noms = collect($this->getJson('/api/v1/media?per_page=100')->assertOk()->json('data'))->pluck('name')->all();

    expect($noms)->toBe([
        '20 Minutes ZZ',
        '"Alpha" Journal',
        "'Bravo' Magazine",
        'Charlie TV',
        '+ Plus Radio',
        '/ Slash Info',
        'Zeta Hebdo',
    ]);

    $inverse = collect($this->getJson('/api/v1/media?per_page=100&sort=-name')->assertOk()->json('data'))->pluck('name')->all();
    expect($inverse)->toBe(array_reverse($noms));
});

test('medias : accents, casse et signes de tete ignores ; chiffres en tete (avis A09)', function () {
    // Production en locale C : `[:alnum:]` n'y connaît que l'ASCII. Retirer les
    // signes AVANT les accents rangeait « École » à « cole » et « À la folie »
    // à L ; et sans `lower`, « zoom » passait après « Zébra ».
    foreach (['zoom', 'Zébra', '+ Plus Radio', 'Émissions éco', '20 Minutes', 'École des Loisirs', 'À la folie'] as $nom) {
        triMedia($this->espace, $nom);
    }

    $noms = collect($this->getJson('/api/v1/media?per_page=100')->assertOk()->json('data'))->pluck('name')->all();

    expect($noms)->toBe([
        '20 Minutes',
        'À la folie',
        'École des Loisirs',
        'Émissions éco',
        '+ Plus Radio',
        'Zébra',
        'zoom',
    ]);
});

test('medias : la cle de tri, en SQL, ignore accents, casse et signes de tete', function () {
    $cle = fn (string $nom): string => (string) DB::selectOne('SELECT ' . TriNomMedia::expression('?::text') . ' AS c', [$nom])->c;

    expect($cle('École des Loisirs'))->toBe('ecole des loisirs')
        ->and($cle('"ÉTHIQUE & SANTÉ"'))->toBe('ethique & sante"')
        ->and($cle('+ Plus Radio'))->toBe('plus radio')
        ->and($cle('20 Minutes'))->toBe('20 minutes');
});

test('medias : l index porte EXACTEMENT l expression du tri, et le planificateur s en sert', function () {
    $reel = DB::selectOne(
        "SELECT pg_get_indexdef(c.oid, 2, true) AS expr FROM pg_class c WHERE c.relname = 'idx_media_tri_nom'",
    );
    expect($reel)->not->toBeNull();

    // L'attendu est DÉPARSÉ par Postgres à partir de la constante PHP : même
    // normalisation (transtypages, parenthèses) que l'index réel.
    DB::statement('CREATE TEMP TABLE tri_temoin (LIKE media)');
    DB::statement('CREATE INDEX tri_temoin_idx ON tri_temoin (workspace_id, (' . TriNomMedia::expression('name') . '))');
    $attendu = DB::selectOne(
        "SELECT pg_get_indexdef(c.oid, 2, true) AS expr FROM pg_class c WHERE c.relname = 'tri_temoin_idx'",
    );
    expect($reel->expr)->toBe($attendu->expr);

    DB::statement('SET LOCAL enable_seqscan = off');
    DB::statement('SET LOCAL enable_sort = off');
    $plan = collect(DB::select(
        'EXPLAIN SELECT id FROM media WHERE workspace_id = ? AND deleted_at IS NULL ORDER BY ' . TriNomMedia::expression('media.name') . ' LIMIT 100',
        [$this->espace],
    ))->map(fn ($l) => implode(' ', (array) $l))->implode("\n");
    expect($plan)->toContain('idx_media_tri_nom');
});
