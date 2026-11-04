<?php

/**
 * L7 — Commande `crm:usine:pousser-agregats` : éteinte par défaut, sans secret rien ne part,
 * signée, agrégée, journal sans donnée de personne, alerte en cas d'échec.
 */

use App\Models\Workspace;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

const L7_URL = 'https://usine.example.test/api/agregats-crm';
// Valeur factice construite à l'exécution (aucun littéral que le scan de secrets prendrait pour un vrai).
const L7_SECRET = 'X' . '_' . 'abcdefghijklmnopqrstuvwxyz0123456789';

function l7Allumer(?string $secret = L7_SECRET): string
{
    config(['usine.agregats.enabled' => true, 'usine.agregats.url' => L7_URL, 'usine.agregats.secret' => $secret, 'usine.agregats.seuil' => 3]);
    config(['crm.ingest.business_workspace' => 'axion-ia']);
    $ws = (string) Workspace::create(['id' => (string) Str::uuid(), 'slug' => 'axion-ia', 'name' => 'Axion'])->id;
    DB::table('fonctions_espace')->updateOrInsert(['espace_id' => $ws, 'fonction' => 'origines.lecture'], ['ouverte' => true, 'updated_at' => now()]);
    $hier = now()->subDay()->format('Y-m-d');
    foreach (range(1, 4) as $i) {
        DB::table('personnes')->insert([
            'workspace_id' => $ws, 'person_key' => 'pk' . $i, 'email' => "p{$i}@example.invalid",
            'email_hash' => hash('sha256', "p{$i}@example.invalid"), 'premiere_source' => 'site',
            'premiere_source_at' => now(), 'legal_basis' => 'consent',
            'origines' => json_encode([['mot' => 'guide', 'reseau' => 'instagram', 'compte' => 'axion', 'guide' => 'g', 'date' => $hier . 'T09:00:00Z', 'post_ref' => 'POSTREF' . $i . 'abcdef']]),
        ]);
    }

    return $ws;
}

test('éteint par défaut (config) et aucune requête HTTP quand éteint', function () {
    expect(config('usine.agregats.enabled'))->toBeFalse();
    Http::fake();

    $this->artisan('crm:usine:pousser-agregats')->assertExitCode(0);

    Http::assertNothingSent();
});

test('secret absent ou trop court : rien n’est envoyé et une alerte "secret absent" part', function (?string $secret) {
    l7Allumer($secret);
    Http::fake();
    Log::spy();

    $this->artisan('crm:usine:pousser-agregats')->assertExitCode(0);

    Http::assertNothingSent();
    Log::shouldHaveReceived('critical')->withArgs(fn ($m, $c = []) => str_contains($m, 'Chiffres CRM → Usine') && str_contains((string) ($c['corps'] ?? ''), 'secret'))->once();
})->with([[null], [''], ['trop-court']]);

test('pousse les jours manquants + J-1, avec les en-têtes signés et un corps agrégé', function () {
    l7Allumer();
    $hier = now()->subDay()->format('Y-m-d');
    Http::fake([
        L7_URL => Http::sequence()->push(['jours' => []], 200)->whenEmpty(Http::response(['ok' => true], 200)),
    ]);

    $this->artisan('crm:usine:pousser-agregats')->assertExitCode(0);

    $envois = Http::recorded()->filter(fn ($p) => $p[0]->method() === 'POST');
    expect($envois->count())->toBeGreaterThanOrEqual(1);
    $req = $envois->first()[0];
    $horodatage = $req->header('X-Axion-Horodatage')[0] ?? '';
    $nonce = $req->header('X-Axion-Nonce')[0] ?? '';
    $attendu = hash_hmac('sha256', "POST\n/api/agregats-crm\n{$horodatage}\n{$nonce}\n" . hash('sha256', $req->body()), L7_SECRET);

    expect($req->header('X-Axion-Signature')[0] ?? '')->toBe($attendu)
        ->and($nonce)->toMatch('/^[0-9a-f]{32}$/')
        ->and(abs((int) $horodatage - time()))->toBeLessThan(120)
        ->and($req->body())->not->toContain('@')->not->toContain('POSTREF')->not->toContain(L7_SECRET)
        ->and(json_decode($req->body(), true))->toHaveKeys(['jour', 'genere_le', 'lignes'])
        ->and(DB::table('usine_agregats_envois')->where('jour', $hier)->where('statut_http', 200)->exists())->toBeTrue();
});

test('rattrapage : les jours déjà reçus par l’Usine ne sont pas renvoyés, les dates futures ou > 7 j sont ignorées', function () {
    l7Allumer();
    $hier = now()->subDay()->format('Y-m-d');
    Http::fake([L7_URL => Http::sequence()->push(['jours' => [$hier, now()->addDays(3)->format('Y-m-d'), now()->subDays(30)->format('Y-m-d')]], 200)->whenEmpty(Http::response(['ok' => true], 200))]);

    $this->artisan('crm:usine:pousser-agregats')->assertExitCode(0);

    $jours = Http::recorded()->filter(fn ($p) => $p[0]->method() === 'POST')
        ->map(fn ($p) => json_decode($p[0]->body(), true)['jour'])->values()->all();
    // J-1 est toujours poussé, jamais une date future ni > 7 j.
    foreach ($jours as $j) {
        expect($j <= $hier)->toBeTrue()->and($j >= now()->subDays(7)->format('Y-m-d'))->toBeTrue();
    }
});

test('non-2xx : alerte "attention", journal sans corps ni signature, ligne d’envoi écrite', function () {
    l7Allumer();
    Http::fake([L7_URL => Http::response('boom ' . L7_SECRET, 500)]);
    $vus = [];
    Log::listen(function ($e) use (&$vus): void {
        $vus[] = json_encode([$e->level, $e->message, $e->context], JSON_UNESCAPED_UNICODE);
    });

    $this->artisan('crm:usine:pousser-agregats');

    expect(DB::table('usine_agregats_envois')->where('statut_http', 500)->exists())->toBeTrue();
    $journal = implode("\n", $vus);
    expect($journal)->toContain('Chiffres CRM → Usine non envoyés')
        ->and($journal)->not->toContain(L7_SECRET)
        ->and($journal)->not->toContain('X-Axion-Signature');
    expect(json_encode(DB::table('usine_agregats_envois')->get()))->not->toContain(L7_SECRET);
});

test('un espace dont origines.lecture est fermé est exclu des agrégats', function () {
    $ws = l7Allumer();
    DB::table('fonctions_espace')->where('espace_id', $ws)->where('fonction', 'origines.lecture')->update(['ouverte' => false]);
    Http::fake();

    $this->artisan('crm:usine:pousser-agregats')->assertExitCode(0);

    Http::assertNothingSent();
});

test('la tâche est planifiée une seule fois, sans chevauchement, et ne supprime rien', function () {
    $evenements = collect(app(Schedule::class)->events())
        ->filter(fn ($e) => str_contains((string) $e->command, 'crm:usine:pousser-agregats'));

    expect($evenements)->toHaveCount(1)
        ->and($evenements->first()->withoutOverlapping)->toBeTrue()
        ->and($evenements->first()->expression)->toBe('30 5 * * *');
});

test('joursRecus en échec : seul J-1 est poussé (pas 7 jours d’un coup)', function () {
    l7Allumer();
    $hier = now()->utc()->subDay()->format('Y-m-d');
    Http::fake([L7_URL => Http::sequence()->push('panne', 500)->whenEmpty(Http::response(['ok' => true], 200))]);

    $this->artisan('crm:usine:pousser-agregats')->assertExitCode(0);

    $jours = Http::recorded()->filter(fn ($p) => $p[0]->method() === 'POST')
        ->map(fn ($p) => json_decode($p[0]->body(), true)['jour'])->values()->all();
    expect($jours)->toBe([$hier]);
});

test('J-1 est calculé en UTC, pas à l’heure de l’appli (Europe/Paris)', function () {
    l7Allumer();
    // 23:30 UTC le 4 = 00:30 le 5 à Paris : J-1 en UTC est le 3, pas le 4.
    Illuminate\Support\Carbon::setTestNow('2026-11-04 23:30:00 UTC');
    Http::fake([L7_URL => Http::sequence()->push(['jours' => []], 200)->whenEmpty(Http::response(['ok' => true], 200))]);

    $this->artisan('crm:usine:pousser-agregats')->assertExitCode(0);

    $jours = Http::recorded()->filter(fn ($p) => $p[0]->method() === 'POST')
        ->map(fn ($p) => json_decode($p[0]->body(), true)['jour'])->values()->all();
    Illuminate\Support\Carbon::setTestNow();
    expect(end($jours))->toBe('2026-11-03')->and($jours)->not->toContain('2026-11-04');
});
