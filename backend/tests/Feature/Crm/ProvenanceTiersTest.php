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
use App\Models\Company;
use App\Models\EmailAudience;
use App\Models\User;
use App\Models\Workspace;
use App\Providers\ProvenanceTiersServiceProvider;
use App\Services\Audiences\AudienceBuilderService;
use App\Services\Audit\AuditHashChain;
use App\Services\Rgpd\GdprPortabilityService;
use Database\Seeders\DefaultAudiencesSeeder;
use Database\Seeders\PermissionsAndRolesSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
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

/**
 * Une provenance. `$version` : un numéro (écrit au format du contrat Partners,
 * `information-article-14/vN`), un texte brut tel que reçu, ou NULL.
 */
function ptProvenance(string $ws, int $contactId, string $origine, int|string|null $version, string $ref): int
{
    return (int) DB::table('contacts_provenances_tiers')->insertGetId([
        'workspace_id' => $ws, 'contact_id' => $contactId, 'origine' => $origine,
        'reference_externe' => $ref,
        'information_tiers_version' => is_int($version) ? ProvenanceTiers::PREFIXE_VERSION . $version : $version,
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
        ->and(ProvenanceTiers::informationInsuffisante('information-article-14/v4'))->toBeTrue()
        ->and(ProvenanceTiers::informationInsuffisante('information-article-14/v5'))->toBeFalse()
        ->and(ProvenanceTiers::informationInsuffisante('information-article-14/v12'))->toBeFalse()
        // Le texte reçu est stocké tel quel ; un « 5 » d'un AUTRE format ne
        // vaut rien : seul le préfixe du contrat est reconnu.
        ->and(ProvenanceTiers::informationInsuffisante('5'))->toBeTrue()
        ->and(ProvenanceTiers::informationInsuffisante('autre-texte/v5'))->toBeTrue()
        ->and(ProvenanceTiers::informationInsuffisante('information-article-14/v5-bis'))->toBeTrue()
        ->and(ProvenanceTiers::informationInsuffisante(''))->toBeTrue()
        ->and(ProvenanceTiers::numeroVersion('information-article-14/v5'))->toBe(5)
        ->and(ProvenanceTiers::numeroVersion('v5'))->toBeNull();

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
        'v3' => 3, 'vnulle' => null, 'v5' => 5, 'sans' => false, 'autre' => 'autre-texte/v9',
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
        ->and($r['exclues'][EligibiliteAdresse::INFORMATION_TIERS_INSUFFISANTE])->toBe(3);
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
        // exclue (aucun destinataire), mais le motif tiers n'est ni nommé ni
        // RECONSTITUABLE : `exclues_total` moins la somme des autres motifs
        // redonnerait son compteur — il en est donc retiré aussi.
        $r = $this->postJson('/api/v1/audiences/apercu-destinataires', $apercu);
        if ($role !== 'viewer') {
            $r->assertOk()->assertJsonPath('data.exclues_total', 0)->assertJsonPath('data.destinataires', 0);
            $donnees = $r->json('data');
            expect($donnees['exclues_total'])->toBe(array_sum($donnees['exclues']));
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
        ->assertJsonPath('data.0.information_tiers_version', 'information-article-14/v3')
        ->assertJsonPath('data.0.information_tiers_numero', 3)
        ->assertJsonPath('data.0.information_suffisante', false);
    expect((string) $this->getJson("/api/v1/contacts/{$contact}")->assertOk()->getContent())->toContain('apporteur')
        ->and((string) $this->getJson("/api/v1/companies/{$fiche}")->assertOk()->getContent())->toContain('societe');
    $this->postJson('/api/v1/audiences/apercu-destinataires', $apercu)->assertOk()
        ->assertJsonPath('data.exclues.' . EligibiliteAdresse::INFORMATION_TIERS_INSUFFISANTE, 1)
        ->assertJsonPath('data.exclues_total', 1);
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

// ── Réserves des relectures de #312 (03/10/2026) ────────────────────────────
//
// RÈGLE ABSOLUE du propriétaire : on garde tout, on n'efface JAMAIS rien
// automatiquement ; une demande d'effacement = mise à l'écart + décision
// humaine au cas par cas.

test('rien ne supprime une provenance : la suppression d une personne qui en porte une est REFUSÉE, la provenance subsiste', function () {
    $ws = F::espace('zz-pt-restrict');
    $c = F::contact($ws, F::fiche($ws, 'ZZ Restrict'), 'Zoe', 'ZZRESTRICT');
    $p = ptProvenance($ws, $c, 'apporteur', 5, 'zz-ref-restrict');

    // Savepoint : l'erreur ne doit pas avorter la transaction du test.
    expect(fn () => DB::transaction(fn () => DB::table('contacts')->where('id', $c)->delete()))
        ->toThrow(QueryException::class);

    expect(DB::table('contacts')->where('id', $c)->exists())->toBeTrue()
        ->and(DB::table('contacts_provenances_tiers')->where('id', $p)->exists())->toBeTrue();

    // L'espace non plus ne peut pas emporter la provenance.
    expect(fn () => DB::transaction(fn () => DB::table('workspaces')->where('id', $ws)->delete()))
        ->toThrow(QueryException::class);
    expect(DB::table('contacts_provenances_tiers')->where('id', $p)->exists())->toBeTrue();

    // Les deux clés étrangères sont en RESTRICT (`r`), aucune en CASCADE.
    $actions = collect(DB::select(
        "SELECT a.attname AS colonne, c.confdeltype AS action FROM pg_constraint c
           JOIN pg_attribute a ON a.attrelid = c.conrelid AND a.attnum = ANY (c.conkey)
          WHERE c.conrelid = 'public.contacts_provenances_tiers'::regclass AND c.contype = 'f'",
    ))->mapWithKeys(fn ($l) => [$l->colonne => $l->action])->sortKeys()->all();
    expect($actions)->toBe(['contact_id' => 'r', 'workspace_id' => 'r']);
});

test('le rôle applicatif ne peut ni supprimer ni vider une provenance (REVOKE DELETE, TRUNCATE)', function () {
    $role = (string) config('database.connections.pgsql_app.username', 'axion_app');
    if (DB::selectOne('SELECT 1 AS e FROM pg_roles WHERE rolname = ?', [$role]) === null) {
        $this->markTestSkipped("Rôle {$role} absent de cette base.");
    }
    $droit = static fn (string $privilege): bool => (bool) DB::selectOne(
        'SELECT has_table_privilege(?, ?, ?) AS ok',
        [$role, 'public.contacts_provenances_tiers', $privilege],
    )->ok;

    expect($droit('DELETE'))->toBeFalse()
        ->and($droit('TRUNCATE'))->toBeFalse()
        ->and($droit('SELECT'))->toBeTrue()
        ->and($droit('INSERT'))->toBeTrue()
        ->and($droit('UPDATE'))->toBeTrue();
});

test('droit d accès (art. 15) : l export de la personne dit d où viennent ses données', function () {
    Storage::fake('local');
    $ws = F::espace('zz-pt-acces');
    $email = 'zoe.acces@zz-pt.example.invalid';
    $c = F::contact($ws, F::fiche($ws, 'ZZ Acces'), 'Zoe', 'ZZACCES', ['email' => $email]);
    ptProvenance($ws, $c, 'commercial', 5, 'zz-ref-acces');
    // Une autre personne : sa provenance ne sort pas dans cet export.
    $autre = F::contact($ws, F::fiche($ws, 'ZZ Autre'), 'Zia', 'ZZAUTRE', ['email' => 'zia@zz-pt.example.invalid']);
    ptProvenance($ws, $autre, 'societe', 4, 'zz-ref-autre');

    $resultat = app(GdprPortabilityService::class)->export($email);
    $json = Crypt::decryptString(
        (string) Storage::disk('local')->get('gdpr-exports/' . $resultat['token'] . '.enc'),
    );
    $contenu = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

    expect($contenu)->toHaveKey('provenances_tiers')
        ->and($contenu['provenances_tiers'])->toHaveCount(1)
        ->and($contenu['provenances_tiers'][0]['origine'])->toBe('commercial')
        ->and($contenu['provenances_tiers'][0]['information_tiers_version'])->toBe('information-article-14/v5')
        ->and($contenu['provenances_tiers'][0]['recu_le'])->not->toBeNull()
        ->and($json)->not->toContain('zz-ref-autre')
        ->and($json)->not->toContain('societe');
});

test('exports entreprises et personnes : la personne apportée mal informée ne sort pas, les autres sortent', function () {
    $this->seed(PermissionsAndRolesSeeder::class);
    $this->mock(AuditHashChain::class)->shouldReceive('record')->andReturn(1);
    config(['crm.console_v2' => true, 'crm.ingest.business_workspace' => 'axion-ia']);
    $ws = Workspace::create(['id' => (string) Str::uuid(), 'slug' => 'axion-ia', 'name' => 'ZZ Axion', 'settings' => []])->id;

    $fiche = F::fiche($ws, 'ZZ Export', ['legal_form' => '5710']);
    $adresses = ['mal' => 3, 'inconnue' => null, 'bien' => 5, 'sans' => false];
    foreach ($adresses as $cle => $version) {
        $email = $cle . '@zz-pt-export.example.invalid';
        $id = F::contact($ws, $fiche, 'Zoe', 'ZZEXP' . strtoupper($cle), ['email' => $email]);
        DB::table('personnes')->insert([
            'workspace_id' => $ws, 'person_key' => hash('sha256', 'zz-pt|' . $email), 'contact_id' => $id,
            'email' => $email, 'email_hash' => hash('sha256', $email), 'email_nature' => 'pro',
            'premiere_source' => 'newsletter', 'premiere_source_at' => now()->subDays(3),
            'derniere_interaction_at' => now()->subDays(3), 'legal_basis' => 'consent',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        if ($version !== false) {
            ptProvenance($ws, $id, 'apporteur', $version, 'zz-ref-exp-' . $cle);
        }
    }

    $this->actingAs(ptUtilisateur($ws, 'owner'));
    $entreprises = (string) $this->get('/api/v1/companies/export')->assertOk()->streamedContent();
    $personnes = (string) $this->get('/api/v1/crm/personnes/export')->assertOk()->streamedContent();
    // Même avec `inclure_non_prospectables=oui` : ce n'est pas une préférence,
    // c'est une interdiction.
    $toutes = (string) $this->get('/api/v1/crm/personnes/export?inclure_non_prospectables=oui')->assertOk()->streamedContent();

    foreach (['entreprises' => $entreprises, 'personnes' => $personnes, 'toutes' => $toutes] as $nom => $csv) {
        foreach (['bien@zz-pt-export.example.invalid', 'sans@zz-pt-export.example.invalid'] as $sort) {
            expect(str_contains($csv, $sort))->toBeTrue("{$nom} : « {$sort} » doit sortir");
        }
        foreach (['mal@zz-pt-export.example.invalid', 'inconnue@zz-pt-export.example.invalid', 'ZZEXPMAL', 'ZZEXPINCONNUE'] as $reste) {
            expect(str_contains($csv, $reste))->toBeFalse("{$nom} : « {$reste} » ne doit pas sortir");
        }
    }
});

test('retour arrière refusé quand dernier_echange_initiative_at porte des valeurs ; la colonne reste', function () {
    $ws = F::espace('zz-pt-down');
    $c = F::contact($ws, F::fiche($ws, 'ZZ Down'), 'Zoe', 'ZZDOWN');
    DB::table('activities')->insert([
        'workspace_id' => $ws, 'contact_id' => $c, 'type' => 'form_submission', 'kind' => 'form_submission',
        'occurred_at' => '2026-09-10 10:00:00+00', 'created_at' => now(),
    ]);
    expect(DB::table('contacts')->where('id', $c)->value('dernier_echange_initiative_at'))->not->toBeNull()
        ->and(DB::table('contacts_provenances_tiers')->count())->toBe(0);

    $migration = require database_path('migrations/2026_10_03_000080_provenance_tiers.php');
    expect(fn () => DB::transaction(fn () => $migration->down()))
        ->toThrow(RuntimeException::class, 'dernier_echange_initiative_at');

    expect(DB::selectOne(
        "SELECT 1 AS ok FROM information_schema.columns WHERE table_name = 'contacts' AND column_name = 'dernier_echange_initiative_at'",
    ))->not->toBeNull()
        ->and(DB::table('contacts')->where('id', $c)->value('dernier_echange_initiative_at'))->not->toBeNull();
});

test('le garde-fou du retour arrière lit hors RLS : un rôle sans BYPASSRLS échoue au lieu de compter 0', function () {
    $source = (string) file_get_contents(database_path('migrations/2026_10_03_000080_provenance_tiers.php'));
    $down = substr($source, (int) strpos($source, 'public function down()'));

    expect($down)->toContain('SET LOCAL row_security = off')
        ->and(strpos($down, 'SET LOCAL row_security = off'))->toBeLessThan((int) strpos($down, 'count('));
});

test('l alias concaténé dans le SQL est gardé (patron QuarantaineSite::alias)', function () {
    expect(ProvenanceTiers::informationInsuffisanteSql('contacts'))->toContain('contacts.id');
    expect(fn () => ProvenanceTiers::informationInsuffisanteSql('contacts.id); DROP TABLE contacts; --'))
        ->toThrow(InvalidArgumentException::class);
    expect(fn () => ProvenanceTiers::informationInsuffisanteSql('Contacts'))->toThrow(InvalidArgumentException::class);
    expect(fn () => ProvenanceTiers::informationInsuffisanteSql('contacts', 'id OR 1=1'))->toThrow(InvalidArgumentException::class);
});

test('contrat Partners : la version est le TEXTE reçu (32 caractères au plus) ; aucune date d acte n est stockée', function () {
    $colonnes = collect(DB::select(
        "SELECT column_name, data_type, character_maximum_length FROM information_schema.columns WHERE table_name = 'contacts_provenances_tiers'",
    ))->keyBy('column_name');

    expect($colonnes)->not->toHaveKey('information_tiers_at')
        ->and($colonnes['information_tiers_version']->data_type)->toBe('text')
        ->and($colonnes)->toHaveKey('derniere_sequence');

    $ws = F::espace('zz-pt-version');
    $c = F::contact($ws, F::fiche($ws, 'ZZ Version'), 'Zoe', 'ZZVERSION');
    expect(fn () => DB::transaction(fn () => ptProvenance($ws, $c, 'apporteur', str_repeat('v', 33), 'zz-ref-long')))
        ->toThrow(QueryException::class);
});

test('une séquence Partners plus ancienne (ou rejouée) n écrase jamais une provenance plus récente', function () {
    $ws = F::espace('zz-pt-seq');
    $c = F::contact($ws, F::fiche($ws, 'ZZ Seq'), 'Zoe', 'ZZSEQ');
    $p = ptProvenance($ws, $c, 'apporteur', 4, 'zz-ref-seq');
    DB::table('contacts_provenances_tiers')->where('id', $p)->update([
        'information_tiers_version' => 'information-article-14/v5', 'derniere_sequence' => 10,
    ]);

    // Message v4 livré EN RETARD (séquence 7) : ignoré.
    DB::table('contacts_provenances_tiers')->where('id', $p)->update([
        'information_tiers_version' => 'information-article-14/v4', 'derniere_sequence' => 7,
    ]);
    // Rejeu de la même séquence : ignoré aussi.
    DB::table('contacts_provenances_tiers')->where('id', $p)->update([
        'information_tiers_version' => 'information-article-14/v4', 'derniere_sequence' => 10,
    ]);
    // Sans séquence alors qu'une est connue : ignoré.
    DB::table('contacts_provenances_tiers')->where('id', $p)->update([
        'information_tiers_version' => 'information-article-14/v4', 'derniere_sequence' => null,
    ]);
    $l = DB::table('contacts_provenances_tiers')->where('id', $p)->first();
    expect($l->information_tiers_version)->toBe('information-article-14/v5')
        ->and((int) $l->derniere_sequence)->toBe(10);

    // Un message plus récent s'applique.
    DB::table('contacts_provenances_tiers')->where('id', $p)->update([
        'information_tiers_version' => 'information-article-14/v6', 'derniere_sequence' => 11,
    ]);
    expect(DB::table('contacts_provenances_tiers')->where('id', $p)->value('information_tiers_version'))
        ->toBe('information-article-14/v6');
});

test('le déclencheur de la timeline ne s exécute que pour les gestes de la personne (clause WHEN)', function () {
    $def = (string) DB::selectOne(
        "SELECT pg_get_triggerdef(t.oid) AS d FROM pg_trigger t WHERE t.tgname = 'activites_echange_initiative' AND t.tgrelid = 'public.activities'::regclass",
    )->d;
    preg_match('/WHEN \(\(new\.kind = ANY \(ARRAY\[([^\]]*)\]/i', $def, $m);
    preg_match_all("/'([^']+)'/", $m[1] ?? '', $valeurs);
    $lu = $valeurs[1];
    sort($lu);
    $attendu = Taxonomy::ACTIVITY_KINDS_INITIATIVE_PERSONNE;
    sort($attendu);

    expect($lu)->toBe($attendu);
});

test('l index d opposition par empreinte de téléphone est construit à part, CONCURRENTLY, et valide', function () {
    $principale = (string) file_get_contents(database_path('migrations/2026_10_03_000080_provenance_tiers.php'));
    $index = (string) file_get_contents(database_path('migrations/2026_10_03_000081_opt_out_index_phone_hash.php'));

    expect($principale)->not->toContain('CREATE INDEX IF NOT EXISTS idx_opt_out_scope_phone_hash')
        ->and($index)->toContain('CREATE INDEX CONCURRENTLY')
        ->and($index)->toContain('public $withinTransaction = false;');

    $valide = DB::selectOne(
        "SELECT i.indisvalid AS ok FROM pg_index i JOIN pg_class c ON c.oid = i.indexrelid WHERE c.relname = 'idx_opt_out_scope_phone_hash'",
    );
    expect($valide)->not->toBeNull()->and($valide->ok)->toBeTrue();
});

test('l ancien numéro de migration est libéré pour #311 (000070)', function () {
    expect(file_exists(database_path('migrations/2026_10_03_000070_provenance_tiers.php')))->toBeFalse()
        ->and(file_exists(database_path('migrations/2026_10_03_000080_provenance_tiers.php')))->toBeTrue();
});

test('miroirs PHP et SQL de la règle de version : même verdict sur chaque texte', function () {
    $ws = F::espace('zz-pt-miroir');
    $fiche = F::fiche($ws, 'ZZ Miroir');
    $textes = [
        null, '', '5', 'v5', 'autre-texte/v5', 'information-article-14/v', 'information-article-14/v4',
        'information-article-14/v5', 'information-article-14/v05', 'information-article-14/v12',
        'information-article-14/v5-bis', 'INFORMATION-ARTICLE-14/V5', ' information-article-14/v5',
        "information-article-14/v5\n",
    ];
    foreach ($textes as $i => $texte) {
        $c = F::contact($ws, $fiche, 'Zoe', 'ZZMIROIR' . $i);
        if ($texte !== '') {
            ptProvenance($ws, $c, 'apporteur', $texte, 'zz-ref-miroir-' . $i);
        } else {
            // Le CHECK refuse un texte vide : on n'en stocke pas.
            expect(fn () => DB::transaction(fn () => ptProvenance($ws, $c, 'apporteur', '', 'zz-ref-vide')))
                ->toThrow(QueryException::class);

            continue;
        }
        $sql = (bool) DB::selectOne(
            'SELECT ' . ProvenanceTiers::informationInsuffisanteSql('contacts') . ' AS v FROM contacts WHERE contacts.id = ?',
            [$c],
        )->v;
        expect($sql)->toBe(ProvenanceTiers::informationInsuffisante($texte), json_encode($texte));
    }
});

// ── Ordre des motifs (relecture EXACTITUDE de la fusion #311 / #312) ────────
//
// L'ordre EI → `site_non_verifie` → `information_tiers_insuffisante` →
// `invalide` a été fixé à la main pendant la fusion : ces tests le figent.
// Une adresse exclue pour deux raisons est comptée sous la PREMIÈRE ; le
// motif tiers n'étant servi qu'au owner, l'inverser ferait disparaître du
// compteur des autres rôles une adresse en quarantaine.

test('ordre des motifs : EI, puis site non vérifié, puis tiers, tous avant invalide', function () {
    $base = ['status' => null, 'verification' => VerificationEmail::VALIDE, 'perso' => false, 'deja_informe' => false];
    $email = 'zoe@zz-pt-ordre.example.invalid';
    $motif = static fn (array ...$occ): ?string => EligibiliteAdresse::motif($email, array_map(static fn (array $o): array => $o + $base, $occ));

    expect(array_slice(EligibiliteAdresse::MOTIFS, 0, 4))->toBe([
        EligibiliteAdresse::ENTREPRISE_INDIVIDUELLE, EligibiliteAdresse::SITE_NON_VERIFIE,
        EligibiliteAdresse::INFORMATION_TIERS_INSUFFISANTE, EligibiliteAdresse::INVALIDE,
    ])
        // Les trois drapeaux sur la même occurrence, puis répartis sur deux.
        ->and($motif(['entreprise_individuelle' => true, 'site_non_verifie' => true, 'information_tiers_insuffisante' => true]))
        ->toBe(EligibiliteAdresse::ENTREPRISE_INDIVIDUELLE)
        ->and($motif(['information_tiers_insuffisante' => true], ['entreprise_individuelle' => true]))
        ->toBe(EligibiliteAdresse::ENTREPRISE_INDIVIDUELLE)
        // (a) site ET tiers : le site gagne, sur une occurrence ou sur deux.
        ->and($motif(['site_non_verifie' => true, 'information_tiers_insuffisante' => true]))
        ->toBe(EligibiliteAdresse::SITE_NON_VERIFIE)
        ->and($motif(['information_tiers_insuffisante' => true], ['site_non_verifie' => true]))
        ->toBe(EligibiliteAdresse::SITE_NON_VERIFIE)
        // (b) tiers face à `invalide`, sous ses trois formes : le tiers gagne.
        ->and($motif(['information_tiers_insuffisante' => true, 'verification' => VerificationEmail::INVALIDE]))
        ->toBe(EligibiliteAdresse::INFORMATION_TIERS_INSUFFISANTE)
        ->and($motif(['information_tiers_insuffisante' => true], ['status' => 'invalid']))
        ->toBe(EligibiliteAdresse::INFORMATION_TIERS_INSUFFISANTE)
        ->and(EligibiliteAdresse::motif('pas-une-adresse', [$base + ['information_tiers_insuffisante' => true]]))
        ->toBe(EligibiliteAdresse::INFORMATION_TIERS_INSUFFISANTE)
        // Le site, lui aussi, passe avant `invalide`.
        ->and($motif(['site_non_verifie' => true, 'status' => 'disposable']))
        ->toBe(EligibiliteAdresse::SITE_NON_VERIFIE)
        // Témoin : sans drapeau, `invalide` reste dit.
        ->and($motif(['verification' => VerificationEmail::INVALIDE]))->toBe(EligibiliteAdresse::INVALIDE);
});

/**
 * Le jeu de bout en bout : trois personnes, chacune exclue pour DEUX raisons
 * (ou trois), sur des fiches au site deviné non vérifié.
 *
 *  - `ei@…`    fiche d'entrepreneur individuel, adresse du domaine deviné, tiers v3 → EI ;
 *  - `site@…`  adresse du domaine deviné, tiers v3                               → site_non_verifie ;
 *  - `tiers@…` autre domaine (hors quarantaine), tiers v3, `email_status` invalid → tiers.
 *
 * @return array{fiche: int, ei: int}
 */
function ptJeuOrdre(string $ws, array $plus = []): array
{
    $fiches = [];
    foreach (['fiche' => '5710', 'ei' => '1000'] as $cle => $forme) {
        $domaine = 'zz-pt-ordre-' . $cle . '.example.invalid';
        $fiches[$cle] = F::fiche($ws, 'ZZ Ordre ' . $cle, $plus + [
            'legal_form' => $forme, 'website' => 'https://www.' . $domaine . '/', 'website_method' => 'guess',
            'metadata' => '{}', 'email_generic' => null,
        ]);
    }
    $personnes = [
        ['ei', 'ei@zz-pt-ordre-ei.example.invalid', 'valid'],
        ['fiche', 'site@zz-pt-ordre-fiche.example.invalid', 'valid'],
        ['fiche', 'tiers@zz-pt-ailleurs.example.invalid', 'invalid'],
    ];
    foreach ($personnes as $i => [$fiche, $email, $statut]) {
        $id = F::contact($ws, $fiches[$fiche], 'Zoe', 'ZZORDRE' . $i, [
            'email' => $email, 'email_status' => $statut, 'discovery_source' => 'insee', 'metadata' => ptMetaValide($email),
        ]);
        ptProvenance($ws, $id, 'apporteur', 3, 'zz-ref-ordre-' . $i);
    }

    return $fiches;
}

test('ordre des motifs de bout en bout : aperçu d une audience, et exclues_total d un rôle non-owner', function () {
    $this->seed(PermissionsAndRolesSeeder::class);
    $this->mock(AuditHashChain::class)->shouldReceive('record')->andReturn(1);
    config(['crm.console_v2' => true, 'crm.ingest.business_workspace' => 'axion-ia']);
    $ws = Workspace::create(['id' => (string) Str::uuid(), 'slug' => 'axion-ia', 'name' => 'ZZ Axion', 'settings' => []])->id;
    $cible = F::tag($ws, 'zz-cible-pt-ordre');
    foreach (ptJeuOrdre($ws) as $fiche) {
        F::lier($ws, $fiche, $cible);
    }
    $criteres = ['all' => [['field' => 'tags', 'op' => 'contains_any', 'value' => ['zz-cible-pt-ordre']]]];

    $r = app(ResolveurDestinataires::class)->resoudre($ws, $criteres, new ReglageDestinataires(ReglageDestinataires::LES_DEUX), null);
    expect($r['lignes'])->toBe([])
        ->and($r['exclues'][EligibiliteAdresse::ENTREPRISE_INDIVIDUELLE])->toBe(1)
        ->and($r['exclues'][EligibiliteAdresse::SITE_NON_VERIFIE])->toBe(1)
        ->and($r['exclues'][EligibiliteAdresse::INFORMATION_TIERS_INSUFFISANTE])->toBe(1)
        ->and($r['exclues'][EligibiliteAdresse::INVALIDE])->toBe(0);

    // Rôle non-owner : le motif tiers est retiré, mais l'EI et le site restent
    // comptés — exclues_total vaut 2, pas 0 (les deux adresses ne
    // disparaissent pas derrière le motif masqué).
    $apercu = ['criteria' => $criteres, 'destinataires_mode' => ReglageDestinataires::LES_DEUX];
    $this->actingAs(ptUtilisateur($ws, 'operator'));
    $donnees = $this->postJson('/api/v1/audiences/apercu-destinataires', $apercu)->assertOk()
        ->assertJsonPath('data.destinataires', 0)
        ->assertJsonPath('data.exclues_total', 2)
        ->assertJsonPath('data.exclues.' . EligibiliteAdresse::ENTREPRISE_INDIVIDUELLE, 1)
        ->assertJsonPath('data.exclues.' . EligibiliteAdresse::SITE_NON_VERIFIE, 1)
        ->json('data');
    expect($donnees['exclues'])->not->toHaveKey(EligibiliteAdresse::INFORMATION_TIERS_INSUFFISANTE);

    $this->actingAs(ptUtilisateur($ws, 'owner'));
    $this->postJson('/api/v1/audiences/apercu-destinataires', $apercu)->assertOk()
        ->assertJsonPath('data.exclues_total', 3)
        ->assertJsonPath('data.exclues.' . EligibiliteAdresse::INFORMATION_TIERS_INSUFFISANTE, 1);
});

test('ordre des motifs de bout en bout : crm:campagne:destinataires compte chaque adresse sous son premier motif', function () {
    $ws = F::espace('zz-pt-ordre-liste');
    config(['crm.ingest.business_workspace' => F::slug($ws)]);
    foreach (ptJeuOrdre($ws) as $fiche) {
        F::proteger($ws, $fiche, FichesProtegees::TAG_ORGANISATEURS);
    }

    ResolveurDnsSimule::toutVerifier();
    $fichier = (string) tempnam(sys_get_temp_dir(), 'zz-pt-ordre-');
    try {
        Artisan::call('crm:campagne:destinataires', ['segment' => 'organisateurs-evenements', 'sortie' => $fichier]);
        $sortie = Artisan::output();
        $lignes = file($fichier, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
    } finally {
        @unlink($fichier);
    }

    expect($lignes)->toBe([])
        ->and(F::compteur($sortie, 'ecartees_entreprise_individuelle'))->toBe(1)
        ->and(F::compteur($sortie, 'ecartees_site_non_verifie'))->toBe(1)
        ->and(F::compteur($sortie, 'ecartees_information_tiers'))->toBe(1)
        ->and(F::compteur($sortie, 'ecartees_invalides'))->toBe(0);
});

// ── « Joignable » des audiences : même règle que l'envoi ────────────────────
//
// #311 a retiré les adresses en quarantaine du critère `email_hors_quarantaine`.
// Le motif tiers suit le même patron : une personne apportée par un tiers sans
// information suffisante ne rend plus sa fiche « joignable » (SQL du recalcul
// de 04:00 ET miroir en mémoire). Rien n'est réécrit ni supprimé.

test('joignable : une personne apportée mal informée ne rend pas sa fiche joignable ; v5 et sans provenance, si', function () {
    $this->mock(AuditHashChain::class)->shouldReceive('record')->andReturn(1);
    $ws = F::espace('zz-pt-joignable');
    $fiches = [];
    foreach (['mal' => 3, 'inconnue' => null, 'bien' => 5, 'sans' => false, 'mixte' => 3] as $cle => $version) {
        $fiches[$cle] = F::fiche($ws, 'ZZ Joignable ' . $cle, [
            'website' => 'zz-pt-j-' . $cle . '.example.invalid', 'website_method' => 'brave', 'metadata' => '{}',
            'email_generic' => null, 'prospection_status' => 'ready_for_outreach',
        ]);
        $email = $cle . '@zz-pt-ailleurs.example.invalid';
        $id = F::contact($ws, $fiches[$cle], 'Zoe', 'ZZJ' . strtoupper($cle), ['email' => $email, 'email_status' => 'valid']);
        if ($version !== false) {
            ptProvenance($ws, $id, 'commercial', $version, 'zz-ref-j-' . $cle);
        }
    }
    // « mixte » : une AUTRE personne, sans provenance, la rend joignable.
    F::contact($ws, $fiches['mixte'], 'Zia', 'ZZJMIXTE2', ['email' => 'zia@zz-pt-ailleurs.example.invalid', 'email_status' => 'valid']);
    $attendus = [$fiches['bien'], $fiches['sans'], $fiches['mixte']];
    sort($attendus);

    $builder = app(AudienceBuilderService::class);
    $critere = ['all' => [['field' => AudienceBuilderService::CHAMP_EMAIL_HORS_QUARANTAINE, 'op' => 'eq', 'value' => true]]];
    $membres = $builder->buildPublicQuery($ws, $critere)->pluck('id')->map(static fn ($v): int => (int) $v)->sort()->values()->all();
    expect($membres)->toBe($attendus);
    $non = $builder->buildPublicQuery($ws, ['all' => [['field' => AudienceBuilderService::CHAMP_EMAIL_HORS_QUARANTAINE, 'op' => 'eq', 'value' => false]]])
        ->pluck('id')->map(static fn ($v): int => (int) $v)->sort()->values()->all();
    expect($non)->toBe([$fiches['mal'], $fiches['inconnue']]);

    // Le miroir en mémoire (enrichissement, step12) dit la même chose.
    $this->seed(DefaultAudiencesSeeder::class);
    $idJ = (int) EmailAudience::query()->where('workspace_id', $ws)->where('name', 'Prospects contactables')->value('id');
    foreach ($fiches as $cle => $id) {
        $dans = in_array($idJ, $builder->evaluateForCompany(Company::query()->findOrFail($id)), true);
        expect($dans)->toBe(in_array($id, $attendus, true), "miroir en mémoire : {$cle}");
    }

    // Le recalcul de 04:00 : la fiche mal informée n'est pas comptée ; la
    // provenance, elle, est intacte (rien n'est réécrit ni supprimé).
    Artisan::call('audiences:full-refresh');
    $recalcul = DB::table('audience_members')->where('audience_id', $idJ)->pluck('company_id')
        ->map(static fn ($v): int => (int) $v)->unique()->sort()->values()->all();
    expect($recalcul)->toBe($attendus)
        ->and(DB::table('contacts_provenances_tiers')->count())->toBe(4);
});

test('joignable, sous axion_app (RLS) : la sous-requête tiers est servie par son index et n ajoute aucun accès à companies', function () {
    $critere = ['all' => [
        ['field' => AudienceBuilderService::CHAMP_EMAIL_HORS_QUARANTAINE, 'op' => 'eq', 'value' => true],
        ['field' => 'prospection_status', 'op' => 'eq', 'value' => 'ready_for_outreach'],
    ]];
    $ws = (string) Str::uuid();
    $q = app(AudienceBuilderService::class)->buildPublicQuery($ws, $critere);
    expect($q->toSql())->toContain('contacts_provenances_tiers');

    // Le rôle de production, sous la politique RLS de la table : c'est là que
    // le recalcul de 04:00 s'exécute (`RefreshAudienceChunkJob::inWorkspace`).
    // Un EXPLAIN ne lit aucune ligne : l'espace n'a pas besoin d'exister.
    $app = DB::connection('pgsql_app');
    try {
        $role = $app->selectOne('SELECT rolsuper, rolbypassrls FROM pg_roles WHERE rolname = current_user');
        expect($role->rolsuper)->toBeFalse()->and($role->rolbypassrls)->toBeFalse();
        $app->select('SELECT set_config(?, ?, false)', ['app.current_workspace_id', $ws]);
        // Sur une table de test vide, le planificateur libre préfère à bon
        // droit un balayage : on prouve ici que l'index RESTE utilisable sous
        // la politique RLS (ses conditions ne l'empêchent pas). Sur la table
        // pleine, c'est lui que le coût désigne.
        $app->statement('SET enable_seqscan = off');
        $plan = implode("\n", array_map(
            static fn ($l): string => (string) $l->{'QUERY PLAN'},
            $app->select('EXPLAIN ' . $q->toSql(), $q->getBindings()),
        ));
    } finally {
        $app->statement('RESET enable_seqscan');
        $app->select('SELECT set_config(?, ?, false)', ['app.current_workspace_id', '']);
        $app->disconnect();
    }
    expect($plan)->toContain('idx_contacts_provenances_tiers_contact')
        ->and($plan)->not->toContain('Seq Scan on contacts_provenances_tiers')
        // Un seul accès à `companies`, celui des autres critères.
        ->and(preg_match_all('/ on companies\b/', $plan))->toBe(1);
});
