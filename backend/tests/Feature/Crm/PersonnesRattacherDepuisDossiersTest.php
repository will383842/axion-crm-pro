<?php

use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/**
 * C3 (#784) : rattrapage `crm:personnes-rattacher-depuis-dossiers`, À BLANC PAR
 * DÉFAUT. Adresses en `@example.invalid`, SIREN en 9xxxxxxxx.
 */
beforeEach(function () {
    config([
        'crm.ingest.personnes_enabled' => false,
        'crm.ingest.demandes_personne_enabled' => true,
        'crm.ingest.business_workspace' => 'ws-c3',
    ]);
    $this->workspace = Workspace::create(['id' => (string) Str::uuid(), 'slug' => 'ws-c3', 'name' => 'Rattrapage C3', 'settings' => []]);
});

function c3Fiche(string $siren): int
{
    return (int) DB::table('companies')->insertGetId([
        'workspace_id' => test()->workspace->id, 'siren' => $siren, 'denomination' => 'ZZ FICHE ' . $siren, 'discovery_source' => 'site',
        'quality_score' => 0, 'signals' => '{}', 'metadata' => '{}', 'created_at' => now(), 'updated_at' => now(),
    ]);
}

function c3Contact(int $fiche, ?string $cle, string $email = 'zz.c3@example.invalid', string $nom = 'ZZ C3'): int
{
    return (int) DB::table('contacts')->insertGetId([
        'workspace_id' => test()->workspace->id, 'company_id' => $fiche, 'first_name' => 'Jeanne', 'last_name' => $nom,
        'email' => $email, 'person_key' => $cle, 'discovery_source' => 'site', 'created_at' => now(), 'updated_at' => now(),
    ]);
}

/** @return array{0: int, 1: int} [personne, demande en attente] */
function c3Personne(string $cle, string $email = 'zz.c3@example.invalid', array $en = []): array
{
    $p = (int) DB::table('personnes')->insertGetId($en + [
        'workspace_id' => test()->workspace->id, 'person_key' => $cle, 'email' => $email, 'email_hash' => hash('sha256', $email),
        'first_name' => 'Jeanne', 'last_name' => 'ZZ C3', 'legal_basis' => 'precontractual', 'premiere_source' => 'formulaire',
        'premiere_source_at' => now(), 'created_at' => now(), 'updated_at' => now(),
    ]);
    $a = (int) DB::table('activities')->insertGetId([
        'workspace_id' => test()->workspace->id, 'contact_id' => null, 'type' => 'form_submission', 'kind' => 'form_submission',
        'occurred_at' => now(), 'person_key' => $cle, 'external_ref' => 'site:event:' . Str::uuid(),
        'subject_type' => null, 'subject_id' => null, 'title' => 'ZZ demande',
        'payload' => json_encode(['pending_match' => ['denomination' => 'ZZ DECLAREE']]), 'created_at' => now(),
    ]);

    return [$p, $a];
}

function c3Lancer(array $options = []): string
{
    Artisan::call('crm:personnes-rattacher-depuis-dossiers', $options);

    return Artisan::output();
}

/** @return array<string, mixed> */
function c3Etat(): array
{
    return [
        DB::table('personnes')->orderBy('id')->get()->map(fn ($l) => (array) $l)->all(),
        DB::table('activities')->orderBy('id')->get()->map(fn ($l) => (array) $l)->all(),
        DB::table('contacts')->orderBy('id')->get()->map(fn ($l) => (array) $l)->all(),
    ];
}

const C3_CLE = 'c3c3c3c3c3c3c3c3c3c3c3c3c3c3c3c3c3c3c3c3c3c3c3c3c3c3c3c3c3c3c3c3';

test('1. à blanc par défaut : compte ce qui serait fait et n écrit rien', function () {
    $fiche = c3Fiche('900000901');
    c3Contact($fiche, C3_CLE);
    c3Personne(C3_CLE);
    $avant = c3Etat();

    DB::enableQueryLog();
    $sortie = c3Lancer();
    $requetes = array_column(DB::getQueryLog(), 'query');
    DB::disableQueryLog();

    expect($sortie)->toContain('À BLANC')
        ->and($sortie)->toContain('personnes à lier : 1')
        ->and($sortie)->toContain('demandes à solder : 1')
        ->and($sortie)->not->toContain('zz.c3@example')
        ->and($sortie)->not->toContain(C3_CLE)
        ->and(c3Etat())->toBe($avant)
        ->and(array_filter($requetes, static fn (string $q): bool => preg_match('/^\s*(insert|update|delete)\b/i', $q) === 1))->toBe([]);
});

test('2. --appliquer : personne liée, demande soldée, pending_match gardé, rien supprimé', function () {
    $fiche = c3Fiche('900000901');
    $contact = c3Contact($fiche, C3_CLE);
    [$personne, $demande] = c3Personne(C3_CLE);
    $nb = [DB::table('activities')->count(), DB::table('contacts')->count(), DB::table('personnes')->count()];

    DB::enableQueryLog();
    $sortie = c3Lancer(['--appliquer' => true]);
    $requetes = array_column(DB::getQueryLog(), 'query');
    DB::disableQueryLog();

    $p = DB::table('personnes')->where('id', $personne)->first();
    $a = DB::table('activities')->where('id', $demande)->first();
    $payload = json_decode($a->payload, true);
    expect($sortie)->toContain('APPLIQUÉ')
        ->and($p->contact_id)->toBe($contact)
        ->and($p->company_id)->toBe($fiche)
        ->and($a->subject_type)->toBe('company')
        ->and($a->subject_id)->toBe($fiche)
        ->and($a->contact_id)->toBe($contact)
        ->and($payload['arbitrage']['attached_by'])->toBe('rattrapage:dossiers')
        ->and($payload)->toHaveKey('pending_match')
        ->and([DB::table('activities')->count(), DB::table('contacts')->count(), DB::table('personnes')->count()])->toBe($nb)
        ->and(array_filter($requetes, static fn (string $q): bool => preg_match('/^\s*delete\b/i', $q) === 1))->toBe([]);
});

test('3. rejeu : rien de plus à faire, rien ne bouge', function () {
    $fiche = c3Fiche('900000901');
    c3Contact($fiche, C3_CLE);
    c3Personne(C3_CLE);
    c3Lancer(['--appliquer' => true]);
    $apres = c3Etat();

    $sortie = c3Lancer(['--appliquer' => true]);

    expect($sortie)->toContain('personnes à lier : 0')
        ->and(c3Etat())->toBe($apres);
});

test('4. personne opposée (opposition_at ou opt_out) : rien', function () {
    $fiche = c3Fiche('900000901');
    c3Contact($fiche, C3_CLE);
    c3Personne(C3_CLE, en: ['opposition_at' => now()]);
    $fiche2 = c3Fiche('900000902');
    $cle2 = str_repeat('d', 64);
    c3Contact($fiche2, $cle2, 'zz.c3b@example.invalid');
    c3Personne($cle2, 'zz.c3b@example.invalid');
    DB::table('opt_out')->insert(['email' => null, 'email_hash' => hash('sha256', 'zz.c3b@example.invalid'), 'scope' => 'business', 'source' => 'test', 'reason' => 'test', 'created_at' => now()]);
    $avant = c3Etat();

    $sortie = c3Lancer(['--appliquer' => true]);

    expect($sortie)->toContain('opposées (laissées) : 2')
        ->and(c3Etat())->toBe($avant);
});

test('5. liée ailleurs, ou clé présente dans deux fiches : rien, jamais de déplacement', function () {
    $a = c3Fiche('900000901');
    $b = c3Fiche('900000902');
    $contactA = c3Contact($a, C3_CLE);
    c3Personne(C3_CLE, en: ['contact_id' => $contactA, 'company_id' => $a, 'rattachee_at' => now()]);
    $cle2 = str_repeat('e', 64);
    c3Contact($a, $cle2, 'zz.c3b@example.invalid', 'ZZ B1');
    c3Contact($b, $cle2, 'zz.c3b@example.invalid', 'ZZ B2');
    c3Personne($cle2, 'zz.c3b@example.invalid');
    // Une clé liée à un contact à la corbeille : jamais déplacée non plus.
    $cle3 = str_repeat('f', 64);
    $morte = c3Contact($a, $cle3, 'zz.c3c@example.invalid', 'ZZ C1');
    DB::table('contacts')->where('id', $morte)->update(['deleted_at' => now()]);
    c3Contact($b, $cle3, 'zz.c3c@example.invalid', 'ZZ C2');
    c3Personne($cle3, 'zz.c3c@example.invalid', ['contact_id' => $morte, 'company_id' => $a, 'rattachee_at' => now()]);
    $avant = c3Etat();

    $sortie = c3Lancer(['--appliquer' => true]);

    expect($sortie)->toContain('personnes à lier : 0')
        ->and(c3Etat())->toBe($avant);
});

test('6. contact d une autre clé pour la même adresse : rien (clé divergente)', function () {
    $fiche = c3Fiche('900000901');
    c3Contact($fiche, str_repeat('a', 64));
    c3Personne(C3_CLE);
    $avant = c3Etat();

    $sortie = c3Lancer(['--appliquer' => true]);

    expect(c3Etat())->toBe($avant)
        ->and($sortie)->toContain('personnes à lier : 0')
        ->and($sortie)->toContain('clé divergente (laissées) : 1');
});

test('7. drapeau des demandes fermé : la personne est liée, la demande reste en attente', function () {
    config(['crm.ingest.demandes_personne_enabled' => false]);
    $fiche = c3Fiche('900000901');
    $contact = c3Contact($fiche, C3_CLE);
    [$personne, $demande] = c3Personne(C3_CLE);

    c3Lancer(['--appliquer' => true]);

    expect(DB::table('personnes')->where('id', $personne)->value('contact_id'))->toBe($contact)
        ->and(DB::table('activities')->where('id', $demande)->value('subject_id'))->toBeNull();
});

test('8. contact ou fiche à la corbeille ne compte pas', function () {
    $fiche = c3Fiche('900000901');
    c3Contact($fiche, C3_CLE);
    DB::table('companies')->where('id', $fiche)->update(['deleted_at' => now()]);
    c3Personne(C3_CLE);
    $avant = c3Etat();

    c3Lancer(['--appliquer' => true]);

    expect(c3Etat())->toBe($avant);
});

test('9. --a-blanc et --appliquer ensemble : refusé, rien n est écrit', function () {
    $fiche = c3Fiche('900000901');
    c3Contact($fiche, C3_CLE);
    c3Personne(C3_CLE);
    $avant = c3Etat();

    $code = Artisan::call('crm:personnes-rattacher-depuis-dossiers', ['--a-blanc' => true, '--appliquer' => true]);

    expect($code)->not->toBe(0)
        ->and(c3Etat())->toBe($avant);
});
