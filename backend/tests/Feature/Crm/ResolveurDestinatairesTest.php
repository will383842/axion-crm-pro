<?php

/**
 * À QUI ÉCRIRE DANS CHAQUE ORGANISATION — `ResolveurDestinataires`
 * (2026-09-30). Fixtures FICTIVES (dépôt public).
 *
 * Jeu semé (REQ-CAM-007, 018, 079, 082) :
 *  - « ZZ Fédé » : générique `bureau@zz-fede` (vérifiée), un Président
 *    (`role`), une Trésorière, une personne dont seul le TITRE dit
 *    « Président » (jamais retenue par la fonction), un canal nominatif
 *    typé, un canal SANS type ;
 *  - la générique partagée `accueil@zz-maison` : `email_generic` de TROIS
 *    fiches (Maison 1, 2, 3) et dans les canaux typés d'une QUATRIÈME
 *    (Maison 4) → UN destinataire, QUATRE organisations ;
 *  - « ZZ Seule » : une générique, aucune personne ;
 *  - des adresses inéligibles, une par motif (opposition, non vérifiée,
 *    personnelle, invalide, domiciliation partagée).
 */

use App\Crm\Campagnes\EligibiliteAdresse;
use App\Crm\Campagnes\ReglageDestinataires as R;
use App\Crm\Campagnes\ResolveurDestinataires;
use App\Crm\Doublons\AdressesPartagees;
use App\Crm\Emails\VerificationEmail;
use App\Crm\Listes\ListesManuelles;
use App\Models\ListeManuelle;
use App\Models\User;
use App\Services\Audit\AuditHashChain;
use App\Support\WorkspaceContext;
use Database\Seeders\PermissionsAndRolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\Support\DoublonsFixtures as F;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/** La fiche de vérification que pose `crm:emails:verifier` pour une adresse VALIDE. */
function rdValide(string $email, array $en_plus = []): array
{
    return $en_plus + [
        'statut' => VerificationEmail::VALIDE, 'motif' => 'mx', 'verifie_par' => VerificationEmail::SOURCE,
        'empreinte' => VerificationEmail::empreinte(mb_strtolower(trim($email))),
    ];
}

function rdOrg(string $ws, string $nom, ?string $generique, array $canaux = [], bool $verifiee = true): int
{
    $signals = [];
    if ($generique !== null && $verifiee) {
        $signals['email_generic_verification'] = rdValide($generique, ['type' => 'generique']);
    }
    if ($canaux !== []) {
        $signals['contact_channels'] = $canaux;
    }

    return F::fiche($ws, $nom, ['email_generic' => $generique, 'signals' => json_encode($signals ?: new stdClass)]);
}

function rdPersonne(string $ws, int $org, string $nom, string $email, array $attrs = [], bool $verifiee = true): int
{
    $meta = $attrs['metadata'] ?? [];
    unset($attrs['metadata']);
    if ($verifiee) {
        $meta['email_verification'] = rdValide($email);
    }

    return F::contact($ws, $org, 'Z', $nom, ['email' => $email, 'metadata' => json_encode($meta ?: new stdClass)] + $attrs);
}

/** @return array<string, mixed> */
function rdResoudre(object $t, R $reglage, ?array $criteres = null): array
{
    return app(ResolveurDestinataires::class)->resoudre($t->ws, $criteres ?? ['all' => [['field' => 'tags', 'op' => 'contains_any', 'value' => ['zz-cible']]]], $reglage, null);
}

/** @return list<string> */
function rdAdresses(array $r): array
{
    $e = array_map(static fn (array $l): string => $l['email'], $r['lignes']);
    sort($e);

    return $e;
}

beforeEach(function () {
    $this->mock(AuditHashChain::class)->shouldReceive('record')->andReturn(1);
    $this->ws = F::espace('zz-destinataires');
    $ws = $this->ws;
    $cible = F::tag($ws, 'zz-cible');

    $this->fede = rdOrg($ws, 'ZZ Fédé', 'bureau@zz-fede.example.invalid', [
        'emails' => ['canal-nom@zz-fede.example.invalid', 'sans-type@zz-fede.example.invalid'],
        'details' => ['canal-nom@zz-fede.example.invalid' => rdValide('canal-nom@zz-fede.example.invalid', ['type' => 'nominatif'])],
    ]);
    $this->president = rdPersonne($ws, $this->fede, 'ZZPRESIDENT', 'president@zz-fede.example.invalid', ['role' => 'Président']);
    $this->tresoriere = rdPersonne($ws, $this->fede, 'ZZTRESORIERE', 'tresoriere@zz-fede.example.invalid', ['role' => 'Trésorière']);
    $this->titre = rdPersonne($ws, $this->fede, 'ZZTITRE', 'titre@zz-fede.example.invalid', ['role' => 'Chargée de mission', 'title' => 'Président']);

    $this->maisons = [];
    foreach ([1, 2, 3] as $n) {
        $this->maisons[] = rdOrg($ws, "ZZ Maison {$n}", 'accueil@zz-maison.example.invalid');
    }
    $this->maisons[] = rdOrg($ws, 'ZZ Maison 4', null, [
        'emails' => ['accueil@zz-maison.example.invalid'],
        'details' => ['accueil@zz-maison.example.invalid' => rdValide('accueil@zz-maison.example.invalid', ['type' => 'generique'])],
    ]);
    $this->seule = rdOrg($ws, 'ZZ Seule', 'contact@zz-seule.example.invalid');

    // Une adresse inéligible par motif.
    $this->ineligibles = rdOrg($ws, 'ZZ Inéligibles', 'non-verifiee@zz-inel.example.invalid', [], false);
    rdPersonne($ws, $this->ineligibles, 'ZZOPPOSE', 'oppose@zz-inel.example.invalid');
    DB::table('opt_out')->insert(['email_hash' => hash('sha256', 'oppose@zz-inel.example.invalid'), 'scope' => 'business', 'source' => 'test', 'created_at' => now()]);
    rdPersonne($ws, $this->ineligibles, 'ZZPERSO', 'zz.perso@gmail.com');
    rdPersonne($ws, $this->ineligibles, 'ZZINVALIDE', 'invalide@zz-inel.example.invalid', ['email_status' => 'invalid']);
    rdPersonne($ws, $this->ineligibles, 'ZZDOMICILIATION', 'domicile@zz-domiciliation.example.invalid');
    WorkspaceContext::run($ws, fn () => AdressesPartagees::inscrire($ws, [[
        'email' => 'domicile@zz-domiciliation.example.invalid', 'domaine' => 'zz-domiciliation.example.invalid', 'nb' => 4, 'nature' => 'domiciliation',
    ]]));

    // Hors cible : jamais retenue.
    $this->horsCible = rdOrg($ws, 'ZZ Hors cible', 'hors@zz-hors.example.invalid');

    foreach (array_merge([$this->fede, $this->seule, $this->ineligibles], $this->maisons) as $id) {
        F::lier($ws, $id, $cible);
    }
});

test('REQ-CAM-082 — une générique de 3 fiches ET du canal d une 4e : UN destinataire, QUATRE organisations', function () {
    $r = rdResoudre($this, new R(R::GENERIQUE));

    $ligne = collect($r['lignes'])->firstWhere('email', 'accueil@zz-maison.example.invalid');
    expect($ligne['nb_organisations'])->toBe(4)
        ->and(collect($ligne['organisations'])->pluck('id')->sort()->values()->all())->toBe($this->maisons)
        ->and(collect($r['lignes'])->where('email', 'accueil@zz-maison.example.invalid')->count())->toBe(1)
        ->and($r['adresses_partagees_entre_organisations'])->toBe(1)
        ->and($r['doublons_evites'])->toBe(3);
});

test('mode GÉNÉRIQUE : seulement les adresses génériques', function () {
    $r = rdResoudre($this, new R(R::GENERIQUE));

    expect(rdAdresses($r))->toBe(['accueil@zz-maison.example.invalid', 'bureau@zz-fede.example.invalid', 'contact@zz-seule.example.invalid'])
        ->and($r['par_type'])->toBe(['generique' => 3, 'nominative' => 0])
        ->and($r['ecartees_par_le_reglage']['personnes_non_demandees'])->toBeGreaterThan(0);
});

test('mode PERSONNES NOMMÉES : contacts et canal nominatif ; jamais le canal sans type', function () {
    $r = rdResoudre($this, new R(R::NOMINATIVES));

    expect(rdAdresses($r))->toBe([
        'canal-nom@zz-fede.example.invalid', 'president@zz-fede.example.invalid',
        'titre@zz-fede.example.invalid', 'tresoriere@zz-fede.example.invalid',
    ])->and($r['ecartees_par_le_reglage']['type_inconnu'])->toBe(1)
        ->and($r['ecartees_par_le_reglage']['generique_non_demandee'])->toBeGreaterThanOrEqual(3);
});

test('mode LES DEUX : l union, chaque adresse une seule fois', function () {
    $r = rdResoudre($this, new R(R::LES_DEUX));

    expect($r['destinataires'])->toBe(7)
        ->and(count(rdAdresses($r)))->toBe(count(array_unique(rdAdresses($r))));
});

test('défaut PERSONNE SINON GÉNÉRIQUE : les personnes de la Fédé, la générique de la Seule', function () {
    $r = rdResoudre($this, new R);

    expect(rdAdresses($r))->toContain('president@zz-fede.example.invalid')->toContain('contact@zz-seule.example.invalid')
        ->not->toContain('bureau@zz-fede.example.invalid')
        ->and($r['ecartees_par_le_reglage']['generique_remplacee_par_une_personne'])->toBe(1);
});

test('REQ-CAM-079 — fonction « président » : seul le Président par `role`, jamais par `title`', function () {
    $r = rdResoudre($this, new R(R::NOMINATIVES, ['président']));

    expect(rdAdresses($r))->toBe(['president@zz-fede.example.invalid'])
        // La trésorière, le « Président » de TITRE, le canal nominatif (sans
        // fonction connue), et les quatre personnes sans fonction de « ZZ
        // Inéligibles » : le filtre passe AVANT le jugement d'éligibilité.
        ->and($r['ecartees_par_le_reglage']['fonction_non_retenue'])->toBe(7);
});

test('« seulement certains contacts » : les personnes COCHÉES dans la liste exigée', function () {
    $liste = ListeManuelle::create(['workspace_id' => $this->ws, 'nom' => 'ZZ Invités']);
    ListesManuelles::ajouter($liste, [], [$this->tresoriere], null, 'coche');
    $criteres = ['all' => [['field' => 'liste_manuelle', 'op' => 'in', 'value' => [$liste->id]]]];

    $r = rdResoudre($this, new R(R::NOMINATIVES, [], true), $criteres);

    expect(rdAdresses($r))->toBe(['tresoriere@zz-fede.example.invalid'])
        ->and($r['organisations'])->toBe(1)
        ->and($r['ecartees_par_le_reglage']['personne_non_cochee'])->toBe(3);
});

test('les exclues, une par motif ; la domiciliation partagée se garde sur option', function () {
    $r = rdResoudre($this, new R(R::LES_DEUX));

    expect($r['exclues'][EligibiliteAdresse::OPPOSITION])->toBe(1)
        ->and($r['exclues'][EligibiliteAdresse::NON_VERIFIEE])->toBe(1)
        ->and($r['exclues'][EligibiliteAdresse::PERSONNELLE])->toBe(1)
        ->and($r['exclues'][EligibiliteAdresse::INVALIDE])->toBe(1)
        ->and($r['exclues'][EligibiliteAdresse::ADRESSE_PARTAGEE])->toBe(1)
        ->and(rdAdresses($r))->not->toContain('oppose@zz-inel.example.invalid')
        ->and($r['organisations_sans_destinataire'])->toBe(1);

    $avec = rdResoudre($this, new R(R::LES_DEUX, [], false, true));
    expect(rdAdresses($avec))->toContain('domicile@zz-domiciliation.example.invalid')
        ->and($avec['exclues'][EligibiliteAdresse::ADRESSE_PARTAGEE])->toBe(0);
});

test('le résolveur n écrit RIEN en base', function () {
    $tables = ['companies', 'contacts', 'opt_out', 'activities', 'listes_manuelles_membres', 'audience_members', 'email_sends'];
    $avant = array_map(static fn (string $t): int => DB::table($t)->count(), $tables);
    $maj = DB::table('companies')->max('updated_at');

    rdResoudre($this, new R(R::LES_DEUX));

    expect(array_map(static fn (string $t): int => DB::table($t)->count(), $tables))->toBe($avant)
        ->and(DB::table('companies')->max('updated_at'))->toBe($maj);
});

// ── L'API ──────────────────────────────────────────────────────────────────

function rdCompte(string $ws, string $role): User
{
    $user = User::create([
        'id' => (string) Str::uuid(), 'email' => $role . '-' . Str::random(6) . '@example.invalid', 'name' => 'ZZ ' . $role,
        'password_hash' => Hash::make('PasswordTest12345!'), 'current_workspace_id' => $ws, 'first_login_completed_at' => now(),
    ]);
    setPermissionsTeamId($ws);
    $user->assignRole($role);

    return $user;
}

test('API — l aperçu d une audience enregistrée : chiffres pour tous, adresses masquées en lecture seule', function () {
    $this->seed(PermissionsAndRolesSeeder::class);
    $audience = (int) DB::table('email_audiences')->insertGetId([
        'workspace_id' => $this->ws, 'name' => 'ZZ Audience',
        'criteria' => json_encode(['all' => [['field' => 'tags', 'op' => 'contains_any', 'value' => ['zz-cible']]]]),
        'destinataires_mode' => R::GENERIQUE,
    ]);

    $this->actingAs(rdCompte($this->ws, 'operator'));
    $clair = $this->getJson("/api/v1/audiences/{$audience}/destinataires")->assertOk();
    $clair->assertJsonPath('data.destinataires', 3)->assertJsonPath('data.reglage.mode', R::GENERIQUE);
    expect($clair->getContent())->toContain('accueil@zz-maison.example.invalid');

    $this->actingAs(rdCompte($this->ws, 'viewer'));
    $masque = $this->getJson("/api/v1/audiences/{$audience}/destinataires")->assertOk();
    $masque->assertJsonPath('data.destinataires', 3);
    expect($masque->getContent())->not->toContain('accueil@zz-maison.example.invalid');
});

test('API — le réglage s enregistre (fonctions comprises) et l aperçu non enregistré le suit', function () {
    $this->seed(PermissionsAndRolesSeeder::class);
    $this->actingAs(rdCompte($this->ws, 'operator'));
    $criteres = ['all' => [['field' => 'tags', 'op' => 'contains_any', 'value' => ['zz-cible']]]];

    $id = (int) $this->postJson('/api/v1/audiences', [
        'name' => 'ZZ Présidents', 'criteria' => $criteres,
        'destinataires_mode' => R::NOMINATIVES, 'destinataires_fonctions' => ['Président', 'Délégué général'],
    ])->assertCreated()->json('data.id');

    $this->getJson("/api/v1/audiences/{$id}")->assertOk()
        ->assertJsonPath('data.destinataires.mode', R::NOMINATIVES)
        ->assertJsonPath('data.destinataires.fonctions', ['Président', 'Délégué général']);
    $this->getJson("/api/v1/audiences/{$id}/destinataires")->assertOk()->assertJsonPath('data.destinataires', 1);

    $this->putJson("/api/v1/audiences/{$id}", ['destinataires_mode' => R::LES_DEUX, 'destinataires_fonctions' => []])->assertOk()
        ->assertJsonPath('data.destinataires.fonctions', []);

    $this->postJson('/api/v1/audiences/apercu-destinataires', ['criteria' => $criteres, 'destinataires_mode' => R::GENERIQUE])
        ->assertOk()->assertJsonPath('data.destinataires', 3);
    $this->postJson('/api/v1/audiences/apercu-destinataires', ['criteria' => $criteres, 'destinataires_mode' => 'tout_le_monde'])
        ->assertStatus(422);
    $this->postJson('/api/v1/audiences/apercu-destinataires', ['criteria' => ['all' => [['field' => 'liste_manuelle', 'op' => 'in', 'value' => [999999]]]]])
        ->assertStatus(422);
});
