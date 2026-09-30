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
 * L'HARMONISATION ET L'IMPORT DE LA PRESSE, REJOUÉS SOUS LE RÔLE DE
 * PRODUCTION (2026-09-30).
 *
 * Les autres tests tournent sous `axion` (SUPERUSER, BYPASSRLS). En
 * production, les commandes parlent en `axion_app`, et `companies`,
 * `contacts`, `tags`, `company_tag`, `media`, `journalists` sont sous RLS :
 * un contexte d'espace mal posé, un droit manquant (écrire
 * `journalists.contact_id`, lire le registre des retraits) rendrait les
 * commandes inertes en restant vertes là-bas. Données semées et nettoyées
 * par le propriétaire. Fixtures FICTIVES.
 */
function prProprio(): Connection
{
    return DB::connection('pgsql_owner');
}

afterEach(function () {
    DB::connection('pgsql_app')->select('SELECT set_config(?, ?, false)', ['app.current_workspace_id', '']);
    DB::connection('pgsql_app')->disconnect();
    prProprio()->disconnect();
});

test('sous axion_app, l harmonisation cree la fiche protegee, le contact du journaliste et le lien ; l import cree fiche, media et contact', function () {
    // La chaîne d'audit est partagée : une écriture validée ici fausserait
    // les suites qui la vérifient.
    $this->mock(AuditHashChain::class)->shouldReceive('record')->twice()->andReturn(1);
    config(['crm.scrape_funnel.validate_mx' => false]);

    $owner = prProprio();
    $espace = (string) Str::uuid();
    $marque = substr(str_replace('-', '', $espace), 0, 6);
    $slug = 'zz-presse-rls-' . $marque;
    $owner->table('workspaces')->insert([
        'id' => $espace, 'slug' => $slug, 'name' => 'ZZ presse RLS', 'settings' => '{}',
        'cost_cap_eur' => 100, 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
    ]);
    config(['crm.ingest.business_workspace' => $slug]);

    $media = (int) $owner->table('media')->insertGetId([
        'workspace_id' => $espace, 'name' => 'ZZ Gazette RLS', 'media_type' => 'presse_quotidien', 'media_family' => 'editorial',
        'source' => 'cppap', 'enrich_status' => 'pending', 'diffusion_zone' => 'local', 'created_at' => now(), 'updated_at' => now(),
    ]);
    $journaliste = (int) $owner->table('journalists')->insertGetId([
        'workspace_id' => $espace, 'media_id' => $media, 'first_name' => 'Zoe', 'last_name' => 'ZZRLS' . $marque,
        'source' => 'wikidata', 'created_at' => now(), 'updated_at' => now(),
    ]);
    $identifiant = 'presse:zz-rls:' . $marque;
    $fichier = (string) tempnam(sys_get_temp_dir(), 'zz-presse-rls-');
    file_put_contents($fichier, json_encode([
        'identifiant' => $identifiant, 'nom' => 'ZZ Radio RLS', 'type' => 'radio', 'zone' => 'national',
        'journaliste' => ['prenom' => 'Max', 'nom' => 'ZZRLSIMPORT' . $marque],
    ]) . "\n");

    $precedente = DB::getDefaultConnection();
    try {
        DB::setDefaultConnection('pgsql_app');
        $harmonise = Artisan::call('crm:presse:harmoniser');
        $importe = Artisan::call('crm:presse:importer', ['file' => $fichier]);
        DB::setDefaultConnection($precedente);

        $fiche = (int) $owner->table('media')->where('id', $media)->value('company_id');
        $ficheImport = $owner->table('companies')->where('workspace_id', $espace)->where('foreign_id', $identifiant)->first();
        $protegee = static fn (int $id): bool => $owner->table('company_tag')->join('tags', 'tags.id', '=', 'company_tag.tag_id')
            ->where('company_tag.company_id', $id)->where('tags.slug', FichesProtegees::TAG_PRESSE)->exists();

        expect($harmonise)->toBe(0)
            ->and($importe)->toBe(0)
            ->and($fiche)->toBeGreaterThan(0)
            ->and($owner->table('companies')->where('id', $fiche)->value('relation_type'))->toBe('presse_media')
            ->and($protegee($fiche))->toBeTrue()
            ->and($owner->table('journalists')->where('id', $journaliste)->value('contact_id'))->not->toBeNull()
            ->and($owner->table('contacts')->where('external_ref', 'journaliste:' . $journaliste)->value('company_id'))->toBe($fiche)
            ->and($owner->table('company_tag')->join('tags', 'tags.id', '=', 'company_tag.tag_id')
                ->where('company_tag.company_id', $fiche)->where('tags.slug', 'media-zone:local')->exists())->toBeTrue()
            ->and($ficheImport)->not->toBeNull()
            ->and($protegee((int) $ficheImport->id))->toBeTrue()
            ->and($owner->table('media')->where('company_id', $ficheImport->id)->value('media_type'))->toBe('radio')
            ->and($owner->table('contacts')->where('company_id', $ficheImport->id)->count())->toBe(1);
    } finally {
        DB::setDefaultConnection($precedente);
        @unlink($fichier);
        // Les fiches sont PROTÉGÉES : leur suppression passe par la levée
        // volontaire du déclencheur, dans une transaction.
        $owner->transaction(function () use ($owner, $espace): void {
            $owner->statement("SET LOCAL app.autoriser_suppression_protegee = 'on'");
            $owner->table('journalists')->where('workspace_id', $espace)->delete();
            $owner->table('media')->where('workspace_id', $espace)->delete();
            foreach (['company_tag', 'contacts', 'activities', 'scraper_runs', 'business_events', 'contacts_retires'] as $table) {
                $owner->table($table)->where('workspace_id', $espace)->delete();
            }
            $owner->table('companies')->where('workspace_id', $espace)->delete();
            $owner->table('contacts_retires')->where('workspace_id', $espace)->delete();
            $owner->table('tags')->where('workspace_id', $espace)->delete();
            $owner->table('workspaces')->where('id', $espace)->delete();
        });
    }
});
