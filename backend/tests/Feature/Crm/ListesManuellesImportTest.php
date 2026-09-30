<?php

/**
 * LISTES MANUELLES — importer un fichier par RAPPROCHEMENT (2026-09-30).
 *
 * Une ligne désigne une fiche qui existe déjà (SIREN, identifiant CRM,
 * `crm_ref`, adresse déjà portée par une personne ou une organisation). Une
 * ligne qui ne se rapproche de rien est COMPTÉE et REJETÉE : aucune fiche
 * n'est jamais créée, et une adresse libre n'entre jamais. Le bilan ne rend
 * que des numéros de ligne, jamais les valeurs. Fixtures FICTIVES.
 */

use App\Crm\Listes\ImportListe;
use App\Models\ListeManuelle;
use App\Models\User;
use App\Services\Audit\AuditHashChain;
use Database\Seeders\PermissionsAndRolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\Support\DoublonsFixtures as F;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    $this->seed(PermissionsAndRolesSeeder::class);
    $this->mock(AuditHashChain::class)->shouldReceive('record')->andReturn(1);
    $this->ws = F::espace('zz-listes-import');
    $this->autre = F::espace('zz-listes-import-autre');

    $this->usine = F::fiche($this->ws, 'ZZ Usine', ['siren' => '900000401', 'email_generic' => 'Contact@ZZ-usine.example.invalid']);
    $this->atelier = F::fiche($this->ws, 'ZZ Atelier', ['siren' => '900000402']);
    $this->canal = F::fiche($this->ws, 'ZZ Canal', [
        'siren' => '900000403',
        'signals' => json_encode(['contact_channels' => ['emails' => ['bureau@zz-canal.example.invalid']]]),
    ]);
    $this->directrice = F::contact($this->ws, $this->atelier, 'Zoé', 'ZZDIRECTRICE', ['email' => 'zoe@zz-atelier.example.invalid']);
    // Même SIREN dans un AUTRE espace : jamais rapproché d'ici.
    $this->ailleurs = F::fiche($this->autre, 'ZZ Ailleurs', ['siren' => '900000499']);

    $this->user = User::create([
        'id' => (string) Str::uuid(), 'email' => 'op-' . Str::random(6) . '@example.invalid', 'name' => 'ZZ op',
        'password_hash' => Hash::make('PasswordTest12345!'), 'current_workspace_id' => $this->ws, 'first_login_completed_at' => now(),
    ]);
    setPermissionsTeamId($this->ws);
    $this->user->assignRole('operator');
    $this->actingAs($this->user);

    $this->liste = (int) $this->postJson('/api/v1/listes-manuelles', ['nom' => 'Invités salon ZZ'])->assertCreated()->json('data.id');
});

test('CSV à en-tête : SIREN, adresse de personne, adresse générique, canal — et les rejets comptés par motif', function () {
    $fiches = DB::table('companies')->count();
    $csv = implode("\n", [
        'nom;siren;email',
        'Usine;900 000 401;',                          // SIREN (espaces tolérés) → ZZ Usine
        'Directrice;;ZOE@zz-atelier.example.invalid',  // adresse d'une PERSONNE → la personne
        'Canal;;bureau@zz-canal.example.invalid',      // adresse d'un canal → l'organisation
        'Libre;;inconnue@zz-libre.example.invalid',    // adresse ABSENTE du CRM → rejetée
        'Ailleurs;900000499;',                         // SIREN d'un autre espace → rejeté
        'Illisible;12;pas-une-adresse',                // ni SIREN ni adresse → format inconnu
        'Usine bis;900000401;',                        // la même clé deux fois
    ]);

    $r = $this->postJson("/api/v1/listes-manuelles/{$this->liste}/import", ['contenu' => $csv])->assertOk();

    $r->assertJsonPath('data.lignes_lues', 7)
        ->assertJsonPath('data.rapprochees', 3)
        ->assertJsonPath('data.rejetees.introuvable', 2)
        ->assertJsonPath('data.rejetees.format_inconnu', 1)
        ->assertJsonPath('data.doublons_dans_le_fichier', 1)
        ->assertJsonPath('data.par_type.siren', 1)
        ->assertJsonPath('data.par_type.email_personne', 1)
        ->assertJsonPath('data.par_type.email_organisation', 1)
        ->assertJsonPath('data.ajout.ajoutes', 3)
        ->assertJsonPath('data.exemples_rejets.0.ligne', 5)
        ->assertJsonPath('data.exemples_rejets.0.motif', ImportListe::INTROUVABLE);

    // Le bilan ne cite JAMAIS une valeur du fichier.
    expect($r->getContent())->not->toContain('inconnue@zz-libre')->not->toContain('900000499');

    $membres = DB::table('listes_manuelles_membres')->where('liste_id', $this->liste)->get();
    expect($membres->pluck('company_id')->filter()->sort()->values()->all())->toBe([$this->usine, $this->canal])
        ->and($membres->pluck('contact_id')->filter()->values()->all())->toBe([$this->directrice])
        ->and($membres->pluck('origine')->unique()->values()->all())->toBe(['import'])
        // AUCUNE fiche créée, même pour l'adresse inconnue.
        ->and(DB::table('companies')->count())->toBe($fiches);
});

test('l adresse générique d une organisation se rapproche, quelle que soit sa casse', function () {
    $this->postJson("/api/v1/listes-manuelles/{$this->liste}/import", ['contenu' => "contact@zz-usine.example.invalid\n"])
        ->assertOk()->assertJsonPath('data.rapprochees', 1)->assertJsonPath('data.par_type.email_organisation', 1);

    expect(DB::table('listes_manuelles_membres')->where('liste_id', $this->liste)->value('company_id'))->toBe($this->usine);
});

test('JSONL : la sortie de crm:campagne:destinataires (crm_ref) se ré-importe', function () {
    $jsonl = implode("\n", [
        json_encode(['crm_ref' => 'organisation:' . $this->usine, 'email' => 'contact@zz-usine.example.invalid']),
        json_encode(['crm_ref' => 'contact:' . $this->directrice, 'email' => 'zoe@zz-atelier.example.invalid']),
        json_encode(['crm_ref' => 'organisation:' . $this->ailleurs]),
        '{pas du json',
    ]);

    $this->postJson("/api/v1/listes-manuelles/{$this->liste}/import", ['contenu' => $jsonl])
        ->assertOk()
        ->assertJsonPath('data.rapprochees', 2)
        ->assertJsonPath('data.rejetees.introuvable', 1)
        ->assertJsonPath('data.rejetees.format_inconnu', 1);
});

test('fichier sans en-tête (un SIREN par ligne), envoyé comme fichier', function () {
    $fichier = UploadedFile::fake()->createWithContent('invites.csv', "900000401\n900000402\n900000403\n");

    $this->post("/api/v1/listes-manuelles/{$this->liste}/import", ['fichier' => $fichier], ['Accept' => 'application/json'])
        ->assertOk()->assertJsonPath('data.rapprochees', 3)->assertJsonPath('data.ajout.ajoutes', 3);
});

test('À BLANC : le bilan, et rien n est écrit', function () {
    $this->postJson("/api/v1/listes-manuelles/{$this->liste}/import", ['contenu' => "siren\n900000401\n900000402", 'a_blanc' => true])
        ->assertOk()->assertJsonPath('data.a_blanc', true)->assertJsonPath('data.rapprochees', 2)
        ->assertJsonMissingPath('data.ajout');

    expect(DB::table('listes_manuelles_membres')->where('liste_id', $this->liste)->count())->toBe(0);
});

test('un fichier vide ou trop long est refusé en clair', function () {
    $this->postJson("/api/v1/listes-manuelles/{$this->liste}/import", ['contenu' => "\n \n"])->assertStatus(422);

    expect(fn () => ImportListe::analyser($this->ws, str_repeat("900000401\n", ImportListe::LIGNES_MAX + 2)))
        ->toThrow(InvalidArgumentException::class, 'trop long');
});

test('un identifiant CRM seul désigne une organisation ; une colonne contact_id, une personne', function () {
    $csv = "company_id,contact_id\n{$this->usine},\n,{$this->directrice}\n";

    $this->postJson("/api/v1/listes-manuelles/{$this->liste}/import", ['contenu' => $csv])
        ->assertOk()->assertJsonPath('data.rapprochees', 2);

    $liste = ListeManuelle::findOrFail($this->liste);
    expect(DB::table('listes_manuelles_membres')->where('liste_id', $liste->id)->whereNotNull('contact_id')->value('contact_id'))
        ->toBe($this->directrice);
});
