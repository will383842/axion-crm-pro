<?php

/**
 * NR-16 — R9 et plan 22 § 5, l’instantané des drapeaux.
 *
 * Chaque drapeau `CRM_*` et `MOCK_*` LU PAR `config/*.php` garde la valeur par
 * défaut qu’il a aujourd’hui (photo `instantanes/nr16-drapeaux.json`), et
 * TOUT NOUVEAU drapeau booléen doit être ÉTEINT par défaut : un lot qui peut
 * changer la production part derrière un drapeau éteint (règle du plan 22 § 5).
 *
 * La lecture est STATIQUE (on lit le texte des fichiers de config, jamais la
 * valeur posée sur un serveur : le filet ne lit aucun secret).
 */

use Tests\Feature\NonRegression\Instantane;
use Tests\TestCase;

uses(TestCase::class);

/**
 * Lit « env('NOM', défaut) » avec parenthèses imbriquées.
 *
 * @return array<string, string> nom => défaut tel qu’écrit
 */
function nr16Drapeaux(): array
{
    $drapeaux = [];
    foreach (glob(base_path('config/*.php')) ?: [] as $fichier) {
        // Sans commentaires : une doc qui cite un drapeau n’est pas une lecture.
        $code = '';
        foreach (token_get_all((string) file_get_contents($fichier)) as $t) {
            if (is_array($t) && in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            $code .= is_array($t) ? $t[1] : $t;
        }
        $offset = 0;
        while (preg_match("/env\(\s*'((?:CRM|MOCK)_[A-Z0-9_]+)'\s*(,)?/", $code, $m, PREG_OFFSET_CAPTURE, $offset)) {
            $nom = $m[1][0];
            $debut = $m[0][1] + strlen($m[0][0]);
            $offset = $debut;
            if (($m[2][0] ?? '') !== ',') {
                $drapeaux[$nom] = '(aucun défaut)';

                continue;
            }
            // Avance jusqu’à la parenthèse fermante qui équilibre env(.
            $profondeur = 1;
            $fin = $debut;
            for ($n = strlen($code); $fin < $n && $profondeur > 0; $fin++) {
                if ($code[$fin] === '(') {
                    $profondeur++;
                } elseif ($code[$fin] === ')') {
                    $profondeur--;
                }
            }
            $drapeaux[$nom] = trim(preg_replace('/\s+/', ' ', substr($code, $debut, $fin - $debut - 1)) ?? '');
        }
    }
    ksort($drapeaux);

    return $drapeaux;
}

test('NR-16 les drapeaux CRM_* et MOCK_* ont les valeurs par défaut de la photo de référence', function () {
    Instantane::verifier('nr16-drapeaux', nr16Drapeaux());
});

test('NR-16 tout drapeau booléen lu par la config est ÉTEINT par défaut (sauf les allumés connus)', function () {
    // Les drapeaux qui valent `true` par défaut AUJOURD’HUI, tous documentés :
    // les simulateurs suivent l’environnement (vrai hors production) ; la
    // validation MX de la collecte est un comportement existant, pas un nouveau lot.
    $allumesConnus = ['CRM_SCRAPE_VALIDATE_MX', 'MOCK_MAIL', 'MOCK_MODE', 'MOCK_SCRAPERS'];

    $allumes = array_keys(array_filter(
        nr16Drapeaux(),
        fn (string $defaut): bool => str_contains($defaut, 'true'),
    ));
    sort($allumes);

    expect($allumes)->toBe($allumesConnus);
});

test('NR-16 les drapeaux de lots à venir qui existent déjà sont éteints', function () {
    $d = nr16Drapeaux();

    // Ceux que le plan 22 § 5 déclare « existe » : on garantit qu’ils sont éteints.
    foreach (['CRM_DB_APP_ROLE_ENABLED', 'CRM_OUTBOUND_ENABLED', 'CRM_INGEST_ENABLED', 'CRM_PURGE_ENABLED', 'CRM_SYNC_DOSSIER_CLIENT_STAGE'] as $nom) {
        expect($d[$nom] ?? null)->toBe('false');
    }
    expect($d['CRM_PARTNERS_MODE'] ?? null)->toBe("'off'");
});

test('NR-16 la lecture sait voir des drapeaux (témoin de couverture)', function () {
    expect(count(nr16Drapeaux()))->toBeGreaterThan(30);
});
