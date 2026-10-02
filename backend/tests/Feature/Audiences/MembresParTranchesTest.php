<?php

use App\Jobs\RefreshAudienceChunkJob;
use App\Models\AudienceMember;
use App\Models\Company;
use App\Models\EmailAudience;
use App\Models\Workspace;
use App\Services\Audiences\AudienceBuilderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/**
 * LES MEMBRES D'UNE AUDIENCE S'INSÈRENT PAR TRANCHES (panne de production
 * du 2026-10-02, 23 h 04).
 *
 * Postgres refuse une requête de plus de 65 535 paramètres ; chaque ligne de
 * `audience_members` en porte cinq. Un lot de 5 000 fiches produit une ligne
 * par PERSONNE : mesuré en production, 13 375 lignes (66 875 paramètres)
 * pour l'audience 1 à l'offset 195 000 et 13 472 pour l'audience 3 à
 * l'offset 125 000. L'`insertOrIgnore` unique de ces deux lots échouait à
 * chaque essai, et la capture Sentry de l'erreur épuisait la mémoire du
 * worker : le lot restait suspendu, le rappel `finally` ne venait jamais.
 *
 * Ici, UNE fiche et 13 200 personnes contactables (66 000 paramètres) :
 * au-dessus de la limite, par le chemin en ligne comme par le job de lot.
 * Fixtures FICTIVES (dépôt public).
 */
beforeEach(function () {
    $this->ws = Workspace::create(['id' => Str::uuid()->toString(), 'name' => 'ZZ tranches', 'slug' => 'zz-tranches-' . uniqid()]);
    $this->fiche = Company::create([
        'workspace_id' => $this->ws->id,
        'siren' => '95' . str_pad((string) random_int(0, 9999999), 7, '0', STR_PAD_LEFT),
        'department_code' => '38',
    ]);
    DB::statement(<<<'SQL'
        INSERT INTO contacts (workspace_id, company_id, last_name, email, email_status, sources, metadata)
        SELECT ?, ?, 'ZZ Tranche ' || g, 'zz.tranche.' || g || '@example.invalid', 'valid', '[]'::jsonb, '{}'::jsonb
          FROM generate_series(1, 13200) AS g
    SQL, [$this->ws->id, $this->fiche->id]);

    $this->audience = EmailAudience::create([
        'workspace_id' => $this->ws->id,
        'name' => 'ZZ audience tranches',
        'criteria' => ['all' => [['field' => 'department_code', 'op' => 'eq', 'value' => '38']]],
    ]);
    $this->service = app(AudienceBuilderService::class);
});

test('une fiche a 13 200 personnes depasse les 65 535 parametres d un seul insert', function () {
    $lignes = $this->service->lignesMembres($this->audience, [(int) $this->fiche->id]);

    expect(count($lignes))->toBe(13200)
        ->and(count($lignes) * count($lignes[0]))->toBeGreaterThan(65535)
        ->and(AudienceBuilderService::MEMBRES_PAR_INSERTION * count($lignes[0]))->toBeLessThan(65535);
});

test('le rafraichissement en ligne insere les 13 200 membres sans erreur', function () {
    $this->service->refresh($this->audience);

    $this->audience->refresh();
    expect($this->audience->member_count)->toBe(13200)
        ->and($this->audience->refreshed_at)->not->toBeNull();
})->group('slow');

test('le job de lot insere les 13 200 membres sans erreur', function () {
    (new RefreshAudienceChunkJob(audienceId: (int) $this->audience->id, offset: 0, limit: 5000))
        ->pourEspace((string) $this->ws->id)
        ->handle($this->service);

    expect(AudienceMember::where('audience_id', $this->audience->id)->count())->toBe(13200);
})->group('slow');
