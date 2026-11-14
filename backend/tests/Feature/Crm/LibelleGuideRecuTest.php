<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/** Libellé d'activité de `lead_magnet_requested` : suit le guide reçu. Adresses en `.test`. */
const LGR_SECRET = 'secret-de-test-lgr-aaaabbbbccccddddeeeeffff00001111';
const LGR_EMAIL = 'zz.libelle@example.test';

beforeEach(function () {
    config([
        'crm.ingest.enabled' => true,
        'crm.ingest.personnes_enabled' => true,
        'crm.ingest.hmac_secret' => LGR_SECRET,
        'crm.ingest.business_workspace' => 'axion-ia',
        'crm.ingest.max_clock_skew_seconds' => 300,
    ]);

    if (DB::table('workspaces')->where('slug', 'axion-ia')->doesntExist()) {
        DB::table('workspaces')->insert([
            'id' => (string) Str::uuid(), 'slug' => 'axion-ia', 'name' => 'Axion-IA', 'settings' => '{}',
            'cost_cap_eur' => 100, 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }
});

/** Envoie l'événement et rend le titre de l'activité créée. */
function lgrTitre(array $extra = [], int $version = 2): string
{
    $event = array_replace([
        'schema_version' => $version,
        'event_id' => (string) Str::uuid(),
        'event_type' => 'lead_magnet_requested',
        'occurred_at' => '2026-11-02T10:00:00Z',
        'subject_ref' => 'site:guide_request:' . Str::uuid(),
        'person' => ['person_key' => hash('sha256', 'lgr|' . LGR_EMAIL), 'email' => LGR_EMAIL],
    ], $extra);
    $body = json_encode($event, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $ts = (string) time();

    test()->call('POST', '/api/internal/site-sync', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_ACCEPT' => 'application/json',
        'HTTP_X_SITE_TIMESTAMP' => $ts,
        'HTTP_X_SITE_SIGNATURE' => hash_hmac('sha256', $ts . '.' . $body, LGR_SECRET),
    ], $body)->assertOk();

    return (string) DB::table('activities')->where('external_ref', '!=', '')->orderByDesc('id')->value('title');
}

function lgrChamps(string $guide): array
{
    return [
        'origine_contenu' => ['mot' => 'm', 'reseau' => 'reseau-a', 'guide' => $guide, 'date' => '2026-11-02T10:00:00Z'],
        'guides_recus' => [['guide' => $guide, 'date' => '2026-11-02T10:00:00Z']],
    ];
}

test('LGR a — ancien événement sans guide : libellé inchangé', function () {
    expect(lgrTitre([], 1))->toBe('Guide IA entreprise téléchargé');
});

test('LGR b — guide-ia : libellé inchangé', function () {
    expect(lgrTitre(lgrChamps('guide-ia')))->toBe('Guide IA entreprise téléchargé');
});

test('LGR c — code sans entrée de config : le code brut', function () {
    expect(lgrTitre(lgrChamps('gde-conversation')))->toBe('Guide téléchargé : gde-conversation');
});

test('LGR d — code présent dans la config : son libellé', function () {
    config(['guides.publics.gde-test' => ['nom' => 'N', 'libelle' => 'Guide de test (PDF)', 'url' => 'https://exemple.invalid/g.pdf']]);

    expect(lgrTitre(lgrChamps('gde-test')))->toBe('Guide téléchargé : Guide de test (PDF)');
});
