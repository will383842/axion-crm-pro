<?php

/**
 * LES DROITS DE LA PERSONNE TRAVERSENT LE LIEN JOURNALISTE ↔ CONTACT, DANS
 * LES DEUX SENS (relecture sécurité de #264, veto RGPD, 2026-09-30).
 *
 * Depuis l'harmonisation, une même personne vit dans `journalists` (console
 * presse) et dans le contact lié (`journalists.contact_id`), que les
 * campagnes visent. Une opposition ou un effacement exercé d'un côté doit
 * valoir de l'autre — et la presse n'entre dans aucune audience tant que Will
 * n'a pas ouvert son segment, quel que soit le chemin (`GardePresse`).
 *
 * Fixtures FICTIVES (dépôt public).
 */

use App\Crm\Campagnes\GardePresse;
use App\Crm\FichesProtegees;
use App\Crm\Presse\LienJournalisteContact;
use App\Models\Company;
use App\Models\EmailAudience;
use App\Models\User;
use App\Services\Audiences\AudienceBuilderService;
use App\Services\Rgpd\GdprErasureService;
use App\Support\EligibiliteCampagne;
use App\Support\ListeSuppression;
use Database\Seeders\PermissionsAndRolesSeeder;
use Database\Seeders\ScrapingSourcesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    config(['crm.scrape_funnel.validate_mx' => false, 'crm.ingest.business_workspace' => 'axion-ia']);
    $this->espace = (string) (DB::table('workspaces')->where('slug', 'axion-ia')->value('id') ?? Str::uuid());
    if (! DB::table('workspaces')->where('id', $this->espace)->exists()) {
        DB::table('workspaces')->insert([
            'id' => $this->espace, 'slug' => 'axion-ia', 'name' => 'Axion-IA', 'settings' => '{}',
            'cost_cap_eur' => 100, 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }
    $this->seed(ScrapingSourcesSeeder::class);
});

/**
 * Un média avec fiche et un journaliste, harmonisés : rend [fiche, journaliste, contact].
 *
 * @param  array<string, mixed>  $journaliste
 * @return array{0: int, 1: int, 2: int}
 */
function pdHarmonise(string $espace, array $journaliste = []): array
{
    $fiche = (int) DB::table('companies')->insertGetId([
        'workspace_id' => $espace, 'siren' => '9' . str_pad((string) random_int(0, 99999999), 8, '0', STR_PAD_LEFT),
        'denomination' => 'ZZ EDITEUR DROITS', 'entity_nature' => 'entreprise', 'created_at' => now(), 'updated_at' => now(),
    ]);
    $media = (int) DB::table('media')->insertGetId([
        'workspace_id' => $espace, 'company_id' => $fiche, 'name' => 'ZZ Gazette droits', 'media_type' => 'presse_quotidien',
        'media_family' => 'editorial', 'source' => 'cppap', 'enrich_status' => 'pending', 'created_at' => now(), 'updated_at' => now(),
    ]);
    $j = (int) DB::table('journalists')->insertGetId($journaliste + [
        'workspace_id' => $espace, 'media_id' => $media, 'first_name' => 'Zoe', 'last_name' => 'ZZDROITS',
        'source' => 'wikidata', 'created_at' => now(), 'updated_at' => now(),
    ]);
    Artisan::call('crm:presse:harmoniser');
    $contact = (int) DB::table('journalists')->where('id', $j)->value('contact_id');
    expect($contact)->toBeGreaterThan(0);

    return [$fiche, $j, $contact];
}

function pdConsole(TestCase $test, string $espace): void
{
    $utilisateur = User::create([
        'id' => (string) Str::uuid(), 'email' => Str::uuid() . '@example.test', 'name' => 'Console',
        'password_hash' => Hash::make('PasswordTest12345!'), 'current_workspace_id' => $espace, 'first_login_completed_at' => now(),
    ]);
    $test->seed(PermissionsAndRolesSeeder::class);
    app(PermissionRegistrar::class)->setPermissionsTeamId($espace);
    $utilisateur->assignRole('owner');
    $test->actingAs($utilisateur);
}

// ── Sens journaliste → contact ────────────────────────────────────────────

test('une OPPOSITION posee dans la console presse atteint le contact lie et la table opt_out', function () {
    [, $j, $contact] = pdHarmonise($this->espace, [
        'email' => 'zoe.zz@zz-droits.example.invalid', 'acces' => 'email_redaction', 'phone' => '06 00 00 00 11',
    ]);
    // Le contact porte une AUTRE adresse (complétée ailleurs) : elle doit être opposée aussi.
    DB::table('contacts')->where('id', $contact)->update(['email' => 'zoe.autre@zz-droits.example.invalid']);
    $temoin = 'temoin@zz-droits.example.invalid';
    expect(EligibiliteCampagne::peutRecevoir('zoe.autre@zz-droits.example.invalid'))->toBeTrue();
    pdConsole($this, $this->espace);

    $this->postJson("/api/v1/journalists/{$j}/opt-out")->assertOk();

    expect(DB::table('journalists')->where('id', $j)->value('opt_out'))->toBeTrue()
        ->and(EligibiliteCampagne::peutRecevoir('zoe.zz@zz-droits.example.invalid'))->toBeFalse()
        ->and(EligibiliteCampagne::peutRecevoir('zoe.autre@zz-droits.example.invalid'))->toBeFalse()
        ->and(EligibiliteCampagne::peutRecevoir($temoin))->toBeTrue()
        ->and(DB::table('opt_out')->where('scope', 'business')->where('phone', '0600000011')->exists())->toBeTrue()
        ->and(json_decode((string) DB::table('contacts')->where('id', $contact)->value('metadata'), true))->toHaveKey('opposition_presse_le');
});

test('un EFFACEMENT dans la console supprime le contact lie meme SANS adresse, l inscrit au registre ; il ne revient pas', function () {
    [$fiche, $j, $contact] = pdHarmonise($this->espace);
    expect(DB::table('contacts')->where('id', $contact)->value('email'))->toBeNull();
    pdConsole($this, $this->espace);

    $this->deleteJson("/api/v1/journalists/{$j}")->assertNoContent();

    expect(DB::table('contacts')->where('id', $contact)->exists())->toBeFalse()
        ->and(DB::table('contacts_retires')->where('company_id', $fiche)->count())->toBe(1)
        ->and(DB::table('journalists')->where('id', $j)->value('deleted_at'))->not->toBeNull()
        ->and(DB::table('journalists')->where('id', $j)->value('opt_out'))->toBeTrue();

    Artisan::call('crm:presse:harmoniser');
    expect(DB::table('contacts')->where('company_id', $fiche)->where('last_name', 'ZZDROITS')->exists())->toBeFalse();
});

test('l effacement RGPD (GdprErasureService) atteint le contact lie par contact_id, meme sans adresse', function () {
    [$fiche, $j, $contact] = pdHarmonise($this->espace, ['email' => 'zoe.rgpd@zz-droits.example.invalid', 'acces' => 'email_redaction']);
    // Le contact n'a plus d'adresse : seul `contact_id` le relie à la personne.
    DB::table('contacts')->where('id', $contact)->update(['email' => null]);

    app(GdprErasureService::class)->erase('zoe.rgpd@zz-droits.example.invalid');

    expect(DB::table('contacts')->where('id', $contact)->exists())->toBeFalse()
        ->and(DB::table('contacts_retires')->where('company_id', $fiche)->count())->toBe(1)
        ->and(DB::table('journalists')->where('id', $j)->value('email'))->toBeNull();
});

// ── Sens contact → journaliste (porté par la base) ─────────────────────────

test('l EFFACEMENT d un contact atteint la ligne journalists liee : opposee, videe, a la corbeille', function () {
    [, $j, $contact] = pdHarmonise($this->espace, ['email' => 'zoe.sup@zz-droits.example.invalid', 'acces' => 'email_redaction', 'phone' => '06 00 00 00 12']);
    $temoin = (int) DB::table('journalists')->insertGetId([
        'workspace_id' => $this->espace, 'first_name' => 'Tim', 'last_name' => 'ZZTEMOIN', 'email' => 'tim@zz-droits.example.invalid',
        'source' => 'wikidata', 'created_at' => now(), 'updated_at' => now(),
    ]);

    // Un EFFACEMENT : le chemin d'effacement pose son marqueur.
    DB::transaction(function () use ($contact): void {
        LienJournalisteContact::marquerEffacement();
        DB::table('contacts')->where('id', $contact)->delete();
    });

    $ligne = DB::table('journalists')->where('id', $j)->first();
    expect($ligne->opt_out)->toBeTrue()
        ->and($ligne->email)->toBeNull()
        ->and($ligne->phone)->toBeNull()
        ->and($ligne->deleted_at)->not->toBeNull()
        ->and(DB::table('journalists')->where('id', $temoin)->value('opt_out'))->toBeFalse();
});

test('une opposition inscrite dans opt_out atteint le journaliste : par l adresse du contact lie, par le telephone sous une autre forme ; le temoin non', function () {
    [, $j, $contact] = pdHarmonise($this->espace);
    DB::table('contacts')->where('id', $contact)->update(['email' => 'zoe.contact@zz-droits.example.invalid']);
    $parTel = (int) DB::table('journalists')->insertGetId([
        'workspace_id' => $this->espace, 'first_name' => 'Tel', 'last_name' => 'ZZTEL', 'phone' => '06 12 34 56 78',
        'source' => 'wikidata', 'created_at' => now(), 'updated_at' => now(),
    ]);
    $temoin = (int) DB::table('journalists')->insertGetId([
        'workspace_id' => $this->espace, 'first_name' => 'Tim', 'last_name' => 'ZZTEMOIN', 'email' => 'tim@zz-droits.example.invalid',
        'phone' => '06 99 99 99 99', 'source' => 'wikidata', 'created_at' => now(), 'updated_at' => now(),
    ]);

    // Deux oppositions venues d'ailleurs (site, désinscription) : par
    // l'empreinte de l'adresse du CONTACT, et par un numéro au format international.
    DB::table('opt_out')->insert(['email' => null, 'email_hash' => ListeSuppression::empreinte('Zoe.Contact@zz-droits.example.invalid'),
        'scope' => 'business', 'source' => 'test', 'created_at' => now()]);
    DB::table('opt_out')->insert(['email' => null, 'email_hash' => null, 'phone' => '+33612345678',
        'scope' => 'business', 'source' => 'test', 'created_at' => now()]);

    expect(DB::table('journalists')->where('id', $j)->value('opt_out'))->toBeTrue()
        ->and(DB::table('journalists')->where('id', $parTel)->value('opt_out'))->toBeTrue()
        ->and(DB::table('journalists')->where('id', $temoin)->value('opt_out'))->toBeFalse();
});

// ── La presse hors de toute audience, segment presse ouvert ou fermé ───────

test('GardePresse : une fiche de presse n entre dans aucune audience, segment presse OUVERT ou FERME, par AUCUN chemin', function () {
    [$fiche] = pdHarmonise($this->espace);
    $ordinaire = (int) DB::table('companies')->insertGetId([
        'workspace_id' => $this->espace, 'siren' => '900000555', 'denomination' => 'ZZ ORDINAIRE',
        'relation_type' => 'presse_media', 'created_at' => now(), 'updated_at' => now(),
    ]);
    expect(FichesProtegees::estProtegee($fiche))->toBeTrue();

    // La garde seule, sans la protection générale : c'est ce que tout chemin
    // qui lève `FichesProtegees` (listes exigées, #266) doit encore appliquer.
    // Segment presse OUVERT (défaut depuis le 01/10/2026) : la garde tient.
    expect(GardePresse::ouverte())->toBeTrue();
    $ouvert = DB::table('companies')->whereIn('id', [$fiche, $ordinaire]);
    GardePresse::exclure($ouvert);
    $brut = DB::table('companies as c')->whereIn('c.id', [$fiche, $ordinaire])->whereRaw(GardePresse::conditionSql('c.id'));
    expect($ouvert->pluck('id')->map(fn ($v) => (int) $v)->all())->toBe([$ordinaire])
        ->and($brut->pluck('c.id')->map(fn ($v) => (int) $v)->all())->toBe([$ordinaire])
        ->and(GardePresse::admissible($fiche))->toBeFalse()
        ->and(GardePresse::admissible($ordinaire))->toBeTrue();

    // Segment presse FERMÉ par la configuration : la garde tient aussi.
    config(['crm.segments_ouverts' => 'organisateurs-evenements,federations']);
    expect(GardePresse::ouverte())->toBeFalse();
    $ferme = DB::table('companies')->whereIn('id', [$fiche, $ordinaire]);
    GardePresse::exclure($ferme);
    expect($ferme->pluck('id')->map(fn ($v) => (int) $v)->all())->toBe([$ordinaire])
        ->and(GardePresse::admissible($fiche))->toBeFalse();
    config(['crm.segments_ouverts' => '']);

    // Le chemin EN MÉMOIRE (waterfall) n'applique pas la protection générale :
    // c'est la garde qui ferme.
    $audience = EmailAudience::create([
        'workspace_id' => $this->espace, 'name' => 'ZZ presse', 'is_active' => true, 'auto_refresh' => true,
        'criteria' => ['all' => [['field' => 'relation_type', 'op' => 'eq', 'value' => 'presse_media']]],
    ]);
    $service = app(AudienceBuilderService::class);
    expect($service->evaluateForCompany(Company::findOrFail($fiche)))->toBe([])
        ->and($service->evaluateForCompany(Company::findOrFail($ordinaire)))->toBe([$audience->id]);
});

test('le retour arriere de la migration est REFUSE tant que des fiches de presse existent', function () {
    pdHarmonise($this->espace);

    expect(fn () => (require database_path('migrations/2026_10_01_000010_presse_harmonisee.php'))->down())
        ->toThrow(RuntimeException::class, 'Retour arriere refuse');
    // La protection est toujours là.
    expect((string) DB::selectOne("SELECT pg_get_functiondef('public.refuser_suppression_fiche_protegee'::regproc) AS d")->d)
        ->toContain('src:scraping-presse-2026');
});
