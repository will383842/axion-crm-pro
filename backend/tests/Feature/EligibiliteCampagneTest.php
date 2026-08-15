<?php

/**
 * « ÉLIGIBLE CAMPAGNE » — une définition CALCULÉE, jamais un bac figé.
 *
 * Décision prise avec Will le 2026-08-15. Il voulait séparer ce qui est
 * complet pour les campagnes de ce qui ne l'est pas. On ne le fait PAS en
 * déplaçant les fiches : 3,49 M sont encore `pending` et deviendront
 * contactables au fil de l'enrichissement, et surtout une adresse devient
 * inéligible le jour où elle s'oppose. Un ensemble figé continuerait à
 * l'arroser — c'est ainsi qu'on fait blacklister un domaine d'envoi.
 *
 * Ces tests portent donc sur la FRAÎCHEUR autant que sur le filtrage : le
 * dernier vérifie qu'une opposition posée APRÈS coup retire immédiatement la
 * fiche, ce qu'aucun bac ne saurait faire.
 */

use App\Models\Company;
use App\Models\User;
use App\Models\Workspace;
use App\Support\EligibiliteCampagne;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    $this->workspace = Workspace::create([
        'id' => (string) Str::uuid(), 'slug' => 'ws-elig', 'name' => 'WS', 'settings' => [],
    ]);
    $this->user = User::create([
        'id' => (string) Str::uuid(), 'email' => 'elig@example.com', 'name' => 'U',
        'password_hash' => Hash::make('PasswordTest12345!'),
        'current_workspace_id' => $this->workspace->id,
        'first_login_completed_at' => now(),
    ]);
    $this->actingAs($this->user);
});

function fiche(string $workspaceId, array $attrs = []): Company
{
    return Company::create(array_merge([
        'workspace_id' => $workspaceId,
        'siren' => (string) random_int(100000000, 999999999),
        'denomination' => 'Fiche',
        'signals' => [],
        'metadata' => [],
    ], $attrs));
}

function eligibles(string $workspaceId, ?array $paliers = null): array
{
    return EligibiliteCampagne::appliquer(
        Company::query()->where('workspace_id', $workspaceId),
        $paliers,
    )->pluck('denomination')->all();
}

test('sans adresse, une fiche n’est jamais éligible', function () {
    fiche($this->workspace->id, ['denomination' => 'AVEC', 'email_generic' => 'contact@acme.fr']);
    fiche($this->workspace->id, ['denomination' => 'SANS']);

    expect(eligibles($this->workspace->id))->toBe(['AVEC']);
});

test('une opposition retire la fiche — comparaison sur l’adresse', function () {
    fiche($this->workspace->id, ['denomination' => 'OPPOSEE', 'email_generic' => 'stop@acme.fr']);
    fiche($this->workspace->id, ['denomination' => 'OK', 'email_generic' => 'oui@acme.fr']);

    DB::table('opt_out')->insert([
        'email' => 'stop@acme.fr', 'scope' => 'business', 'source' => 'test', 'created_at' => now(),
    ]);

    expect(eligibles($this->workspace->id))->toBe(['OK']);
});

test('une opposition arrivée HACHÉE retire aussi la fiche', function () {
    // Les oppositions venues du site arrivent en empreinte, pas en clair. Une
    // garde qui ne reconnaîtrait qu'une seule des deux formes ne garderait
    // rien la moitié du temps.
    fiche($this->workspace->id, ['denomination' => 'HACHEE', 'email_generic' => 'Cache@Acme.fr']);
    fiche($this->workspace->id, ['denomination' => 'OK', 'email_generic' => 'oui@acme.fr']);

    DB::table('opt_out')->insert([
        // Même calcul que `SiteSyncEvent::emailHash()` : sha256 de l'adresse
        // en minuscules, sans sel.
        'email_hash' => hash('sha256', 'cache@acme.fr'),
        'scope' => 'business', 'source' => 'site', 'created_at' => now(),
    ]);

    expect(eligibles($this->workspace->id))->toBe(['OK']);
});

test('une opposition de l’univers VIVIER ne retire pas une fiche business', function () {
    // Les deux univers sont étanches : se désinscrire d'un vivier de
    // candidatures ne vaut pas opposition commerciale, et l'inverse non plus.
    fiche($this->workspace->id, ['denomination' => 'BUSINESS', 'email_generic' => 'x@acme.fr']);

    DB::table('opt_out')->insert([
        'email' => 'x@acme.fr', 'scope' => 'vivier', 'source' => 'test', 'created_at' => now(),
    ]);

    expect(eligibles($this->workspace->id))->toBe(['BUSINESS']);
});

test('le palier de confiance CIBLE, il ne conditionne pas le droit', function () {
    // `best_email_confidence` est VOLONTAIREMENT hors `$fillable` : c'est un
    // score posé par l'enrichissement, pas une donnée saisissable. Une
    // affectation de masse le laisserait tomber EN SILENCE — ce test l'a
    // découvert en revenant vide. On le pose donc comme le fait le pipeline.
    fiche($this->workspace->id, ['denomination' => 'A', 'email_generic' => 'a@x.fr'])
        ->forceFill(['best_email_confidence' => 'A'])->save();
    fiche($this->workspace->id, ['denomination' => 'C', 'email_generic' => 'c@x.fr'])
        ->forceFill(['best_email_confidence' => 'C'])->save();

    // Sans palier demandé : les deux sont éligibles — le palier est un choix
    // de ciblage explicite, jamais un défaut caché.
    expect(eligibles($this->workspace->id))->toHaveCount(2);
    expect(eligibles($this->workspace->id, ['A']))->toBe(['A']);
});

test('l’inverse montre le RESTE À FAIRE, pas un ensemble vide', function () {
    fiche($this->workspace->id, ['denomination' => 'PRETE', 'email_generic' => 'p@x.fr']);
    fiche($this->workspace->id, ['denomination' => 'A ENRICHIR']);

    $reste = EligibiliteCampagne::appliquerNonEligibles(
        Company::query()->where('workspace_id', $this->workspace->id),
    )->pluck('denomination')->all();

    expect($reste)->toBe(['A ENRICHIR']);
});

test('🔴 la définition est FRAÎCHE : une opposition postérieure retire aussitôt', function () {
    fiche($this->workspace->id, ['denomination' => 'CIBLE', 'email_generic' => 'cible@acme.fr']);

    expect(eligibles($this->workspace->id))->toBe(['CIBLE']);

    DB::table('opt_out')->insert([
        'email' => 'cible@acme.fr', 'scope' => 'business', 'source' => 'desinscription', 'created_at' => now(),
    ]);

    // ⬇️ C'est LA raison de ne pas figer un bac : aucun ensemble recopié ne
    // saurait se corriger tout seul entre deux campagnes.
    expect(eligibles($this->workspace->id))->toBe([]);
});

test('le filtre HTTP expose la même définition', function () {
    fiche($this->workspace->id, ['denomination' => 'PRETE', 'email_generic' => 'ok@x.fr']);
    fiche($this->workspace->id, ['denomination' => 'A ENRICHIR']);

    $prets = $this->getJson('/api/v1/companies?filter[eligible_campagne]=1')->assertOk()->json('data');
    $reste = $this->getJson('/api/v1/companies?filter[eligible_campagne]=0')->assertOk()->json('data');

    expect(array_column($prets, 'denomination'))->toBe(['PRETE']);
    expect(array_column($reste, 'denomination'))->toBe(['A ENRICHIR']);
});
