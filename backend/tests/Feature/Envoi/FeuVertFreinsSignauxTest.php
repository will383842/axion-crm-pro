<?php

use App\Console\Commands\EnvoiTemoinsReleve;
use App\Envoi\Chauffe\DepotChauffe;
use App\Envoi\Depot\DepotSocleDb;
use App\Envoi\Desinscription\RetraitEnvoisEnFile;
use App\Envoi\FeuVert\CatalogueManuels;
use App\Envoi\FeuVert\DepotPreuves;
use App\Envoi\FeuVert\ListeFeuVert;
use App\Envoi\JourEnvoi;
use App\Envoi\Temoins\DepotAccesTemoins;
use App\Envoi\Temoins\HotesTemoins;
use App\Models\User;
use App\Models\Workspace;
use App\Support\WorkspaceContext;
use Database\Seeders\PermissionsAndRolesSeeder;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class)->group('envoi');

/**
 * X5 : signaux STRUCTURELS des points `auto.frein_temoins_branche` et `auto.frein_reponses_baisse`. Lecture seule : le feu vert
 * reste une décision humaine ; aucun test ici n'écrit un drapeau, une décision ou une configuration (sauf config de TEST en mémoire).
 */
const FVS_FREINS = 'auto.frein_reponses_baisse';
const FVS_TEMOINS = 'auto.frein_temoins_branche';

function fvsEspace(): string
{
    config(['envoi.cle_empreinte' => 'cle-de-test-seulement']);
    $id = (string) Str::uuid();
    DB::table('workspaces')->insert(['id' => $id, 'name' => 'Test', 'slug' => 'fvs-' . substr($id, 0, 8), 'settings' => json_encode(['produits' => ['envoi' => 'actif']]), 'created_at' => now(), 'updated_at' => now()]);

    return $id;
}

/** Planificateur de TEST : remplace celui de l'appli le temps du test (rien n'est lancé). */
function fvsPlanifier(?Closure $evaluer = null, bool $releve = true): void
{
    $s = new Schedule;
    $e = $s->command('envoi:evaluer-freins');
    if ($evaluer === null) {
        $e->everyFiveMinutes()->withoutOverlapping(10)->onOneServer();
    } else {
        $evaluer($e);
    }
    if ($releve) {
        $s->command(EnvoiTemoinsReleve::SIGNATURE_PLANIFIEE)->everyFifteenMinutes()->withoutOverlapping(10)->onOneServer()->when(fn (): bool => true);
    }
    app()->instance(Schedule::class, $s);
}

function fvsEspaceChauffe(): string
{
    $id = fvsEspace();
    Artisan::call('envoi:temoins-off', ['workspace' => $id, '--auteur' => 'test']);

    return $id;
}

function fvsBattement(string $ws, string $jour): void
{
    WorkspaceContext::run($ws, fn () => DB::table('envoi_journal_commandes')->insert(['workspace_id' => $ws, 'commande' => 'evaluation_freins', 'auteur' => 'freins', 'details' => '{}', 'jour' => $jour]));
}

function fvsAcces(string $ws, array $fournisseurs): void
{
    foreach ($fournisseurs as $i => $f) {
        app(DepotAccesTemoins::class)->poser($ws, $f, 'temoin' . $i . '@exemple.test', 'mot-de-passe-de-test-' . $i);
    }
}

/**
 * Tout vert : les deux signaux X5 présents, plus les autres verrous automatiques. `$sauf` retire UNE condition (les tables
 * d'envoi sont en ajout seul : on ne les corrige pas après coup, on ne les remplit pas). `$jourBattement` : recul en jours.
 */
function fvsToutVert(string $ws, string $sauf = '', int $jourBattement = 0): void
{
    config([
        'envoi.cle_desinscription' => str_repeat('a', 40), 'envoi.desinscription_hote' => 'app',
        'envoi.desinscription_hotes' => ['app' => 'https://app.exemple.test', 'api' => 'https://api.exemple.test'],
        'envoi.releve_actif' => true, 'envoi.sante.actif' => true, 'envoi.temoins_releve_actif' => true,
    ]);
    app()->instance(RetraitEnvoisEnFile::class, Mockery::mock(RetraitEnvoisEnFile::class));
    DB::table('envoi_drapeaux')->updateOrInsert(['workspace_id' => $ws], ['mode_temoins' => true, 'feu_vert_signature' => null]);
    WorkspaceContext::run($ws, function () use ($ws) {
        DB::table('envoi_essais_blanc')->insert(['workspace_id' => $ws, 'auteur' => 'test', 'debut' => now()->subHour(), 'fin' => now()->subMinutes(30), 'reussi' => true, 'etapes' => '[]']);
        DB::table('envoi_controles_dns')->insert(['workspace_id' => $ws, 'domaine' => 'a.exemple.test', 'controle_le' => now(), 'pret' => true, 'pause' => false]);
        DB::table('envoi_controles_listes')->insert(['workspace_id' => $ws, 'type' => 'domaine', 'ressource' => 'a.exemple.test', 'statut' => 'non_liste', 'zone' => 'z.test', 'pause' => false]);
        foreach (DepotSocleDb::FOURNISSEURS_TEMOINS as $i => $f) {
            DB::table('envoi_temoins')->insert(['workspace_id' => $ws, 'empreinte' => str_repeat(dechex($i + 1), 64), 'fournisseur' => $f]);
            foreach ([0, 1, 2] as $recul) {
                DB::table('envoi_temoins_journees')->insert(['workspace_id' => $ws, 'jour' => JourEnvoi::de()->modify("-{$recul} days")->format('Y-m-d'), 'fournisseur' => $f, 'reception' => 'inbox', 'auth_alignee' => true, 'bouton_vu' => true, 'post_effectif' => true, 'auteur' => 'test']);
            }
        }
    });
    // Planificateur
    match ($sauf) {
        'planif_absente' => (function () {
            $s = new Schedule;
            $s->command(EnvoiTemoinsReleve::SIGNATURE_PLANIFIEE)->everyFifteenMinutes();
            app()->instance(Schedule::class, $s);
        })(),
        'planif_15min' => fvsPlanifier(fn ($e) => $e->everyFifteenMinutes()),
        'planif_quotidienne' => fvsPlanifier(fn ($e) => $e->daily()),
        'planif_semaine' => fvsPlanifier(fn ($e) => $e->everyFiveMinutes()->weekdays()),
        'planif_when' => fvsPlanifier(fn ($e) => $e->everyFiveMinutes()->when(fn (): bool => false)),
        'planif_skip' => fvsPlanifier(fn ($e) => $e->everyFiveMinutes()->skip(fn (): bool => true)),
        'planif_skip_cache' => fvsPlanifier(fn ($e) => $e->everyFiveMinutes()->withoutOverlapping(10)->skip(fn (): bool => true)),
        'planif_environnement' => fvsPlanifier(fn ($e) => $e->everyFiveMinutes()->environments(['production'])),
        'releve_non_planifiee' => fvsPlanifier(null, false),
        default => fvsPlanifier(),
    };
    // Battement du journal
    match ($sauf) {
        'battement_absent' => null,
        'battement_2_jours' => fvsBattement($ws, JourEnvoi::de()->modify('-2 days')->format('Y-m-d')),
        'battement_autre_espace' => fvsBattement(fvsEspace(), JourEnvoi::de()->format('Y-m-d')),
        'battement_autre_commande' => WorkspaceContext::run($ws, fn () => DB::table('envoi_journal_commandes')->insert(['workspace_id' => $ws, 'commande' => 'autre_commande', 'auteur' => 'freins', 'details' => '{}', 'jour' => JourEnvoi::de()->format('Y-m-d')])),
        default => fvsBattement($ws, JourEnvoi::de()->modify("-{$jourBattement} days")->format('Y-m-d')),
    };
    // Accès des témoins et interrupteur de relève
    $tous = array_keys(HotesTemoins::LISTE);
    match ($sauf) {
        'acces_aucun' => null,
        'acces_un_manque' => fvsAcces($ws, array_values(array_diff($tous, ['orange']))),
        'acces_panne' => (function () use ($ws, $tous) {
            // Les 6 fournisseurs ont un accès LISIBLE ; un septième témoin (gmail) est illisible : seule la panne manque au vert.
            fvsAcces($ws, $tous);
            WorkspaceContext::run($ws, fn () => DB::table('envoi_temoins_acces')->insert(['workspace_id' => $ws, 'empreinte_temoin' => str_repeat('f', 64), 'fournisseur' => 'gmail', 'hote' => 'imap.gmail.com', 'identifiant_chiffre' => 'illisible']));
        })(),
        'acces_autre_espace' => fvsAcces(fvsEspace(), $tous),
        default => fvsAcces($ws, $tous),
    };
    match ($sauf) {
        'releve_eteint' => config(['envoi.temoins_releve_actif' => false]),
        'releve_absent' => config(['envoi.temoins_releve_actif' => null]),
        'releve_texte' => config(['envoi.temoins_releve_actif' => '1']),
        default => null,
    };
}

function fvsPoint(string $ws, string $code): object
{
    foreach (app(ListeFeuVert::class)->points($ws) as $p) {
        if ($p->code === $code) {
            return $p;
        }
    }
    throw new RuntimeException($code);
}

// ── auto.frein_reponses_baisse ────────────────────────────────────────────────

test('réponses en baisse : tout présent ⇒ vrai (aujourd’hui comme hier)', function (int $recul) {
    $ws = fvsEspace();
    fvsToutVert($ws, '', $recul);
    expect(fvsPoint($ws, FVS_FREINS))->fait->toBeTrue()->bloquant->toBeFalse()->etat->toBe('fait');
})->with([0, 1]);

test('réponses en baisse : UNE condition absente, tout le reste vert ⇒ faux', function (string $sauf) {
    $ws = fvsEspace();
    fvsToutVert($ws, $sauf);
    expect(fvsPoint($ws, FVS_FREINS))->fait->toBeFalse()->etat->toBe('a_faire');
})->with(['planif_absente', 'planif_15min', 'planif_quotidienne', 'planif_semaine', 'planif_when', 'planif_skip', 'planif_skip_cache', 'planif_environnement', 'battement_absent', 'battement_2_jours', 'battement_autre_espace', 'battement_autre_commande']);

// ── auto.frein_temoins_branche ────────────────────────────────────────────────

test('témoins branchés : tout présent ⇒ vrai, et le détail renvoie à manuel.arrivee_boite', function () {
    $ws = fvsEspace();
    fvsToutVert($ws);
    $p = fvsPoint($ws, FVS_TEMOINS);
    expect($p)->fait->toBeTrue()->etat->toBe('fait')
        ->and($p->detail)->toContain('exécution réelle prouvable seulement après ouverture : voir manuel.arrivee_boite')
        ->and(CatalogueManuels::POINTS)->toHaveKey('manuel.arrivee_boite');
});

test('témoins branchés : UNE condition absente, tout le reste vert ⇒ faux', function (string $sauf) {
    $ws = fvsEspace();
    fvsToutVert($ws, $sauf);
    expect(fvsPoint($ws, FVS_TEMOINS))->fait->toBeFalse()->etat->toBe('a_faire');
})->with(['releve_eteint', 'releve_absent', 'releve_texte', 'releve_non_planifiee', 'acces_aucun', 'acces_un_manque', 'acces_panne', 'acces_autre_espace']);

test('l’état global ne devient JAMAIS « accordé » tout seul : deux signaux verts, la séparation et les 22 points « serveur réel » restent rouges', function () {
    $ws = fvsEspace();
    fvsToutVert($ws);
    foreach (CatalogueManuels::codesManuels() as $code) {
        if (! CatalogueManuels::POINTS[$code]['serveur_reel']) {
            app(DepotPreuves::class)->ajouter($ws, $code, 'Preuve de test, réf. exemple-01', 'auteur');
        }
    }
    $avant = DB::table('envoi_drapeaux')->where('workspace_id', $ws)->first();
    $points = app(ListeFeuVert::class)->points($ws);
    $bloquants = array_values(array_map(fn ($p) => $p->code, array_filter($points, fn ($p) => $p->bloquant && $p->code !== ListeFeuVert::POINT_EXPLOITATION)));
    $serveur = array_keys(array_filter(CatalogueManuels::POINTS, fn ($c, $k) => str_starts_with($k, 'manuel.') && $c['serveur_reel'], ARRAY_FILTER_USE_BOTH));
    expect(fvsPoint($ws, FVS_FREINS)->fait)->toBeTrue()
        ->and(fvsPoint($ws, FVS_TEMOINS)->fait)->toBeTrue()
        ->and($serveur)->toHaveCount(22)
        ->and($bloquants)->toContain('auto.separation_lettre_prospection')
        ->and(array_diff($serveur, $bloquants))->toBe([])
        ->and(app(ListeFeuVert::class)->derniereDecision($ws))->toBeNull()
        ->and(DB::table('envoi_feu_vert_decisions')->where('workspace_id', $ws)->count())->toBe(0)
        ->and(DB::table('envoi_drapeaux')->where('workspace_id', $ws)->first())->toEqual($avant);
});

test('DecisionFeuVert répond 409 tant que la séparation et les 22 points « serveur réel » sont rouges, même avec les deux signaux verts', function () {
    $this->seed(PermissionsAndRolesSeeder::class);
    config(['envoi.cle_empreinte' => 'cle-de-test-seulement']);
    $ws = Workspace::create(['id' => (string) Str::uuid(), 'slug' => 'fvs-' . Str::random(8), 'name' => 'Espace X5', 'settings' => ['produits' => ['envoi' => 'actif']]])->id;
    $u = User::create(['id' => (string) Str::uuid(), 'email' => 'owner-' . Str::random(6) . '@exemple.test', 'name' => 'Responsable Test', 'password_hash' => password_hash('MotDePasseDeTest2026!', PASSWORD_BCRYPT, ['cost' => 4]), 'current_workspace_id' => $ws, 'first_login_completed_at' => now()]);
    setPermissionsTeamId($ws);
    $u->assignRole('owner');
    fvsToutVert($ws);
    foreach (CatalogueManuels::codesManuels() as $code) {
        app(DepotPreuves::class)->ajouter($ws, $code, 'Preuve de test, réf. exemple-01', 'auteur'); // même les points « serveur réel » : ils restent rouges
    }
    $e = (string) $this->actingAs($u)->getJson('/api/v1/envoi/feu-vert')->assertOk()->json('empreinte_liste');
    $this->actingAs($u)->postJson('/api/v1/envoi/feu-vert/decision', ['decision' => 'accorde', 'motif' => 'Décision de test après relecture, réf. exemple-01.'], ['If-Match' => $e])->assertStatus(409);
    expect(DB::table('envoi_feu_vert_decisions')->where('workspace_id', $ws)->count())->toBe(0);
});

test('l’empreinte change quand un point bascule (signal réponses, puis signal témoins)', function () {
    $liste = app(ListeFeuVert::class);
    // Même espace, trois états du même couple de points.
    $ws = fvsEspace();
    fvsToutVert($ws);
    $vert = $liste->empreinte($ws, $liste->points($ws));
    config(['envoi.temoins_releve_actif' => false]);
    $sansTemoins = $liste->empreinte($ws, $liste->points($ws));
    config(['envoi.temoins_releve_actif' => true]);
    fvsPlanifier(fn ($e) => $e->daily());
    $sansFreins = $liste->empreinte($ws, $liste->points($ws));
    expect($sansTemoins)->not->toBe($vert)->and($sansFreins)->not->toBe($vert)->and($sansFreins)->not->toBe($sansTemoins);
});

test('lecture seule : calculer la liste n’écrit ni journal, ni drapeau, ni configuration', function () {
    $ws = fvsEspace();
    fvsToutVert($ws);
    $avant = [DB::table('envoi_journal_commandes')->count(), DB::table('envoi_drapeaux')->count(), config('envoi.reel_actif'), config('envoi.temoins_releve_actif')];
    app(ListeFeuVert::class)->points($ws);
    expect([DB::table('envoi_journal_commandes')->count(), DB::table('envoi_drapeaux')->count(), config('envoi.reel_actif'), config('envoi.temoins_releve_actif')])->toBe($avant)
        ->and(config('envoi.reel_actif'))->toBeFalse();
});

test('le catalogue reste en version 6 : codes inchangés, libellés inchangés', function () {
    expect(CatalogueManuels::VERSION)->toBe(6)->and(CatalogueManuels::POINTS)->toHaveKeys([FVS_FREINS, FVS_TEMOINS]);
});

// ── envoi:evaluer-freins : battement quotidien ────────────────────────────────

test('la commande journalise UN battement par jour et par espace, sans donnée de personne', function () {
    $a = fvsEspace();
    $b = fvsEspace();
    expect(Artisan::call('envoi:evaluer-freins'))->toBe(0);
    expect(Artisan::call('envoi:evaluer-freins'))->toBe(0); // deuxième passage du jour : pas de doublon
    foreach ([$a, $b] as $ws) {
        $l = DB::table('envoi_journal_commandes')->where('workspace_id', $ws)->where('commande', 'evaluation_freins')->get();
        expect($l)->toHaveCount(1)->and((string) $l[0]->jour)->toBe(JourEnvoi::de()->format('Y-m-d'))->and((string) $l[0]->details)->toBe('[]')->and($l[0]->auteur)->toBe('freins');
    }
});

test('exception à l’évaluation : aucun battement pour cet espace, classe + message fixe, jamais le message brut', function () {
    $espaces = [fvsEspaceChauffe(), fvsEspaceChauffe()];
    sort($espaces);
    $maintenant = now()->toDateTimeImmutable();
    foreach ($espaces as $ws) {
        app(DepotChauffe::class)->incrementer($ws, 'b@example.test', JourEnvoi::de($maintenant), 'autre', 'rebonds_durs', 3);
    }
    DB::unprepared("CREATE OR REPLACE FUNCTION test_x5_panne() RETURNS trigger AS \$f\$ BEGIN
        IF NEW.workspace_id = '{$espaces[0]}'::uuid THEN RAISE EXCEPTION 'detail-brut-secret-x5'; END IF; RETURN NEW; END; \$f\$ LANGUAGE plpgsql");
    DB::unprepared('CREATE TRIGGER test_x5_panne BEFORE INSERT ON envoi_pauses FOR EACH ROW EXECUTE FUNCTION test_x5_panne()');
    Log::spy();
    $code = Artisan::call('envoi:evaluer-freins');
    $sortie = Artisan::output();
    expect($code)->toBe(1)
        ->and($sortie)->toContain(QueryException::class)
        ->and($sortie)->not->toContain('secret-x5')->not->toContain('SQLSTATE')->not->toContain('INSERT');
    Log::shouldNotHaveReceived('error', fn ($m, $c = []) => str_contains($m . json_encode($c), 'secret-x5'));
    $battus = DB::table('envoi_journal_commandes')->where('commande', 'evaluation_freins')->pluck('workspace_id')->all();
    expect($battus)->not->toContain($espaces[0])->toContain($espaces[1]);
});
