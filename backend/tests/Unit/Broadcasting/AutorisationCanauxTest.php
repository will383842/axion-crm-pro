<?php

/**
 * 🔴 L'AUTORISATION DES CANAUX PRIVÉS AUTORISAIT TOUT LE MONDE.
 *
 * `routes/channels.php` comparait `(int) $user->current_workspace_id === $workspaceId`.
 * Or ces identifiants sont des UUID : `(int) 'a1b2c3d4-…'` vaut **0**, des deux
 * côtés. L'expression valait donc `0 === 0` — vrai pour n'importe quel
 * workspace et n'importe quel utilisateur.
 *
 * Ces tests n'appellent pas Laravel Broadcast (qui n'enregistre les canaux que
 * si un broadcaster temps réel est actif, ce qui n'est pas le cas ici) : ils
 * vérifient LA COMPARAISON elle-même, sur des UUID réels. C'est précisément ce
 * que le cast cassait.
 *
 * ⚠️ Si quelqu'un retype ces paramètres en `int`, ces tests rougissent.
 */
$autorise = static fn (string $identifiantUtilisateur, string $identifiantDemande): bool => hash_equals($identifiantUtilisateur, $identifiantDemande);

$casse = static fn (string $identifiantUtilisateur, string $identifiantDemande): bool => (int) $identifiantUtilisateur === (int) $identifiantDemande;

test('deux UUID DIFFÉRENTS ne s’autorisent pas', function () use ($autorise) {
    $sien = 'a1b2c3d4-1111-4000-8000-000000000001';
    $autre = 'f9e8d7c6-2222-4000-8000-000000000002';

    expect($autorise($sien, $autre))->toBeFalse();
});

test('un UUID s’autorise lui-même', function () use ($autorise) {
    $sien = 'a1b2c3d4-1111-4000-8000-000000000001';

    expect($autorise($sien, $sien))->toBeTrue();
});

test('LA PREUVE DU DÉFAUT : le cast en entier autorisait deux UUID étrangers', function () use ($casse) {
    $sien = 'a1b2c3d4-1111-4000-8000-000000000001';
    $autre = 'f9e8d7c6-2222-4000-8000-000000000002';

    // Ce test documente le comportement CASSÉ, pour que la nature du défaut
    // reste lisible : deux identifiants sans aucun rapport se valaient.
    expect($casse($sien, $autre))->toBeTrue()
        ->and((int) $sien)->toBe(0)
        ->and((int) $autre)->toBe(0);
});

test('routes/channels.php ne type plus ces paramètres en int', function () {
    // ⚠️ ON RETIRE LES COMMENTAIRES AVANT D'ANALYSER.
    //
    // Première version de ce test : elle cherchait `int $workspaceId` dans le
    // fichier entier — et le TROUVAIT, dans le bloc d'explication qui cite
    // justement l'ancien code cassé. Un test statique qui lit ses propres
    // commentaires ne teste rien : il rougit sur sa propre documentation, et il
    // rougirait tout autant si le code était correct.
    //
    // `token_get_all` donne le code réel, sans commentaires ni docblocks.
    $codeSeul = '';
    foreach (token_get_all(file_get_contents(base_path('routes/channels.php'))) as $jeton) {
        if (is_array($jeton)) {
            if (in_array($jeton[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            $codeSeul .= $jeton[1];
        } else {
            $codeSeul .= $jeton;
        }
    }

    expect($codeSeul)->not->toContain('int $workspaceId')
        ->and($codeSeul)->not->toContain('int $userId')
        ->and($codeSeul)->toContain('hash_equals');
});
