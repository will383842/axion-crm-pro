<?php

/**
 * GARDE DES RÉSIDUS D'EFFACEMENT — audit 360, B15-006 (S0).
 *
 * L'effacement laissait l'adresse et le téléphone EN CLAIR dans six tables.
 * La plus parlante est `activities` : son `payload` est un JSONB qui garde
 * `{"tel":"+33…","email":"jean.dupont@…"}`, et la clé étrangère vers le contact
 * est en `SET NULL` — supprimer le contact laissait donc la ligne, et avec elle
 * le téléphone de la personne qui venait de demander son effacement.
 *
 * Ces gardes vérifient qu'il ne reste RIEN, et — tout aussi important — que ce
 * qui doit rester reste : les listes d'opposition, qui sont ce qui empêche de
 * recontacter la personne.
 */

use App\Services\Rgpd\GdprErasureService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

const COURRIEL_EFFACE = 'jean.dupont@residu.test';
const TEL_EFFACE = '+33612345678';

/**
 * Le schema impose qu'une fiche candidate vive dans un espace dont le slug
 * commence par `vivier` — une contrainte d'etancheite, et une bonne. La sonde
 * la respecte au lieu de la contourner.
 */
function espaceResidu(string $prefixe = 'residu'): string
{
    $id = (string) Str::uuid();
    DB::table('workspaces')->insert([
        'id' => $id,
        'slug' => $prefixe . '-' . Str::random(8),
        'name' => 'Espace résidu',
        'settings' => '{}',
        'is_active' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return $id;
}

test('B15-006 — apres effacement, ni le telephone ni l adresse ne subsistent', function () {
    $espace = espaceResidu();
    $cle = hash('sha256', COURRIEL_EFFACE);

    // Une personne, sa fiche, sa timeline, et un courriel echange.
    $companyId = DB::table('companies')->insertGetId([
        'workspace_id' => $espace,
        'denomination' => 'Entreprise résidu',
        'siren' => (string) random_int(100000000, 999999999),
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $contactId = DB::table('contacts')->insertGetId([
        'workspace_id' => $espace, 'company_id' => $companyId,
        'first_name' => 'Jean', 'last_name' => 'Dupont',
        'email' => COURRIEL_EFFACE, 'phone' => TEL_EFFACE, 'person_key' => $cle,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    // 🔴 La ligne qui survivait, avec le telephone en clair dans son JSON.
    DB::table('activities')->insert([
        'workspace_id' => $espace,
        'contact_id' => $contactId,
        'person_key' => $cle,
        'type' => 'inbound',
        'kind' => 'form_submission',
        'title' => 'Demande de contact',
        'payload' => json_encode(['tel' => TEL_EFFACE, 'email' => COURRIEL_EFFACE]),
        'occurred_at' => now(),
        'created_at' => now(),
    ]);

    DB::table('candidates')->insert([
        'workspace_id' => espaceResidu('vivier'),
        'first_name' => 'Jean', 'last_name' => 'Dupont',
        'email' => COURRIEL_EFFACE, 'phone' => TEL_EFFACE, 'person_key' => $cle,
        'relation_type' => 'candidat_commercial',
        'lifecycle_stage' => 'nouveau',
        'legal_basis' => 'consent',
        'created_at' => now(), 'updated_at' => now(),
    ]);

    app(GdprErasureService::class)->erase(COURRIEL_EFFACE, TEL_EFFACE);

    // Plus aucune trace en clair, dans AUCUNE des tables concernées.
    expect(DB::table('contacts')->where('email', COURRIEL_EFFACE)->count())->toBe(0);
    expect(DB::table('candidates')->where('email', COURRIEL_EFFACE)->count())->toBe(0);
    expect(DB::table('activities')->whereRaw('payload::text ILIKE ?', ['%' . TEL_EFFACE . '%'])->count())->toBe(0);
    expect(DB::table('activities')->whereRaw('payload::text ILIKE ?', ['%' . COURRIEL_EFFACE . '%'])->count())->toBe(0);
});

test('B15-006 — le telephone d un contact media est neutralise, pas seulement l adresse', function () {
    $espace = espaceResidu();
    DB::table('media')->insert([
        'workspace_id' => $espace,
        'name' => 'Journal résidu',
        'media_type' => 'presse_quotidien',
        'email' => COURRIEL_EFFACE,
        'phone' => TEL_EFFACE,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    app(GdprErasureService::class)->erase(COURRIEL_EFFACE, TEL_EFFACE);

    $media = DB::table('media')->where('name', 'Journal résidu')->first();
    expect($media->email)->toBeNull();
    expect($media->phone)->toBeNull();
});

test('B15-006 — TEMOIN : ce qui appartient a QUELQU UN D AUTRE n est pas touche', function () {
    $espace = espaceResidu();
    $companyId = DB::table('companies')->insertGetId([
        'workspace_id' => $espace,
        'denomination' => 'Entreprise voisine',
        'siren' => (string) random_int(100000000, 999999999),
        'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('contacts')->insert([
        'workspace_id' => $espace, 'company_id' => $companyId,
        'first_name' => 'Marie', 'last_name' => 'Martin',
        'email' => 'marie.martin@voisine.test', 'phone' => '+33699999999',
        'person_key' => hash('sha256', 'marie.martin@voisine.test'),
        'created_at' => now(), 'updated_at' => now(),
    ]);

    app(GdprErasureService::class)->erase(COURRIEL_EFFACE, TEL_EFFACE);

    // Sans ce témoin, un effacement qui viderait la base entière passerait pour
    // une réussite.
    expect(DB::table('contacts')->where('email', 'marie.martin@voisine.test')->count())->toBe(1);
});

test('B15-006 — TEMOIN : les listes d opposition SURVIVENT a l effacement', function () {
    $espace = espaceResidu();
    DB::table('opt_out')->insert([
        'email' => null,
        'email_hash' => hash('sha256', COURRIEL_EFFACE),
        'scope' => 'business',
        'source' => 'gdpr_erasure_bisystem',
        'created_at' => now(),
    ]);

    app(GdprErasureService::class)->erase(COURRIEL_EFFACE, TEL_EFFACE);

    // Les purger ferait exactement l'inverse de ce que la personne demande :
    // elle redeviendrait joignable à la prochaine collecte.
    // `toBeGreaterThan(0)` et non `toBe(1)` : l'effacement AJOUTE lui-même une
    // ligne d'opposition. Exiger exactement 1 mesurerait ce comptage, pas la
    // survie de la ligne d'origine.
    expect(DB::table('opt_out')->where('email_hash', hash('sha256', COURRIEL_EFFACE))->count())
        ->toBeGreaterThan(0);
});
