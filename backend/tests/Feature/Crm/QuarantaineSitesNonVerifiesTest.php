<?php

/**
 * LOT N5 — UNE ADRESSE TIRÉE D'UN SITE DEVINÉ NON VÉRIFIÉ EST EN QUARANTAINE
 * (03/10/2026).
 *
 * `QuarantaineSite` : sur une fiche au site deviné non vérifié (`SiteFiable`),
 * l'adresse générique, les personnes relevées sur le site et toute adresse du
 * domaine du site ne sont jamais exportées, jamais comptées joignables,
 * jamais envoyées. Rien n'est effacé ni réécrit.
 *
 * Ce fichier prouve « 0 adresse sur domaine non vérifié » dans : les quatre
 * exports, `crm:campagne:destinataires`, l'aperçu d'une audience
 * (`ResolveurDestinataires`), « Confiance email A » et « Prospects
 * contactables » ; que la migration ne touche que les audiences d'origine ;
 * qu'un recalcul (04:00 / 04:45) ne réintroduit rien ; et le rejeu sous
 * `axion_app` (RLS forcée).
 *
 * Fixtures FICTIVES (dépôt public) : noms « ZZ », domaines `.example.invalid`,
 * SIREN fictifs (préfixe 94 / 95).
 */

use App\Crm\Campagnes\EligibiliteAdresse;
use App\Crm\Campagnes\ReglageDestinataires;
use App\Crm\Campagnes\ResolveurDestinataires;
use App\Crm\Emails\VerificationEmail;
use App\Crm\FichesProtegees;
use App\Crm\Sites\QuarantaineSite;
use App\Models\Company;
use App\Models\EmailAudience;
use App\Models\User;
use App\Services\Audiences\AudienceBuilderService;
use App\Services\Audiences\CritereAudienceInvalide;
use App\Services\Audit\AuditHashChain;
use App\Support\WorkspaceContext;
use Database\Seeders\DefaultAudiencesSeeder;
use Database\Seeders\PermissionsAndRolesSeeder;
use Illuminate\Database\Connection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\DoublonsFixtures as F;
use Tests\Support\ResolveurDnsSimule;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/** Le marqueur « vérifiée valide » d'une adresse (générique ou personne). */
function qsVerif(string $email): array
{
    return [
        'statut' => VerificationEmail::VALIDE, 'motif' => 'mx', 'verifie_par' => VerificationEmail::SOURCE,
        'empreinte' => VerificationEmail::empreinte(mb_strtolower(trim($email))), 'type' => 'generique',
    ];
}

function qsSignals(string $email): string
{
    return (string) json_encode(['email_generic_verification' => qsVerif($email)]);
}

function qsMetaContact(string $email): string
{
    return (string) json_encode(['email_verification' => qsVerif($email)]);
}

/**
 * Le jeu commun : une fiche au site DEVINÉ non vérifié, une fiche devinée puis
 * VÉRIFIÉE, une fiche au site trouvé autrement.
 *
 * @return array{devinee: int, verifiee: int, fiable: int}
 */
function qsJeu(string $ws, array $plus = []): array
{
    $devinee = F::fiche($ws, 'ZZ Devinee', $plus + [
        'website' => 'https://www.zz-devine.example.invalid/', 'website_method' => 'guess',
        'email_generic' => 'contact@zz-devine.example.invalid', 'signals' => qsSignals('contact@zz-devine.example.invalid'),
        'best_email_confidence' => 'A', 'metadata' => '{}',
    ]);
    // Relevée sur le site deviné (mentions légales) : quarantaine.
    F::contact($ws, $devinee, 'Zoe', 'ZZML', ['email' => 'zoe@zz-autre-domaine.example.invalid', 'discovery_source' => 'mentions-legales',
        'email_status' => 'valid', 'metadata' => qsMetaContact('zoe@zz-autre-domaine.example.invalid')]);
    // Adresse du domaine deviné (sous-domaine), source INSEE : quarantaine.
    F::contact($ws, $devinee, 'Yves', 'ZZDOM', ['email' => 'yves@mail.zz-devine.example.invalid', 'discovery_source' => 'insee',
        'email_status' => 'valid', 'metadata' => qsMetaContact('yves@mail.zz-devine.example.invalid')]);
    // Connue autrement, sur un autre domaine : elle part.
    F::contact($ws, $devinee, 'Xavier', 'ZZINSEE', ['email' => 'xavier@zz-cabinet-x.example.invalid', 'discovery_source' => 'insee',
        'email_status' => 'valid', 'metadata' => qsMetaContact('xavier@zz-cabinet-x.example.invalid')]);

    $verifiee = F::fiche($ws, 'ZZ Verifiee', $plus + [
        'website' => 'zz-verifie.example.invalid', 'website_method' => 'guess2',
        'metadata' => json_encode(['site_entreprise' => ['statut' => 'verifie']]),
        'email_generic' => 'contact@zz-verifie.example.invalid', 'signals' => qsSignals('contact@zz-verifie.example.invalid'),
        'best_email_confidence' => 'A',
    ]);
    $fiable = F::fiche($ws, 'ZZ Fiable', $plus + [
        'website' => 'https://zz-fiable.example.invalid', 'website_method' => 'brave',
        'email_generic' => 'contact@zz-fiable.example.invalid', 'signals' => qsSignals('contact@zz-fiable.example.invalid'),
        'best_email_confidence' => 'A', 'metadata' => '{}',
    ]);

    return ['devinee' => $devinee, 'verifiee' => $verifiee, 'fiable' => $fiable];
}

/** Les adresses qui ne doivent JAMAIS sortir. */
const QS_EN_QUARANTAINE = [
    'contact@zz-devine.example.invalid',
    'zoe@zz-autre-domaine.example.invalid',
    'yves@mail.zz-devine.example.invalid',
];

function qsAucuneEnQuarantaine(string $texte): void
{
    foreach (QS_EN_QUARANTAINE as $e) {
        expect(str_contains(mb_strtolower($texte), $e))->toBeFalse("Adresse en quarantaine sortie : {$e}");
    }
    // Garde large : aucune adresse du domaine deviné, quelle qu'elle soit.
    expect(preg_match('/@([a-z0-9.-]+\.)?zz-devine\.example\.invalid/i', $texte))->toBe(0);
}

// ─────────────────────────────────────────────────────────────────────────
// La règle
// ─────────────────────────────────────────────────────────────────────────

test('la règle : générique, source site, domaine du site ; une autre source sur un autre domaine passe', function () {
    expect(QuarantaineSite::ficheNonVerifiee('guess', '{}'))->toBeTrue()
        ->and(QuarantaineSite::ficheNonVerifiee('guess2', ['site_entreprise' => ['statut' => 'verifie']]))->toBeFalse()
        ->and(QuarantaineSite::ficheNonVerifiee('brave', null))->toBeFalse()
        ->and(QuarantaineSite::personne(true, 'mentions-legales', 'a@ailleurs.example.invalid', 'x.example.invalid'))->toBeTrue()
        ->and(QuarantaineSite::personne(true, 'site', 'a@ailleurs.example.invalid', 'x.example.invalid'))->toBeTrue()
        ->and(QuarantaineSite::personne(true, 'insee', 'a@mail.x.example.invalid', 'https://www.x.example.invalid/contact'))->toBeTrue()
        ->and(QuarantaineSite::personne(true, 'insee', 'a@x.example.invalid', 'shop.x.example.invalid'))->toBeTrue()
        ->and(QuarantaineSite::personne(true, 'insee', 'a@ailleurs.example.invalid', 'x.example.invalid'))->toBeFalse()
        ->and(QuarantaineSite::personne(true, 'insee', 'a@notx.example.invalid', 'x.example.invalid'))->toBeFalse()
        ->and(QuarantaineSite::personne(false, 'site', 'a@x.example.invalid', 'x.example.invalid'))->toBeFalse();

    // Le motif passe juste après l'EI, avant tout autre : même vérifiée valide.
    $occ = ['status' => null, 'verification' => VerificationEmail::VALIDE, 'perso' => false, 'deja_informe' => false];
    expect(EligibiliteAdresse::motif('contact@zz-devine.example.invalid', [$occ + ['site_non_verifie' => true]]))
        ->toBe(EligibiliteAdresse::SITE_NON_VERIFIE)
        ->and(EligibiliteAdresse::motif('contact@zz-devine.example.invalid', [$occ + ['site_non_verifie' => true, 'entreprise_individuelle' => true]]))
        ->toBe(EligibiliteAdresse::ENTREPRISE_INDIVIDUELLE)
        ->and(EligibiliteAdresse::MOTIFS)->toContain(EligibiliteAdresse::SITE_NON_VERIFIE);
});

test('le SQL et le miroir PHP disent la même chose, sans rien réécrire', function () {
    $ws = F::espace('zz-qs-sql');
    $j = qsJeu($ws);
    $avant = DB::table('contacts')->where('workspace_id', $ws)->orderBy('id')->get()->toJson()
        . DB::table('companies')->where('workspace_id', $ws)->orderBy('id')->get()->toJson();

    $lignes = DB::table('contacts')->join('companies', 'companies.id', '=', 'contacts.company_id')
        ->where('contacts.workspace_id', $ws)
        ->selectRaw('contacts.email, contacts.discovery_source, companies.website, companies.website_method, companies.metadata, '
            . QuarantaineSite::personneSql('contacts', 'companies') . ' AS q')
        ->get();
    foreach ($lignes as $l) {
        $php = QuarantaineSite::personne(QuarantaineSite::ficheNonVerifiee($l->website_method, $l->metadata), $l->discovery_source, $l->email, $l->website);
        expect($l->q)->toBe($php, $l->email);
    }
    $generiques = DB::table('companies')->where('workspace_id', $ws)
        ->selectRaw('id, ' . QuarantaineSite::generiqueSql('companies') . ' AS q')->pluck('q', 'id')->all();
    expect($generiques)->toBe([$j['devinee'] => true, $j['verifiee'] => false, $j['fiable'] => false]);

    $apres = DB::table('contacts')->where('workspace_id', $ws)->orderBy('id')->get()->toJson()
        . DB::table('companies')->where('workspace_id', $ws)->orderBy('id')->get()->toJson();
    expect($apres)->toBe($avant);
});

// ─────────────────────────────────────────────────────────────────────────
// Envoi : aperçu d'audience et liste en fichier
// ─────────────────────────────────────────────────────────────────────────

test('aperçu d une audience : 0 adresse en quarantaine, motif site_non_verifie compté', function () {
    $this->mock(AuditHashChain::class)->shouldReceive('record')->andReturn(1);
    $ws = F::espace('zz-qs-apercu');
    $cible = F::tag($ws, 'zz-cible-qs');
    foreach (qsJeu($ws) as $id) {
        F::lier($ws, $id, $cible);
    }

    $r = app(ResolveurDestinataires::class)->resoudre(
        $ws,
        ['all' => [['field' => 'tags', 'op' => 'contains_any', 'value' => ['zz-cible-qs']]]],
        new ReglageDestinataires(ReglageDestinataires::LES_DEUX),
        null,
    );

    $adresses = collect($r['lignes'])->pluck('email')->sort()->values()->all();
    qsAucuneEnQuarantaine(implode("\n", $adresses));
    expect($adresses)->toBe([
        'contact@zz-fiable.example.invalid', 'contact@zz-verifie.example.invalid', 'xavier@zz-cabinet-x.example.invalid',
    ])->and($r['exclues'][EligibiliteAdresse::SITE_NON_VERIFIE])->toBe(3);
});

test('crm:campagne:destinataires : 0 adresse en quarantaine, compteur ecartees_site_non_verifie', function () {
    $ws = F::espace('zz-qs-liste');
    config(['crm.ingest.business_workspace' => F::slug($ws)]);
    foreach (qsJeu($ws) as $id) {
        F::proteger($ws, $id, FichesProtegees::TAG_ORGANISATEURS);
    }

    ResolveurDnsSimule::toutVerifier();
    $fichier = (string) tempnam(sys_get_temp_dir(), 'zz-qs-');
    try {
        Artisan::call('crm:campagne:destinataires', ['segment' => 'organisateurs-evenements', 'sortie' => $fichier]);
        $sortie = Artisan::output();
        $contenu = (string) file_get_contents($fichier);
    } finally {
        @unlink($fichier);
    }

    qsAucuneEnQuarantaine($contenu);
    expect($contenu)->toContain('contact@zz-fiable.example.invalid')
        ->toContain('contact@zz-verifie.example.invalid')
        ->and(F::compteur($sortie, 'ecartees_site_non_verifie'))->toBe(3);
});

test('campaigns:start-scheduled n envoie rien : il ne lance que des campagnes de collecte', function () {
    Mail::fake();
    $ws = F::espace('zz-qs-planif');
    qsJeu($ws);

    Artisan::call('campaigns:start-scheduled');

    Mail::assertNothingSent();
    Mail::assertNothingQueued();
});

test('audience presse : la générique d une fiche NON presse au site deviné n y passe jamais (ni celle d un média deviné)', function () {
    $this->mock(AuditHashChain::class)->shouldReceive('record')->andReturn(1);
    config(['crm.segments_ouverts' => 'presse']);
    $ws = F::espace('zz-qs-presse');
    $theme = F::tag($ws, 'zz-qs-theme');
    $presse = F::tag($ws, FichesProtegees::TAG_PRESSE, ['is_locked' => true, 'category' => 'intent']);
    // Fiche ordinaire au site deviné, même thème : hors audience presse.
    foreach (qsJeu($ws) as $id) {
        F::lier($ws, $id, $theme);
    }
    // Média au site deviné : sa générique est jugée par sa provenance.
    $media = F::fiche($ws, 'ZZ Media Devine', ['website' => 'zz-media-devine.example.invalid', 'website_method' => 'guess',
        'email_generic' => 'redaction@zz-media-devine.example.invalid', 'signals' => qsSignals('redaction@zz-media-devine.example.invalid'),
        'entity_nature' => 'media', 'relation_type' => 'presse_media', 'metadata' => '{}']);
    F::lier($ws, $media, $theme);
    F::lier($ws, $media, $presse);

    $r = app(ResolveurDestinataires::class)->resoudre(
        $ws,
        ['all' => [['field' => 'segment', 'op' => 'eq', 'value' => 'presse'], ['field' => 'tags', 'op' => 'contains_any', 'value' => ['zz-qs-theme']]]],
        new ReglageDestinataires(ReglageDestinataires::LES_DEUX),
        null,
    );

    $adresses = implode("\n", collect($r['lignes'])->pluck('email')->all());
    qsAucuneEnQuarantaine($adresses);
    expect($adresses)->not->toContain('zz-media-devine')
        ->and($r['organisations'])->toBe(1);
});

// ─────────────────────────────────────────────────────────────────────────
// Les quatre exports
// ─────────────────────────────────────────────────────────────────────────

function qsExportateur(string $ws): User
{
    $user = User::create([
        'id' => (string) Str::uuid(), 'email' => 'qs-' . Str::random(6) . '@example.invalid', 'name' => 'ZZ Exportateur',
        'password_hash' => Hash::make('PasswordTest12345!'), 'current_workspace_id' => $ws, 'first_login_completed_at' => now(),
    ]);
    app(PermissionRegistrar::class)->setPermissionsTeamId($ws);
    $user->assignRole('owner');
    DB::table('user_workspaces')->insertOrIgnore([
        'user_id' => $user->id, 'workspace_id' => $ws, 'role_slug' => 'owner', 'invited_at' => now(), 'joined_at' => now(),
    ]);

    return $user;
}

function qsCsv(TestResponse $reponse): string
{
    ob_start();
    $reponse->baseResponse->sendContent();

    return (string) ob_get_clean();
}

test('les 4 exports (entreprises, personnes, médias, journalistes) : 0 adresse en quarantaine', function () {
    $this->seed(PermissionsAndRolesSeeder::class);
    $ws = F::espace('zz-qs-export');
    config(['crm.console_v2' => true, 'crm.ingest.business_workspace' => F::slug($ws)]);
    $j = qsJeu($ws);
    $this->actingAs(qsExportateur($ws));

    // Entreprises : les fiches sortent, sans les adresses en quarantaine.
    $entreprises = qsCsv($this->get('/api/v1/companies/export')->assertOk());
    qsAucuneEnQuarantaine($entreprises);
    expect($entreprises)->toContain('ZZ Devinee')
        ->toContain('xavier@zz-cabinet-x.example.invalid')
        ->toContain('contact@zz-fiable.example.invalid')
        ->toContain('contact@zz-verifie.example.invalid');

    // Personnes (lettre) : rattachées à la fiche devinée, sur son domaine ou
    // liées à une personne relevée sur le site — même avec tout inclure.
    $zoe = (int) DB::table('contacts')->where('workspace_id', $ws)->where('last_name', 'ZZML')->value('id');
    $base = ['workspace_id' => $ws, 'email_nature' => 'pro', 'premiere_source' => 'newsletter', 'legal_basis' => 'consent',
        'premiere_source_at' => now(), 'derniere_interaction_at' => now(), 'created_at' => now(), 'updated_at' => now()];
    foreach ([
        ['contact@zz-devine.example.invalid', $j['devinee'], null],
        ['zoe@zz-autre-domaine.example.invalid', $j['devinee'], $zoe],
        ['xavier@zz-cabinet-x.example.invalid', $j['devinee'], null],
        ['contact@zz-fiable.example.invalid', $j['fiable'], null],
    ] as [$email, $fiche, $contact]) {
        $pid = (int) DB::table('personnes')->insertGetId($base + [
            'person_key' => hash('sha256', 'qs|' . $email), 'email' => $email, 'email_hash' => hash('sha256', $email),
            'company_id' => $fiche, 'contact_id' => $contact, 'rattachee_at' => now(),
        ]);
        DB::table('abonnements')->insert(['workspace_id' => $ws, 'personne_id' => $pid, 'canal' => 'lettre', 'statut' => 'abonne',
            'consent_version' => 'v1', 'consent_at' => now(), 'dernier_evenement_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
    }
    foreach (['', '?inclure_non_prospectables=oui'] as $option) {
        $personnes = $this->get('/api/v1/crm/personnes/export' . $option)->assertOk()->streamedContent();
        qsAucuneEnQuarantaine($personnes);
        expect($personnes)->toContain('xavier@zz-cabinet-x.example.invalid')->toContain('contact@zz-fiable.example.invalid');
    }

    // Médias : site deviné non vérifié (ni marqueur entreprise ni marqueur
    // média sur la fiche) → adresse retenue ; site vérifié → adresse sortie.
    $mediaDevine = (int) DB::table('media')->insertGetId(['workspace_id' => $ws, 'company_id' => $j['devinee'], 'name' => 'ZZ RADIO DEVINEE',
        'media_type' => 'radio', 'website' => 'zz-devine.example.invalid', 'website_method' => 'guess',
        'email' => 'redaction@zz-devine.example.invalid', 'source' => 'naf-extract', 'created_at' => now(), 'updated_at' => now()]);
    DB::table('media')->insert(['workspace_id' => $ws, 'company_id' => $j['verifiee'], 'name' => 'ZZ RADIO VERIFIEE',
        'media_type' => 'radio', 'website' => 'zz-verifie.example.invalid', 'website_method' => 'guess',
        'email' => 'redaction@zz-verifie.example.invalid', 'source' => 'naf-extract', 'created_at' => now(), 'updated_at' => now()]);
    $medias = qsCsv($this->get('/api/v1/media/export')->assertOk());
    qsAucuneEnQuarantaine($medias);
    expect($medias)->toContain('ZZ RADIO DEVINEE')->toContain('redaction@zz-verifie.example.invalid');

    // Journalistes relevés sur le site deviné de leur média : sans adresse.
    DB::table('journalists')->insert(['workspace_id' => $ws, 'media_id' => $mediaDevine, 'first_name' => 'Wanda', 'last_name' => 'ZZJOUR',
        'email' => 'wanda@zz-devine.example.invalid', 'source' => 'ours', 'opt_out' => false, 'created_at' => now(), 'updated_at' => now()]);
    $journalistes = qsCsv($this->get('/api/v1/journalists/export')->assertOk());
    qsAucuneEnQuarantaine($journalistes);
    expect($journalistes)->toContain('ZZJOUR');

    // Rien n'a été effacé ni réécrit.
    expect(DB::table('companies')->where('id', $j['devinee'])->value('email_generic'))->toBe('contact@zz-devine.example.invalid')
        ->and(DB::table('media')->where('id', $mediaDevine)->value('email'))->toBe('redaction@zz-devine.example.invalid');
});

// ─────────────────────────────────────────────────────────────────────────
// Audiences par défaut, recalculs, migration
// ─────────────────────────────────────────────────────────────────────────

/** @return list<int> */
function qsMembres(string $ws, string $nom): array
{
    $id = EmailAudience::query()->where('workspace_id', $ws)->where('name', $nom)->value('id');

    return DB::table('audience_members')->where('audience_id', $id)->orderBy('company_id')->pluck('company_id')
        ->map(static fn ($v): int => (int) $v)->unique()->values()->all();
}

test('audiences par défaut : « Confiance email A » et « Prospects contactables » sans adresse en quarantaine, même après les recalculs de 04:00 et 04:45', function () {
    $this->mock(AuditHashChain::class)->shouldReceive('record')->andReturn(1);
    $ws = F::espace('zz-qs-aud');
    $j = qsJeu($ws, ['prospection_status' => 'ready_for_outreach']);
    // Une fiche devinée dont la SEULE adresse est la générique (quarantaine).
    $seule = F::fiche($ws, 'ZZ Seule', [
        'website' => 'zz-seule.example.invalid', 'website_method' => 'guess', 'metadata' => '{}',
        'email_generic' => 'info@zz-seule.example.invalid', 'best_email_confidence' => 'A',
        'prospection_status' => 'ready_for_outreach',
    ]);
    $this->seed(DefaultAudiencesSeeder::class);

    $tour = function () use ($ws, $j, $seule): void {
        Artisan::call('audiences:full-refresh');
        $confianceA = qsMembres($ws, 'Confiance email A (domaine = site)');
        $joignables = qsMembres($ws, 'Prospects contactables');
        // « A » : la fiche devinée (A = domaine deviné) n'y est plus ; la
        // vérifiée et la fiable y restent.
        expect($confianceA)->not->toContain($j['devinee'])->not->toContain($seule)
            ->toContain($j['verifiee'])->toContain($j['fiable']);
        // Joignables : la fiche dont la seule adresse est en quarantaine ne
        // compte pas ; la devinée compte par une adresse hors quarantaine.
        expect($joignables)->not->toContain($seule)
            ->toContain($j['devinee'])->toContain($j['verifiee'])->toContain($j['fiable']);
    };
    $tour();

    // 04:45 — notes recalculées (`--refresh`), puis 04:00 le lendemain.
    Artisan::call('prospection:score-email-confidence', ['--refresh' => true]);
    // Sa seule adresse est en quarantaine : elle ne note plus la fiche.
    expect(DB::table('companies')->where('id', $seule)->value('best_email_confidence'))->toBeNull()
        ->and(DB::table('contacts')->where('email', 'yves@mail.zz-devine.example.invalid')->value('email_confidence'))->not->toBe('A')
        ->and(DB::table('companies')->where('id', $j['fiable'])->value('best_email_confidence'))->toBe('A')
        ->and(DB::table('companies')->where('id', $j['verifiee'])->value('best_email_confidence'))->toBe('A');
    $tour();

    // Le chemin en mémoire (enrichissement, step12) dit la même chose.
    $builder = app(AudienceBuilderService::class);
    $idA = (int) EmailAudience::query()->where('workspace_id', $ws)->where('name', 'Confiance email A (domaine = site)')->value('id');
    $idJ = (int) EmailAudience::query()->where('workspace_id', $ws)->where('name', 'Prospects contactables')->value('id');
    $memoire = static fn (int $id): array => $builder->evaluateForCompany(Company::query()->findOrFail($id));
    expect($memoire($seule))->not->toContain($idJ)->not->toContain($idA)
        ->and($memoire($j['devinee']))->toContain($idJ)->not->toContain($idA)
        ->and($memoire($j['fiable']))->toContain($idJ)->toContain($idA);
});

/** La migration du lot, chargée telle quelle. */
function qsMigration(): object
{
    return require database_path('migrations/2026_10_03_000070_audiences_par_defaut_hors_quarantaine.php');
}

test('migration conditionnelle : seules les audiences encore d origine changent ; une audience retouchée est intacte ; rejouable ; down exact', function () {
    $ws = F::espace('zz-qs-migr');
    $exclusion = ['field' => 'relation_type', 'op' => 'in', 'value' => ['client', 'partenaire', 'presse_media', 'fournisseur', 'investisseur']];
    $hasEmail = ['field' => 'has_email', 'op' => 'eq', 'value' => true];
    $origineIdf = ['all' => [$hasEmail, ['field' => 'prospection_status', 'op' => 'eq', 'value' => 'ready_for_outreach'],
        ['field' => 'region_code', 'op' => 'eq', 'value' => '11']], 'not' => [$exclusion]];
    // Clés dans un autre ordre (jsonb) : c'est toujours l'origine.
    $origineA = ['not' => [$exclusion], 'all' => [['value' => true, 'op' => 'eq', 'field' => 'has_email'],
        ['field' => 'best_email_confidence', 'op' => 'eq', 'value' => 'A']]];
    $retouchee = ['all' => [$hasEmail, ['field' => 'prospection_status', 'op' => 'eq', 'value' => 'ready_for_outreach'],
        ['field' => 'department_code', 'op' => 'eq', 'value' => '75']], 'not' => [$exclusion]];
    $ids = [];
    foreach ([
        'Prospects contactables — Île-de-France' => $origineIdf,
        'Confiance email A (domaine = site)' => $origineA,
        'Prospects contactables' => $retouchee,
    ] as $nom => $criteres) {
        $ids[$nom] = (int) DB::table('email_audiences')->insertGetId([
            'workspace_id' => $ws, 'name' => $nom, 'criteria' => json_encode($criteres), 'is_active' => true, 'auto_refresh' => true,
            'member_count' => 42, 'refreshed_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }
    $lire = static fn (string $nom): array => json_decode((string) DB::table('email_audiences')->where('id', $ids[$nom])->value('criteria'), true);
    $avantRetouchee = DB::table('email_audiences')->where('id', $ids['Prospects contactables'])->first();

    $m = qsMigration();
    $m->up();
    $m->up(); // rejouable

    $idf = $lire('Prospects contactables — Île-de-France');
    $a = $lire('Confiance email A (domaine = site)');
    expect(collect($idf['all'])->pluck('field')->all())->toBe(['email_hors_quarantaine', 'prospection_status', 'region_code'])
        ->and(collect($a['all'])->pluck('field')->all())->toBe(['email_hors_quarantaine', 'best_email_confidence'])
        ->and(collect($a['not'])->pluck('field')->all())->toBe(['relation_type', 'site_non_verifie'])
        ->and(DB::table('email_audiences')->where('id', $ids['Confiance email A (domaine = site)'])->value('refreshed_at'))->toBeNull();
    // Valides pour le constructeur (sinon le recalcul de 04:00 lèverait).
    AudienceBuilderService::validerCriteres($idf);
    AudienceBuilderService::validerCriteres($a);

    // L'audience retouchée à la main : strictement inchangée.
    expect(DB::table('email_audiences')->where('id', $ids['Prospects contactables'])->first())->toEqual($avantRetouchee);

    $m->down();
    expect($lire('Prospects contactables — Île-de-France'))->toEqual($origineIdf)
        ->and($lire('Confiance email A (domaine = site)')['all'][0]['field'])->toBe('has_email')
        ->and($lire('Prospects contactables'))->toEqual($retouchee);
});

test('les nouveaux critères n admettent que eq avec un booléen', function (string $champ, string $op, mixed $valeur) {
    AudienceBuilderService::validerCriteres(['all' => [['field' => $champ, 'op' => $op, 'value' => $valeur]]]);
})->with([
    ['email_hors_quarantaine', 'neq', true],
    ['email_hors_quarantaine', 'eq', 'oui'],
    ['site_non_verifie', 'in', [true]],
])->throws(CritereAudienceInvalide::class);

// ─────────────────────────────────────────────────────────────────────────
// Sous le rôle de production (axion_app, RLS forcée)
// ─────────────────────────────────────────────────────────────────────────

function qsProprio(): Connection
{
    return DB::connection('pgsql_owner');
}

function qsApp(): Connection
{
    return DB::connection('pgsql_app');
}

/** @return array{id: string, marque: string, devinee: int, fiable: int} */
function qsEspaceRls(): array
{
    $owner = qsProprio();
    $id = (string) Str::uuid();
    $marque = substr(str_replace('-', '', $id), 0, 8);
    $owner->table('workspaces')->insert([
        'id' => $id, 'slug' => 'zz-qs-rls-' . $marque, 'name' => 'ZZ QS RLS', 'settings' => '{}',
        'cost_cap_eur' => 100, 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
    ]);
    $ids = [];
    foreach (['devinee' => 'guess', 'fiable' => 'brave'] as $cle => $methode) {
        $email = 'contact@zz-' . $cle . '-' . $marque . '.example.invalid';
        $ids[$cle] = (int) $owner->table('companies')->insertGetId([
            'workspace_id' => $id, 'siren' => '95' . random_int(1000000, 9999999), 'denomination' => 'ZZ QS Rls ' . $cle . ' ' . $marque,
            'website' => 'zz-' . $cle . '-' . $marque . '.example.invalid', 'website_method' => $methode,
            'email_generic' => $email, 'metadata' => '{}', 'quality_score' => 0, 'prospection_status' => 'ready_for_outreach',
            'signals' => qsSignals($email), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    return ['id' => $id, 'marque' => $marque, 'devinee' => $ids['devinee'], 'fiable' => $ids['fiable']];
}

/** @param  array{id: string}  $e */
function qsNettoyer(array $e): void
{
    $owner = qsProprio();
    $owner->transaction(function () use ($owner, $e): void {
        // Nettoyage du TEST (données semées ici) — le produit ne supprime rien.
        foreach (['contacts', 'companies'] as $table) {
            $owner->table($table)->where('workspace_id', $e['id'])->delete();
        }
        $owner->table('workspaces')->where('id', $e['id'])->delete();
    });
}

test('sous axion_app (RLS) : aperçu et audience « joignables » sans adresse en quarantaine, le critère ne change pas l accès à companies', function () {
    $a = qsEspaceRls();
    $b = qsEspaceRls();
    $precedente = DB::getDefaultConnection();

    try {
        DB::setDefaultConnection('pgsql_app');
        $role = DB::selectOne('SELECT rolsuper, rolbypassrls FROM pg_roles WHERE rolname = current_user');
        expect($role->rolsuper)->toBeFalse()->and($role->rolbypassrls)->toBeFalse();
        DB::select('SELECT set_config(?, ?, false)', ['app.current_workspace_id', $a['id']]);

        $r = app(ResolveurDestinataires::class)->resoudre(
            $a['id'],
            ['all' => [['field' => 'country_code', 'op' => 'eq', 'value' => 'FR']]],
            new ReglageDestinataires(ReglageDestinataires::GENERIQUE),
            null,
        );
        expect(collect($r['lignes'])->pluck('email')->all())->toBe(['contact@zz-fiable-' . $a['marque'] . '.example.invalid'])
            ->and($r['exclues'][EligibiliteAdresse::SITE_NON_VERIFIE])->toBe(1);

        $criteres = ['all' => [
            ['field' => 'email_hors_quarantaine', 'op' => 'eq', 'value' => true],
            ['field' => 'prospection_status', 'op' => 'eq', 'value' => 'ready_for_outreach'],
        ]];
        $builder = app(AudienceBuilderService::class);
        $q = $builder->buildPublicQuery($a['id'], $criteres);
        // `WorkspaceContext::run` (l'aperçu ci-dessus) repose le contexte à
        // la sortie : on le rejoue comme le fait chaque chemin de production.
        $membres = WorkspaceContext::run($a['id'], static fn (): array => (clone $q)->pluck('id')->map(static fn ($v): int => (int) $v)->all());
        expect($membres)->toBe([$a['fiable']]);

        // Le PLAN (relecture #311) : ce que l'on prouve, c'est que le critère
        // n'ajoute AUCUN accès à `companies` et ne change pas son chemin
        // d'accès — l'expression n'est qu'un filtre sur les fiches que les
        // autres critères atteignent déjà (ici, le recalcul de 04:00, un
        // traitement de fond ; l'aperçu web l'évalue sur les fiches retenues).
        // Comparé avec et sans le critère, planificateur libre puis sans
        // balayage séquentiel. Un `Seq Scan` imposé par le critère rougit.
        $sans = $builder->buildPublicQuery($a['id'], ['all' => [$criteres['all'][1]]]);
        $acces = static function ($requete): array {
            $lignes = array_map(static fn ($l): string => (string) $l->{'QUERY PLAN'}, qsApp()->select('EXPLAIN ' . $requete->toSql(), $requete->getBindings()));
            $noeuds = [];
            foreach ($lignes as $l) {
                if (preg_match('/((?:Seq|Index|Index Only|Bitmap Heap|Bitmap Index) Scan)(?: using (\S+))? on companies\b/', $l, $m) === 1) {
                    $noeuds[] = $m[1] . ' ' . ($m[2] ?? '');
                }
            }

            return $noeuds;
        };
        foreach (['on', 'off'] as $balayage) {
            qsApp()->statement('SET enable_seqscan = ' . $balayage);
            try {
                expect($acces($q))->toBe($acces($sans));
            } finally {
                qsApp()->statement('RESET enable_seqscan');
            }
        }
    } finally {
        DB::setDefaultConnection($precedente);
        qsApp()->select('SELECT set_config(?, ?, false)', ['app.current_workspace_id', '']);
        qsApp()->disconnect();
        qsNettoyer($a);
        qsNettoyer($b);
        qsProprio()->disconnect();
    }
});
