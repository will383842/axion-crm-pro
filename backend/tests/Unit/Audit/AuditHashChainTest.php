<?php

use App\Services\Audit\AuditHashChain;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

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
test('B16-001 — TEMOIN (bis) : sans secret utilisable, la chaine refuse de se declarer valide', function () {
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

/**
 * B16-013 — la colonne `prev_hash` ne doit porter AUCUN défaut SQL.
 *
 * Ce que cette garde inspecte réellement, et rien d'autre : le catalogue
 * (`pg_attrdef`) pour `audit_logs` ET pour chacune de ses partitions. Elle ne
 * dit rien de la valeur écrite par le code — c'est l'affaire du test suivant.
 *
 * Mesure du 2026-08-22 : le défaut valait `repeat('0', 64)`, posé par la
 * migration 2026_08_16_000001 qui devait justement le RETIRER. Un défaut, quelle
 * que soit sa valeur, laisse un INSERT qui omet `prev_hash` s'insérer sans
 * bruit ; `verifyChain()` rejette alors la ligne suivante en criant à la
 * falsification, sans qu'il y en ait eu. Une alerte d'intégrité qui crie au loup
 * finit par n'être plus lue.
 */
test('B16-013 — audit_logs.prev_hash ne porte aucun defaut SQL, ni sur le parent ni sur ses partitions', function () {
    $defauts = DB::select(<<<'SQL'
        SELECT c.relname                          AS table_concernee,
               pg_get_expr(d.adbin, d.adrelid)    AS defaut
        FROM   pg_attrdef  d
        JOIN   pg_class    c ON c.oid = d.adrelid
        JOIN   pg_attribute a ON a.attrelid = d.adrelid AND a.attnum = d.adnum
        WHERE  a.attname = 'prev_hash'
          AND (c.oid = 'audit_logs'::regclass
               OR c.oid IN (SELECT inhrelid FROM pg_inherits WHERE inhparent = 'audit_logs'::regclass))
        ORDER  BY c.relname
    SQL);

    $lisible = implode(', ', array_map(
        fn ($d) => "{$d->table_concernee} => {$d->defaut}",
        $defauts,
    ));

    expect($defauts)->toBe(
        [], // Pest : le message ne doit PAS être un 2e argument de toContain/toHaveKey.
        "B16-013 rouvert : un DEFAULT est revenu sur audit_logs.prev_hash ({$lisible}). "
        . "Un INSERT omettant prev_hash s'insererait alors en silence avec le maillon zero, "
        . 'et verifyChain() crierait a la falsification des la ligne suivante. '
        . 'Geste : ajouter une migration `ALTER TABLE <table> ALTER COLUMN prev_hash DROP DEFAULT` '
        . '(modele : backend/database/migrations/2026_08_22_150000_audit_logs_prev_hash_sans_defaut.php), '
        . "et verifier qu'aucun CREATE TABLE de migration ne repose un DEFAULT sur cette colonne.",
    );
});

// Sentinelle du maillon zéro : 64 zéros hex, PAS la chaîne 'GENESIS'.
// Ce test attendait 'GENESIS' (le DEFAULT SQL de la colonne `prev_hash`), mais
// ce DEFAULT est inatteignable : `AuditHashChain::record()` fournit toujours
// `prev_hash` explicitement, et `verifyChain()` amorce la chaîne sur 64 zéros.
// Les deux sites du service sont d'accord entre eux depuis le premier commit ;
// c'était l'attente du test qui était fausse (et le DEFAULT SQL, retiré depuis
// par B16-013, n'existe plus du tout).
// On garde 64 zéros : même largeur qu'un SHA-256, donc « prev_hash est toujours
// un digest 64-hex » reste vrai — et les hashs déjà en base restent vérifiables.
test('first record uses the 64-zero genesis prev hash', function () {
    $chain = new AuditHashChain;
    $id = $chain->record([
        'workspace_id' => null,
        'user_id' => null,
        'method' => 'GET',
        'path' => '/api/v1/test',
        'status' => 200,
        'ip' => '127.0.0.1',
        'user_agent' => 'test',
        'payload_hash' => hash('sha256', 'payload'),
    ]);
    expect($id)->toBeGreaterThan(0);

    $row = DB::table('audit_logs')->where('id', $id)->first();
    expect($row->prev_hash)->toBe(AuditHashChain::GENESIS_PREV_HASH);
    expect($row->prev_hash)->toBe(str_repeat('0', 64));
    expect($row->current_hash)->toHaveLength(64);
});

test('subsequent record chains to previous hash', function () {
    $chain = new AuditHashChain;
    $a = $chain->record(['method' => 'POST', 'path' => '/a', 'status' => 200, 'ip' => '127.0.0.1', 'payload_hash' => 'x']);
    $b = $chain->record(['method' => 'POST', 'path' => '/b', 'status' => 200, 'ip' => '127.0.0.1', 'payload_hash' => 'y']);

    $rowA = DB::table('audit_logs')->where('id', $a)->first();
    $rowB = DB::table('audit_logs')->where('id', $b)->first();
    expect($rowB->prev_hash)->toBe($rowA->current_hash);
});

test('verifyChain returns true for untampered chain', function () {
    $chain = new AuditHashChain;
    for ($i = 0; $i < 10; $i++) {
        $chain->record(['method' => 'POST', 'path' => "/test/{$i}", 'status' => 200, 'ip' => '127.0.0.1', 'payload_hash' => "h{$i}"]);
    }
    expect($chain->verifyChain())->toBeTrue();
});

test('verifyChain detects tampering', function () {
    $chain = new AuditHashChain;
    $chain->record(['method' => 'POST', 'path' => '/x', 'status' => 200, 'ip' => '127.0.0.1', 'payload_hash' => 'x']);
    $id = $chain->record(['method' => 'POST', 'path' => '/y', 'status' => 200, 'ip' => '127.0.0.1', 'payload_hash' => 'y']);

    // Tamper : modifie le path d'une ligne sans recalculer le hash.
    DB::table('audit_logs')->where('id', $id)->update(['path' => '/TAMPERED']);

    expect($chain->verifyChain())->toBeFalse();
});

// Garde de non-régression du défaut réparé le 2026-08-16 : `record()` hashait la
// forme APPELANT (clé `method`) tandis que `verifyChain()` relisait la forme
// COLONNE (`event_type`). Résultat : le verbe n'entrait pas dans le hash relu et
// `verifyChain()` rendait false sur toute chaîne intacte. Aucun test existant ne
// falsifiait `event_type` — une réparation qui se contenterait de retirer le
// verbe du hash passait donc tous les tests. Celui-ci l'interdit.
test('verifyChain detects tampering on event_type (the HTTP verb)', function () {
    $chain = new AuditHashChain;
    $chain->record(['method' => 'POST', 'path' => '/x', 'status' => 200, 'ip' => '127.0.0.1', 'payload_hash' => 'x']);
    $id = $chain->record(['method' => 'DELETE', 'path' => '/y', 'status' => 200, 'ip' => '127.0.0.1', 'payload_hash' => 'y']);

    // Un DELETE maquillé en GET : le cas d'effacement de traces par excellence.
    DB::table('audit_logs')->where('id', $id)->update(['event_type' => 'GET']);

    expect($chain->verifyChain())->toBeFalse();
});

test('verifyChain detects a rewritten prev_hash link', function () {
    $chain = new AuditHashChain;
    $chain->record(['method' => 'POST', 'path' => '/x', 'status' => 200, 'ip' => '127.0.0.1', 'payload_hash' => 'x']);
    $id = $chain->record(['method' => 'POST', 'path' => '/y', 'status' => 200, 'ip' => '127.0.0.1', 'payload_hash' => 'y']);

    // Casser le maillon déclaré (p. ex. après suppression d'une ligne gênante).
    DB::table('audit_logs')->where('id', $id)->update(['prev_hash' => str_repeat('0', 64)]);

    expect($chain->verifyChain())->toBeFalse();
});
