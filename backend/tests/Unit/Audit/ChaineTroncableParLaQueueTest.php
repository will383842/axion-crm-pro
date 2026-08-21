<?php

use App\Services\Audit\AuditHashChain;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/**
 * ══════════════════════════════════════════════════════════════════════════
 * B16-002 et B16-003 (S0, conception) — la chaine d'audit est TRONCABLE PAR
 * LA QUEUE, et son horodatage n'est pas couvert.
 * ══════════════════════════════════════════════════════════════════════════
 *
 * Ce fichier ne repare rien. Il FIGE deux constats qui, jusqu'ici, n'existaient
 * qu'en prose dans un rapport d'audit. Un constat en prose se perd au premier
 * refactoring ; un constat en test rougit le jour ou quelqu'un le corrige, et
 * le jour ou quelqu'un l'aggrave.
 *
 * POURQUOI ON NE CORRIGE PAS ICI. `AuditHashChain` calcule
 *   hash_n = sha256( hash_(n-1) || canonical_json(row_n) || secret )
 * Faire entrer `created_at` dans `canonical()`, ou ajouter un compteur au
 * maillon, change la valeur de TOUS les condenses futurs. Les lignes deja
 * ecrites en production restent hachees a l'ancienne forme : `verifyChain()`
 * les declarerait toutes rompues des le deploiement. Ce n'est pas un correctif
 * de ligne, c'est une migration versionnee de format (voir le rapport de lot
 * pour la piece proposee : une ANCRE externe, qui ne touche pas au hachage).
 *
 * CE QUE CHAQUE TEST PROUVE. Chacun des deux constats est pose en TRIPTYQUE :
 *   1. la chaine intacte se verifie VRAIE  (sinon le banc ne prouve rien) ;
 *   2. une alteration COUVERTE par le hachage se verifie FAUSSE
 *      (sinon `verifyChain()` pourrait ne jamais rien detecter, et un « vrai »
 *      apres l'attaque ne voudrait rien dire) ;
 *   3. l'alteration VISEE par le constat se verifie VRAIE — c'est le defaut.
 * Sans le point 2, le point 3 serait un vert creux.
 */

/**
 * Le banc ne pose aucun `AUDIT_HASH_CHAIN_SECRET` (cf. l'en-tete de
 * `AuditHashChainTest.php`). Sans secret utilisable, `verifyChain()` rend
 * `false` AVANT meme de lire une ligne : tous les cas ci-dessous seraient
 * verts pour la mauvaise raison. On pose donc un vrai secret, comme la
 * production en a un (mesure 64 caracteres par l'agent 40 le 2026-08-20).
 *
 * Nom distinct de `SECRET_CHAINE_DE_TEST` (defini par
 * `AuditHashChainExtendedTest.php`) : deux `const` de meme nom au meme niveau
 * de fichier feraient une erreur fatale des que Pest charge les deux fichiers
 * dans le meme processus.
 */
const SECRET_CHAINE_LOT9 = 'secret-de-banc-lot9-troncature-de-queue-2026';

beforeEach(function () {
    config(['services.audit.hash_chain_secret' => SECRET_CHAINE_LOT9]);
});

/**
 * Ecrit `$n` maillons et rend leurs identifiants, dans l'ordre de la chaine.
 *
 * @return list<int>
 */
function ecrireMaillons(AuditHashChain $chaine, int $n): array
{
    $ids = [];
    for ($i = 0; $i < $n; $i++) {
        $ids[] = $chaine->record([
            'method' => 'POST',
            'path' => "/api/v1/maillon/{$i}",
            'status' => 200,
            'ip' => '127.0.0.1',
            'user_agent' => "agent-{$i}",
            'payload_hash' => hash('sha256', "corps-{$i}"),
        ]);
    }

    return $ids;
}

/**
 * TEMOIN DE BANC — a lire AVANT les deux constats.
 *
 * Il repond a la seule question qui rendrait tout le reste sans valeur :
 * « et si `verifyChain()` rendait VRAI quoi qu'on lui fasse ? » Il verifie
 * donc, dans le meme processus et avec le meme secret :
 *   - qu'une chaine intacte de 5 maillons se declare valide ;
 *   - qu'une alteration d'une colonne COUVERTE (`path`) la casse ;
 *   - que le banc a bien ecrit 5 lignes (une chaine VIDE se verifie vraie
 *     trivialement : un test qui oublierait d'ecrire serait vert pour rien).
 */
test('TEMOIN de banc : verifyChain sait dire vrai sur une chaine intacte, et faux sur une ligne couverte alteree', function () {
    $chaine = new AuditHashChain;
    $ids = ecrireMaillons($chaine, 5);

    // Le banc a-t-il vraiment ecrit ? Zero ligne = verdict « valide » gratuit.
    expect(DB::table('audit_logs')->count())->toBe(5);
    expect($ids)->toHaveCount(5);

    // Le secret pose en `beforeEach` est bien pris en compte : sans lui,
    // `verifyChain()` rendrait faux sans lire une seule ligne.
    expect($chaine->secretEstUtilisable())->toBeTrue();

    expect($chaine->verifyChain())->toBeTrue();

    // `path` entre dans `canonical()` : le toucher DOIT casser la chaine.
    DB::table('audit_logs')->where('id', $ids[2])->update(['path' => '/altere']);
    expect($chaine->verifyChain())->toBeFalse();
});

/**
 * ── B16-002 (S0) ──────────────────────────────────────────────────────────
 * « Supprimer la DERNIERE ligne du journal ne rompt pas la chaine : le journal
 *   n'est pas append-only. »  AuditHashChain.php:159-180 (`verifyChain`).
 *
 * MECANIQUE DU DEFAUT. `verifyChain()` parcourt les lignes par `id` croissant
 * et verifie, pour chacune, que `prev_hash` vaut le `current_hash` de la
 * precedente. C'est une verification de CONTINUITE : elle prouve qu'aucune
 * ligne LUE n'a ete modifiee, et qu'aucune ligne n'a ete retiree AU MILIEU
 * (le chainon suivant pointerait dans le vide). Elle ne prouve RIEN sur la
 * longueur : un prefixe de chaine est une chaine parfaitement valide.
 *
 * CE QUE CELA VAUT EN PRATIQUE. Le scenario d'effacement de traces n'est pas
 * « modifier une ligne genante » — cela, la chaine l'attrape. C'est
 * « supprimer les N dernieres lignes », c'est-a-dire exactement celles qui
 * viennent d'etre ecrites par l'intrus. Un `DELETE FROM audit_logs WHERE id >
 * (SELECT max(id) - N ...)` laisse un journal que `audit:verify-chain` declare
 * « OK — aucune anomalie détectée » a 03:00, et personne n'apprend rien.
 */
test('B16-002 — retirer les dernieres lignes ne rompt PAS la chaine (le journal n est pas append-only)', function () {
    $chaine = new AuditHashChain;
    $ids = ecrireMaillons($chaine, 6);
    expect($chaine->verifyChain())->toBeTrue();

    // ── Contre-epreuve : retirer une ligne AU MILIEU est bien detecte. ─────
    // Elle etablit que la suppression n'est pas invisible en general — seule
    // la queue l'est. Sans elle, le vert du cas suivant pourrait s'expliquer
    // par une verification qui ne regarde rien.
    DB::table('audit_logs')->where('id', $ids[2])->delete();
    expect(DB::table('audit_logs')->count())->toBe(5);
    expect($chaine->verifyChain())->toBeFalse();

    // ── Le constat lui-meme, sur une chaine neuve. ────────────────────────
    DB::table('audit_logs')->delete();
    $chaine = new AuditHashChain;
    $ids = ecrireMaillons($chaine, 6);
    expect(DB::table('audit_logs')->count())->toBe(6);

    // On retire les DEUX dernieres : le geste d'un intrus qui efface sa propre
    // trace, pas celui d'un faussaire qui retouche une ligne ancienne.
    DB::table('audit_logs')->whereIn('id', [$ids[4], $ids[5]])->delete();

    // TEMOIN : la suppression a REELLEMENT eu lieu. Sans ce compte, un
    // `delete()` sans effet (mauvais identifiant, ligne deja absente) rendrait
    // le cas vert en ne prouvant rien du tout.
    expect(DB::table('audit_logs')->count())->toBe(4);

    // 🔴 LE CONSTAT. Mesure le 2026-08-20 sur ce depot : deux lignes retirees
    // sur six, et la chaine se declare VALIDE. Ecrit en positif parce que le
    // defaut n'est pas reparable ici (voir l'en-tete : versionner le format de
    // hachage invaliderait toutes les chaines existantes).
    //
    // ⚠️ SI CE TEST ROUGIT UN JOUR, NE LE « REPARE » PAS EN LE SUPPRIMANT :
    // c'est que quelqu'un a ferme B16-002 (ancre externe, compteur de maillons,
    // ou format versionne). Remplace alors ce cas par son inverse — la
    // troncature DOIT etre detectee — et note le numero du correctif ici.
    expect($chaine->verifyChain())->toBeTrue(
        'B16-002 est ferme : la troncature par la queue est desormais detectee. '
        . 'Inverse ce cas au lieu de le supprimer.',
    );
});

/**
 * Forme extreme du meme defaut, gardee separee parce qu'elle se dit en une
 * phrase et qu'elle est la plus parlante : le journal INTEGRALEMENT efface se
 * verifie valide.
 *
 * `verifyChain()` boucle sur zero ligne et rend `true`. La commande nocturne
 * `audit:verify-chain` ecrit alors « Audit hash chain OK — aucune anomalie
 * détectée » sur une table vide. C'est le cas limite de la troncature par la
 * queue : on a tronque jusqu'a la tete.
 */
test('B16-002 (forme extreme) — un journal integralement efface se declare valide', function () {
    $chaine = new AuditHashChain;
    ecrireMaillons($chaine, 4);
    expect(DB::table('audit_logs')->count())->toBe(4);
    expect($chaine->verifyChain())->toBeTrue();

    DB::table('audit_logs')->delete();
    expect(DB::table('audit_logs')->count())->toBe(0);

    // 🔴 LE CONSTAT, dans sa forme la plus courte a citer : quatre lignes
    // ecrites, zero ligne restante, verdict « valide ».
    expect($chaine->verifyChain())->toBeTrue(
        'B16-002 est ferme : un journal vide ne se declare plus valide. '
        . 'Inverse ce cas au lieu de le supprimer.',
    );
});

/**
 * ── B16-003 (S0) ──────────────────────────────────────────────────────────
 * « L'horodatage n'entre pas dans le hachage : `created_at` est modifiable
 *   sans rompre la chaine. »  AuditHashChain.php:192-206 (`canonical()`).
 *
 * MECANIQUE DU DEFAUT. `canonical()` construit son tableau a partir de SEPT
 * champs : workspace_id, user_id, method (event_type), path, status
 * (status_code), ip, payload_hash. `created_at` n'y figure pas, alors que la
 * colonne existe, qu'elle est ecrite par `record()` (`'created_at' => now()`)
 * et qu'elle est LA colonne sur laquelle repose tout l'usage du journal : le
 * partitionnement mensuel, la retention 24 mois, et la reponse a la seule
 * question qu'on pose a un journal d'audit — « QUAND ? ».
 *
 * CE QUE CELA VAUT EN PRATIQUE. `audit_logs` est partitionnee PAR
 * `created_at` (migration 2026_05_17_000011, retention 24 mois). Antidater une
 * ligne la fait donc physiquement CHANGER DE PARTITION — le test ci-dessous le
 * verifie en relisant la valeur. Une ligne genante datee de 25 mois en arriere
 * tombe hors de la fenetre de retention et disparaitra au prochain passage de
 * pg_partman, sans qu'aucune verification de chaine n'ait rien a redire au
 * moment du geste : elle ne regarde pas l'horodatage.
 *
 * A noter, et non traite ici : la retention supprime une PARTITION ENTIERE,
 * donc un intervalle de dates — ce qui, `id` etant croissant avec le temps,
 * retire un PREFIXE de la chaine. `verifyChain()` amorce toujours sur
 * GENESIS_PREV_HASH ; le premier maillon survivant portera un `prev_hash`
 * inconnu et la chaine se declarera rompue. Autrement dit la retention
 * normale, a elle seule, fera un jour rougir la verification. Voir le rapport
 * de lot : purge et verification de chaine ne se sont jamais parlees.
 */
test('B16-003 — modifier created_at ne rompt PAS la chaine (l horodatage n est pas couvert)', function () {
    $chaine = new AuditHashChain;
    $ids = ecrireMaillons($chaine, 3);
    expect($chaine->verifyChain())->toBeTrue();

    $cible = $ids[1];
    $avant = DB::table('audit_logs')->where('id', $cible)->value('created_at');
    expect($avant)->not->toBeNull();

    // Antidatage de dix ans : le geste qui sort une ligne de la fenetre de
    // retention. Dix ans, et non une heure, pour que le changement soit
    // indiscutable meme si le pilote Postgres rend l'horodatage a la seconde.
    DB::statement(
        "UPDATE audit_logs SET created_at = created_at - INTERVAL '10 years' WHERE id = ?",
        [$cible],
    );

    // TEMOIN : l'ecriture a REELLEMENT pris. `audit_logs` est partitionnee par
    // `created_at` : un UPDATE de la cle de partitionnement peut echouer ou
    // etre refuse selon la version de Postgres et les partitions presentes. Si
    // rien n'avait bouge, le cas serait vert sans avoir rien teste.
    $apres = DB::table('audit_logs')->where('id', $cible)->value('created_at');
    expect((string) $apres)->not->toBe((string) $avant);

    // TEMOIN : la ligne est toujours la (un UPDATE qui l'aurait fait
    // disparaitre ramenerait au cas B16-002, pas a celui-ci).
    expect(DB::table('audit_logs')->count())->toBe(3);

    // 🔴 LE CONSTAT. Une ligne antidatee de dix ans, et la chaine se declare
    // VALIDE. Le journal repond donc « rien n'a bouge » a la question « QUAND
    // cela s'est-il passe ? » alors que la reponse a change de dix ans.
    expect($chaine->verifyChain())->toBeTrue(
        'B16-003 est ferme : created_at entre desormais dans le hachage. '
        . 'Inverse ce cas au lieu de le supprimer, et verifie la migration de format.',
    );
});

/**
 * ── DECOUVERTE HORS LOT, non reparee : `user_agent` non plus n'est pas hache.
 *
 * `record()` ECRIT `user_agent` (AuditHashChain.php:130) et `canonical()` ne
 * le LIT PAS. C'est exactement la meme mecanique que B16-003, sur une autre
 * colonne, et l'audit ne l'a pas nommee. On la fige ici plutot que de la
 * laisser en prose : le jour ou quelqu'un versionnera le format de hachage
 * pour y faire entrer `created_at`, ce cas lui rappellera qu'il y a DEUX
 * colonnes ecrites et non couvertes, pas une.
 *
 * Portee reelle : `user_agent` est la seule trace du CLIENT utilise. Le
 * maquiller ne change pas ce qui a ete fait, mais change a qui on l'attribue.
 * Severite moindre que l'horodatage, mecanique identique.
 */
test('DECOUVERTE (hors lot) — user_agent est ecrit mais pas hache : le modifier ne rompt pas la chaine', function () {
    $chaine = new AuditHashChain;
    $ids = ecrireMaillons($chaine, 3);
    expect($chaine->verifyChain())->toBeTrue();

    $cible = $ids[1];
    expect(DB::table('audit_logs')->where('id', $cible)->value('user_agent'))->toBe('agent-1');

    DB::table('audit_logs')->where('id', $cible)->update(['user_agent' => 'agent-maquille']);

    // TEMOIN : la valeur a bien change en base.
    expect(DB::table('audit_logs')->where('id', $cible)->value('user_agent'))->toBe('agent-maquille');

    // 🔴 LE CONSTAT, hors lot, non repare.
    expect($chaine->verifyChain())->toBeTrue(
        'user_agent entre desormais dans le hachage : bonne nouvelle. '
        . 'Inverse ce cas au lieu de le supprimer.',
    );
});
