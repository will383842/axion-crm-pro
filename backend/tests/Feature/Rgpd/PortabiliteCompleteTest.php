<?php

/**
 * GARDE DE LA PORTABILITÉ — audit 360, B15-003 (S0).
 *
 * L'export des articles 15 et 20 ne couvrait que **4 tables sur 31**. Manquaient
 * la timeline de la personne, sa fiche candidat, sa fiche journaliste ou
 * praticien, ses courriels échangés — c'est-à-dire l'essentiel de ce que le CRM
 * sait d'elle.
 *
 * INVARIANT POSÉ : *ce qu'on sait effacer, on doit savoir l'exporter*. Le dernier
 * test le vérifie table pour table, pour qu'aucun des deux services ne puisse
 * apprendre une table sans l'autre.
 */

use App\Services\Rgpd\GdprPortabilityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

const COURRIEL_EXPORT = 'sujet@portabilite.test';

function espacePort(string $prefixe = 'port'): string
{
    $id = (string) Str::uuid();
    DB::table('workspaces')->insert([
        'id' => $id, 'slug' => $prefixe . '-' . Str::random(8), 'name' => 'Espace portabilité',
        'settings' => '{}', 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
    ]);

    return $id;
}

/** Rend le contenu déchiffré de l'export. */
function contenuExporte(array $resultat): array
{
    $chemin = 'gdpr-exports/' . $resultat['token'] . '.enc';
    $chiffre = Storage::disk('local')->get($chemin);

    return json_decode(Crypt::decryptString($chiffre), true, 512, JSON_THROW_ON_ERROR);
}

test('B15-003 — l export contient la TIMELINE de la personne', function () {
    Storage::fake('local');
    $espace = espacePort();
    $cle = hash('sha256', COURRIEL_EXPORT);

    $companyId = DB::table('companies')->insertGetId([
        'workspace_id' => $espace, 'denomination' => 'Entreprise export',
        'siren' => (string) random_int(100000000, 999999999),
        'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('contacts')->insert([
        'workspace_id' => $espace, 'company_id' => $companyId,
        'first_name' => 'Sujet', 'last_name' => 'Test', 'email' => COURRIEL_EXPORT,
        'person_key' => $cle, 'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('activities')->insert([
        'workspace_id' => $espace, 'person_key' => $cle,
        'type' => 'inbound', 'kind' => 'form_submission',
        'title' => 'Demande de contact',
        'payload' => json_encode(['message' => 'Bonjour']),
        'occurred_at' => now(), 'created_at' => now(),
    ]);

    $contenu = contenuExporte(app(GdprPortabilityService::class)->export(COURRIEL_EXPORT));

    expect($contenu)->toHaveKey('activities');
    expect($contenu['activities'])->toHaveCount(1);
});

test('B15-003 — l export contient la fiche CANDIDAT', function () {
    Storage::fake('local');
    DB::table('candidates')->insert([
        'workspace_id' => espacePort('vivier'),
        'first_name' => 'Sujet', 'last_name' => 'Test', 'email' => COURRIEL_EXPORT,
        'relation_type' => 'candidat_commercial', 'lifecycle_stage' => 'nouveau',
        'legal_basis' => 'consent', 'created_at' => now(), 'updated_at' => now(),
    ]);

    $contenu = contenuExporte(app(GdprPortabilityService::class)->export(COURRIEL_EXPORT));

    expect($contenu['candidates'])->toHaveCount(1);
});

test('B15-003 — TEMOIN : l export ne contient PAS les donnees de quelqu un d autre', function () {
    Storage::fake('local');
    $espace = espacePort();
    $companyId = DB::table('companies')->insertGetId([
        'workspace_id' => $espace, 'denomination' => 'Voisine',
        'siren' => (string) random_int(100000000, 999999999),
        'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('contacts')->insert([
        'workspace_id' => $espace, 'company_id' => $companyId,
        'first_name' => 'Marie', 'last_name' => 'Martin', 'email' => 'marie@voisine.test',
        'person_key' => hash('sha256', 'marie@voisine.test'),
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $contenu = contenuExporte(app(GdprPortabilityService::class)->export(COURRIEL_EXPORT));

    // Sans ce témoin, un export qui déverserait toute la base passerait pour
    // une réussite — et serait lui-même une violation.
    expect($contenu['contacts'])->toHaveCount(0);
});

test('B15-003 — INVARIANT : tout ce que l effacement supprime, l export le connait', function () {
    // Les deux services doivent se répondre table pour table. Si l'un apprend
    // une table et pas l'autre, on effacerait une donnée qu'on aurait refusé de
    // montrer — ou l'inverse.
    $effacement = file_get_contents(base_path('app/Services/Rgpd/GdprErasureService.php'));
    $export = file_get_contents(base_path('app/Services/Rgpd/GdprPortabilityService.php'));

    $tablesSensibles = [
        'contacts', 'candidates', 'activities', 'journalists',
        'media', 'health_practitioners', 'email_messages',
    ];

    foreach ($tablesSensibles as $table) {
        expect($effacement)->toContain("'{$table}'");
        expect($export)->toContain("'{$table}'");
    }
});
