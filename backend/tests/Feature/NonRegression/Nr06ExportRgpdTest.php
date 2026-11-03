<?php

/**
 * NR-06 — R13, l’export RGPD (articles 15 et 20) et l’effacement.
 *
 * Plan 22 : « l’export d’une personne factice contient AU MOINS toutes les
 * tables qu’il contient aujourd’hui ; l’effacement efface partout ». Les gardes
 * existantes (`PortabiliteCompleteTest` : « ce qu’on sait effacer, on sait
 * l’exporter », table par table ; `GdprErasureTest` ; `SiteGdprTest`) sont
 * rattachées au groupe par `tests/Pest.php`.
 *
 * Ajouts du filet (deux photos « au moins », qui servent aussi à l’arbitrage des
 * tables d’ébauche du plan 21 § 5) :
 *  - `nr06-export-tables` : les tables que le service d’export interroge ;
 *  - `nr06-export-cles` : les clés du contenu d’un export réel d’une personne factice.
 * Retirer une table de l’export fait rougir ; en AJOUTER une est permis.
 *
 * Personne factice : `sujet@nr06.example.test`.
 */

use App\Services\Audit\AuditHashChain;
use App\Services\Dedup\DeduplicationService;
use App\Services\Rgpd\GdprErasureService;
use App\Services\Rgpd\GdprPortabilityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\NonRegression\EspacesFactices;
use Tests\Feature\NonRegression\Instantane;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

const NR06_COURRIEL = 'sujet@nr06.example.test';

/** @return list<string> */
function nr06TablesLues(): array
{
    $source = (string) file_get_contents(base_path('app/Services/Rgpd/GdprPortabilityService.php'));
    preg_match_all("/DB::table\('([a-z_0-9]+)'\)/", $source, $m);
    $tables = array_values(array_unique($m[1]));
    sort($tables);

    return $tables;
}

/** @return array<string, mixed> */
function nr06Exporter(string $courriel = NR06_COURRIEL): array
{
    $r = app(GdprPortabilityService::class)->export($courriel);
    $chiffre = Storage::disk('local')->get('gdpr-exports/' . $r['token'] . '.enc');

    return json_decode(Crypt::decryptString($chiffre), true, 512, JSON_THROW_ON_ERROR);
}

function nr06Semer(string $espace): void
{
    $societe = DB::table('companies')->insertGetId([
        'workspace_id' => $espace, 'siren' => '900000009', 'denomination' => 'Societe Nr06',
        'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('contacts')->insert([
        'workspace_id' => $espace, 'company_id' => $societe, 'first_name' => 'Sujet', 'last_name' => 'Nr06',
        'email' => NR06_COURRIEL, 'person_key' => hash('sha256', NR06_COURRIEL),
        'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('activities')->insert([
        'workspace_id' => $espace, 'person_key' => hash('sha256', NR06_COURRIEL),
        'type' => 'inbound', 'kind' => 'form_submission', 'title' => 'Demande',
        'payload' => json_encode(['message' => 'Bonjour']), 'occurred_at' => now(), 'created_at' => now(),
    ]);
}

beforeEach(function () {
    Storage::fake('local');
    $this->espaces = EspacesFactices::creer();
});

test('NR-06 l’export interroge AU MOINS toutes les tables qu’il interrogeait', function () {
    Instantane::verifierInclut('nr06-export-tables', nr06TablesLues());
});

test('NR-06 l’export d’une personne factice contient AU MOINS les clés de la photo', function () {
    nr06Semer($this->espaces['a']);

    Instantane::verifierInclut('nr06-export-cles', array_keys(nr06Exporter()));
});

test('NR-06 l’export retrouve la personne, ses contacts et sa timeline (témoin de fond)', function () {
    nr06Semer($this->espaces['a']);
    $contenu = nr06Exporter();

    expect($contenu['subject'])->toBe(NR06_COURRIEL)
        ->and($contenu['contacts'])->toHaveCount(1)
        ->and($contenu['activities'])->toHaveCount(1);
});

test('NR-06 l’export d’une personne dans les DEUX espaces la retrouve dans les deux', function () {
    nr06Semer($this->espaces['a']);
    nr06Semer($this->espaces['b']);

    expect(nr06Exporter()['contacts'])->toHaveCount(2);
});

test('NR-06 (L3c-0) l’effacement masque partout sans rien supprimer, laisse une opposition, et l’export suivant garde les contacts masqués', function () {
    nr06Semer($this->espaces['a']);
    nr06Semer($this->espaces['b']);

    $resultat = (new GdprErasureService(new AuditHashChain, new DeduplicationService))->erase(NR06_COURRIEL);

    expect($resultat['deleted']['contacts'])->toBe(2)
        // Rien n'est supprimé : les lignes restent, masquées (corbeille douce).
        ->and(DB::table('contacts')->where('email', NR06_COURRIEL)->count())->toBe(2)
        ->and(DB::table('contacts')->where('email', NR06_COURRIEL)->whereNull('deleted_at')->count())->toBe(0)
        ->and(DB::table('opt_out')->where('email_hash', hash('sha256', NR06_COURRIEL))->exists())->toBeTrue()
        // L'export d'accès (art. 15) inclut quand même les éléments masqués.
        ->and(nr06Exporter()['contacts'])->toHaveCount(2);
});
