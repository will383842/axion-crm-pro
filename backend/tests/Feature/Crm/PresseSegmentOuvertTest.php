<?php

/**
 * LE SEGMENT PRESSE OUVERT (décision de Will du 01/10/2026) — et la règle de
 * PROVENANCE des adresses (`AdressePresseFiable`).
 *
 * Dans le segment presse, une adresse n'est destinataire que si sa provenance
 * est fiable : journaliste avec la porte `email_redaction`, adresse de
 * rédaction importée d'une liste presse, adresse d'une ligne `media` d'une
 * source presse au site non deviné, fiche sans site deviné, ou site vérifié
 * (`companies.metadata.site_verifie = true`). Une adresse tirée d'un site
 * DEVINÉ non vérifié ne part jamais. Et la presse n'entre par AUCUN autre
 * chemin : une audience de prospection ne l'aspire pas.
 *
 * Chaque cas porte un TÉMOIN. Fixtures FICTIVES (dépôt public).
 */

use App\Crm\Campagnes\AdressePresseFiable;
use App\Crm\Campagnes\GardePresse;
use App\Crm\Campagnes\Segments;
use App\Crm\FichesProtegees;
use App\Models\Contact;
use App\Services\Audiences\AudienceBuilderService;
use App\Support\EligibiliteCampagne;
use App\Support\ListeSuppression;
use Database\Seeders\ScrapingSourcesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\ResolveurDnsSimule;
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

/** @param  array<string, mixed>  $valeurs */
function psoFiche(string $espace, array $valeurs = [], bool $presse = true): int
{
    $id = (int) DB::table('companies')->insertGetId($valeurs + [
        'workspace_id' => $espace, 'siren' => '9' . str_pad((string) random_int(0, 99999999), 8, '0', STR_PAD_LEFT),
        'denomination' => 'ZZ MEDIA', 'entity_nature' => 'media', 'relation_type' => 'presse_media',
        'created_at' => now(), 'updated_at' => now(),
    ]);
    if ($presse) {
        psoTag($espace, $id, FichesProtegees::TAG_PRESSE);
    }

    return $id;
}

function psoTag(string $espace, int $fiche, string $slug): void
{
    $tag = DB::table('tags')->where('workspace_id', $espace)->where('slug', $slug)->value('id')
        ?? DB::table('tags')->insertGetId(['workspace_id' => $espace, 'slug' => $slug, 'name' => $slug, 'category' => 'intent',
            'kind' => 'auto', 'rules' => '{}', 'is_locked' => true, 'created_at' => now(), 'updated_at' => now()]);
    DB::table('company_tag')->insertOrIgnore(['company_id' => $fiche, 'tag_id' => $tag, 'workspace_id' => $espace,
        'assigned_at' => now(), 'assigned_by' => 'auto-rule']);
}

/** @param  array<string, mixed>  $valeurs */
function psoMedia(string $espace, int $fiche, array $valeurs = []): int
{
    return (int) DB::table('media')->insertGetId($valeurs + [
        'workspace_id' => $espace, 'company_id' => $fiche, 'name' => 'ZZ titre', 'media_type' => 'presse_quotidien',
        'media_family' => 'editorial', 'source' => 'cppap', 'enrich_status' => 'pending', 'created_at' => now(), 'updated_at' => now(),
    ]);
}

/**
 * Un contact de la presse (journaliste harmonisé) : `acces` est la porte.
 */
function psoJournaliste(string $espace, int $fiche, string $email, ?string $acces, bool $oppose = false): int
{
    $j = (int) DB::table('journalists')->insertGetId([
        'workspace_id' => $espace, 'company_id' => $fiche, 'first_name' => 'Zoe', 'last_name' => 'ZZ' . strtoupper(Str::random(5)),
        'email' => $email, 'acces' => $acces, 'source' => 'wikidata', 'opt_out' => $oppose,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    return (int) DB::table('contacts')->insertGetId([
        'workspace_id' => $espace, 'company_id' => $fiche, 'first_name' => 'Zoe', 'last_name' => 'ZZJOURNALISTE' . $j,
        'email' => $email, 'email_status' => 'valid', 'external_ref' => 'journaliste:' . $j,
        'sources' => json_encode(['presse-2026']), 'metadata' => json_encode(['acces' => $acces]),
        'created_at' => now(), 'updated_at' => now(),
    ]);
}

/** @return array{lignes: array<string, string>, bilan: array<string, int>, code: int} */
function psoDestinataires(string $segment = Segments::PRESSE): array
{
    ResolveurDnsSimule::toutVerifier();
    $sortie = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'zz-pso-' . Str::random(8) . '.jsonl';
    $code = Artisan::call('crm:campagne:destinataires', ['segment' => $segment, 'sortie' => $sortie]);
    $sortieTexte = Artisan::output();
    $lignes = [];
    foreach (is_file($sortie) ? (file($sortie, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: []) : [] as $l) {
        $d = json_decode($l, true);
        $lignes[(string) $d['email']] = (string) ($d['provenance'] ?? '');
    }
    @unlink($sortie);
    preg_match_all('/\|\s*([a-z_]+)\s*\|\s*(\d+)\s*\|/', $sortieTexte, $m, PREG_SET_ORDER);
    $bilan = [];
    foreach ($m as $x) {
        $bilan[$x[1]] = (int) $x[2];
    }

    return ['lignes' => $lignes, 'bilan' => $bilan, 'code' => $code];
}

test('🔴 une adresse tirée d un site DEVINÉ non vérifié n est JAMAIS destinataire ; vérifiée, elle part ; une fiche au site non deviné part', function () {
    $devinee = psoFiche($this->espace, ['website' => 'https://zz-bijou.example.invalid', 'website_method' => 'guess',
        'email_generic' => 'contact@zz-bijou.example.invalid']);
    psoFiche($this->espace, ['website' => 'https://zz-autre.example.invalid', 'website_method' => 'guess2',
        'email_generic' => 'info@zz-autre.example.invalid', 'metadata' => json_encode([AdressePresseFiable::MARQUEUR_SITE_VERIFIE => true])]);
    psoFiche($this->espace, ['website' => 'https://zz-journal.example.invalid', 'email_generic' => 'redaction@zz-journal.example.invalid']);
    // Le site deviné d'une LIGNE MEDIA de la fiche contamine aussi (ses
    // adresses ont pu être recopiées par l'harmonisation).
    $parMedia = psoFiche($this->espace, ['email_generic' => 'contact@zz-radio.example.invalid']);
    psoMedia($this->espace, $parMedia, ['source' => 'naf-extract', 'website_method' => 'guess', 'email' => 'contact@zz-radio.example.invalid']);

    $r = psoDestinataires();

    expect($r['code'])->toBe(0)
        ->and($r['lignes'])->not->toHaveKey('contact@zz-bijou.example.invalid')
        ->and($r['lignes'])->not->toHaveKey('contact@zz-radio.example.invalid')
        ->and($r['lignes']['info@zz-autre.example.invalid'] ?? null)->toBe(AdressePresseFiable::SITE_VERIFIE)
        ->and($r['lignes']['redaction@zz-journal.example.invalid'] ?? null)->toBe(AdressePresseFiable::SITE_FIABLE)
        ->and($r['bilan']['ecartees_site_devine'])->toBe(2)
        // Rien n'est supprimé.
        ->and(DB::table('companies')->where('id', $devinee)->value('email_generic'))->toBe('contact@zz-bijou.example.invalid');
});

test('🔴 une adresse de rédaction importée d une liste presse part, même sur un site deviné ; une source presse au site non deviné aussi', function () {
    $liste = psoFiche($this->espace, ['website_method' => 'guess', 'email_generic' => 'redaction@zz-liste.example.invalid']);
    AdressePresseFiable::retenirEmailListe($liste, ' Redaction@ZZ-liste.example.invalid ');
    AdressePresseFiable::retenirEmailListe($liste, 'redaction@zz-liste.example.invalid');

    $source = psoFiche($this->espace, ['website_method' => 'guess', 'email_generic' => 'contact@zz-cppap.example.invalid']);
    psoMedia($this->espace, $source, ['source' => 'cppap', 'email' => 'contact@zz-cppap.example.invalid']);
    // Témoin : même source presse, mais SON site est deviné
    // (`media:generate-redaction-emails` fabrique `redaction@<domaine>`).
    $temoin = psoFiche($this->espace, ['website_method' => 'guess', 'email_generic' => 'redaction@zz-faux.example.invalid']);
    psoMedia($this->espace, $temoin, ['source' => 'cppap', 'website_method' => 'guess', 'email' => 'redaction@zz-faux.example.invalid']);

    $r = psoDestinataires();

    $meta = json_decode((string) DB::table('companies')->where('id', $liste)->value('metadata'), true);
    expect($meta[AdressePresseFiable::CLE_EMAILS_LISTE])->toBe(['redaction@zz-liste.example.invalid'])
        ->and($r['lignes']['redaction@zz-liste.example.invalid'] ?? null)->toBe(AdressePresseFiable::LISTE_PRESSE)
        ->and($r['lignes']['contact@zz-cppap.example.invalid'] ?? null)->toBe(AdressePresseFiable::SOURCE_PRESSE)
        ->and($r['lignes'])->not->toHaveKey('redaction@zz-faux.example.invalid')
        ->and($r['bilan']['ecartees_site_devine'])->toBe(1);
});

test('🔴 journaliste : porte email_redaction incluse (même sur un site deviné) ; sans porte exclu ; opposé exclu', function () {
    $fiche = psoFiche($this->espace, ['website_method' => 'guess']);
    psoJournaliste($this->espace, $fiche, 'zoe@zz-titre.example.invalid', 'email_redaction');
    psoJournaliste($this->espace, $fiche, 'max@zz-titre.example.invalid', 'linkedin_direct');
    psoJournaliste($this->espace, $fiche, 'ines@zz-titre.example.invalid', null);
    psoJournaliste($this->espace, $fiche, 'oppose@zz-titre.example.invalid', 'email_redaction', oppose: true);

    $r = psoDestinataires();

    expect($r['lignes']['zoe@zz-titre.example.invalid'] ?? null)->toBe(AdressePresseFiable::JOURNALISTE)
        ->and($r['lignes'])->not->toHaveKey('max@zz-titre.example.invalid')
        ->and($r['lignes'])->not->toHaveKey('ines@zz-titre.example.invalid')
        ->and($r['lignes'])->not->toHaveKey('oppose@zz-titre.example.invalid')
        ->and($r['bilan']['ecartees_journaliste_sans_acces'])->toBe(2)
        ->and($r['bilan']['ecartees_journaliste_retire'])->toBe(1);
});

test('une fiche « média possible » n est jamais traitée comme presse ; une opposition écarte l adresse', function () {
    $possible = psoFiche($this->espace, ['email_generic' => 'contact@zz-portail.example.invalid']);
    psoTag($this->espace, $possible, 'media-possible:a-verifier');
    psoFiche($this->espace, ['email_generic' => 'redaction@zz-oppose.example.invalid']);
    DB::table('opt_out')->insert(['email' => null, 'email_hash' => ListeSuppression::empreinte('redaction@zz-oppose.example.invalid'),
        'scope' => 'business', 'source' => 'test', 'created_at' => now()]);
    psoFiche($this->espace, ['email_generic' => 'redaction@zz-temoin.example.invalid']);

    $r = psoDestinataires();

    expect($r['lignes'])->not->toHaveKey('contact@zz-portail.example.invalid')
        ->and($r['lignes'])->not->toHaveKey('redaction@zz-oppose.example.invalid')
        ->and($r['lignes'])->toHaveKey('redaction@zz-temoin.example.invalid')
        ->and($r['bilan']['ecartees_media_possible'])->toBe(1);
});

test('🔴 segment presse OUVERT : une audience de prospection n aspire pas la presse, ni une campagne générale ses journalistes', function () {
    expect(GardePresse::ouverte())->toBeTrue();
    $presse = psoFiche($this->espace, ['email_generic' => 'redaction@zz-aspire.example.invalid', 'department_code' => '75']);
    $journaliste = psoJournaliste($this->espace, $presse, 'zoe@zz-aspire.example.invalid', 'email_redaction');
    $ordinaire = psoFiche($this->espace, ['denomination' => 'ZZ PROSPECT', 'entity_nature' => 'entreprise', 'relation_type' => 'prospect',
        'email_generic' => 'contact@zz-prospect.example.invalid', 'department_code' => '75'], presse: false);

    $ids = app(AudienceBuilderService::class)
        ->buildPublicQuery($this->espace, ['all' => [['field' => 'department_code', 'op' => 'eq', 'value' => '75']]])
        ->pluck('id')->map(fn ($v) => (int) $v)->all();
    $eligibles = EligibiliteCampagne::appliquerContacts(Contact::query()->where('company_id', $presse))->pluck('id')->all();

    expect($ids)->toBe([$ordinaire])
        ->and($eligibles)->not->toContain($journaliste);
});
