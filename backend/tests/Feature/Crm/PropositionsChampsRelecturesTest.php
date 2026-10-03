<?php

/**
 * FILE DE PROPOSITIONS (N13) — CORRECTIFS DES RELECTURES DE #316.
 *
 *  - accepter est un « comparer puis écrire » : l'écran envoie l'EMPREINTE de
 *    ce qu'il a montré ; si la fiche a changé depuis (valeur ou origine du
 *    champ), rien n'est écrit et l'API répond 409 « rechargez » ;
 *  - la valeur RÉELLEMENT remplacée est gardée avec la décision ;
 *  - double clic et décisions concurrentes : une seule gagne ;
 *  - l'audit d'acceptation note l'origine précédente du champ (sans valeur) ;
 *  - une fiche à la corbeille ne s'affiche pas comme une fiche vivante ;
 *  - `role` (destinataires des campagnes) n'est jamais rempli directement ;
 *  - longueurs bornées dès l'entrée ; un seul format de téléphone ;
 *  - le cache de la pastille n'est vidé qu'après le COMMIT de l'appelant ;
 *  - RGPD art. 15/20 : l'export rend les propositions de la personne ;
 *  - RGPD art. 17 : l'effacement les neutralise (`[effacé]`), sans supprimer
 *    aucune ligne, par un chemin unique et borné.
 *
 * Fixtures FICTIVES (dépôt public) : noms « ZZ », `.example.invalid`,
 * numéros en 01 99.
 */

use App\Crm\Propositions\FicheModifiee;
use App\Crm\Propositions\PropositionDejaDecidee;
use App\Crm\Propositions\Propositions;
use App\Http\Controllers\Api\Crm\ATraiterController;
use App\Models\User;
use App\Services\Audit\AuditHashChain;
use App\Services\Rgpd\GdprErasureService;
use App\Services\Rgpd\GdprPortabilityService;
use Database\Seeders\PermissionsAndRolesSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Support\DoublonsFixtures as F;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    config(['crm.console_v2' => true]);
    Cache::flush();
    $this->audits = [];
    $audits = &$this->audits;
    $this->mock(AuditHashChain::class)->shouldReceive('record')->andReturnUsing(function (array $ligne) use (&$audits): int {
        $audits[] = $ligne;

        return count($audits);
    });
});

function prService(): Propositions
{
    return app(Propositions::class);
}

function prOwner(string $ws): User
{
    $user = User::create([
        'id' => (string) Str::uuid(), 'email' => 'pr-owner-' . Str::random(6) . '@example.invalid', 'name' => 'ZZ owner',
        'password_hash' => Hash::make('PasswordTest12345!'), 'current_workspace_id' => $ws, 'first_login_completed_at' => now(),
    ]);
    setPermissionsTeamId($ws);
    $user->assignRole('owner');
    DB::table('user_workspaces')->insertOrIgnore([
        'user_id' => $user->id, 'workspace_id' => $ws, 'role_slug' => 'owner', 'invited_at' => now(), 'joined_at' => now(),
    ]);

    return $user;
}

/** L'empreinte de ce que l'écran montrerait AUJOURD'HUI pour cette proposition. */
function prEmpreinte(int $propositionId): string
{
    $p = DB::table('propositions_champs')->where('id', $propositionId)->first();
    $table = $p->entite === 'entreprise' ? 'companies' : 'contacts';
    $fiche = DB::table($table)->where('id', $p->entite_id)->first();

    return Propositions::empreinte((string) $p->entite, $fiche, (string) $p->champ);
}

function prDerniere(string $ws): int
{
    return (int) DB::table('propositions_champs')->where('workspace_id', $ws)->orderByDesc('id')->value('id');
}

// ── Comparer puis écrire ────────────────────────────────────────────────────

test('accepter après une modification de la fiche : refus, la fiche reste intacte', function () {
    $this->seed(PermissionsAndRolesSeeder::class);
    $ws = F::espace('zz-pr-cas');
    $owner = prOwner($ws);
    $fiche = F::fiche($ws, 'ZZ Cas', ['phone' => '0199000002']);
    prService()->proposer($ws, 'entreprise', $fiche, 'phone', '0199000001', 'apporteur');
    $id = prDerniere($ws);
    $vue = prEmpreinte($id);

    // Un humain corrige la fiche entre l'affichage et le clic.
    DB::table('companies')->where('id', $fiche)->update(['phone' => '0199000003']);

    expect(fn () => prService()->accepter($ws, $id, $owner, $vue))->toThrow(FicheModifiee::class);
    expect(DB::table('companies')->where('id', $fiche)->value('phone'))->toBe('0199000003')
        ->and(DB::table('propositions_champs')->where('id', $id)->value('statut'))->toBe('en_attente')
        ->and($this->audits)->toBe([]);

    // Rechargée, la décision redevient possible — en connaissance de cause.
    prService()->accepter($ws, $id, $owner, prEmpreinte($id));
    expect(DB::table('companies')->where('id', $fiche)->value('phone'))->toBe('0199000001');
});

test('champ devenu DÉCLARÉ après l affichage : refus, même à valeur égale', function () {
    $this->seed(PermissionsAndRolesSeeder::class);
    $ws = F::espace('zz-pr-decl');
    $owner = prOwner($ws);
    $fiche = F::fiche($ws, 'ZZ Decl', ['city' => 'Lyon', 'field_origins' => json_encode(['city' => 'collected'])]);
    prService()->proposer($ws, 'entreprise', $fiche, 'city', 'Bron', 'societe');
    $id = prDerniere($ws);
    $vue = prEmpreinte($id);

    DB::table('companies')->where('id', $fiche)->update(['field_origins' => json_encode(['city' => 'declared'])]);

    expect(fn () => prService()->accepter($ws, $id, $owner, $vue))->toThrow(FicheModifiee::class);
    expect(DB::table('companies')->where('id', $fiche)->value('city'))->toBe('Lyon');
});

test('deux propositions sur le même champ : la seconde ne passe pas en silence', function () {
    $this->seed(PermissionsAndRolesSeeder::class);
    $ws = F::espace('zz-pr-deux');
    $owner = prOwner($ws);
    $fiche = F::fiche($ws, 'ZZ Deux', ['city' => 'Lyon']);
    prService()->proposer($ws, 'entreprise', $fiche, 'city', 'Bron', 'societe');
    $a = prDerniere($ws);
    prService()->proposer($ws, 'entreprise', $fiche, 'city', 'Vienne', 'apporteur');
    $b = prDerniere($ws);
    // Les deux lignes affichées en même temps : même valeur actuelle vue.
    $vueA = prEmpreinte($a);
    $vueB = prEmpreinte($b);

    prService()->accepter($ws, $a, $owner, $vueA);
    expect(fn () => prService()->accepter($ws, $b, $owner, $vueB))->toThrow(FicheModifiee::class);
    expect(DB::table('companies')->where('id', $fiche)->value('city'))->toBe('Bron')
        ->and(DB::table('propositions_champs')->where('id', $b)->value('statut'))->toBe('en_attente');
});

test('double clic : la seconde acceptation est refusée, une seule décision est écrite', function () {
    $this->seed(PermissionsAndRolesSeeder::class);
    $ws = F::espace('zz-pr-clic');
    $owner = prOwner($ws);
    $fiche = F::fiche($ws, 'ZZ Clic', ['city' => 'Lyon']);
    prService()->proposer($ws, 'entreprise', $fiche, 'city', 'Bron', 'societe');
    $id = prDerniere($ws);
    $vue = prEmpreinte($id);

    prService()->accepter($ws, $id, $owner, $vue);
    expect(fn () => prService()->accepter($ws, $id, $owner, $vue))->toThrow(PropositionDejaDecidee::class)
        ->and($this->audits)->toHaveCount(1);
});

test('la valeur réellement REMPLACÉE est gardée avec la décision, pas seulement celle du jour de la proposition', function () {
    $this->seed(PermissionsAndRolesSeeder::class);
    $ws = F::espace('zz-pr-rempl');
    $owner = prOwner($ws);
    $fiche = F::fiche($ws, 'ZZ Remplacee', ['phone' => '0199000002']);
    prService()->proposer($ws, 'entreprise', $fiche, 'phone', '0199000001', 'apporteur');
    $id = prDerniere($ws);
    DB::table('companies')->where('id', $fiche)->update(['phone' => '0199000003']);

    prService()->accepter($ws, $id, $owner, prEmpreinte($id));

    $p = DB::table('propositions_champs')->where('id', $id)->first();
    expect($p->valeur_actuelle)->toBe('0199000002')
        ->and($p->valeur_remplacee)->toBe('0199000003')
        ->and(DB::table('companies')->where('id', $fiche)->value('phone'))->toBe('0199000001');
});

test('un refus ne pose aucune valeur remplacée', function () {
    $this->seed(PermissionsAndRolesSeeder::class);
    $ws = F::espace('zz-pr-refus');
    $owner = prOwner($ws);
    $fiche = F::fiche($ws, 'ZZ Refus', ['city' => 'Lyon']);
    prService()->proposer($ws, 'entreprise', $fiche, 'city', 'Bron', 'societe');
    $id = prDerniere($ws);

    prService()->refuser($ws, $id, $owner);

    expect(DB::table('propositions_champs')->where('id', $id)->value('valeur_remplacee'))->toBeNull()
        ->and(fn () => DB::table('propositions_champs')->insert([
            'workspace_id' => $ws, 'entite' => 'entreprise', 'entite_id' => $fiche, 'champ' => 'city',
            'valeur_proposee' => 'Givors', 'origine' => 'societe', 'valeur_remplacee' => 'Lyon',
        ]))->toThrow(QueryException::class);
});

test('l audit d acceptation note l origine PRÉCÉDENTE du champ, jamais une valeur', function () {
    $this->seed(PermissionsAndRolesSeeder::class);
    $ws = F::espace('zz-pr-orig');
    $owner = prOwner($ws);
    $fiche = F::fiche($ws, 'ZZ Origine', ['phone' => '0199000002', 'field_origins' => json_encode(['phone' => 'declared'])]);
    prService()->proposer($ws, 'entreprise', $fiche, 'phone', '0199000001', 'apporteur');
    $id = prDerniere($ws);

    prService()->accepter($ws, $id, $owner, prEmpreinte($id));

    $trace = json_encode($this->audits[0]);
    expect($this->audits[0]['path'])->toContain('origine précédente : declared')
        ->and($trace)->not->toContain('0199000001')
        ->and($trace)->not->toContain('0199000002');
});

// ── API ─────────────────────────────────────────────────────────────────────

test('API : accepter exige l empreinte affichée, et répond 409 « la fiche a changé » si elle ne tient plus', function () {
    $this->seed(PermissionsAndRolesSeeder::class);
    $ws = F::espace('zz-pr-api');
    $fiche = F::fiche($ws, 'ZZ Api', ['city' => 'Lyon']);
    prService()->proposer($ws, 'entreprise', $fiche, 'city', 'Bron', 'societe');
    $this->actingAs(prOwner($ws));

    $ligne = $this->getJson('/api/v1/crm/propositions')->assertOk()->json('data.0');
    expect($ligne['empreinte'])->toBeString()->not->toBe('')
        ->and($ligne['fiche_modifiee_depuis'])->toBeFalse()
        ->and($ligne['champ_declare'])->toBeFalse()
        ->and($ligne['fiche_supprimee'])->toBeFalse();

    $this->postJson("/api/v1/crm/propositions/{$ligne['id']}/accepter")->assertStatus(422);

    DB::table('companies')->where('id', $fiche)->update(['city' => 'Givors']);
    $this->postJson("/api/v1/crm/propositions/{$ligne['id']}/accepter", ['empreinte' => $ligne['empreinte']])
        ->assertStatus(409)
        ->assertJsonPath('message', 'La fiche a changé depuis l’affichage : rechargez la page avant de décider.');
    expect(DB::table('companies')->where('id', $fiche)->value('city'))->toBe('Givors');

    $relue = $this->getJson('/api/v1/crm/propositions')->assertOk()->json('data.0');
    expect($relue['valeur_actuelle'])->toBe('Givors')
        ->and($relue['fiche_modifiee_depuis'])->toBeTrue();
    $this->postJson("/api/v1/crm/propositions/{$ligne['id']}/accepter", ['empreinte' => $relue['empreinte']])
        ->assertOk()->assertJsonPath('statut', 'acceptee');
    expect(DB::table('companies')->where('id', $fiche)->value('city'))->toBe('Bron');
});

test('API : un champ déclaré par la personne est signalé à l écran', function () {
    $this->seed(PermissionsAndRolesSeeder::class);
    $ws = F::espace('zz-pr-apid');
    $fiche = F::fiche($ws, 'ZZ Api Decl', ['city' => 'Lyon', 'field_origins' => json_encode(['city' => 'declared'])]);
    prService()->proposer($ws, 'entreprise', $fiche, 'city', 'Bron', 'societe');
    $this->actingAs(prOwner($ws));

    expect($this->getJson('/api/v1/crm/propositions')->assertOk()->json('data.0.champ_declare'))->toBeTrue();
});

test('API : une fiche à la corbeille n est pas affichée comme vivante et ne s accepte pas', function () {
    $this->seed(PermissionsAndRolesSeeder::class);
    $ws = F::espace('zz-pr-corb');
    $fiche = F::fiche($ws, 'ZZ Corbeille', ['city' => 'Lyon']);
    prService()->proposer($ws, 'entreprise', $fiche, 'city', 'Bron', 'societe');
    DB::table('companies')->where('id', $fiche)->update(['deleted_at' => now()]);
    $this->actingAs(prOwner($ws));

    $ligne = $this->getJson('/api/v1/crm/propositions')->assertOk()->json('data.0');
    expect($ligne['fiche'])->toBeNull()
        ->and($ligne['fiche_supprimee'])->toBeTrue()
        ->and($ligne['empreinte'])->toBeNull();
    $this->postJson("/api/v1/crm/propositions/{$ligne['id']}/accepter", ['empreinte' => 'x'])->assertStatus(409);
    $this->postJson("/api/v1/crm/propositions/{$ligne['id']}/refuser")->assertOk();
});

// ── Réserves non bloquantes ─────────────────────────────────────────────────

test('le rôle d une personne (destinataires des campagnes) n est jamais rempli directement', function () {
    $ws = F::espace('zz-pr-role');
    $fiche = F::fiche($ws, 'ZZ Role');
    $c = F::contact($ws, $fiche, 'Zoe', 'ZZROLE');

    expect(prService()->proposer($ws, 'personne', $c, 'role', 'Gérante', 'apporteur'))->toBe(Propositions::PROPOSEE)
        ->and(DB::table('contacts')->where('id', $c)->value('role'))->toBeNull();
});

test('une valeur trop longue est refusée dès l entrée, sans rien écrire', function () {
    $ws = F::espace('zz-pr-long');
    $fiche = F::fiche($ws, 'ZZ Long');

    expect(fn () => prService()->proposer($ws, 'entreprise', $fiche, 'address', str_repeat('a', 2001), 'apporteur'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => prService()->proposer($ws, 'entreprise', $fiche, 'phone', str_repeat('1', 41), 'apporteur'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => prService()->proposer($ws, 'entreprise', $fiche, 'postcode', '69000690006', 'apporteur'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => prService()->proposer($ws, 'entreprise', $fiche, 'city', 'Lyon', 'apporteur', str_repeat('r', 201)))->toThrow(InvalidArgumentException::class)
        ->and(DB::table('companies')->where('id', $fiche)->value('address'))->toBeNull()
        ->and(DB::table('propositions_champs')->count())->toBe(0);
});

test('le même numéro écrit en +33 ou en 0, avec ou sans séparateurs, n ouvre rien', function () {
    $ws = F::espace('zz-pr-tel');
    $fiche = F::fiche($ws, 'ZZ Tel', ['phone' => '01 99 00 00 01']);

    expect(prService()->proposer($ws, 'entreprise', $fiche, 'phone', '+33 1 99 00 00 01', 'apporteur'))->toBe(Propositions::IDENTIQUE)
        ->and(prService()->proposer($ws, 'entreprise', $fiche, 'phone', '(01) 99.00.00.01', 'apporteur'))->toBe(Propositions::IDENTIQUE)
        ->and(DB::table('propositions_champs')->count())->toBe(0);
});

test('appelée dans la transaction de l appelant, la file ne vide la pastille qu après son COMMIT', function () {
    $ws = F::espace('zz-pr-cache');
    $fiche = F::fiche($ws, 'ZZ Cache', ['city' => 'Lyon']);
    Cache::put(ATraiterController::cle($ws), ['propositions' => 0], 60);

    DB::transaction(function () use ($ws, $fiche): void {
        prService()->proposer($ws, 'entreprise', $fiche, 'city', 'Bron', 'societe');
        expect(Cache::has(ATraiterController::cle($ws)))->toBeTrue();
    });

    expect(Cache::has(ATraiterController::cle($ws)))->toBeFalse();
});

// ── RGPD ────────────────────────────────────────────────────────────────────

test('RGPD art. 15/20 : l export rend les propositions qui concernent la personne, pas celles des autres', function () {
    Storage::fake('local');
    $this->seed(PermissionsAndRolesSeeder::class);
    $ws = F::espace('zz-pr-port');
    $owner = prOwner($ws);
    $fiche = F::fiche($ws, 'ZZ Port');
    $email = 'zz.port.' . Str::random(6) . '@example.invalid';
    $c = F::contact($ws, $fiche, 'Zoe', 'ZZPORT', ['email' => $email, 'title' => 'Gérante']);
    $autre = F::contact($ws, $fiche, 'Yan', 'ZZAUTRE', ['email' => 'zz.autre@example.invalid', 'title' => 'Comptable']);
    prService()->proposer($ws, 'personne', $c, 'title', 'Présidente', 'apporteur', 'zz-ref-port');
    $id = prDerniere($ws);
    prService()->accepter($ws, $id, $owner, prEmpreinte($id));
    prService()->proposer($ws, 'personne', $c, 'phone', '0199000007', 'societe');
    prService()->proposer($ws, 'personne', $autre, 'title', 'Directeur', 'apporteur');

    $resultat = app(GdprPortabilityService::class)->export($email);
    $contenu = json_decode(Crypt::decryptString(Storage::disk('local')->get('gdpr-exports/' . $resultat['token'] . '.enc')), true);

    $lignes = $contenu['propositions_champs'] ?? null;
    expect($lignes)->toBeArray()->toHaveCount(2);
    $titre = collect($lignes)->firstWhere('champ', 'title');
    expect($titre['valeur_proposee'])->toBe('Présidente')
        ->and($titre['valeur_remplacee'])->toBe('Gérante')
        ->and($titre['origine'])->toBe('apporteur')
        ->and($titre['statut'])->toBe('acceptee')
        ->and($titre)->not->toHaveKey('decidee_par')
        ->and($titre)->not->toHaveKey('reference_externe')
        ->and(json_encode($lignes))->not->toContain('Directeur');
});

test('RGPD art. 17 : l effacement NEUTRALISE les propositions de la personne, sans supprimer une ligne', function () {
    $this->seed(PermissionsAndRolesSeeder::class);
    $ws = F::espace('zz-pr-eff');
    $owner = prOwner($ws);
    $fiche = F::fiche($ws, 'ZZ Eff', ['phone' => '0199000001']);
    $email = 'zz.eff.' . Str::random(6) . '@example.invalid';
    $c = F::contact($ws, $fiche, 'Zoe', 'ZZEFF', ['email' => $email, 'title' => 'Gérante', 'phone' => '0199000008']);
    $autre = F::contact($ws, $fiche, 'Yan', 'ZZGARDE', ['email' => 'zz.garde@example.invalid', 'title' => 'Comptable']);
    prService()->proposer($ws, 'personne', $c, 'title', 'Présidente', 'apporteur', 'zz-ref-eff');
    $acceptee = prDerniere($ws);
    prService()->accepter($ws, $acceptee, $owner, prEmpreinte($acceptee));
    prService()->proposer($ws, 'personne', $c, 'linkedin_url', 'https://zz.example.invalid/zoe', 'societe');
    $attente = prDerniere($ws);
    // Son numéro proposé sur la fiche de l'ENTREPRISE : il part aussi.
    prService()->proposer($ws, 'entreprise', $fiche, 'phone', '+33 1 99 00 00 08', 'commercial');
    $surEntreprise = prDerniere($ws);
    prService()->proposer($ws, 'personne', $autre, 'title', 'Directeur', 'apporteur');
    $garde = prDerniere($ws);
    $avant = DB::table('propositions_champs')->count();

    $bilan = app(GdprErasureService::class)->erase($email);

    expect(DB::table('propositions_champs')->count())->toBe($avant)
        ->and($bilan['deleted']['propositions_champs_neutralisees'])->toBe(3);

    $a = DB::table('propositions_champs')->where('id', $acceptee)->first();
    expect($a->valeur_proposee)->toBe('[effacé]')
        ->and($a->valeur_actuelle)->toBe('[effacé]')
        ->and($a->valeur_remplacee)->toBe('[effacé]')
        ->and($a->reference_externe)->toBe('[effacé]')
        ->and($a->statut)->toBe('acceptee')
        ->and($a->decidee_par)->toBe($owner->id)
        ->and($a->effacee_le)->not->toBeNull();

    $e = DB::table('propositions_champs')->where('id', $attente)->first();
    expect($e->valeur_proposee)->toBe('[effacé]')
        ->and($e->valeur_actuelle)->toBeNull()
        ->and($e->statut)->toBe('effacee')
        ->and($e->decidee_le)->not->toBeNull();

    expect(DB::table('propositions_champs')->where('id', $surEntreprise)->value('valeur_proposee'))->toBe('[effacé]')
        ->and(DB::table('propositions_champs')->where('id', $garde)->value('valeur_proposee'))->toBe('Directeur');

    // Une proposition effacée ne revient pas dans la file.
    $this->actingAs($owner);
    $ids = collect($this->getJson('/api/v1/crm/propositions')->assertOk()->json('data'))->pluck('id')->all();
    expect($ids)->toBe([$garde]);
});

test('RGPD art. 17 : hors de la fonction d effacement, personne ne réécrit ni ne neutralise une proposition', function () {
    $ws = F::espace('zz-pr-borne');
    $fiche = F::fiche($ws, 'ZZ Borne', ['city' => 'Lyon']);
    prService()->proposer($ws, 'entreprise', $fiche, 'city', 'Bron', 'societe');
    $id = prDerniere($ws);

    expect(fn () => DB::table('propositions_champs')->where('id', $id)->update([
        'valeur_proposee' => '[effacé]', 'effacee_le' => now(), 'statut' => 'effacee', 'decidee_le' => now(),
    ]))->toThrow(QueryException::class);
    expect(DB::table('propositions_champs')->where('id', $id)->value('valeur_proposee'))->toBe('Bron');

    // L'effacement passe par la fonction dédiée, et par elle seule.
    $source = file_get_contents(base_path('app/Services/Rgpd/GdprErasureService.php'));
    expect($source)->toContain('propositions_champs_effacer(');
});
