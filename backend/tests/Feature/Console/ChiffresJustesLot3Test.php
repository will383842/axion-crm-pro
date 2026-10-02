<?php

/**
 * LOT 3 « DES CHIFFRES JUSTES » — audit visuel de la console du 2026-10-02.
 *
 * Chaque bloc rejoue un constat de l'audit, et rougit sans son correctif :
 *   - tableau de bord : « Qualité moyenne 0/100 » (colonne `quality_tier`
 *     inexistante) ; activité récente en lignes techniques ;
 *   - indicateurs calculés sur la PAGE affichée (Entreprises, Médias,
 *     Journalistes) présentés comme des totaux ;
 *   - Médias : sites devinés montrés comme sites ;
 *   - Journalistes : noms d'émissions et de chaînes comptés comme personnes ;
 *   - Collectes : collectes de TEST en tête de liste ;
 *   - lenteurs : totaux recomptés à chaque affichage (journaux de collecte,
 *     fédérations), comptes par étiquette, liste tronquée à 500, fiches
 *     entières rendues pour une grille de 9 colonnes.
 *
 * Fixtures FICTIVES (dépôt public).
 */

use App\Http\Controllers\Api\CompaniesController;
use App\Models\User;
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
        'id' => $this->espace, 'slug' => 'zz-lot3-' . substr(str_replace('-', '', $this->espace), 0, 8), 'name' => 'ZZ lot 3',
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

function l3Entreprise(string $espace, array $champs = []): int
{
    return (int) DB::table('companies')->insertGetId($champs + [
        'workspace_id' => $espace,
        'siren' => '9' . str_pad((string) random_int(0, 99999999), 8, '0', STR_PAD_LEFT),
        'denomination' => 'ZZ LOT3 ' . Str::random(6),
        'created_at' => now(), 'updated_at' => now(),
    ]);
}

function l3Requetes(callable $travail, string $motif): int
{
    $vues = 0;
    DB::listen(function ($q) use (&$vues, $motif): void {
        if (preg_match($motif, $q->sql) === 1) {
            $vues++;
        }
    });
    $travail();

    return $vues;
}

// ── 2. Tableau de bord : la qualité ─────────────────────────────────────────

test('tableau de bord : repartition et moyenne reelles de quality_score, et part des scores perimes', function () {
    $a = l3Entreprise($this->espace);
    $b = l3Entreprise($this->espace);
    $c = l3Entreprise($this->espace);
    // `quality_score` n'est pas écouté par le déclencheur du barème : ces
    // valeurs restent telles quelles — et divergent du barème (périmées).
    DB::table('companies')->where('id', $a)->update(['quality_score' => 95]);
    DB::table('companies')->where('id', $b)->update(['quality_score' => 60]);
    DB::table('companies')->where('id', $c)->update(['quality_score' => 10]);

    $r = $this->getJson('/api/v1/dashboard/stats')->assertOk();

    expect($r->json('quality_distribution'))->toBe(['complete' => 1, 'partielle' => 1, 'basique' => 1])
        ->and($r->json('quality_avg'))->toBe(55)
        ->and($r->json('quality_a_recalculer_pct'))->toBeGreaterThan(0);
});

test('tableau de bord : des scores alignes sur le bareme ne sont pas annonces perimes', function () {
    l3Entreprise($this->espace, ['website' => 'https://zz-lot3.example.invalid']);
    l3Entreprise($this->espace);
    DB::statement('UPDATE companies c SET quality_score = company_quality_score_calcul(c) WHERE workspace_id = ?', [$this->espace]);

    $r = $this->getJson('/api/v1/dashboard/stats')->assertOk();

    expect((float) $r->json('quality_a_recalculer_pct'))->toBe(0.0)
        ->and($r->json('quality_avg'))->toBeInt();
});

// ── 2. Tableau de bord : l'activité récente ────────────────────────────────

test('journal metier=1 : ni progression de lot, ni ouvertures de session ; limit respecte', function () {
    $ligne = fn (string $type, string $chemin): array => [
        'workspace_id' => $this->espace, 'event_type' => $type, 'path' => $chemin, 'status_code' => 200,
        'prev_hash' => str_repeat('0', 64), 'current_hash' => Str::random(64), 'created_at' => now(),
    ];
    DB::table('audit_logs')->insert([
        $ligne('IMPORT_PRESSE', 'artisan crm:presse:importer'),
        $ligne('JOIGNABILITE_LOT', 'artisan crm:joignabilite:calculer — ids 1-1000'),
        $ligne('RELATIONS_IMPORT_PAQUET', 'artisan crm:relations:importer — paquet 1'),
        $ligne('POST', 'api/v1/auth/login'),
        $ligne('POST', 'api/internal/site-sync'),
        $ligne('POST', 'api/v1/auth/password/reset'),
    ]);

    $types = collect($this->getJson('/api/v1/audit-logs?metier=1&limit=10')->assertOk()->json('data'))
        ->map(fn (array $l): string => $l['event_type'] . ' ' . $l['path'])->all();

    expect($types)->toContain('IMPORT_PRESSE artisan crm:presse:importer')
        ->toContain('POST api/v1/auth/password/reset')
        ->and(implode('|', $types))->not->toContain('_LOT')
        ->and(implode('|', $types))->not->toContain('_PAQUET')
        ->and(implode('|', $types))->not->toContain('auth/login')
        ->and(implode('|', $types))->not->toContain('site-sync');

    // Sans `metier`, rien n'est caché (le journal complet reste consultable).
    expect($this->getJson('/api/v1/audit-logs')->json('meta.total'))->toBe(6)
        ->and($this->getJson('/api/v1/audit-logs?limit=2')->json('meta.per_page'))->toBe(2);
});

// ── 3. Entreprises : indicateurs sur toute la base, charge utile allégée ───

test('entreprises : vue=liste ne rend que les colonnes de la grille, sans signals ni metadata', function () {
    l3Entreprise($this->espace, ['signals' => json_encode(['gros' => str_repeat('x', 2000)])]);

    $ligne = $this->getJson('/api/v1/companies?vue=liste&per_page=10')->assertOk()->json('data.0');
    $complete = $this->getJson('/api/v1/companies?per_page=10')->assertOk()->json('data.0');

    expect(array_keys($ligne))->not->toContain('signals')
        ->and(array_keys($ligne))->not->toContain('metadata')
        ->and(array_keys($ligne))->toContain('denomination')
        ->and(array_keys($ligne))->toContain('quality_score')
        // Les autres appelants de /companies ne changent pas.
        ->and(array_keys($complete))->toContain('signals');
});

test('entreprises : /companies/stats compte TOUTE la base, pas la page', function () {
    foreach (range(1, 3) as $i) {
        l3Entreprise($this->espace, ['size_category' => 'tpe', 'sector_main' => 'btp', 'enriched_at' => now()]);
    }
    l3Entreprise($this->espace, ['size_category' => 'pme', 'sector_main' => 'btp']);

    $s = $this->getJson('/api/v1/companies/stats')->assertOk();

    expect($s->json('total'))->toBe(4)
        ->and($s->json('enrichies_pct'))->toBe(75)
        ->and($s->json('top_taille.code'))->toBe('tpe')
        ->and($s->json('top_taille.pct'))->toBe(75)
        ->and($s->json('top_secteur.code'))->toBe('btp')
        ->and($s->json('top_secteur.n'))->toBe(4);

    // Servi depuis le cache : le second appel ne relit pas la base.
    expect(l3Requetes(fn () => $this->getJson('/api/v1/companies/stats')->assertOk(), '/from "companies"/i'))->toBe(0);
    expect(CompaniesController::cleStats($this->espace))->toContain($this->espace);
});

// ── 4. Médias : production à part, sites vérifiés seulement ────────────────

function l3Media(string $espace, string $nom, string $famille, ?string $site, ?string $statut): int
{
    $metadata = $statut === null ? '{}' : json_encode(['site_media' => ['statut' => $statut, 'url' => $site, 'le' => '2026-10-01', 'v' => 1]]);
    $fiche = l3Entreprise($espace, ['denomination' => strtoupper($nom), 'website' => $site, 'metadata' => $metadata]);

    return (int) DB::table('media')->insertGetId([
        'workspace_id' => $espace, 'company_id' => $fiche, 'name' => $nom,
        'media_type' => $famille === 'audiovisual_production' ? 'production_audiovisuelle' : 'presse_journal',
        'media_family' => $famille, 'website' => $site, 'source' => 'naf-extract', 'enrich_status' => 'pending',
        'created_at' => now(), 'updated_at' => now(),
    ]);
}

test('medias : le site n est rendu comme fiable que s il est verifie ; filtre site_fiable', function () {
    l3Media($this->espace, 'ZZ Gazette verifiee', 'editorial', 'https://zz-gazette.example.invalid', 'verifie');
    l3Media($this->espace, 'ZZ Agence devinee', 'editorial', 'https://agence.example.invalid', 'non-conforme');
    l3Media($this->espace, 'ZZ Sans controle', 'editorial', 'https://zz-sans.example.invalid', null);

    $lignes = collect($this->getJson('/api/v1/media?per_page=100')->assertOk()->json('data'))->keyBy('name');

    expect($lignes['ZZ Gazette verifiee']['site_verifie'])->toContain('zz-gazette.example.invalid')
        ->and($lignes['ZZ Agence devinee']['site_verifie'])->toBeNull()
        ->and($lignes['ZZ Agence devinee']['site_statut'])->toBe('non-conforme')
        // Rien n'est effacé : le site deviné reste rendu, marqué non vérifié à l'écran.
        ->and($lignes['ZZ Agence devinee']['website'])->toBe('https://agence.example.invalid')
        ->and($lignes['ZZ Sans controle']['site_verifie'])->toBeNull()
        ->and(array_key_exists('site_media_marqueur', $lignes['ZZ Sans controle']))->toBeFalse();

    $fiables = collect($this->getJson('/api/v1/media?filter[site_fiable]=true')->assertOk()->json('data'))->pluck('name')->all();
    expect($fiables)->toBe(['ZZ Gazette verifiee']);
});

test('medias : /media/stats porte sur toute la selection filtree, pas sur la page', function () {
    l3Media($this->espace, 'ZZ A', 'editorial', 'https://zz-a.example.invalid', 'verifie');
    l3Media($this->espace, 'ZZ B', 'editorial', 'https://zz-b.example.invalid', 'a-confirmer');
    l3Media($this->espace, 'ZZ C', 'editorial', null, null);
    l3Media($this->espace, 'ZZ Prod', 'audiovisual_production', 'https://zz-prod.example.invalid', 'verifie');

    // Une page d'UNE ligne : l'ancien calcul « de la page » aurait dit 0 % ou 100 %.
    $this->getJson('/api/v1/media?per_page=1&filter[media_family]=editorial')->assertOk();
    $s = $this->getJson('/api/v1/media/stats?filter[media_family]=editorial')->assertOk();

    expect($s->json('total'))->toBe(3)
        ->and($s->json('avec_site_fiable'))->toBe(1)
        ->and($s->json('top_type.media_type'))->toBe('presse_journal');

    // La production n'est pas comptée parmi les médias, mais reste accessible.
    expect($this->getJson('/api/v1/media/stats?filter[media_family]=audiovisual_production')->json('total'))->toBe(1);
});

// ── 5. Journalistes : des personnes, pas des émissions ─────────────────────

test('journalistes : les noms d emissions et de chaines ne sont ni montres ni comptes par defaut', function () {
    $media = l3Media($this->espace, 'ZZ Antenne', 'editorial', null, null);
    $j = fn (?string $prenom, string $nom, ?string $email = null) => DB::table('journalists')->insert([
        'workspace_id' => $this->espace, 'media_id' => $media, 'first_name' => $prenom, 'last_name' => $nom,
        'email' => $email, 'role' => 'présentateur', 'source' => 'press-kit', 'created_at' => now(), 'updated_at' => now(),
    ]);
    $j('Zoé', 'Zzmartin', 'zoe.zzmartin@zz-antenne.example.invalid');
    $j('Xavier', 'de Zzmoulins');
    $j('Divers', '(feuilleton)');
    $j('France', '3');
    $j('Journaliste', 'éco local');
    $j(null, 'Quotidien');
    $j('Centre des monuments', 'nationaux');

    $r = $this->getJson('/api/v1/journalists?per_page=100')->assertOk();
    $noms = collect($r->json('data'))->map(fn (array $l): string => trim(($l['first_name'] ?? '') . ' ' . $l['last_name']))->sort()->values()->all();

    expect($noms)->toBe(['Xavier de Zzmoulins', 'Zoé Zzmartin'])
        ->and($r->json('meta.total'))->toBe(2)
        ->and($r->json('meta.stats'))->toBe(['total' => 2, 'avec_email' => 1, 'opt_out' => 0, 'ecartees' => 5]);

    // Rien n'est supprimé : les lignes écartées restent consultables.
    expect($this->getJson('/api/v1/journalists?filter[personne_reelle]=false')->json('meta.total'))->toBe(5)
        ->and($this->getJson('/api/v1/journalists?filter[personne_reelle]=tous')->json('meta.total'))->toBe(7)
        ->and(DB::table('journalists')->where('workspace_id', $this->espace)->whereNull('deleted_at')->count())->toBe(7);
});

// ── 6. Collectes : archiver, jamais supprimer ──────────────────────────────

function l3Collecte(string $espace, string $createur, string $nom, string $statut): int
{
    return (int) DB::table('scraping_campaigns')->insertGetId([
        'workspace_id' => $espace, 'created_by' => $createur, 'name' => $nom, 'status' => $statut,
        'sources' => '[]', 'zones' => '[]', 'created_at' => now(), 'updated_at' => now(),
    ]);
}

test('collectes : la reprise archive les collectes de test terminees, et elles seules', function () {
    $test1 = l3Collecte($this->espace, $this->user->id, 'Test Paris INSEE', 'cancelled');
    $test2 = l3Collecte($this->espace, $this->user->id, 'TEST Grenoble INSEE', 'completed');
    $enCours = l3Collecte($this->espace, $this->user->id, 'Test en cours', 'running');
    $vraie = l3Collecte($this->espace, $this->user->id, 'Testament et successions', 'completed');

    (require base_path('database/migrations/2026_10_02_000010_collectes_archivees.php'))->up();

    $archivees = DB::table('scraping_campaigns')->whereNotNull('archived_at')->pluck('id')->all();
    expect($archivees)->toEqualCanonicalizing([$test1, $test2])
        // Rien n'est supprimé.
        ->and(DB::table('scraping_campaigns')->whereIn('id', [$test1, $test2, $enCours, $vraie])->whereNull('deleted_at')->count())->toBe(4);

    $vues = collect($this->getJson('/api/v1/campaigns')->assertOk()->json('data'))->pluck('id')->all();
    expect($vues)->toEqualCanonicalizing([$enCours, $vraie]);
    expect(collect($this->getJson('/api/v1/campaigns?archivees=tous')->json('data'))->pluck('id')->all())
        ->toEqualCanonicalizing([$test1, $test2, $enCours, $vraie]);
});

test('collectes : archiver puis desarchiver depuis la console ; refuse sur une collecte en cours', function () {
    $finie = l3Collecte($this->espace, $this->user->id, 'ZZ finie', 'completed');
    $enCours = l3Collecte($this->espace, $this->user->id, 'ZZ en cours', 'running');

    $this->postJson("/api/v1/campaigns/{$finie}/archive")->assertOk();
    $this->postJson("/api/v1/campaigns/{$enCours}/archive")->assertStatus(422);
    expect(collect($this->getJson('/api/v1/campaigns')->json('data'))->pluck('id')->all())->toBe([$enCours]);

    $this->postJson("/api/v1/campaigns/{$finie}/unarchive")->assertOk();
    expect(collect($this->getJson('/api/v1/campaigns')->json('data'))->pluck('id')->all())->toContain($finie);
});

// ── 8. Lenteurs ─────────────────────────────────────────────────────────────

test('etiquettes : plus de troncature a 500, et les comptes par etiquette sont servis depuis le cache', function () {
    $lignes = [];
    foreach (range(1, 501) as $i) {
        $lignes[] = ['workspace_id' => $this->espace, 'slug' => 'zz-l3-' . $i, 'name' => 'ZZ étiquette ' . $i, 'created_at' => now(), 'updated_at' => now()];
    }
    DB::table('tags')->insert($lignes);
    $tag = (int) DB::table('tags')->where('workspace_id', $this->espace)->where('slug', 'zz-l3-1')->value('id');
    $fiche = l3Entreprise($this->espace);
    DB::table('company_tag')->insert(['company_id' => $fiche, 'tag_id' => $tag, 'workspace_id' => $this->espace]);

    $r = $this->getJson('/api/v1/tags')->assertOk();
    expect(count($r->json('data')))->toBe(501)
        ->and(collect($r->json('data'))->firstWhere('id', $tag)['companies_count'])->toBe(1);

    expect(l3Requetes(fn () => $this->getJson('/api/v1/tags')->assertOk(), '/from "company_tag"/i'))->toBe(0);
});

test('journaux de collecte et federations : le total n est pas recompte a chaque affichage', function () {
    DB::table('scraper_runs')->insert([
        'workspace_id' => $this->espace, 'source' => 'zz', 'status' => 'success', 'started_at' => now(),
        'created_at' => now(), 'updated_at' => now(),
    ]);

    expect($this->getJson('/api/v1/scraper-runs')->assertOk()->json('meta.total'))->toBe(1);
    expect(l3Requetes(fn () => $this->getJson('/api/v1/scraper-runs')->assertOk(), '/count\(\*\).*from "scraper_runs"/i'))->toBe(0);

    $this->getJson('/api/v1/federations')->assertOk();
    expect(l3Requetes(fn () => $this->getJson('/api/v1/federations')->assertOk(), '/count\(\*\).*from "federations"/i'))->toBe(0);
});
