<?php

/**
 * EFFACEMENT (art. 17) — LES FICHES D'ORGANISATION (relecture sécurité de la
 * PR #255, 2026-09-29).
 *
 * L'adresse et le mobile d'une personne vivent aussi sur la fiche de son
 * organisation : e-mail générique, téléphone, canaux collectés (et leur fiche
 * de vérification), fiches personnes qui portent son numéro sans son adresse.
 * Les deux portes d'effacement ne les touchaient pas. Chaque garde est prouvée
 * face à un TÉMOIN (une autre organisation, d'autres coordonnées) qui, lui,
 * ne doit pas bouger. Fixtures FICTIVES (dépôt public).
 */

use App\Crm\FichesProtegees;
use App\Crm\Rgpd\EffacementCoordonneesFiches;
use App\Crm\Rgpd\SiteGdprService;
use App\Jobs\VerifierEffacementRgpd;
use App\Models\User;
use App\Services\Rgpd\GdprErasureService;
use App\Services\Rgpd\GdprPortabilityService;
use Database\Seeders\PermissionsAndRolesSeeder;
use Database\Seeders\ScrapingSourcesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

const EFO_EMAIL = 'zoe.zzpresidente@zz-fede.example.invalid';
const EFO_MOBILE = '06 00 00 00 42';
const EFO_MOBILE_AUTRE_FORME = '+33 6 00 00 00 42';

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

    // Une fédération PROTÉGÉE qui porte l'adresse et le mobile de sa
    // présidente aux TROIS emplacements : e-mail générique / téléphone, canaux,
    // et fiche personne (le mobile y est écrit autrement).
    $this->fede = (int) DB::table('companies')->insertGetId([
        'workspace_id' => $this->espace, 'siren' => '900000501', 'entity_nature' => 'federation',
        'denomination' => 'ZZ Fede effacement', 'email_generic' => EFO_EMAIL, 'phone' => EFO_MOBILE,
        'signals' => json_encode([
            'contact_channels' => [
                'emails' => [strtoupper(EFO_EMAIL), 'secretariat@zz-fede.example.invalid'],
                'phones' => [EFO_MOBILE_AUTRE_FORME, '01 00 00 00 10'],
                'details' => [
                    EFO_EMAIL => ['type' => 'nominatif', 'verifie_le' => '2026-09-28'],
                    'secretariat@zz-fede.example.invalid' => ['type' => 'generique', 'verifie_le' => '2026-09-28'],
                ],
            ],
            'email_generic_verification' => ['type' => 'generique', 'verifie_le' => '2026-09-28'],
        ]),
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $tag = (int) DB::table('tags')->insertGetId([
        'workspace_id' => $this->espace, 'slug' => FichesProtegees::TAG_FEDERATIONS, 'name' => 'Fédérations',
        'category' => 'intent', 'kind' => 'auto', 'rules' => '{}', 'is_locked' => true,
        'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('company_tag')->insert([
        'company_id' => $this->fede, 'tag_id' => $tag, 'workspace_id' => $this->espace,
        'assigned_at' => now(), 'assigned_by' => 'auto-rule',
    ]);
    $this->presidente = (int) DB::table('contacts')->insertGetId([
        'workspace_id' => $this->espace, 'company_id' => $this->fede, 'first_name' => 'Zoe', 'last_name' => 'ZZPRESIDENTE',
        'email' => EFO_EMAIL, 'phone' => EFO_MOBILE_AUTRE_FORME, 'created_at' => now(), 'updated_at' => now(),
    ]);
    // Un DOUBLON de la même personne, sans l'adresse, avec le même mobile —
    // sur la fiche de l'ANTENNE (une personne n'a qu'une fiche par organisme).
    $this->antenne = (int) DB::table('companies')->insertGetId([
        'workspace_id' => $this->espace, 'siren' => '900000503', 'denomination' => 'ZZ Antenne',
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $this->doublon = (int) DB::table('contacts')->insertGetId([
        'workspace_id' => $this->espace, 'company_id' => $this->antenne, 'first_name' => 'Zoe', 'last_name' => 'ZZPRESIDENTE',
        'phone' => '0033600000042', 'created_at' => now(), 'updated_at' => now(),
    ]);

    // TÉMOIN : une autre organisation, d'autres coordonnées.
    $this->temoin = (int) DB::table('companies')->insertGetId([
        'workspace_id' => $this->espace, 'siren' => '900000502', 'denomination' => 'ZZ Temoin',
        'email_generic' => 'accueil@zz-temoin.example.invalid', 'phone' => '06 00 00 00 99',
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $this->contactTemoin = (int) DB::table('contacts')->insertGetId([
        'workspace_id' => $this->espace, 'company_id' => $this->temoin, 'last_name' => 'ZZTEMOIN',
        'email' => 'temoin@zz-temoin.example.invalid', 'phone' => '06 00 00 00 98', 'created_at' => now(), 'updated_at' => now(),
    ]);
});

function efoFiche(int $id): object
{
    return DB::table('companies')->where('id', $id)->first();
}

/** @return array<string, mixed> */
function efoSignals(int $id): array
{
    return (array) json_decode((string) efoFiche($id)->signals, true);
}

/**
 * Le verdict de la preuve DIFFÉRÉE d'un effacement console (relecture P1) :
 * ce que `VerifierEffacementRgpd` constatera dans la file. Vide : complet.
 *
 * @param  array<string, mixed>  $resultat
 * @return array<string, int>
 */
function efoVerdict(array $resultat, string $email = EFO_EMAIL): array
{
    /** @var list<string> $personnels */
    $personnels = $resultat['personnels'];

    return VerifierEffacementRgpd::constater($email, $personnels);
}

/** La demande que la porte du site inscrit (P2). */
function efoDemandeSite(): object
{
    $ligne = DB::table('rgpd_requests')->whereRaw("metadata->>'origin' = 'site-sync-gdpr'")->orderByDesc('id')->first();
    expect($ligne)->not->toBeNull();

    return $ligne;
}

function efoConnecterAdmin(string $espace): User
{
    test()->seed(PermissionsAndRolesSeeder::class);
    $user = User::create([
        'id' => (string) Str::uuid(), 'email' => 'admin-' . Str::random(6) . '@example.invalid', 'name' => 'ZZ admin',
        'password_hash' => Hash::make('PasswordTest12345!'), 'current_workspace_id' => $espace,
        'first_login_completed_at' => now(),
    ]);
    setPermissionsTeamId($espace);
    $user->assignRole('admin');
    test()->actingAs($user);

    return $user;
}

/** Une opposition GLOBALE existe-t-elle sur ce numéro (ses 9 chiffres d'abonné) ? */
function efoOppose(string $abonne): bool
{
    return DB::table('opt_out')->whereNotNull('phone')
        ->whereRaw("regexp_replace(phone, '[^0-9]', '', 'g') LIKE ?", ['%' . $abonne])
        ->exists();
}

test('l effacement console atteint les trois emplacements d une fiche PROTEGEE, qui survit ; le temoin ne bouge pas', function () {
    $resultat = app(GdprErasureService::class)->erase(EFO_EMAIL);

    $fiche = efoFiche($this->fede);
    $canaux = efoSignals($this->fede)['contact_channels'];
    expect($fiche)->not->toBeNull()
        ->and($fiche->email_generic)->toBeNull()
        ->and($fiche->phone)->toBeNull()
        ->and(efoSignals($this->fede))->not->toHaveKey('email_generic_verification')
        ->and($canaux['emails'])->toBe(['secretariat@zz-fede.example.invalid'])
        ->and($canaux['phones'])->toBe(['01 00 00 00 10'])
        ->and(array_keys($canaux['details']))->toBe(['secretariat@zz-fede.example.invalid'])
        ->and(DB::table('contacts')->whereIn('id', [$this->presidente, $this->doublon])->count())->toBe(0)
        ->and(efoVerdict($resultat))->toBe([])
        // Témoin intact.
        ->and(efoFiche($this->temoin)->email_generic)->toBe('accueil@zz-temoin.example.invalid')
        ->and(efoFiche($this->temoin)->phone)->toBe('06 00 00 00 99')
        ->and(DB::table('contacts')->where('id', $this->contactTemoin)->exists())->toBeTrue();
});

test('l effacement pose l opposition sur l adresse ET le mobile, et le re-import ne les remet pas', function () {
    app(GdprErasureService::class)->erase(EFO_EMAIL);

    expect(DB::table('opt_out')->where('scope', 'business')->where('email_hash', hash('sha256', EFO_EMAIL))->exists())->toBeTrue()
        ->and(DB::table('opt_out')->where('scope', 'business')->whereNotNull('phone')->exists())->toBeTrue();

    $fichier = (string) tempnam(sys_get_temp_dir(), 'zz-efo-');
    file_put_contents($fichier, json_encode([
        'siren' => '900000501', 'nom' => 'ZZ Fede effacement', 'famille' => 'federation_syndicat_pro',
        'niveau' => 'national', 'pertinence' => 'haute', 'contactabilite' => 'email_verifie',
        'email_generique' => EFO_EMAIL, 'telephone' => EFO_MOBILE,
        'emails_autres' => [['email' => EFO_EMAIL, 'type' => 'nominatif', 'domaine_verifie' => true, 'verifie_le' => '2026-09-28']],
        'telephones_autres' => [EFO_MOBILE_AUTRE_FORME],
        'personnes' => [['prenom' => 'Zoe', 'nom' => 'ZZPRESIDENTE', 'fonction' => 'Présidente', 'email' => EFO_EMAIL, 'linkedin' => null]],
    ]) . "\n");
    try {
        Artisan::call('crm:import-federations', ['file' => $fichier]);
    } finally {
        @unlink($fichier);
    }

    $fiche = efoFiche($this->fede);
    $texte = mb_strtolower((string) $fiche->signals);
    expect($fiche->email_generic)->toBeNull()
        ->and($fiche->phone)->toBeNull()
        ->and($texte)->not->toContain(EFO_EMAIL)
        ->and($texte)->not->toContain('00 00 00 42')
        ->and(DB::table('contacts')->where('company_id', $this->fede)->where('last_name', 'ZZPRESIDENTE')->exists())->toBeFalse();
});

test('une adresse restee ailleurs dans les signaux rend l effacement INCOMPLET, et il le dit', function () {
    DB::table('companies')->where('id', $this->temoin)->update([
        'signals' => json_encode(['mentions_legales' => ['contact' => EFO_EMAIL]]),
    ]);

    $resultat = app(GdprErasureService::class)->erase(EFO_EMAIL);

    expect(efoVerdict($resultat))->toHaveKey('companies.signals');
});

test('le journal de l effacement ne porte JAMAIS l adresse en clair', function () {
    $messages = [];
    Event::listen(MessageLogged::class, function (MessageLogged $m) use (&$messages): void {
        $messages[] = $m->message . ' ' . json_encode($m->context);
    });

    $resultat = app(GdprErasureService::class)->erase(EFO_EMAIL);
    // Le verdict est journalisé par la preuve différée : elle aussi.
    VerifierEffacementRgpd::dispatchSync(EFO_EMAIL, $resultat['personnels']);

    expect(implode("\n", $messages))->toContain('GDPR erasure complete');
    foreach ($messages as $ligne) {
        expect(mb_strtolower($ligne))->not->toContain(EFO_EMAIL);
    }
});

test('l export CSV des entreprises ne rend plus l adresse ni le mobile apres effacement', function () {
    $this->seed(PermissionsAndRolesSeeder::class);
    $user = User::create([
        'id' => (string) Str::uuid(), 'email' => 'admin-' . Str::random(6) . '@example.invalid', 'name' => 'ZZ admin',
        'password_hash' => Hash::make('PasswordTest12345!'), 'current_workspace_id' => $this->espace,
        'first_login_completed_at' => now(),
    ]);
    setPermissionsTeamId($this->espace);
    $user->assignRole('admin');
    $this->actingAs($user);

    $avant = $this->get('/api/v1/companies/export')->streamedContent();
    // Témoin de l'instrument : AVANT, l'export porte bien l'adresse.
    expect($avant)->toContain(EFO_EMAIL);

    app(GdprErasureService::class)->erase(EFO_EMAIL);

    $apres = $this->get('/api/v1/companies/export')->streamedContent();
    expect($apres)->not->toContain(EFO_EMAIL)
        ->and($apres)->not->toContain(EFO_MOBILE)
        ->and($apres)->toContain('accueil@zz-temoin.example.invalid');
});

test('l effacement venu du site atteint aussi les fiches d organisation', function () {
    app(SiteGdprService::class)->erase(hash('sha256', 'zz-efo'), EFO_EMAIL, 'business');

    $fiche = efoFiche($this->fede);
    expect($fiche->email_generic)->toBeNull()
        ->and($fiche->phone)->toBeNull()
        ->and(efoSignals($this->fede)['contact_channels']['emails'])->toBe(['secretariat@zz-fede.example.invalid'])
        ->and(DB::table('contacts')->where('id', $this->doublon)->exists())->toBeFalse()
        ->and(efoFiche($this->temoin)->email_generic)->toBe('accueil@zz-temoin.example.invalid');
});

test('ce qu on sait effacer, on sait l exporter : les fiches d organisation qui portent l adresse', function () {
    $fiches = EffacementCoordonneesFiches::fichesPortant(strtoupper(EFO_EMAIL));

    expect(array_column($fiches, 'id'))->toBe([$this->fede]);
});

// ── S3 : le standard n'est pas le numéro de la personne ────────────────────

test('S3 — un standard d organisation ou un numero partage ne quittent que les fiches de la personne ; ils ne sont pas opposes', function () {
    // Le standard d'une organisation, inscrit aussi sur la fiche de la personne.
    $organisation = (int) DB::table('companies')->insertGetId([
        'workspace_id' => $this->espace, 'siren' => '900000520', 'denomination' => 'ZZ Standard',
        'phone' => '04 72 00 00 10', 'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('contacts')->insert([
        'workspace_id' => $this->espace, 'company_id' => $organisation, 'first_name' => 'Zia', 'last_name' => 'ZZDEMANDEUSE',
        'email' => 'zia@zz-standard.example.invalid', 'phone' => '04.72.00.00.10', 'created_at' => now(), 'updated_at' => now(),
    ]);
    // Un portable PARTAGÉ avec un collègue (ligne de service).
    $autre = (int) DB::table('companies')->insertGetId([
        'workspace_id' => $this->espace, 'siren' => '900000521', 'denomination' => 'ZZ Service',
        'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('contacts')->insert([
        'workspace_id' => $this->espace, 'company_id' => $autre, 'first_name' => 'Zia', 'last_name' => 'ZZDEMANDEUSE',
        'email' => 'zia@zz-standard.example.invalid', 'phone' => '06 11 11 11 11', 'created_at' => now(), 'updated_at' => now(),
    ]);
    $collegue = (int) DB::table('contacts')->insertGetId([
        'workspace_id' => $this->espace, 'company_id' => $autre, 'first_name' => 'Zed', 'last_name' => 'ZZCOLLEGUE',
        'email' => 'zed@zz-standard.example.invalid', 'phone' => '+33 6 11 11 11 11', 'created_at' => now(), 'updated_at' => now(),
    ]);

    $resultat = app(GdprErasureService::class)->erase('zia@zz-standard.example.invalid');

    expect(DB::table('contacts')->where('email', 'zia@zz-standard.example.invalid')->count())->toBe(0)
        // Le standard reste à l'organisation, le portable partagé au collègue.
        ->and(efoFiche($organisation)->phone)->toBe('04 72 00 00 10')
        ->and(DB::table('contacts')->where('id', $collegue)->value('phone'))->toBe('+33 6 11 11 11 11')
        // Aucune opposition globale sur ces numéros.
        ->and(efoOppose('472000010'))->toBeFalse()
        ->and(efoOppose('611111111'))->toBeFalse()
        ->and(efoVerdict($resultat, 'zia@zz-standard.example.invalid'))->toBe([]);
});

// ── S4 : la preuve lit plus large que l'effacement ─────────────────────────

test('S4 — une adresse dans une note de Will ou dans metadata, un numero mal forme : l effacement est INCOMPLET', function (string $zone) {
    match ($zone) {
        'federations.partenariat_note' => DB::table('federations')->insert([
            'company_id' => $this->temoin, 'workspace_id' => $this->espace, 'famille' => 'ordre', 'niveau' => 'national',
            'pertinence' => 'haute', 'contactabilite' => 'aucun_contact', 'partenariat_note' => 'Rappeler ' . strtoupper(EFO_EMAIL),
        ]),
        'events.demarche_note' => DB::table('events')->insert([
            'workspace_id' => $this->espace, 'external_ref' => 'zz-efo-note', 'nom' => 'ZZ salon', 'type' => 'salon',
            'demarche_note' => 'Contact : ' . EFO_EMAIL, 'created_at' => now(), 'updated_at' => now(),
        ]),
        'companies.metadata' => DB::table('companies')->where('id', $this->temoin)->update([
            // Numéro mal formé : la normalisation de l'effacement ne le lit pas,
            // la recherche par chiffres, si.
            'metadata' => json_encode(['standard' => '06.00.00.00.42 (poste 3)']),
        ]),
    };

    $resultat = app(GdprErasureService::class)->erase(EFO_EMAIL);

    expect(efoVerdict($resultat))->toHaveKey($zone);
})->with(['federations.partenariat_note', 'events.demarche_note', 'companies.metadata']);

test('S4, P2 — la porte du site dit « incomplet », et la demande le montre : en cours, motif, journal, console', function () {
    DB::table('companies')->where('id', $this->temoin)->update([
        'metadata' => json_encode(['contact' => EFO_EMAIL]),
    ]);
    $messages = [];
    Event::listen(MessageLogged::class, function (MessageLogged $m) use (&$messages): void {
        $messages[] = ['niveau' => $m->level, 'texte' => $m->message . ' ' . json_encode($m->context)];
    });

    $resultat = app(SiteGdprService::class)->erase(hash('sha256', 'zz-efo'), EFO_EMAIL, 'business');

    $demande = efoDemandeSite();
    $metadata = (array) json_decode((string) $demande->metadata, true);
    expect($resultat['verification'])->toBe('differee')
        ->and($resultat)->not->toHaveKey('personnels')
        // La demande reste EN COURS, avec le motif et l'emplacement.
        ->and($demande->status)->toBe('processing')
        ->and($metadata['verification'])->toBe('incomplete')
        ->and($metadata['motif'])->toBe(VerifierEffacementRgpd::MOTIF_INCOMPLET)
        ->and($metadata['residus'])->toHaveKey('companies.metadata');

    // Le journal le dit, sans l'adresse.
    $alertes = array_values(array_filter($messages, fn (array $m): bool => $m['niveau'] === 'warning' && str_contains($m['texte'], 'INCOMPLETE')));
    expect($alertes)->toHaveCount(1)
        ->and(mb_strtolower($alertes[0]['texte']))->not->toContain(EFO_EMAIL)
        ->and($alertes[0]['texte'])->toContain(hash('sha256', EFO_EMAIL));

    // La console la liste, en cours, avec son motif.
    efoConnecterAdmin($this->espace);
    $ligne = collect($this->getJson('/api/v1/rgpd/requests')->assertOk()->json('data'))->firstWhere('id', $demande->id);
    expect($ligne)->not->toBeNull()
        ->and($ligne['status'])->toBe('processing')
        ->and($ligne['metadata']['motif'])->toBe(VerifierEffacementRgpd::MOTIF_INCOMPLET);
});

test('P2 — temoin : un effacement du site sans residu solde sa demande (done, complete)', function () {
    app(SiteGdprService::class)->erase(hash('sha256', 'zz-efo'), EFO_EMAIL, 'business');

    $demande = efoDemandeSite();
    expect($demande->status)->toBe('done')
        ->and(json_decode((string) $demande->metadata, true)['verification'])->toBe('complete');
});

test('P1 — la console met la demande EN COURS puis la preuve differee la solde, ou la laisse en cours', function (bool $residu) {
    if ($residu) {
        DB::table('companies')->where('id', $this->temoin)->update(['metadata' => json_encode(['contact' => EFO_EMAIL])]);
    }
    efoConnecterAdmin($this->espace);
    $id = (int) DB::table('rgpd_requests')->insertGetId([
        'workspace_id' => $this->espace, 'type' => 'erasure', 'status' => 'pending', 'subject_email' => EFO_EMAIL,
        'requested_at' => now(), 'metadata' => '{}', 'created_at' => now(), 'updated_at' => now(),
    ]);

    $reponse = $this->postJson("/api/v1/rgpd/requests/{$id}/process")->assertOk();

    // En file `sync`, la preuve a déjà tourné : la réponse relit son verdict.
    $metadata = (array) json_decode((string) DB::table('rgpd_requests')->where('id', $id)->value('metadata'), true);
    expect($reponse->json('request.status'))->toBe($residu ? 'processing' : 'done')
        ->and($metadata['verification'])->toBe($residu ? 'incomplete' : 'complete')
        // Les numéros personnels ne sont ni rendus ni archivés.
        ->and($reponse->json('result'))->not->toHaveKey('personnels')
        ->and(json_encode($metadata))->not->toContain('00 00 00 42');
})->with(['sans residu' => false, 'avec residu' => true]);

// ── S5 : la porte du site relève par clé de personne ───────────────────────

test('S5 — la porte du site releve les numeros par cle de personne, pas seulement par adresse', function () {
    $fiche = (int) DB::table('companies')->insertGetId([
        'workspace_id' => $this->espace, 'siren' => '900000530', 'denomination' => 'ZZ Club PK',
        'phone' => '06 00 00 00 77', 'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('contacts')->insert([
        'workspace_id' => $this->espace, 'company_id' => $fiche, 'last_name' => 'ZZPK', 'email' => null,
        'phone' => '06 00 00 00 77', 'person_key' => 'zz-pk-s5', 'created_at' => now(), 'updated_at' => now(),
    ]);

    app(SiteGdprService::class)->erase('zz-pk-s5', 'autre-adresse@zz-pk.example.invalid', 'business');

    expect(efoFiche($fiche)->phone)->toBeNull()
        ->and(DB::table('contacts')->where('person_key', 'zz-pk-s5')->exists())->toBeFalse();
});

// ── S1 et S6 : l'export ne rend que les valeurs de la personne ─────────────

test('S1 — l export rend les valeurs de la personne et leur emplacement, jamais celles d un tiers', function () {
    // Une fiche trouvée par ses CANAUX : son e-mail générique et son standard
    // sont ceux d'un tiers.
    $tiers = (int) DB::table('companies')->insertGetId([
        'workspace_id' => $this->espace, 'siren' => '900000540', 'denomination' => 'ZZ Tiers',
        'email_generic' => 'tiers@zz-tiers.example.invalid', 'phone' => '06 99 99 99 01',
        'signals' => json_encode(['contact_channels' => ['emails' => [EFO_EMAIL], 'phones' => ['06 99 99 99 02']]]),
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $fiches = EffacementCoordonneesFiches::fichesPortant(EFO_EMAIL, [EFO_MOBILE]);
    $parId = collect($fiches)->keyBy('id');
    $texte = json_encode($fiches);

    expect($parId)->toHaveKey($tiers)
        ->and($parId[$tiers]['emplacements'])->toBe([['emplacement' => 'companies.signals.contact_channels.emails', 'valeur' => EFO_EMAIL]])
        ->and($texte)->not->toContain('tiers@zz-tiers')
        ->and($texte)->not->toContain('99 99 01')
        ->and($texte)->not->toContain('99 99 02')
        // La fiche de la personne elle-même : ses trois emplacements.
        ->and(array_column($parId[$this->fede]['emplacements'], 'emplacement'))
        ->toContain('companies.email_generic', 'companies.phone', 'companies.signals.contact_channels.emails', 'companies.signals.contact_channels.phones');
});

test('S6 — l export venu du site rend aussi les fiches d organisation, sans valeur d un tiers', function () {
    DB::table('companies')->where('id', $this->fede)->update(['email_generic' => 'standard@zz-fede.example.invalid']);

    $export = app(SiteGdprService::class)->export(hash('sha256', 'zz-efo'), EFO_EMAIL);
    $fiches = collect($export['business']['fiches_organisation'])->keyBy('id');

    expect($fiches)->toHaveKey($this->fede)
        ->and(json_encode($export['business']['fiches_organisation']))->not->toContain('standard@zz-fede')
        ->and(array_column($fiches[$this->fede]['emplacements'], 'valeur'))->toContain(EFO_EMAIL);
});

// ── E1 : un numéro a des frontières ────────────────────────────────────────

test('E1 — un SIREN suivi d une date ne forment pas le numero par hasard : l effacement est complet', function () {
    // Les chiffres collés de ce texte contiennent « 0600000042 »
    // (906000000|4200…) : une recherche de sous-suite de chiffres criait au
    // résidu. Le numéro, lui, n'y est pas.
    DB::table('companies')->where('id', $this->temoin)->update([
        'metadata' => json_encode(['siren_ref' => '906000000', 'cree_le' => '4200-01-01']),
    ]);

    $resultat = app(GdprErasureService::class)->erase(EFO_EMAIL);

    expect(efoVerdict($resultat))->toBe([]);
});

test('E1 — temoin : le numero ecrit autrement, avec separateurs, est toujours trouve', function (string $ecriture) {
    DB::table('companies')->where('id', $this->temoin)->update([
        'metadata' => json_encode(['note' => 'Joindre au ' . $ecriture . ' (poste 3)']),
    ]);

    $resultat = app(GdprErasureService::class)->erase(EFO_EMAIL);

    expect(efoVerdict($resultat))->toHaveKey('companies.metadata');
})->with(['06.00.00.00.42', '+33 (0)6 00 00 00 42', '0033-6-00-00-00-42', '0600000042']);

// ── E2 : standard des canaux, et opposition jamais par union ───────────────

test('E2 — une ligne fixe des CANAUX d une organisation est un standard : ni effacee de ses canaux, ni opposee', function () {
    // La fédération porte « 01 00 00 00 10 » dans ses canaux ; une personne
    // l'a aussi sur sa fiche. Ce n'est pas son numéro : c'est le standard.
    DB::table('contacts')->insert([
        'workspace_id' => $this->espace, 'company_id' => $this->temoin, 'first_name' => 'Zou', 'last_name' => 'ZZLIGNE',
        'email' => 'zou@zz-ligne.example.invalid', 'phone' => '01.00.00.00.10', 'created_at' => now(), 'updated_at' => now(),
    ]);

    app(GdprErasureService::class)->erase('zou@zz-ligne.example.invalid');

    expect(efoSignals($this->fede)['contact_channels']['phones'])->toContain('01 00 00 00 10')
        ->and(efoOppose('100000010'))->toBeFalse();
});

test('E2 — un numero personnel ici mais porte par un autre dans un autre espace n est PAS oppose', function (string $porte) {
    $autreEspace = (string) Str::uuid();
    DB::table('workspaces')->insert([
        'id' => $autreEspace, 'slug' => 'zz-efo-autre', 'name' => 'ZZ autre espace', 'settings' => '{}',
        'cost_cap_eur' => 100, 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
    ]);
    $ailleurs = (int) DB::table('companies')->insertGetId([
        'workspace_id' => $autreEspace, 'siren' => '900000550', 'denomination' => 'ZZ Ailleurs',
        'created_at' => now(), 'updated_at' => now(),
    ]);
    // Là-bas, une AUTRE personne porte ce mobile.
    $collegue = (int) DB::table('contacts')->insertGetId([
        'workspace_id' => $autreEspace, 'company_id' => $ailleurs, 'first_name' => 'Zed', 'last_name' => 'ZZAILLEURS',
        'email' => 'zed@zz-ailleurs.example.invalid', 'phone' => EFO_MOBILE, 'created_at' => now(), 'updated_at' => now(),
    ]);

    $porte === 'console'
        ? app(GdprErasureService::class)->erase(EFO_EMAIL)
        : app(SiteGdprService::class)->erase(hash('sha256', 'zz-efo'), EFO_EMAIL, 'business');

    // Ici, effacé ; là-bas, intact ; et AUCUNE opposition globale.
    expect(efoFiche($this->fede)->phone)->toBeNull()
        ->and(DB::table('contacts')->where('id', $collegue)->value('phone'))->toBe(EFO_MOBILE)
        ->and(efoOppose('600000042'))->toBeFalse();
})->with(['console', 'site']);

// ── E3 : l'export des articles 15 et 20, par le service ────────────────────

test('E3 — GdprPortabilityService exporte les fiches d organisation de la personne, sans valeur d un tiers', function () {
    $tiers = (int) DB::table('companies')->insertGetId([
        'workspace_id' => $this->espace, 'siren' => '900000560', 'denomination' => 'ZZ Tiers export',
        'email_generic' => 'tiers@zz-tiers-export.example.invalid', 'phone' => '06 99 99 99 03',
        'signals' => json_encode(['contact_channels' => ['emails' => [EFO_EMAIL], 'phones' => ['06 99 99 99 04']]]),
        'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('rgpd_requests')->insert([
        'workspace_id' => $this->espace, 'type' => 'portability', 'status' => 'pending', 'subject_email' => EFO_EMAIL,
        'requested_at' => now(), 'metadata' => '{}', 'created_at' => now(), 'updated_at' => now(),
    ]);

    $service = app(GdprPortabilityService::class);
    $jeton = $service->export(EFO_EMAIL)['token'];
    $archive = (array) json_decode((string) $service->retrieve($jeton), true);
    $fiches = collect($archive['fiches_organisation'])->keyBy('id');
    $texte = json_encode($archive['fiches_organisation']);

    expect($fiches)->toHaveKey($this->fede)
        ->and($fiches)->toHaveKey($tiers)
        ->and($fiches[$tiers]['emplacements'])->toBe([['emplacement' => 'companies.signals.contact_channels.emails', 'valeur' => EFO_EMAIL]])
        ->and($texte)->not->toContain('tiers@zz-tiers-export')
        ->and($texte)->not->toContain('99 99 03')
        ->and($texte)->not->toContain('99 99 04')
        ->and($archive['fiches_organisation_perimetre'])->toBe('complet');
});

// ── E4 : la timeline, par les numéros personnels ───────────────────────────

test('E4 — la preuve differee efface la timeline qui porte un numero PERSONNEL, pas seulement celui de la demande', function () {
    $activite = (int) DB::table('activities')->insertGetId([
        'workspace_id' => $this->espace, 'type' => 'call', 'kind' => 'call', 'occurred_at' => now(),
        'external_ref' => 'zz-efo-e4', 'title' => 'ZZ appel', 'payload' => json_encode(['tel' => '06 00 00 00 42']),
        'created_at' => now(),
    ]);
    // TÉMOIN : l'appel d'une autre personne.
    $temoin = (int) DB::table('activities')->insertGetId([
        'workspace_id' => $this->espace, 'type' => 'call', 'kind' => 'call', 'occurred_at' => now(),
        'external_ref' => 'zz-efo-e4-temoin', 'title' => 'ZZ appel', 'payload' => json_encode(['tel' => '06 00 00 00 98']),
        'created_at' => now(),
    ]);

    // La console ne cite que l'adresse : le numéro n'est connu que par la
    // fiche de la personne.
    $resultat = app(GdprErasureService::class)->erase(EFO_EMAIL);
    VerifierEffacementRgpd::dispatchSync(EFO_EMAIL, $resultat['personnels']);

    expect(DB::table('activities')->where('id', $activite)->exists())->toBeFalse()
        ->and(DB::table('activities')->where('id', $temoin)->exists())->toBeTrue();
});
