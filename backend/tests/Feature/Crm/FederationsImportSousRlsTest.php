<?php

use App\Crm\FichesProtegees;
use App\Services\Audit\AuditHashChain;
use Illuminate\Database\Connection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/**
 * L'IMPORT DES FÉDÉRATIONS, REJOUÉ SOUS LE RÔLE DE PRODUCTION (2026-09-29).
 *
 * `FederationsImportTest` tourne sous `axion` (SUPERUSER, BYPASSRLS). En
 * production, la commande parle en `axion_app`, et `companies`, `contacts`,
 * `tags`, `company_tag`, `federations` sont en RLS forcée qui échoue FERMÉE :
 * un contexte d'espace mal posé rendrait l'import inerte en restant vert
 * là-bas. La garde anti-cycle et la tête de réseau passent aussi sous RLS.
 * Données semées et nettoyées par le propriétaire.
 */
function fedRlsProprio(): Connection
{
    return DB::connection('pgsql_owner');
}

afterEach(function () {
    DB::connection('pgsql_app')->select('SELECT set_config(?, ?, false)', ['app.current_workspace_id', '']);
    DB::connection('pgsql_app')->disconnect();
    fedRlsProprio()->disconnect();
});

test('sous axion_app, l import cree les fiches protegees, leurs lignes, leurs contacts et relie la tete', function () {
    // La chaîne d'audit est partagée : une écriture validée ici fausserait
    // les suites qui la vérifient.
    $this->mock(AuditHashChain::class)->shouldReceive('record')->once()->andReturn(1);
    config(['crm.scrape_funnel.validate_mx' => false]);

    $owner = fedRlsProprio();
    $espace = (string) Str::uuid();
    $marque = substr(str_replace('-', '', $espace), 0, 6);
    $slug = 'zz-fed-rls-' . $marque;
    $owner->table('workspaces')->insert([
        'id' => $espace, 'slug' => $slug, 'name' => 'ZZ fédérations RLS', 'settings' => '{}',
        'cost_cap_eur' => 100, 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
    ]);
    config(['crm.ingest.business_workspace' => $slug]);

    // SIREN propres à ce test : la table est partagée entre sessions.
    $sirenTete = '95' . str_pad((string) random_int(0, 9999999), 7, '0', STR_PAD_LEFT);
    $sirenAntenne = '96' . substr($sirenTete, 2);
    $ligne = static fn (string $siren, ?string $tete, string $niveau): string => (string) json_encode([
        'siren' => $siren, 'nom' => 'ZZ Fede RLS ' . $niveau, 'famille' => 'federation_syndicat_pro',
        'niveau' => $niveau, 'secteurs' => ['btp'], 'tailles_adherents' => ['tpe'], 'pertinence' => 'haute',
        'contactabilite' => 'email_verifie', 'email_generique' => 'contact@zz-rls-' . $siren . '.example.invalid',
        'personnes' => [['prenom' => 'Zoe', 'nom' => 'ZZRLS' . $siren, 'fonction' => 'Présidente', 'email' => null, 'linkedin' => null]],
        'tete_de_reseau' => $tete,
    ]);
    $fichier = (string) tempnam(sys_get_temp_dir(), 'zz-fed-rls-');
    file_put_contents($fichier, $ligne($sirenAntenne, $sirenTete, 'departemental') . "\n" . $ligne($sirenTete, null, 'national') . "\n");

    $precedente = DB::getDefaultConnection();
    try {
        DB::setDefaultConnection('pgsql_app');
        $code = Artisan::call('crm:import-federations', ['file' => $fichier]);
        DB::setDefaultConnection($precedente);

        $tete = $owner->table('companies')->where('workspace_id', $espace)->where('siren', $sirenTete)->first();
        $antenne = $owner->table('companies')->where('workspace_id', $espace)->where('siren', $sirenAntenne)->first();
        expect($code)->toBe(0)
            ->and($tete)->not->toBeNull()
            ->and($antenne)->not->toBeNull()
            ->and($owner->table('federations')->where('company_id', $antenne->id)->value('parent_company_id'))->toBe((int) $tete->id)
            ->and($owner->table('contacts')->where('company_id', $tete->id)->count())->toBe(1)
            ->and($owner->table('company_tag')->join('tags', 'tags.id', '=', 'company_tag.tag_id')
                ->where('company_tag.company_id', $tete->id)->where('tags.slug', FichesProtegees::TAG_FEDERATIONS)->exists())->toBeTrue();
    } finally {
        DB::setDefaultConnection($precedente);
        @unlink($fichier);
        // Les fiches sont PROTÉGÉES : leur suppression passe par la levée
        // volontaire du déclencheur, dans une transaction.
        $owner->transaction(function () use ($owner, $espace): void {
            $owner->statement("SET LOCAL app.autoriser_suppression_protegee = 'on'");
            foreach (['federations', 'company_tag', 'contacts', 'activities', 'scraper_runs', 'business_events'] as $table) {
                $owner->table($table)->where('workspace_id', $espace)->delete();
            }
            $owner->table('companies')->where('workspace_id', $espace)->delete();
            $owner->table('tags')->where('workspace_id', $espace)->delete();
            $owner->table('workspaces')->where('id', $espace)->delete();
        });
    }
});
