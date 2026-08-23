<?php

use App\Services\Audit\AuditHashChain;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/**
 * 🔴 CE FICHIER A ETE CASSE PAR `46f1717`, ET NON MIS A JOUR PAR LUI.
 *
 * Le correctif B16-001 fait que `verifyChain()` REFUSE de se declarer valide
 * quand le secret n'est pas utilisable. C'est tout l'objet du correctif : une
 * chaine hachee sans secret reste parfaitement coherente avec elle-meme, si
 * bien que la verification repondait `valid: true` sur un journal que
 * n'importe qui pouvait reecrire. Un controle d'integrite qui affirme « tout
 * va bien » sans pouvoir le savoir est pire qu'un controle absent.
 *
 * Le banc de test ne pose aucun `AUDIT_HASH_CHAIN_SECRET` : les quatre tests
 * qui attendaient `verifyChain() === true` sont donc passes au rouge sans avoir
 * ete touches. **Ce rouge est la preuve que le correctif marche.**
 *
 * La pente naturelle serait de relacher `secretEstUtilisable()` pour reverdir.
 * Ce serait rouvrir le defaut. On pose donc un vrai secret au banc -- ce que la
 * production doit avoir de toute facon, et qu'elle a : mesure 64 caracteres.
 *
 * Meme geste que pour `P5-35-006` sur `NotificationsControllerTest` : donner au
 * test ce qui lui manque, jamais affaiblir la garde.
 */
const SECRET_CHAINE_DE_TEST = 'secret-de-banc-pour-la-chaine-d-audit-2026';

beforeEach(function () {
    // `AuditHashChain` lit `config('services.audit.hash_chain_secret')` depuis
    // P5-HMAC-002. La config est resolue a l'amorcage : on la pose ici, apres.
    config(['services.audit.hash_chain_secret' => SECRET_CHAINE_DE_TEST]);
});

/**
 * TEMOIN NEGATIF, et c'est lui qui donne sa valeur aux quatre verts ci-dessus.
 *
 * Sans ce cas, on ne saurait pas si la chaine est verifiee ou si le secret est
 * simplement toujours accepte. Il fige le correctif B16-001 : SANS secret
 * utilisable, la verification ne repond PAS « valide ».
 */
test('B16-001 — TEMOIN : sans secret utilisable, la chaine refuse de se declarer valide', function () {
    config(['services.audit.hash_chain_secret' => '']);

    $chaine = new AuditHashChain;
    $chaine->record(['method' => 'POST', 'path' => '/temoin', 'status' => 200]);

    expect($chaine->verifyChain())->toBeFalse(
        "Sans secret, la chaine se declare valide : c'est exactement le defaut B16-001. "
        . 'Elle est coherente avec elle-meme, et ne prouve rien.',
    );

    // Et la valeur de developpement publiee dans le code source ne vaut pas
    // mieux qu'une absence : quiconque lit le depot peut reforger la chaine.
    config(['services.audit.hash_chain_secret' => AuditHashChain::SECRET_DE_DEVELOPPEMENT]);
    expect((new AuditHashChain)->verifyChain())->toBeFalse(
        'La valeur de developpement est ecrite en clair dans un depot public : '
        . "l'accepter reviendrait a n'avoir aucun secret.",
    );
});

test('AuditHashChain record retourne un id positif', function () {
    $chain = new AuditHashChain;
    $id = $chain->record([
        'workspace_id' => (string) Str::uuid(),
        'method' => 'GET',
        'path' => '/api/v1/companies',
        'status' => 200,
        'ip' => '127.0.0.1',
    ]);
    expect($id)->toBeGreaterThan(0);
});

test('X39-035 — verifyChain retourne FALSE sur chaine vide : un journal sans maillon ne prouve rien', function () {
    // ── CAS INVERSÉ le 2026-08-23 (constat X39-035).
    //
    // Il assurait `toBeTrue()` : une chaine SANS AUCUN MAILLON se declarait
    // valide. Autrement dit, celui qui effacait le journal entier obtenait le
    // meme verdict vert que celui qui n'y avait pas touche — ce qu'une chaine
    // cryptographique existe precisement pour rendre impossible.
    //
    // C'est le meme raisonnement que celui deja tenu dans `AuditHashChain` pour
    // le secret absent : « un controle d'integrite qui dit "tout va bien" sans
    // pouvoir le savoir est pire qu'un controle absent : il endort celui qui le
    // lit. » Un journal vide est exactement ce cas.
    //
    // ⚠️ Sur une installation neuve, c'est vrai aussi, et ce n'est PAS une
    // fausse alerte : tant qu'aucune ligne n'a ete ecrite, la chaine n'a rien a
    // demontrer. `raisonChaineVide()` le dit en toutes lettres pour que l'ecran
    // distingue « journal vide » de « journal falsifie ».
    $chain = new AuditHashChain;

    expect($chain->verifyChain())->toBeFalse();
    expect($chain->chaineEstVide())->toBeTrue();
});

test('AuditHashChain verifyChain retourne true sur 1 record', function () {
    $chain = new AuditHashChain;
    $chain->record(['method' => 'GET', 'path' => '/test', 'status' => 200]);
    expect($chain->verifyChain())->toBeTrue();
});

test('AuditHashChain verifyChain retourne true sur 10 records', function () {
    $chain = new AuditHashChain;
    for ($i = 0; $i < 10; $i++) {
        $chain->record(['method' => 'POST', 'path' => "/test/{$i}", 'status' => 201]);
    }
    expect($chain->verifyChain())->toBeTrue();
});

test('AuditHashChain verifyChain détecte tampering manuel', function () {
    $chain = new AuditHashChain;
    $chain->record(['method' => 'GET', 'path' => '/a', 'status' => 200]);
    $chain->record(['method' => 'GET', 'path' => '/b', 'status' => 200]);

    // Tamper : modifier le status du 1er record
    DB::table('audit_logs')->where('path', '/a')->update(['status_code' => 999]);

    expect($chain->verifyChain())->toBeFalse();
});

test('AuditHashChain canonical respecte l\'ordre des clés', function () {
    $chain = new AuditHashChain;
    $id1 = $chain->record([
        'method' => 'GET',
        'path' => '/x',
        'status' => 200,
        'ip' => '1.2.3.4',
    ]);
    // Le record doit avoir un current_hash non-nul
    $row = DB::table('audit_logs')->find($id1);
    expect($row->current_hash)->not->toBeNull();
    expect(strlen($row->current_hash))->toBe(64);  // sha256 hex
});

test('AuditHashChain enchaine prev_hash correctement', function () {
    $chain = new AuditHashChain;
    $id1 = $chain->record(['method' => 'GET', 'path' => '/a', 'status' => 200]);
    $id2 = $chain->record(['method' => 'GET', 'path' => '/b', 'status' => 200]);

    $row1 = DB::table('audit_logs')->find($id1);
    $row2 = DB::table('audit_logs')->find($id2);

    expect($row2->prev_hash)->toBe($row1->current_hash);
});

test('AuditHashChain premier record a prev_hash = 0*64', function () {
    DB::table('audit_logs')->truncate();
    $chain = new AuditHashChain;
    $id = $chain->record(['method' => 'GET', 'path' => '/first', 'status' => 200]);
    $row = DB::table('audit_logs')->find($id);
    expect($row->prev_hash)->toBe(str_repeat('0', 64));
});
