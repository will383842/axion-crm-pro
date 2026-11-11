<?php

use App\Envoi\Chauffe\GardeChauffe;
use App\Envoi\Chauffe\ResolveurEnMemoire;
use App\Envoi\Chauffe\ResolveurFournisseur;
use App\Envoi\Temoins\DepotAccesTemoins;
use App\Envoi\Temoins\LecteurIndesirables;
use App\Envoi\Temoins\LecteurIndesirablesMemoire;
use App\Support\ListeSuppression;
use App\Support\WorkspaceContext;
use Carbon\Carbon;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-12-03 10:00:00', 'UTC'));
    config(['envoi.cle_empreinte' => 'cle-de-test-seulement', 'envoi.reel_actif' => true, 'envoi.temoins_releve_actif' => true]);
    app()->instance(ResolveurFournisseur::class, new ResolveurEnMemoire(['gmail.com' => 'gmail', 'yahoo.fr' => 'autre']));
    app()->forgetInstance(GardeChauffe::class);
});

afterEach(fn () => Carbon::setTestNow());

function espaceReleveR16(): string
{
    $id = (string) Str::uuid();
    DB::table('workspaces')->insert(['id' => $id, 'name' => 'Test', 'slug' => 'rel-' . substr($id, 0, 8), 'created_at' => now(), 'updated_at' => now()]);

    return $id;
}

/** Pose un accès et un message NOTRE envoyé à ce témoin (registre des messages envoyés). */
function temoinAvecMessageR16(string $ws, string $fournisseur, string $adresse, string $messageId, ?string $destinataire = null): void
{
    app(DepotAccesTemoins::class)->poser($ws, $fournisseur, $adresse, 'mdp-de-test');
    envoyerAR16($ws, $destinataire ?? $adresse, $messageId);
}

function envoyerAR16(string $ws, string $destinataire, string $messageId): void
{
    WorkspaceContext::run($ws, function () use ($ws, $destinataire, $messageId) {
        $boite = DB::table('envoi_boites')->where('workspace_id', $ws)->value('id') ?? (string) Str::uuid();
        if (! DB::table('envoi_boites')->where('id', $boite)->exists()) {
            DB::table('envoi_boites')->insert([
                'id' => $boite, 'workspace_id' => $ws, 'adresse' => 'zz-releve@test.invalid',
                'hote' => 'smtp.exemple.test', 'port' => 587, 'chiffrement' => 'starttls', 'identifiants_chiffres' => 'zz-chiffre',
            ]);
        }
        $injection = (string) Str::uuid();
        DB::table('envoi_injections_smtp')->insert(['workspace_id' => $ws, 'boite_id' => $boite, 'id_injection' => $injection, 'cle_idempotence' => 'zz-' . $injection]);
        DB::table('envoi_messages_envoyes')->insert([
            'workspace_id' => $ws,
            'message_id_empreinte' => hash('sha256', 'mid:' . $messageId),
            'id_injection' => $injection,
            'empreinte_destinataire' => ListeSuppression::empreinte($destinataire),
        ]);
    });
}

function releverAvecR16(LecteurIndesirablesMemoire $lecteur): int
{
    app()->instance(LecteurIndesirables::class, $lecteur);

    return Artisan::call('envoi:temoins-releve');
}

function journeesAutoR16(string $ws): array
{
    return WorkspaceContext::run($ws, fn () => DB::table('envoi_temoins_journees')->where('auteur', 'releve_auto')->get()->all());
}

function pausesDeR16(string $ws): array
{
    return WorkspaceContext::run($ws, fn () => DB::table('envoi_pauses')->where('workspace_id', $ws)->get()->all());
}

test('interrupteurs éteints : aucune connexion, aucun lecteur résolu', function () {
    $ws = espaceReleveR16();
    temoinAvecMessageR16($ws, 'gmail', 'temoin@gmail.com', 'm1@envoi.example.invalid');
    $lecteur = new LecteurIndesirablesMemoire(['gmail' => ['m1@envoi.example.invalid']]);
    foreach ([[false, false], [true, false], [false, true]] as [$reel, $releve]) {
        config(['envoi.reel_actif' => $reel, 'envoi.temoins_releve_actif' => $releve]);
        expect(releverAvecR16($lecteur))->toBe(0);
    }
    expect($lecteur->appels)->toBe(0)->and(journeesAutoR16($ws))->toBeEmpty()->and(pausesDeR16($ws))->toBeEmpty();
});

test('notre message en indésirables : pause vers CE fournisseur et journée releve_auto', function () {
    $ws = espaceReleveR16();
    temoinAvecMessageR16($ws, 'gmail', 'temoin@gmail.com', 'm1@envoi.example.invalid');
    expect(releverAvecR16(new LecteurIndesirablesMemoire(['gmail' => ['<m1@envoi.example.invalid>']])))->toBe(0);
    $pauses = pausesDeR16($ws);
    expect($pauses)->toHaveCount(1)->and($pauses[0]->fournisseur)->toBe('gmail');
    $j = journeesAutoR16($ws);
    expect($j)->toHaveCount(1)->and($j[0]->reception)->toBe('indesirables')->and($j[0]->fournisseur)->toBe('gmail')
        ->and($j[0]->jour)->toBe('2026-12-03')->and($j[0]->auth_alignee)->toBeFalse();
    expect(Artisan::output())->not->toContain('temoin@gmail.com')->and(Artisan::output())->not->toContain('mdp-de-test');
});

test('deux relèves le même jour : une seule ligne (rejeu sans effet)', function () {
    $ws = espaceReleveR16();
    temoinAvecMessageR16($ws, 'gmail', 'temoin@gmail.com', 'm1@envoi.example.invalid');
    $lecteur = new LecteurIndesirablesMemoire(['gmail' => ['m1@envoi.example.invalid']]);
    releverAvecR16($lecteur);
    releverAvecR16($lecteur);
    expect(journeesAutoR16($ws))->toHaveCount(1)->and(pausesDeR16($ws))->toHaveCount(1);
});

test('un message d’un tiers dans les indésirables n’est jamais compté', function () {
    $ws = espaceReleveR16();
    temoinAvecMessageR16($ws, 'gmail', 'temoin@gmail.com', 'nous@envoi.example.invalid');
    expect(releverAvecR16(new LecteurIndesirablesMemoire(['gmail' => ['pourriel@tiers.example.invalid']])))->toBe(0);
    expect(journeesAutoR16($ws))->toBeEmpty()->and(pausesDeR16($ws))->toBeEmpty();
});

test('notre message destiné à quelqu’un d’autre n’est pas compté pour ce témoin', function () {
    $ws = espaceReleveR16();
    temoinAvecMessageR16($ws, 'gmail', 'temoin@gmail.com', 'autre@envoi.example.invalid', 'prospect@societe.example.invalid');
    releverAvecR16(new LecteurIndesirablesMemoire(['gmail' => ['autre@envoi.example.invalid']]));
    expect(journeesAutoR16($ws))->toBeEmpty()->and(pausesDeR16($ws))->toBeEmpty();
});

test('un constat peut freiner, jamais libérer : rien d’écrit quand les indésirables sont vides', function () {
    $ws = espaceReleveR16();
    temoinAvecMessageR16($ws, 'gmail', 'temoin@gmail.com', 'm1@envoi.example.invalid');
    releverAvecR16(new LecteurIndesirablesMemoire);
    expect(WorkspaceContext::run($ws, fn () => DB::table('envoi_temoins_journees')->count()))->toBe(0);
});

test('Yahoo : pause « autre », et la limite est écrite dans le rapport', function () {
    $ws = espaceReleveR16();
    temoinAvecMessageR16($ws, 'yahoo', 'temoin@yahoo.fr', 'm1@envoi.example.invalid');
    releverAvecR16(new LecteurIndesirablesMemoire(['yahoo' => ['m1@envoi.example.invalid']]));
    $pauses = pausesDeR16($ws);
    expect($pauses)->toHaveCount(1)->and($pauses[0]->fournisseur)->toBe('autre');
    expect(Artisan::output())->toContain('rattaché à « autre »');
});

test('une panne de lecture est signalée (échec), sans mot de passe ni adresse dans les journaux', function () {
    $ws = espaceReleveR16();
    temoinAvecMessageR16($ws, 'gmail', 'temoin@gmail.com', 'm1@envoi.example.invalid');
    Log::spy();
    $lecteur = new LecteurIndesirablesMemoire;
    $lecteur->mettreEnPanne('gmail');
    expect(releverAvecR16($lecteur))->toBe(1);
    expect(Artisan::output())->not->toContain('mdp-de-test')->and(Artisan::output())->not->toContain('temoin@gmail.com');
    Log::shouldHaveReceived('error')->withArgs(fn ($m, $ctx = []) => ! str_contains(json_encode([$m, $ctx]), 'mdp-de-test') && ! str_contains(json_encode([$m, $ctx]), 'temoin@gmail.com'));
    expect(journeesAutoR16($ws))->toBeEmpty();
});

test('l’index unique ne vise que releve_auto : la saisie manuelle garde « corriger = nouvelle ligne »', function () {
    $ws = espaceReleveR16();
    $ligne = ['workspace_id' => $ws, 'jour' => '2026-12-03', 'fournisseur' => 'gmail', 'reception' => 'inbox', 'auth_alignee' => true, 'bouton_vu' => true, 'post_effectif' => true, 'auteur' => 'quelquun'];
    WorkspaceContext::run($ws, function () use ($ligne) {
        DB::table('envoi_temoins_journees')->insert($ligne);
        DB::table('envoi_temoins_journees')->insert($ligne);
    });
    expect(WorkspaceContext::run($ws, fn () => DB::table('envoi_temoins_journees')->count()))->toBe(2);
});

test('planifiée derrière les deux interrupteurs, sans chevauchement, sur un seul serveur', function () {
    $e = collect(app(Schedule::class)->events())->first(fn ($e) => str_contains((string) $e->command, 'envoi:temoins-releve'));
    expect($e)->not->toBeNull()->and($e->withoutOverlapping)->toBeTrue()->and($e->onOneServer)->toBeTrue();
    foreach ([[false, false], [true, false], [false, true]] as [$reel, $releve]) {
        config(['envoi.reel_actif' => $reel, 'envoi.temoins_releve_actif' => $releve]);
        expect($e->filtersPass($this->app))->toBeFalse();
    }
    config(['envoi.reel_actif' => true, 'envoi.temoins_releve_actif' => true]);
    expect($e->filtersPass($this->app))->toBeTrue();
});

test('temoins_releve_actif est faux par défaut', function () {
    expect(config('envoi.temoins_releve_actif'))->toBeTrue(); // posé vrai par beforeEach
    $defaut = (require base_path('config/envoi.php'))['temoins_releve_actif'];
    expect($defaut)->toBeFalse();
});
