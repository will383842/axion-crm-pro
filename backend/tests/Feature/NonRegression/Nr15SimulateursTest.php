<?php

/**
 * NR-15 — R9, les simulateurs `MOCK_*`.
 *
 * Gardes existantes rattachées au groupe : `AucunSimulacreEnProductionTest`
 * (les 14 liaisons du provider sont refusées en production),
 * `DrapeauxMockAlignesTest`, `SimulacresLusHorsDeLaGardeTest`.
 *
 * Ce fichier ajoute l’INVENTAIRE : tout `MOCK_*` lu par le code est listé, avec
 * la mention « gardé » (passe par `MockServicesProvider`, qui le REFUSE en
 * production) ou « hors garde ». Un NOUVEAU simulateur (par exemple le serveur
 * d’envoi de E1) fait rougir ce test tant qu’il n’est pas déclaré dans la photo
 * `instantanes/nr15-simulateurs.json` ET, s’il simule un service, dans la garde.
 *
 * ⚠️ CONSTAT (plan 21 § 4.1, à corriger par le lot S2) : `MOCK_MAIL`, `MOCK_IGN`
 * et `MOCK_RPPS` sont lus HORS de la garde du provider. Le filet ne les corrige
 * pas : il les marque « hors_garde » dans la photo, de façon visible.
 */

use Tests\Feature\NonRegression\Instantane;
use Tests\TestCase;

uses(TestCase::class);

/** @return list<string> les fichiers PHP où un drapeau MOCK_* peut être lu */
function nr15Fichiers(): array
{
    $racines = [base_path('app'), base_path('config'), base_path('bootstrap'), base_path('routes')];
    $fichiers = [];
    foreach ($racines as $racine) {
        if (! is_dir($racine)) {
            continue;
        }
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($racine, FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            if ($f->isFile() && $f->getExtension() === 'php') {
                $fichiers[] = $f->getPathname();
            }
        }
    }
    sort($fichiers);

    return $fichiers;
}

/** @return array<string, string> nom du drapeau => 'garde' | 'hors_garde' */
function nr15Inventaire(): array
{
    $garde = (string) file_get_contents(base_path('app/Providers/MockServicesProvider.php'));
    preg_match_all("/'(MOCK_[A-Z0-9_]+)'/", $garde, $m);
    $gardes = array_unique($m[1]);

    $tous = [];
    foreach (nr15Fichiers() as $fichier) {
        // On retire les commentaires : une doc qui CITE un drapeau n’est pas une lecture.
        $code = '';
        foreach (token_get_all((string) file_get_contents($fichier)) as $t) {
            if (is_array($t) && in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            $code .= is_array($t) ? $t[1] : $t;
        }
        if (preg_match_all("/['\"](MOCK_[A-Z0-9_]+)['\"]/", $code, $found)) {
            foreach ($found[1] as $nom) {
                $tous[$nom] = in_array($nom, $gardes, true) ? 'garde' : 'hors_garde';
            }
        }
    }
    ksort($tous);

    return $tous;
}

test('NR-15 l’inventaire des simulateurs MOCK_* est celui de la photo de référence', function () {
    Instantane::verifier('nr15-simulateurs', nr15Inventaire());
});

test('NR-15 le maître MOCK_MODE et les 10 drapeaux de la CI sont tous gardés', function () {
    $inventaire = nr15Inventaire();
    $ci = ['MOCK_MODE', 'MOCK_LLM', 'MOCK_PROXIES', 'MOCK_SCRAPERS', 'MOCK_SMTP', 'MOCK_INSEE', 'MOCK_ANNUAIRE_ENTREPRISES', 'MOCK_BODACC', 'MOCK_BAN', 'MOCK_FRANCE_TRAVAIL'];

    foreach ($ci as $nom) {
        expect($inventaire[$nom] ?? null)->toBe('garde');
    }
});

test('NR-15 aucun simulateur ne peut devenir « gardé » ou « hors garde » en silence', function () {
    // Les trois drapeaux hors garde sont une DETTE CONNUE (S2) : si l’un d’eux
    // entre dans la garde, la photo change — c’est voulu, et ce test le dit.
    $horsGarde = array_keys(array_filter(nr15Inventaire(), fn ($v) => $v === 'hors_garde'));

    expect($horsGarde)->toBe(['MOCK_IGN', 'MOCK_MAIL', 'MOCK_RPPS']);
});

test('NR-15 le provider a toujours des simulacres par défaut hors production', function () {
    // Témoin du témoin : sans lui, l’inventaire pourrait être vide sans que rien ne rougisse.
    expect(count(nr15Inventaire()))->toBeGreaterThanOrEqual(13);
});
