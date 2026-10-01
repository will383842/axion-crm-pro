<?php

/**
 * L'AUDIENCE PRESSE (ouverture de la presse, décision de Will du 01/10/2026).
 *
 * Le critère `segment eq presse` (bloc `all`) fait entrer la presse — et
 * SEULEMENT elle — dans une audience ; les filtres « Presse et médias »
 * l'affinent. Aperçu, comptes, rafraîchissement et destinataires n'y retiennent
 * que des adresses de provenance fiable (`AdressePresseFiable`). Une audience
 * sans ce critère n'aspire jamais la presse ; segment fermé, le critère est
 * refusé.
 *
 * Chaque cas porte un TÉMOIN. Fixtures FICTIVES (dépôt public).
 */

use App\Crm\Campagnes\AdressePresseFiable;
use App\Crm\Campagnes\ReglageDestinataires;
use App\Crm\Campagnes\ResolveurDestinataires;
use App\Crm\Emails\QualificationEmail;
use App\Crm\FichesProtegees;
use App\Crm\Presse\ClassementMedia;
use App\Http\Controllers\Api\AudiencesController;
use App\Jobs\RefreshAudienceChunkJob;
use App\Models\EmailAudience;
use App\Models\User;
use App\Services\Audiences\AudienceBuilderService;
use App\Services\Audiences\CritereAudienceInvalide;
use Database\Seeders\PermissionsAndRolesSeeder;
use Database\Seeders\ScrapingSourcesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\Support\ResolveurDnsSimule;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

const AP_ECO = 'media-sujet:economie-entreprise';

const AP_CRITERES = ['all' => [
    ['field' => 'segment', 'op' => 'eq', 'value' => 'presse'],
    ['field' => 'tags', 'op' => 'contains_any', 'value' => [AP_ECO]],
]];

beforeEach(function () {
    config(['crm.scrape_funnel.validate_mx' => false, 'crm.ingest.business_workspace' => 'axion-ia']);
    $this->ws = (string) (DB::table('workspaces')->where('slug', 'axion-ia')->value('id') ?? Str::uuid());
    if (! DB::table('workspaces')->where('id', $this->ws)->exists()) {
        DB::table('workspaces')->insert([
            'id' => $this->ws, 'slug' => 'axion-ia', 'name' => 'Axion-IA', 'settings' => '{}',
            'cost_cap_eur' => 100, 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }
    $this->seed(ScrapingSourcesSeeder::class);

    // P1 : presse éco au site DEVINÉ — sa boîte générique et un contact
    // ordinaire en viennent (jamais) ; une journaliste à la porte
    // `email_redaction` (oui) ; un journaliste sans porte (jamais).
    $this->p1 = apFiche($this->ws, ['website_method' => 'guess', 'email_generic' => 'contact@zz-p1-devine.example.invalid'], [FichesProtegees::TAG_PRESSE, AP_ECO]);
    $this->zoe = apContact($this->ws, $this->p1, 'zoe@zz-p1.example.invalid', journaliste: true, acces: 'email_redaction');
    $this->max = apContact($this->ws, $this->p1, 'max@zz-p1.example.invalid', journaliste: true, acces: 'linkedin_direct');
    $this->info = apContact($this->ws, $this->p1, 'info@zz-p1-devine.example.invalid');
    // P2 : presse éco, site jamais deviné — sa boîte générique part.
    // P2 : presse éco, site jamais deviné — sa boîte générique part. Elle
    // porte AUSSI le tag des organisateurs (GOFAB…) et un contact organisateur
    // à l'adresse fiable : il n'entre JAMAIS dans une audience presse (A09).
    $this->p2 = apFiche($this->ws, ['email_generic' => 'redaction@zz-p2.example.invalid'], [FichesProtegees::TAG_PRESSE, FichesProtegees::TAG_ORGANISATEURS, AP_ECO]);
    $this->orga = apContact($this->ws, $this->p2, 'orga@zz-p2.example.invalid');
    // Témoins : presse SPORT, presse éco « média possible », fiche ordinaire éco.
    $this->p3 = apFiche($this->ws, ['email_generic' => 'redaction@zz-p3.example.invalid'], [FichesProtegees::TAG_PRESSE, 'media-sujet:sport']);
    $this->p4 = apFiche($this->ws, ['email_generic' => 'redaction@zz-p4.example.invalid'], [FichesProtegees::TAG_PRESSE, AP_ECO, 'media-possible:a-verifier']);
    $this->o1 = apFiche($this->ws, ['email_generic' => 'contact@zz-o1.example.invalid', 'entity_nature' => 'entreprise', 'relation_type' => 'prospect'], [AP_ECO]);
    $this->service = app(AudienceBuilderService::class);
});

/**
 * @param  array<string, mixed>  $valeurs
 * @param  list<string>  $tags
 */
function apFiche(string $ws, array $valeurs, array $tags): int
{
    $id = (int) DB::table('companies')->insertGetId($valeurs + [
        'workspace_id' => $ws, 'siren' => '9' . str_pad((string) random_int(0, 99999999), 8, '0', STR_PAD_LEFT),
        'denomination' => 'ZZ MEDIA', 'entity_nature' => 'media', 'relation_type' => 'presse_media',
        'created_at' => now(), 'updated_at' => now(),
    ]);
    foreach ($tags as $slug) {
        $tag = DB::table('tags')->where('workspace_id', $ws)->where('slug', $slug)->value('id')
            ?? DB::table('tags')->insertGetId(['workspace_id' => $ws, 'slug' => $slug, 'name' => $slug, 'category' => 'intent',
                'kind' => 'auto', 'rules' => '{}', 'is_locked' => true, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('company_tag')->insertOrIgnore(['company_id' => $id, 'tag_id' => $tag, 'workspace_id' => $ws,
            'assigned_at' => now(), 'assigned_by' => 'auto-rule']);
    }

    return $id;
}

function apContact(string $ws, int $fiche, string $email, bool $journaliste = false, ?string $acces = null): int
{
    $ref = null;
    if ($journaliste) {
        $ref = 'journaliste:' . DB::table('journalists')->insertGetId([
            'workspace_id' => $ws, 'company_id' => $fiche, 'first_name' => 'Zed', 'last_name' => 'ZZ' . strtoupper(Str::random(6)),
            'email' => $email, 'acces' => $acces, 'source' => 'wikidata', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    return (int) DB::table('contacts')->insertGetId([
        'workspace_id' => $ws, 'company_id' => $fiche, 'first_name' => 'Zed', 'last_name' => 'ZZ' . strtoupper(Str::random(6)),
        'email' => $email, 'email_status' => 'valid', 'external_ref' => $ref,
        'sources' => json_encode($journaliste ? ['presse-2026'] : ['insee']),
        'metadata' => json_encode($journaliste ? ['acces' => $acces] : []),
        'created_at' => now(), 'updated_at' => now(),
    ]);
}

/** @return list<int> */
function apIds(AudienceBuilderService $service, string $ws, array $criteres): array
{
    $ids = array_map('intval', $service->buildPublicQuery($ws, $criteres)->pluck('id')->all());
    sort($ids);

    return $ids;
}

test('🔴 audience presse + thème économie : SEULEMENT la presse éco (ni sport, ni « média possible », ni fiche ordinaire)', function () {
    $attendu = [$this->p1, $this->p2];
    sort($attendu);

    expect(apIds($this->service, $this->ws, AP_CRITERES))->toBe($attendu)
        ->and(AudienceBuilderService::estAudiencePresse(AP_CRITERES))->toBeTrue();
});

test('🔴 audience SANS le critère presse : aucune fiche de presse, même au thème demandé', function () {
    expect(apIds($this->service, $this->ws, ['all' => [['field' => 'tags', 'op' => 'contains_any', 'value' => [AP_ECO]]]]))
        ->toBe([$this->o1]);
});

test('🔴 aperçu et rafraîchissement : seules les adresses de provenance fiable, les écartées comptées par motif', function () {
    $apercu = $this->service->preview($this->ws, AP_CRITERES);
    expect($apercu['companies'])->toBe(2)
        // zoe (journaliste email_redaction) + boîte générique de P2.
        ->and($apercu['contacts'])->toBe(2)
        ->and($apercu['presse_ecartees'] ?? null)->toBe(['site_devine' => 1, 'journaliste_sans_acces' => 1, 'journaliste_retire' => 0]);

    $audience = EmailAudience::create([
        'workspace_id' => $this->ws, 'name' => 'ZZ presse éco', 'criteria' => AP_CRITERES, 'is_active' => true, 'auto_refresh' => false,
    ]);
    $this->service->refresh($audience);
    $membres = DB::table('audience_members')->where('audience_id', $audience->id)
        ->orderBy('company_id')->get(['company_id', 'contact_id'])
        ->map(static fn ($m): array => [(int) $m->company_id, $m->contact_id === null ? null : (int) $m->contact_id])->all();
    $attendu = [[$this->p1, $this->zoe], [$this->p2, null]];
    usort($attendu, static fn ($a, $b) => $a[0] <=> $b[0]);

    expect($membres)->toBe($attendu);
});

test('🔴 destinataires de l audience presse : adresses fiables seulement, motifs de provenance dits', function () {
    ResolveurDnsSimule::toutVerifier();
    $r = app(ResolveurDestinataires::class)->resoudre($this->ws, AP_CRITERES, ReglageDestinataires::depuisTableau(['mode' => ReglageDestinataires::LES_DEUX]), null);
    $emails = array_map(static fn (array $l): string => (string) $l['email'], $r['lignes']);
    sort($emails);

    expect($emails)->toBe(['redaction@zz-p2.example.invalid', 'zoe@zz-p1.example.invalid'])
        ->and($r['exclues']['site_devine'])->toBe(1)
        ->and($r['exclues']['journaliste_sans_acces'])->toBe(1)
        // Même définition que l'aperçu (relecture A09), quel que soit le réglage.
        ->and($r['presse_ecartees'])->toBe($this->service->preview($this->ws, AP_CRITERES)['presse_ecartees']);
    foreach ([ReglageDestinataires::PERSONNE_SINON_GENERIQUE, ReglageDestinataires::GENERIQUE, ReglageDestinataires::NOMINATIVES] as $mode) {
        $autre = app(ResolveurDestinataires::class)->resoudre($this->ws, AP_CRITERES, ReglageDestinataires::depuisTableau(['mode' => $mode]), null);
        expect($autre['presse_ecartees'])->toBe($r['presse_ecartees']);
    }
});

test('🔴 segment presse FERMÉ par la configuration : le critère est refusé, partout', function () {
    config(['crm.segments_ouverts' => 'organisateurs-evenements,federations']);

    expect(fn () => AudienceBuilderService::validerCriteres(AP_CRITERES))->toThrow(CritereAudienceInvalide::class, 'segment presse est ferme')
        ->and(fn () => $this->service->preview($this->ws, AP_CRITERES))->toThrow(CritereAudienceInvalide::class);
    // Une audience ordinaire, elle, passe toujours (témoin).
    expect(apIds($this->service, $this->ws, ['all' => [['field' => 'tags', 'op' => 'contains_any', 'value' => [AP_ECO]]]]))->toBe([$this->o1]);
});

test('le critère presse n existe que sous la forme segment eq presse, dans le bloc all', function () {
    foreach ([
        ['any' => [['field' => 'segment', 'op' => 'eq', 'value' => 'presse']]],
        ['not' => [['field' => 'segment', 'op' => 'eq', 'value' => 'presse']]],
        ['all' => [['field' => 'segment', 'op' => 'eq', 'value' => 'federations']]],
        ['all' => [['field' => 'segment', 'op' => 'in', 'value' => ['presse']]]],
    ] as $criteres) {
        expect(fn () => AudienceBuilderService::validerCriteres($criteres))->toThrow(CritereAudienceInvalide::class);
    }
});

/** @return list<array{0: int, 1: int|null}> */
function apMembres(int $audience): array
{
    $m = DB::table('audience_members')->where('audience_id', $audience)
        ->orderBy('company_id')->orderBy('contact_id')->get(['company_id', 'contact_id'])
        ->map(static fn ($x): array => [(int) $x->company_id, $x->contact_id === null ? null : (int) $x->contact_id])->all();

    return array_values($m);
}

test('🔴 A09 — fiche presse ET organisateurs : son contact organisateur n entre JAMAIS (membres, aperçu, destinataires, liste de campagne)', function () {
    $audience = EmailAudience::create([
        'workspace_id' => $this->ws, 'name' => 'ZZ presse', 'criteria' => AP_CRITERES, 'is_active' => true, 'auto_refresh' => false,
    ]);
    $this->service->refresh($audience);
    $contacts = array_filter(array_column(apMembres((int) $audience->id), 1));
    ResolveurDnsSimule::toutVerifier();
    $r = app(ResolveurDestinataires::class)->resoudre($this->ws, AP_CRITERES, ReglageDestinataires::depuisTableau(['mode' => ReglageDestinataires::LES_DEUX]), null);
    $emails = array_map(static fn (array $l): string => (string) $l['email'], $r['lignes']);

    expect($contacts)->not->toContain($this->orga)->not->toContain($this->info)
        ->and($emails)->not->toContain('orga@zz-p2.example.invalid')->not->toContain('info@zz-p1-devine.example.invalid');

    // La liste de campagne du segment presse : même règle.
    $sortie = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'zz-ap-' . Str::random(8) . '.jsonl';
    Artisan::call('crm:campagne:destinataires', ['segment' => 'presse', 'sortie' => $sortie]);
    $liste = (string) @file_get_contents($sortie);
    @unlink($sortie);
    expect($liste)->toContain('zoe@zz-p1.example.invalid')
        ->not->toContain('orga@zz-p2.example.invalid')->not->toContain('info@zz-p1-devine.example.invalid');
});

test('RefreshAudienceChunkJob (audiences > 5 000 fiches) : sur une audience presse, les mêmes membres que refresh()', function () {
    $audience = EmailAudience::create([
        'workspace_id' => $this->ws, 'name' => 'ZZ presse chunk', 'criteria' => AP_CRITERES, 'is_active' => true, 'auto_refresh' => false,
    ]);
    (new RefreshAudienceChunkJob(audienceId: (int) $audience->id, offset: 0, limit: 100))
        ->pourEspace($this->ws)
        ->handle($this->service);
    $parJob = apMembres((int) $audience->id);
    $this->service->refresh($audience);
    $attendu = [[$this->p1, $this->zoe], [$this->p2, null]];
    usort($attendu, static fn ($a, $b) => $a[0] <=> $b[0]);

    expect($parJob)->toBe($attendu)->and(apMembres((int) $audience->id))->toBe($attendu);
});

test('A09 — membres d une audience presse : affichés segment ouvert, REFUSÉS (422, message clair) segment fermé', function () {
    $this->seed(PermissionsAndRolesSeeder::class);
    $user = User::create([
        'id' => (string) Str::uuid(), 'email' => 'op-' . Str::random(6) . '@example.invalid', 'name' => 'ZZ op',
        'password_hash' => Hash::make('PasswordTest12345!'), 'current_workspace_id' => $this->ws, 'first_login_completed_at' => now(),
    ]);
    setPermissionsTeamId($this->ws);
    $user->assignRole('operator');
    $this->actingAs($user);
    $audience = EmailAudience::create([
        'workspace_id' => $this->ws, 'name' => 'ZZ presse membres', 'criteria' => AP_CRITERES, 'is_active' => true, 'auto_refresh' => false,
    ]);
    $this->service->refresh($audience);

    $this->getJson("/api/v1/audiences/{$audience->id}/members")->assertOk();

    config(['crm.segments_ouverts' => 'organisateurs-evenements,federations']);
    $this->getJson("/api/v1/audiences/{$audience->id}/members")
        ->assertStatus(422)->assertJsonPath('message', AudiencesController::MESSAGE_PRESSE_FERMEE);
    $this->postJson('/api/v1/audiences/preview', ['criteria' => AP_CRITERES])->assertStatus(422);
});

test('A09 — la normalisation SQL des adresses est celle de PHP (espaces, tabulations, fins de ligne, casse)', function () {
    foreach (["  Zoe@ZZ-Titre.example.invalid\t", "\nX@Y.example.invalid \r", "\x0Bz@z.example.invalid", 'deja@propre.example.invalid'] as $brut) {
        $sql = (string) DB::selectOne('SELECT ' . AdressePresseFiable::cleSql('CAST(? AS text)') . ' AS cle', [$brut])->cle;
        expect($sql)->toBe(QualificationEmail::normaliser($brut));
    }

    // Une fiche au site DEVINÉ dont la boîte générique (espace final) est
    // portée, à la casse et aux espaces près, par une source presse : fiable
    // en SQL comme en PHP.
    $fiche = apFiche($this->ws, ['website_method' => 'guess', 'email_generic' => 'redaction@zz-espace.example.invalid '], [FichesProtegees::TAG_PRESSE]);
    DB::table('media')->insert(['workspace_id' => $this->ws, 'company_id' => $fiche, 'name' => 'ZZ titre', 'media_type' => 'presse_quotidien',
        'media_family' => 'editorial', 'source' => 'cppap', 'enrich_status' => 'pending', 'email' => "  Redaction@ZZ-Espace.example.invalid\t",
        'created_at' => now(), 'updated_at' => now()]);
    $parSql = DB::table('companies as c')->where('c.id', $fiche)->whereRaw(AdressePresseFiable::generiqueFiableSql('c.id', 'c'))->exists();
    [$parPhp] = AdressePresseFiable::juger('redaction@zz-espace.example.invalid ', [
        'presse' => false, 'acces' => null, 'site_verifie' => false, 'site_devine' => true,
        'emails_surs' => AdressePresseFiable::emailsSurs($fiche),
    ]);

    expect($parSql)->toBeTrue()->and($parPhp)->toBeTrue();
});

test('🔴 filtre « Secteur couvert » : l’étiquette posée par le classement est celle que le filtre vise (BTP oui, commerce de détail non)', function () {
    $btp = apFiche($this->ws, ['email_generic' => 'redaction@zz-p5.example.invalid'], [FichesProtegees::TAG_PRESSE]);
    $commerce = apFiche($this->ws, ['email_generic' => 'redaction@zz-p6.example.invalid'], [FichesProtegees::TAG_PRESSE]);
    // Les étiquettes viennent du PRODUCTEUR réel (`ClassementMedia::desirees`), pas d'un slug recopié.
    $classement = static fn (string $secteur): array => ['v' => 4, 'themes' => ['metiers-secteurs'], 'secteurs' => [$secteur], 'publics' => [], 'format' => null];
    $slugBtp = array_values(array_filter(array_keys(ClassementMedia::desirees($btp, $classement('btp'), true, false)), static fn (string $s): bool => str_starts_with($s, ClassementMedia::PREFIXE_ETIQUETTE_SECTEUR)));
    $slugCommerce = array_values(array_filter(array_keys(ClassementMedia::desirees($commerce, $classement('commerce_detail'), true, false)), static fn (string $s): bool => str_starts_with($s, ClassementMedia::PREFIXE_ETIQUETTE_SECTEUR)));
    foreach ([[$btp, $slugBtp], [$commerce, $slugCommerce]] as [$id, $slugs]) {
        foreach ($slugs as $slug) {
            $tag = DB::table('tags')->insertGetId(['workspace_id' => $this->ws, 'slug' => $slug, 'name' => $slug, 'category' => 'intent',
                'kind' => 'auto', 'rules' => '{}', 'is_locked' => true, 'created_at' => now(), 'updated_at' => now()]);
            DB::table('company_tag')->insert(['company_id' => $id, 'tag_id' => $tag, 'workspace_id' => $this->ws,
                'assigned_at' => now(), 'assigned_by' => 'auto-rule']);
        }
    }

    // Le code que le front envoie : `media-sujet:secteur-` + code du référentiel généré.
    $vise = ClassementMedia::PREFIXE_ETIQUETTE_SECTEUR . 'btp';
    expect($slugBtp)->toBe([$vise])
        ->and(array_key_exists('btp', ClassementMedia::secteursCouverts()))->toBeTrue()
        ->and(apIds($this->service, $this->ws, ['all' => [
            ['field' => 'segment', 'op' => 'eq', 'value' => 'presse'],
            ['field' => 'tags', 'op' => 'contains_any', 'value' => [$vise]],
        ]]))->toBe([$btp]);
    // Témoin : le commerce de détail est bien étiqueté, et visé par son propre code.
    expect(apIds($this->service, $this->ws, ['all' => [
        ['field' => 'segment', 'op' => 'eq', 'value' => 'presse'],
        ['field' => 'tags', 'op' => 'contains_any', 'value' => [ClassementMedia::PREFIXE_ETIQUETTE_SECTEUR . 'commerce-detail']],
    ]]))->toBe([$commerce]);
});

test('🔴 filtre « Secteur couvert » : une valeur hostile ne vise personne et n’injecte rien (liaison de paramètres)', function () {
    $hostile = ClassementMedia::PREFIXE_ETIQUETTE_SECTEUR . "btp') OR 1=1 --";
    expect(apIds($this->service, $this->ws, ['all' => [
        ['field' => 'segment', 'op' => 'eq', 'value' => 'presse'],
        ['field' => 'tags', 'op' => 'contains_any', 'value' => [$hostile]],
    ]]))->toBe([]);
    // Témoin : le même critère sur une étiquette existante vise bien des fiches.
    expect(apIds($this->service, $this->ws, AP_CRITERES))->not->toBe([]);
});
