<?php

/**
 * LISTES MANUELLES ET CHOIX DES DESTINATAIRES — LA PRESSE RESTE FERMÉE
 * (condition de fusion de #266 posée par Will, 2026-09-30).
 *
 * Tant que `Segments::PRESSE` n'est pas dans `Segments::OUVERTS`, aucune fiche
 * de presse (tag `FichesProtegees::TAG_PRESSE`, garde PAR FICHE) ni aucune
 * personne de la presse (journaliste harmonisé `journaliste:<id>` ou source
 * `presse-2026`, garde PAR CONTACT) ne peut entrer dans une liste manuelle,
 * en sortir avec ses adresses, ou devenir destinataire par elle.
 *
 * Chaque test isole UNE garde ajoutée par #266 — retirer cette garde le fait
 * rougir — et porte un TÉMOIN non-presse, sur la même fiche quand c'est
 * possible, qui doit passer. Rien n'est supprimé : les fiches et les
 * journalistes restent intacts.
 *
 * Fixtures FICTIVES (dépôt public).
 */

use App\Crm\Campagnes\ReglageDestinataires as R;
use App\Crm\Campagnes\ResolveurDestinataires;
use App\Crm\Campagnes\Segments;
use App\Crm\Emails\VerificationEmail;
use App\Crm\FichesProtegees;
use App\Crm\Listes\ImportListe;
use App\Crm\Listes\ListesManuelles;
use App\Crm\Presse\QualificationPresse;
use App\Http\Controllers\Api\ListesManuellesController;
use App\Models\ListeManuelle;
use App\Models\User;
use App\Services\Audit\AuditHashChain;
use Database\Seeders\PermissionsAndRolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\Support\DoublonsFixtures as F;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/** La vérification que pose `crm:emails:verifier` pour une adresse VALIDE. */
function lpValide(string $email, array $en_plus = []): array
{
    return $en_plus + [
        'statut' => VerificationEmail::VALIDE, 'motif' => 'mx', 'verifie_par' => VerificationEmail::SOURCE,
        'empreinte' => VerificationEmail::empreinte(mb_strtolower(trim($email))),
    ];
}

function lpOrg(string $ws, string $nom, string $generique): int
{
    return F::fiche($ws, $nom, [
        'email_generic' => $generique,
        'signals' => json_encode(['email_generic_verification' => lpValide($generique, ['type' => 'generique'])]),
    ]);
}

/** @param  array<string, mixed>  $attrs */
function lpPersonne(string $ws, int $org, string $nom, string $email, array $attrs = []): int
{
    return F::contact($ws, $org, 'Zed', $nom, $attrs + [
        'email' => $email, 'email_status' => 'valid',
        'metadata' => json_encode(['email_verification' => lpValide($email)]),
    ]);
}

/** Une ligne d'appartenance écrite AVANT la garde (la fiche est devenue presse après). */
function lpLigne(string $ws, int $liste, ?int $company, ?int $contact): void
{
    DB::table('listes_manuelles_membres')->insert([
        'workspace_id' => $ws, 'liste_id' => $liste, 'company_id' => $company, 'contact_id' => $contact,
        'origine' => ListesManuelles::ORIGINE_COCHE, 'ajoute_le' => now(),
    ]);
}

function lpListe(string $ws, string $nom): ListeManuelle
{
    return ListeManuelle::create(['workspace_id' => $ws, 'nom' => $nom]);
}

/** @return list<int> */
function lpMembres(array $listes): array
{
    $ids = array_map('intval', ListesManuelles::organisationsMembres($listes)->pluck('company_id')->all());
    sort($ids);

    return array_values(array_unique($ids));
}

function lpCompte(string $ws): User
{
    $user = User::create([
        'id' => (string) Str::uuid(), 'email' => 'op-' . Str::random(6) . '@example.invalid', 'name' => 'ZZ op',
        'password_hash' => Hash::make('PasswordTest12345!'), 'current_workspace_id' => $ws, 'first_login_completed_at' => now(),
    ]);
    setPermissionsTeamId($ws);
    $user->assignRole('operator');

    return $user;
}

beforeEach(function () {
    $this->mock(AuditHashChain::class)->shouldReceive('record')->andReturn(1);
    $this->ws = F::espace('zz-listes-presse');
    $ws = $this->ws;

    // Une fiche ORDINAIRE qui porte un journaliste ET un témoin : seule la
    // garde PAR CONTACT distingue les deux.
    $this->salon = lpOrg($ws, 'ZZ Salon', 'accueil@zz-salon.example.invalid');
    $this->journaliste = lpPersonne($ws, $this->salon, 'ZZJOURNALISTE', 'plume@zz-salon.example.invalid', [
        'sources' => json_encode([QualificationPresse::SOURCE]),
    ]);
    $this->temoin = lpPersonne($ws, $this->salon, 'ZZTEMOIN', 'temoin@zz-salon.example.invalid', ['sources' => json_encode(['insee'])]);

    // Une fiche de PRESSE (tag protégé) et une personne NON journaliste
    // rattachée : seule la garde PAR FICHE les écarte.
    $this->journal = lpOrg($ws, 'ZZ Journal', 'redaction@zz-journal.example.invalid');
    F::proteger($ws, $this->journal, FichesProtegees::TAG_PRESSE);
    $this->standard = lpPersonne($ws, $this->journal, 'ZZSTANDARD', 'standard@zz-journal.example.invalid');

    // Une fiche ordinaire dont la SEULE personne cochée est un journaliste
    // harmonisé (`journaliste:<id>`) ; et son témoin, une fiche ordinaire
    // dont la seule personne cochée n'est pas de la presse.
    $this->club = lpOrg($ws, 'ZZ Club', 'bureau@zz-club.example.invalid');
    $this->pigiste = lpPersonne($ws, $this->club, 'ZZPIGISTE', 'pigiste@zz-club.example.invalid', ['external_ref' => 'journaliste:424242']);
    $this->cercle = lpOrg($ws, 'ZZ Cercle', 'bureau@zz-cercle.example.invalid');
    $this->membreCercle = lpPersonne($ws, $this->cercle, 'ZZMEMBRE', 'membre@zz-cercle.example.invalid');
});

// ── À l'entrée : cocher, importer ────────────────────────────────────────────

test('🔴 ajouter : fiche de presse, journaliste et personne d une fiche de presse REFUSÉS et comptés ; le témoin entre', function () {
    $liste = lpListe($this->ws, 'ZZ Invités');
    $fiches = DB::table('companies')->count();
    $personnes = DB::table('contacts')->count();

    $bilan = ListesManuelles::ajouter($liste, [$this->journal, $this->salon], [$this->journaliste, $this->standard, $this->pigiste, $this->temoin], null, 'coche');

    expect($bilan['presse_refusees'])->toBe(4)
        ->and($bilan['ajoutes'])->toBe(2)
        ->and(DB::table('listes_manuelles_membres')->where('liste_id', $liste->id)->whereNotNull('company_id')->pluck('company_id')->map('intval')->all())
        ->toBe([$this->salon])
        ->and(DB::table('listes_manuelles_membres')->where('liste_id', $liste->id)->whereNotNull('contact_id')->pluck('contact_id')->map('intval')->all())
        ->toBe([$this->temoin])
        // Rien n'est supprimé : les fiches et les journalistes restent.
        ->and(DB::table('companies')->count())->toBe($fiches)
        ->and(DB::table('contacts')->count())->toBe($personnes);
});

test('sansPresse : les deux états de la garde (fermée, puis ouverte)', function () {
    [$orgs, $pers] = ListesManuelles::sansPresse([$this->journal, $this->salon], [$this->journaliste, $this->standard, $this->pigiste, $this->temoin]);
    expect($orgs)->toBe([$this->salon])->and($pers)->toBe([$this->temoin]);

    $ouverts = [...Segments::OUVERTS, Segments::PRESSE];
    [$orgs, $pers] = ListesManuelles::sansPresse([$this->journal, $this->salon], [$this->journaliste, $this->temoin], $ouverts);
    expect($orgs)->toBe([$this->journal, $this->salon])->and($pers)->toBe([$this->journaliste, $this->temoin]);
});

test('🔴 import : une ligne rapprochée de la presse est annoncée à blanc et refusée à l import ; le témoin entre', function () {
    $liste = lpListe($this->ws, 'ZZ Import');
    $siren = (string) DB::table('companies')->where('id', $this->journal)->value('siren');
    $csv = implode("\n", ['siren;email', "{$siren};", ';plume@zz-salon.example.invalid', ';temoin@zz-salon.example.invalid']);

    $analyse = ImportListe::analyser($this->ws, $csv);
    expect($analyse['bilan']['presse_refusees'])->toBe(2)
        ->and(DB::table('listes_manuelles_membres')->where('liste_id', $liste->id)->count())->toBe(0);

    $bilan = ImportListe::importer($liste, $csv, null);
    expect($bilan['ajout']['presse_refusees'])->toBe(2)
        ->and($bilan['ajout']['ajoutes'])->toBe(1)
        ->and(DB::table('listes_manuelles_membres')->where('liste_id', $liste->id)->pluck('contact_id')->map('intval')->all())->toBe([$this->temoin]);
});

test('🔴 API : un journaliste seul est REFUSÉ (422, message clair) ; un geste mixte ajoute le témoin et le dit', function () {
    $this->seed(PermissionsAndRolesSeeder::class);
    $this->actingAs(lpCompte($this->ws));
    $liste = (int) $this->postJson('/api/v1/listes-manuelles', ['nom' => 'ZZ API'])->assertCreated()->json('data.id');

    $refus = $this->postJson("/api/v1/listes-manuelles/{$liste}/membres", ['contact_ids' => [$this->journaliste]])
        ->assertStatus(422)->assertJsonPath('data.presse_refusees', 1);
    expect((string) $refus->json('message'))->toContain('segment presse est fermé')->toContain('Rien n\'a été ajouté')
        ->and(DB::table('listes_manuelles_membres')->where('liste_id', $liste)->count())->toBe(0);

    $mixte = $this->postJson("/api/v1/listes-manuelles/{$liste}/membres", ['company_ids' => [$this->journal], 'contact_ids' => [$this->temoin]])
        ->assertOk()->assertJsonPath('data.presse_refusees', 1)->assertJsonPath('data.ajoutes', 1);
    expect($mixte->json('message'))->toBe(ListesManuellesController::MESSAGE_PRESSE)
        ->and(DB::table('listes_manuelles_membres')->where('liste_id', $liste)->pluck('contact_id')->map('intval')->all())->toBe([$this->temoin]);
});

// ── À la lecture : des lignes écrites avant que la fiche ne devienne presse ──

test('🔴 organisationsMembres : une fiche de presse cochée n est membre de rien ; le témoin coché l est', function () {
    $a = lpListe($this->ws, 'ZZ Directe');
    lpLigne($this->ws, $a->id, $this->journal, null);
    lpLigne($this->ws, $a->id, $this->salon, null);

    expect(lpMembres([$a->id]))->toBe([$this->salon])
        ->and(ListesManuelles::organisationEstMembre($this->journal, [$a->id]))->toBeFalse()
        ->and(ListesManuelles::organisationEstMembre($this->salon, [$a->id]))->toBeTrue();
});

test('🔴 organisationsMembres : un journaliste coché ne fait pas entrer son organisation ; une personne ordinaire, si', function () {
    $b = lpListe($this->ws, 'ZZ Personnes');
    lpLigne($this->ws, $b->id, null, $this->pigiste);
    lpLigne($this->ws, $b->id, null, $this->membreCercle);

    expect(lpMembres([$b->id]))->toBe([$this->cercle])
        ->and(ListesManuelles::organisationEstMembre($this->club, [$b->id]))->toBeFalse()
        ->and(ListesManuelles::organisationEstMembre($this->cercle, [$b->id]))->toBeTrue();
});

test('🔴 organisationsMembres : une personne cochée sur une fiche de presse ne la fait pas entrer', function () {
    $c = lpListe($this->ws, 'ZZ Standard');
    lpLigne($this->ws, $c->id, null, $this->standard);
    lpLigne($this->ws, $c->id, null, $this->membreCercle);

    expect(lpMembres([$c->id]))->toBe([$this->cercle]);
});

test('🔴 personnesMembres : le journaliste coché n est pas une personne cochée ; le témoin de la même fiche, si', function () {
    $d = lpListe($this->ws, 'ZZ Cochées');
    lpLigne($this->ws, $d->id, null, $this->journaliste);
    lpLigne($this->ws, $d->id, null, $this->temoin);

    expect(array_keys(ListesManuelles::personnesMembres([$d->id], [$this->salon])))->toBe([$this->temoin]);
});

// ── Les destinataires d'une audience construite sur une liste ────────────────

test('🔴 résolveur : le journaliste d une fiche ordinaire membre de la liste n est JAMAIS destinataire ; le témoin l est', function () {
    $e = lpListe($this->ws, 'ZZ Campagne');
    lpLigne($this->ws, $e->id, $this->salon, null);
    lpLigne($this->ws, $e->id, $this->journal, null);
    $criteres = ['all' => [['field' => 'liste_manuelle', 'op' => 'in', 'value' => [$e->id]]]];

    $r = app(ResolveurDestinataires::class)->resoudre($this->ws, $criteres, new R(R::LES_DEUX), null);
    $adresses = array_map(static fn (array $l): string => $l['email'], $r['lignes']);
    sort($adresses);

    expect($adresses)->toBe(['accueil@zz-salon.example.invalid', 'temoin@zz-salon.example.invalid'])
        ->and($r['organisations'])->toBe(1);
});

test('🔴 résolveur « seulement les personnes cochées » : le journaliste coché ne part pas ; le témoin coché, si', function () {
    $f = lpListe($this->ws, 'ZZ Nommées');
    lpLigne($this->ws, $f->id, null, $this->journaliste);
    lpLigne($this->ws, $f->id, null, $this->temoin);
    $criteres = ['all' => [['field' => 'liste_manuelle', 'op' => 'in', 'value' => [$f->id]]]];

    $r = app(ResolveurDestinataires::class)->resoudre($this->ws, $criteres, new R(R::NOMINATIVES, [], true), null);

    expect(array_map(static fn (array $l): string => $l['email'], $r['lignes']))->toBe(['temoin@zz-salon.example.invalid']);
});

// ── Ce que l'écran d'une liste montre et compte ──────────────────────────────

test('🔴 membres et effectifs d une liste : ni la fiche de presse ni le journaliste, avec leurs adresses ; les témoins, si', function () {
    $this->seed(PermissionsAndRolesSeeder::class);
    $this->actingAs(lpCompte($this->ws));
    $g = lpListe($this->ws, 'ZZ Écran');
    lpLigne($this->ws, $g->id, $this->journal, null);
    lpLigne($this->ws, $g->id, null, $this->journaliste);
    lpLigne($this->ws, $g->id, null, $this->standard);
    lpLigne($this->ws, $g->id, $this->salon, null);
    lpLigne($this->ws, $g->id, null, $this->temoin);

    $membres = $this->getJson("/api/v1/listes-manuelles/{$g->id}/membres")->assertOk()->assertJsonPath('meta.total', 2);
    expect($membres->getContent())->not->toContain('plume@zz-salon')->not->toContain('redaction@zz-journal')->not->toContain('standard@zz-journal')
        ->toContain('temoin@zz-salon')->toContain('ZZ Salon');

    $this->getJson("/api/v1/listes-manuelles/{$g->id}")->assertOk()
        ->assertJsonPath('data.organisations', 1)->assertJsonPath('data.personnes', 1);
    // Les lignes restent en base : rien n'est supprimé.
    expect(DB::table('listes_manuelles_membres')->where('liste_id', $g->id)->count())->toBe(5);
});
