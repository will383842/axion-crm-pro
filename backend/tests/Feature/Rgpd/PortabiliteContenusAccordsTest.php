<?php

declare(strict_types=1);

use App\Crm\Rgpd\SiteGdprService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * PV-6 (correction) — l'export de portabilité rend les contenus et accords de la
 * personne : origine, guides reçus, consentements et date d'opposition.
 */
uses(TestCase::class, RefreshDatabase::class);

test('PV-6 — l export de portabilité contient origine, guides, consentements et opposition', function () {
    config(['crm.ingest.business_workspace' => 'axion-ia']);
    $courriel = 'zz.export-accords@example.test';
    $espace = (string) Str::uuid();
    DB::table('workspaces')->insert([
        'id' => $espace, 'slug' => 'axion-ia', 'name' => 'Axion-IA', 'settings' => '{}',
        'cost_cap_eur' => 100, 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('personnes')->insert([
        'workspace_id' => $espace, 'person_key' => hash('sha256', $courriel), 'email' => $courriel,
        'premiere_source' => 'guide-ia', 'premiere_source_at' => now(), 'legal_basis' => 'consent',
        'origine_contenu' => json_encode(['mot' => 'mot-a', 'reseau' => 'reseau-a']),
        'guides_recus' => json_encode([['guide' => 'guide-a', 'date' => '2026-11-02T10:00:00Z']]),
        'consentements' => json_encode([['nature' => 'information', 'version' => 'M1']]),
        'opposition_at' => '2026-11-03 10:00:00+00',
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $contenu = app(SiteGdprService::class)->export(hash('sha256', $courriel), $courriel);

    $ligne = $contenu['business']['personnes'][0] ?? null;
    expect($ligne)->not->toBeNull()
        ->and($ligne)->toHaveKeys(['origine_contenu', 'guides_recus', 'consentements', 'opposition_at'])
        ->and($ligne['origine_contenu'])->not->toBeNull()
        ->and($ligne['guides_recus'])->not->toBeNull()
        ->and($ligne['consentements'])->not->toBeNull()
        ->and($ligne['opposition_at'])->not->toBeNull();
});
