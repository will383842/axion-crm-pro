<?php

/**
 * PROVENANCE DES INFORMATIONS VENUES DE TIERS (N12) ET DERNIER ÉCHANGE À
 * L'INITIATIVE DE LA PERSONNE (N14 réduit) — 03/10/2026.
 *
 * Préparation du futur canal Axion Partners : RIEN n'est branché. Ce fichier
 * prouve ce qui est livré :
 *
 *  - le vocabulaire exact du contrat Partners (`apporteur`, `commercial`,
 *    `societe`) dans `Taxonomy` et dans le CHECK de la table neuve ;
 *  - `contacts_provenances_tiers` : sous RLS (ENABLE + FORCE + politique),
 *    `workspace_id`, et VIDE à la livraison ;
 *  - le motif d'exclusion `information_tiers_insuffisante` (version
 *    d'information < 5 ou inconnue), même patron que `entreprise_individuelle`,
 *    dans l'aperçu d'une audience ET dans la liste en fichier ;
 *  - `opt_out.phone_hash` : HMAC À CLÉ, refus sans clé (et refus de démarrer
 *    quand la fonctionnalité est activée sans clé) ;
 *  - lecture réservée au rôle owner : les JSON servis aux autres rôles n'en
 *    contiennent RIEN ;
 *  - `contacts.dernier_echange_initiative_at` alimentée par la timeline, sans
 *    jamais rien supprimer ni reculer.
 *
 * Fixtures FICTIVES (dépôt public) : noms « ZZ », `.example.invalid`.
 */

use App\Crm\Campagnes\EligibiliteAdresse;
use App\Crm\Campagnes\ReglageDestinataires;
use App\Crm\Campagnes\ResolveurDestinataires;
use App\Crm\Emails\VerificationEmail;
use App\Crm\FichesProtegees;
use App\Crm\ProvenanceTiers\EmpreinteTelephone;
use App\Crm\ProvenanceTiers\ProvenanceTiers;
use App\Crm\Taxonomy;
use App\Models\User;
use App\Models\Workspace;
use App\Providers\ProvenanceTiersServiceProvider;
use App\Services\Audit\AuditHashChain;
use Database\Seeders\PermissionsAndRolesSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\Support\DoublonsFixtures as F;
use Tests\Support\ResolveurDnsSimule;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/** Clé de TEST, fictive, jamais une vraie clé (dépôt public). */
const PT_CLE_TEST = 'zz-cle-de-test-uniquement-0123456789abcdef';

/** Les métadonnées d'une adresse de personne vérifiée VALIDE. */
function ptMetaValide(string $email): string
{
    return (string) json_encode(['email_verification' => [
        'statut' => VerificationEmail::VALIDE, 'motif' => 'mx', 'verifie_par' => VerificationEmail::SOURCE,
        'empreinte' => VerificationEmail::empreinte(mb_strtolower(trim($email))),
    ]]);
}

function ptProvenance(string $ws, int $contactId, string $origine, ?int $version, string $ref): int
{
    return (int) DB::table('contacts_provenances_tiers')->insertGetId([
        'workspace_id' => $ws, 'contact_id' => $contactId, 'origine' => $origine,
        'reference_externe' => $ref, 'information_tiers_version' => $version,
        'recu_le' => now(), 'created_at' => now(), 'updated_at' => now(),
    ]);
}

// ── Vocabulaire et schéma ───────────────────────────────────────────────────

test('le vocabulaire du contrat Partners : exactement apporteur, commercial, societe', function () {
    expect(Taxonomy::FIELD_ORIGINS_TIERS)->toBe(['apporteur', 'commercial', 'societe'])
        ->and(ProvenanceTiers::ORIGINES)->toBe(Taxonomy::FIELD_ORIGINS_TIERS)
        ->and(ProvenanceTiers::VERSION_INFORMATION_MINIMALE)->toBe(5);
});

test('la table neuve est sous RLS forcée, porte workspace_id, et est VIDE à la livraison', function () {
    $t = DB::selectOne("SELECT relrowsecurity, relforcerowsecurity FROM pg_class WHERE oid = 'public.contacts_provenances_tiers'::regclass");
    expect($t->relrowsecurity)->toBeTrue()->and($t->relforcerowsecurity)->toBeTrue();

    $politique = DB::selectOne(
        "SELECT qual, with_check FROM pg_policies WHERE tablename = 'contacts_provenances_tiers' AND policyname = 'contacts_provenances_tiers_workspace_isolation'",
    );
    expect($politique)->not->toBeNull()
        ->and((string) $politique->qual)->toContain('app.current_workspace_id')
        ->and((string) $politique->with_check)->toContain('app.current_workspace_id');

    $colonne = DB::selectOne(
        "SELECT is_nullable FROM information_schema.columns WHERE table_name = 'contacts_provenances_tiers' AND column_name = 'workspace_id'",
    );
    expect($colonne->is_nullable)->toBe('NO')
        ->and(DB::table('contacts_provenances_tiers')->count())->toBe(0);
});

test('le CHECK n accepte que les trois origines du contrat', function () {
    $ws = F::espace('zz-pt-check');
    $fiche = F::fiche($ws, 'ZZ Check');
    $c = F::contact($ws, $fiche, 'Zoe', 'ZZCHECK');
    foreach (ProvenanceTiers::ORIGINES as $i => $origine) {
        ptProvenance($ws, $c, $origine, 5, 'zz-ref-' . $i);
    }
    expect(DB::table('contacts_provenances_tiers')->where('contact_id', $c)->count())->toBe(3);

    expect(fn () => ptProvenance($ws, $c, 'declared', 5, 'zz-ref-x'))->toThrow(QueryException::class);
});

test('une provenance ne désigne pas une personne d un autre espace', function () {
    $a = F::espace('zz-pt-a');
    $b = F::espace('zz-pt-b');
    $cb = F::contact($b, F::fiche($b, 'ZZ B'), 'Zoe', 'ZZB');

    expect(fn () => ptProvenance($a, $cb, 'apporteur', 5, 'zz-ref-b'))->toThrow(QueryException::class);
});

// ── Motif d'exclusion ───────────────────────────────────────────────────────

test('la règle : version inconnue ou < 5 exclut ; 5 et plus ne l exclut pas', function () {
    expect(ProvenanceTiers::informationInsuffisante(null))->toBeTrue()
        ->and(ProvenanceTiers::informationInsuffisante(4))->toBeTrue()
        ->and(ProvenanceTiers::informationInsuffisante(5))->toBeFalse()
        ->and(ProvenanceTiers::informationInsuffisante(6))->toBeFalse();

    $occ = ['status' => null, 'verification' => VerificationEmail::VALIDE, 'perso' => false, 'deja_informe' => false];
    expect(EligibiliteAdresse::motif('zoe@zz-pt.example.invalid', [$occ + ['information_tiers_insuffisante' => true]]))
        ->toBe(EligibiliteAdresse::INFORMATION_TIERS_INSUFFISANTE)
        ->and(EligibiliteAdresse::motif('zoe@zz-pt.example.invalid', [$occ + ['information_tiers_insuffisante' => false]]))
        ->not->toBe(EligibiliteAdresse::INFORMATION_TIERS_INSUFFISANTE)
        ->and(EligibiliteAdresse::MOTIFS)->toContain(EligibiliteAdresse::INFORMATION_TIERS_INSUFFISANTE);
});

test('aperçu d une audience : la personne de provenance tiers mal informée est exclue, les autres partent', function () {
    $this->mock(AuditHashChain::class)->shouldReceive('record')->andReturn(1);
    $ws = F::espace('zz-pt-apercu');
    $cible = F::tag($ws, 'zz-cible-pt');
    $fiche = F::fiche($ws, 'ZZ Atelier', ['legal_form' => '5710']);
    F::lier($ws, $fiche, $cible);

    $personnes = [
        'v3' => 3, 'vnulle' => null, 'v5' => 5, 'sans' => false,
    ];
    foreach ($personnes as $cle => $version) {
        $email = $cle . '@zz-pt-atelier.example.invalid';
        $id = F::contact($ws, $fiche, 'Zoe', 'ZZ' . strtoupper($cle), ['email' => $email, 'metadata' => ptMetaValide($email)]);
        if ($version !== false) {
            ptProvenance($ws, $id, 'apporteur', $version, 'zz-ref-' . $cle);
        }
    }

    $r = app(ResolveurDestinataires::class)->resoudre(
        $ws,
        ['all' => [['field' => 'tags', 'op' => 'contains_any', 'value' => ['zz-cible-pt']]]],
        new ReglageDestinataires(ReglageDestinataires::LES_DEUX),
        null,
    );

    $adresses = collect($r['lignes'])->pluck('email')->sort()->values()->all();
    expect($adresses)->toBe(['sans@zz-pt-atelier.example.invalid', 'v5@zz-pt-atelier.example.invalid'])
        ->and($r['exclues'][EligibiliteAdresse::INFORMATION_TIERS_INSUFFISANTE])->toBe(2);
});

test('liste en fichier : la personne mal informée est comptée dans ecartees_information_tiers', function () {
    $ws = F::espace('zz-pt-liste');
    config(['crm.ingest.business_workspace' => F::slug($ws)]);
    $fiche = F::fiche($ws, 'ZZ Organisateur', ['legal_form' => '5710']);
    F::proteger($ws, $fiche, FichesProtegees::TAG_ORGANISATEURS);
    $mal = F::contact($ws, $fiche, 'Zoe', 'ZZMAL', ['email' => 'mal@zz-pt-orga.example.invalid']);
    $bien = F::contact($ws, $fiche, 'Zia', 'ZZBIEN', ['email' => 'bien@zz-pt-orga.example.invalid']);
    ptProvenance($ws, $mal, 'commercial', 4, 'zz-ref-mal');
    ptProvenance($ws, $bien, 'societe', 5, 'zz-ref-bien');

    ResolveurDnsSimule::toutVerifier();
    $fichier = (string) tempnam(sys_get_temp_dir(), 'zz-pt-');
    try {
        Artisan::call('crm:campagne:destinataires', ['segment' => 'organisateurs-evenements', 'sortie' => $fichier]);
        $sortie = Artisan::output();
        $emails = [];
        foreach (file($fichier, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $ligne) {
            $emails[] = (string) json_decode($ligne, true)['email'];
        }
        sort($emails);
    } finally {
        @unlink($fichier);
    }

    expect($emails)->toBe(['bien@zz-pt-orga.example.invalid'])
        ->and(F::compteur($sortie, 'ecartees_information_tiers'))->toBe(1);
});

// ── opt_out.phone_hash : HMAC à clé ─────────────────────────────────────────

test('opt_out porte phone_hash', function () {
    expect(DB::selectOne(
        "SELECT 1 AS ok FROM information_schema.columns WHERE table_name = 'opt_out' AND column_name = 'phone_hash'",
    ))->not->toBeNull();
});

test('sans clé configurée, aucune empreinte n est calculée', function () {
    config(['crm.provenance_tiers.cle_empreinte_telephone' => '']);
    expect(fn () => EmpreinteTelephone::de('06 00 00 00 00'))->toThrow(RuntimeException::class);

    // Une clé trop courte est refusée de même : ce n'est pas une clé.
    config(['crm.provenance_tiers.cle_empreinte_telephone' => 'court']);
    expect(fn () => EmpreinteTelephone::de('06 00 00 00 00'))->toThrow(RuntimeException::class);
});

test('avec une clé : HMAC-SHA256, jamais un SHA nu, normalisé, dépendant de la clé', function () {
    config(['crm.provenance_tiers.cle_empreinte_telephone' => PT_CLE_TEST]);
    $h = EmpreinteTelephone::de('06 00.00-00 00');

    expect($h)->toBe(hash_hmac('sha256', '0600000000', PT_CLE_TEST))
        ->and($h)->not->toBe(hash('sha256', '0600000000'))
        ->and(EmpreinteTelephone::de('0600000000'))->toBe($h);

    config(['crm.provenance_tiers.cle_empreinte_telephone' => PT_CLE_TEST . '-autre']);
    expect(EmpreinteTelephone::de('0600000000'))->not->toBe($h);
});

test('fonctionnalité activée sans clé : l application refuse de démarrer', function () {
    config(['crm.provenance_tiers.actif' => true, 'crm.provenance_tiers.cle_empreinte_telephone' => '']);
    expect(fn () => (new ProvenanceTiersServiceProvider($this->app))->boot())->toThrow(RuntimeException::class);

    config(['crm.provenance_tiers.cle_empreinte_telephone' => PT_CLE_TEST]);
    (new ProvenanceTiersServiceProvider($this->app))->boot();

    // Désactivée (défaut) : aucune clé exigée, rien ne change pour l'existant.
    config(['crm.provenance_tiers.actif' => false, 'crm.provenance_tiers.cle_empreinte_telephone' => '']);
    (new ProvenanceTiersServiceProvider($this->app))->boot();
    expect(true)->toBeTrue();
});

test('aucune clé en clair dans le dépôt : la configuration la lit dans l environnement, vide par défaut', function () {
    $config = (string) file_get_contents(base_path('config/crm.php'));
    expect($config)->toContain("env('CRM_OPT_OUT_PHONE_HMAC_KEY', '')");

    $exemple = (string) file_get_contents(base_path('../.env.example'));
    expect(preg_match('/^CRM_OPT_OUT_PHONE_HMAC_KEY=\s*$/m', $exemple))->toBe(1);
});

// ── Lecture réservée au rôle owner ──────────────────────────────────────────

function ptUtilisateur(string $ws, string $role): User
{
    $user = User::create([
        'id' => (string) Str::uuid(), 'email' => $role . '-' . Str::random(6) . '@example.invalid', 'name' => 'ZZ ' . $role,
        'password_hash' => Hash::make('PasswordTest12345!'), 'current_workspace_id' => $ws, 'first_login_completed_at' => now(),
    ]);
    setPermissionsTeamId($ws);
    $user->assignRole($role);
    DB::table('user_workspaces')->insertOrIgnore([
        'user_id' => $user->id, 'workspace_id' => $ws, 'role_slug' => $role, 'invited_at' => now(), 'joined_at' => now(),
    ]);

    return $user;
}

test('les JSON servis aux rôles non-owner ne contiennent AUCUNE donnée de provenance tiers ; l owner la lit', function () {
    $this->seed(PermissionsAndRolesSeeder::class);
    $this->mock(AuditHashChain::class)->shouldReceive('record')->andReturn(1);
    config([
        'crm.console_v2' => true, 'crm.ingest.business_workspace' => 'axion-ia',
        'crm.provenance_tiers.cle_empreinte_telephone' => PT_CLE_TEST,
    ]);
    $ws = Workspace::create(['id' => (string) Str::uuid(), 'slug' => 'axion-ia', 'name' => 'ZZ Axion', 'settings' => []])->id;

    $cible = F::tag($ws, 'zz-cible-pt-roles');
    $fiche = F::fiche($ws, 'ZZ Apporte', [
        'legal_form' => '5710',
        'field_origins' => json_encode(['denomination' => 'societe', 'naf' => 'declared']),
    ]);
    F::lier($ws, $fiche, $cible);
    $contact = F::contact($ws, $fiche, 'Zoe', 'ZZAPPORTEE', [
        'email' => 'zoe@zz-apporte.example.invalid',
        'metadata' => ptMetaValide('zoe@zz-apporte.example.invalid'),
        'field_origins' => json_encode(['email' => 'apporteur', 'role' => 'commercial', 'last_name' => 'declared']),
    ]);
    ptProvenance($ws, $contact, 'apporteur', 3, 'zz-ref-opaque-7f3a');
    $empreinte = EmpreinteTelephone::de('0600000000');
    DB::table('opt_out')->insert([
        'phone' => null, 'phone_hash' => $empreinte, 'scope' => 'business', 'source' => 'zz-test', 'created_at' => now(),
    ]);

    $marqueurs = ['apporteur', 'commercial', 'societe', 'zz-ref-opaque-7f3a', 'information_tiers', 'provenances_tiers', 'phone_hash', $empreinte];
    // Routes qui servent la personne ou sa fiche : elles RÉPONDENT (200) —
    // sinon « ne contient rien » serait vrai par vacuité.
    $routes = [
        '/api/v1/contacts',
        "/api/v1/contacts/{$contact}",
        "/api/v1/companies/{$fiche}",
        '/api/v1/crm/contacts-hub',
    ];
    $apercu = [
        'criteria' => ['all' => [['field' => 'tags', 'op' => 'contains_any', 'value' => ['zz-cible-pt-roles']]]],
        'destinataires_mode' => ReglageDestinataires::LES_DEUX,
    ];
    $sansMarqueur = function (string $role, string $route, string $corps) use ($marqueurs): void {
        foreach ($marqueurs as $m) {
            expect(str_contains($corps, $m))->toBeFalse("{$role} {$route} contient « {$m} »");
        }
    };

    foreach (['operator', 'admin', 'viewer'] as $role) {
        $this->actingAs(ptUtilisateur($ws, $role));
        foreach ($routes as $route) {
            $sansMarqueur($role, $route, (string) $this->getJson($route)->assertOk()->getContent());
        }
        $refus = $this->getJson("/api/v1/crm/contacts/{$contact}/provenances-tiers")->assertForbidden();
        $sansMarqueur($role, 'provenances-tiers', (string) $refus->getContent());

        // L'aperçu d'audience (rôles qui y ont droit) : la personne est bien
        // exclue — le total le dit —, mais le motif tiers n'est pas nommé.
        $r = $this->postJson('/api/v1/audiences/apercu-destinataires', $apercu);
        if ($role !== 'viewer') {
            $r->assertOk()->assertJsonPath('data.exclues_total', 1)->assertJsonPath('data.destinataires', 0);
        }
        $sansMarqueur($role, 'apercu-destinataires', (string) $r->getContent());
    }

    // Témoin positif : la fiche n'est PAS vidée pour autant (la provenance
    // déclarée reste lisible), et l'owner, lui, lit la provenance tiers.
    $this->actingAs(ptUtilisateur($ws, 'operator'));
    expect((string) $this->getJson("/api/v1/contacts/{$contact}")->assertOk()->getContent())->toContain('declared');

    $this->actingAs(ptUtilisateur($ws, 'owner'));
    $this->getJson("/api/v1/crm/contacts/{$contact}/provenances-tiers")->assertOk()
        ->assertJsonPath('data.0.origine', 'apporteur')
        ->assertJsonPath('data.0.reference_externe', 'zz-ref-opaque-7f3a')
        ->assertJsonPath('data.0.information_tiers_version', 3)
        ->assertJsonPath('data.0.information_suffisante', false);
    expect((string) $this->getJson("/api/v1/contacts/{$contact}")->assertOk()->getContent())->toContain('apporteur')
        ->and((string) $this->getJson("/api/v1/companies/{$fiche}")->assertOk()->getContent())->toContain('societe');
    $this->postJson('/api/v1/audiences/apercu-destinataires', $apercu)->assertOk()
        ->assertJsonPath('data.exclues.' . EligibiliteAdresse::INFORMATION_TIERS_INSUFFISANTE, 1);
});

// ── N14 réduit : dernier échange à l'initiative de la personne ──────────────

test('formulaire, rendez-vous et réponse alimentent dernier_echange_initiative_at, sans jamais reculer', function () {
    $ws = F::espace('zz-pt-echange');
    $c = F::contact($ws, F::fiche($ws, 'ZZ Echange'), 'Zoe', 'ZZECHANGE');
    $activite = static fn (string $kind, string $quand, ?int $contact = null) => DB::table('activities')->insert([
        'workspace_id' => $ws, 'contact_id' => $contact ?? $c, 'type' => $kind, 'kind' => $kind,
        'occurred_at' => $quand, 'created_at' => now(),
    ]);
    $lu = static fn (): ?string => DB::table('contacts')->where('id', $c)->value('dernier_echange_initiative_at');

    expect($lu())->toBeNull();

    // Un geste QUI N'EST PAS de son initiative ne compte pas.
    $activite('scraped', '2026-09-01 10:00:00+00');
    $activite('press_release_sent', '2026-09-02 10:00:00+00');
    expect($lu())->toBeNull();

    $activite('form_submission', '2026-09-10 10:00:00+00');
    expect(strtotime((string) $lu()))->toBe(strtotime('2026-09-10 10:00:00+00'));

    $activite('calendly_booked', '2026-09-20 10:00:00+00');
    expect(strtotime((string) $lu()))->toBe(strtotime('2026-09-20 10:00:00+00'));

    // Un événement plus ANCIEN, enregistré en retard, ne recule pas la date.
    $activite('press_reply', '2026-08-01 10:00:00+00');
    expect(strtotime((string) $lu()))->toBe(strtotime('2026-09-20 10:00:00+00'));

    $activite('press_reply', '2026-09-25 10:00:00+00');
    expect(strtotime((string) $lu()))->toBe(strtotime('2026-09-25 10:00:00+00'));

    // Rien n'a été supprimé : la timeline garde chaque ligne.
    expect(DB::table('activities')->where('contact_id', $c)->count())->toBe(6);
});

test('un événement rattaché APRÈS coup à la personne compte aussi', function () {
    $ws = F::espace('zz-pt-rattache');
    $c = F::contact($ws, F::fiche($ws, 'ZZ Rattache'), 'Zoe', 'ZZRATTACHE');
    $id = DB::table('activities')->insertGetId([
        'workspace_id' => $ws, 'contact_id' => null, 'type' => 'form_submission', 'kind' => 'form_submission',
        'occurred_at' => '2026-09-15 08:00:00+00', 'created_at' => now(),
    ]);
    DB::table('activities')->where('id', $id)->update(['contact_id' => $c]);

    expect(strtotime((string) DB::table('contacts')->where('id', $c)->value('dernier_echange_initiative_at')))
        ->toBe(strtotime('2026-09-15 08:00:00+00'));
});

test('le déclencheur en base connaît exactement Taxonomy::ACTIVITY_KINDS_INITIATIVE_PERSONNE', function () {
    $def = (string) DB::selectOne("SELECT pg_get_functiondef('public.activites_echange_initiative()'::regprocedure) AS d")->d;
    preg_match('/NOT IN \(([^)]*)\)/', $def, $m);
    preg_match_all("/'([^']+)'/", $m[1] ?? '', $valeurs);
    $attendu = Taxonomy::ACTIVITY_KINDS_INITIATIVE_PERSONNE;
    sort($attendu);
    $lu = $valeurs[1];
    sort($lu);

    expect($lu)->toBe($attendu)
        ->and(array_diff(Taxonomy::ACTIVITY_KINDS_INITIATIVE_PERSONNE, Taxonomy::ACTIVITY_KINDS))->toBe([]);
});
