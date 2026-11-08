<?php

/**
 * Envoi P0 L1 — le séquenceur ne doit jamais perdre ses garde-fous (lecture de fichiers, aucune base).
 */

use Tests\TestCase;

uses(TestCase::class);

/** @return list<string> */
function seqFichiersSequence(): array
{
    return glob(base_path('app/Envoi/Sequence/*.php')) ?: [];
}

test('ENVOI-L1 les migrations du séquenceur ont des préfixes uniques 000030 à 000039 et n\'effacent aucune donnée', function () {
    $fichiers = glob(base_path('database/migrations/2026_11_10_0000[3][0-9]_*.php')) ?: [];
    expect($fichiers)->not->toBe([]);
    $prefixes = array_map(fn (string $f): string => substr(basename($f), 0, 21), $fichiers);
    expect(array_unique($prefixes))->toBe($prefixes);
    foreach ($fichiers as $f) {
        $src = (string) file_get_contents($f);
        foreach (['DROP TABLE', 'DELETE FROM', 'TRUNCATE', 'DROP COLUMN', 'Schema::drop'] as $interdit) {
            expect(str_contains($src, $interdit))->toBeFalse(basename($f) . " contient {$interdit}");
        }
        expect(str_contains($src, 'FORCE ROW LEVEL SECURITY') || str_contains($src, '$this->securiser') || str_contains($src, 'self::securiser'))->toBeTrue(basename($f) . ' sans RLS');
    }
});

test('ENVOI-L1 le séquenceur n\'ouvre aucune connexion réseau et ne mesure aucune ouverture', function () {
    foreach (seqFichiersSequence() as $f) {
        $code = (string) file_get_contents($f);
        // Les commentaires disent « ni pixel, ni lien traqué » : on les retire avant de chercher.
        $nu = '';
        foreach (token_get_all($code) as $t) {
            $nu .= is_array($t) ? (in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true) ? '' : $t[1]) : $t;
        }
        foreach (['Http::', 'curl_', 'fsockopen', 'stream_socket_client', 'GuzzleHttp', 'file_get_contents(\'http', 'pixel', 'tracking', 'ouvert_le'] as $interdit) {
            expect(stripos($nu, $interdit))->toBeFalse(basename($f) . " contient « {$interdit} »");
        }
    }
});

test('ENVOI-L1 le séquenceur passe par la porte EnvoiAutorise et par la PasserelleLocale (ServeurEnvoi)', function () {
    $src = (string) file_get_contents(base_path('app/Envoi/Sequence/Sequenceur.php'));
    expect($src)->toContain('EnvoiAutorise::verifier(')->and($src)->toContain('ServeurEnvoi $serveur');
});

test('ENVOI-L1 le séquenceur n\'appelle pas l\'extension PHP calendar (absente de l\'image)', function () {
    foreach (seqFichiersSequence() as $f) {
        $code = (string) file_get_contents($f);
        $nu = '';
        foreach (token_get_all($code) as $t) {
            $nu .= is_array($t) ? (in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true) ? '' : $t[1]) : $t;
        }
        foreach (['easter_days', 'easter_date', 'cal_days_in_month', 'jdtogregorian', 'gregoriantojd'] as $interdit) {
            expect(stripos($nu, $interdit))->toBeFalse(basename($f) . " appelle {$interdit}");
        }
    }
});
