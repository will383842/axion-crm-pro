<?php

/**
 * GARDE : LE JOURNAL PUBLIC DU WORKFLOW « DOUBLONS » NE MONTRE JAMAIS LA SORTIE BRUTE.
 *
 * Le dépôt est PUBLIC, son journal d'actions aussi. Si la commande artisan plante,
 * l'erreur Laravel (QueryException : SQL + valeurs — clé de personne, identifiant
 * d'espace, adresse e-mail) ne doit pas s'y afficher. Le workflow capture donc la
 * sortie SUR LE SERVEUR, n'imprime que les lignes de bilan (libellé + nombre) et,
 * en cas d'échec, seulement « échec, code N », avec le vrai code de retour.
 *
 * Ce test EXÉCUTE l'étape (ssh et docker remplacés par des simulacres locaux),
 * avec des valeurs inventées. Aucun serveur, aucun envoi.
 */

use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

uses(TestCase::class);

function doublonsJournalEtape(): string
{
    $racine = realpath(base_path('..')) ?: base_path('..');
    $workflow = (array) Yaml::parseFile($racine . '/.github/workflows/crm-doublons.yml');
    foreach ((array) ($workflow['jobs']['doublons']['steps'] ?? []) as $etape) {
        if (is_array($etape) && ($etape['name'] ?? '') === 'Doublons via SSH') {
            return (string) $etape['run'];
        }
    }
    throw new RuntimeException('Étape « Doublons via SSH » introuvable.');
}

/**
 * @return array{0: string, 1: int}
 */
function doublonsJournalLancer(bool $echec, string $mode = 'detecter-a-blanc'): array
{
    $dossier = sys_get_temp_dir() . '/doublons-journal-' . bin2hex(random_bytes(4));
    mkdir($dossier);
    file_put_contents($dossier . '/etape.sh', doublonsJournalEtape());
    $sortieDocker = $echec
        ? 'printf "%s\n" "SQLSTATE[23505] cle=PERSONNE-SECRETE espace=ESPACE-SECRET mail=inventee@example.test"; return 7'
        : 'printf "%s\n" "+----+----+" "| compteur | nombre |" "| lots | 3 |" "| fiches_lues | 120 |" "═══ BILAN DE LA DÉTECTION ═══" "Verrous tenus au plus en fin de lot : 5" "FUITE inventee@example.test PERSONNE-SECRETE" "Dupont inventee@example.test : 4"';
    file_put_contents($dossier . '/simu.sh', "ssh(){ shift 3; bash -s; }\ndocker(){ {$sortieDocker}; }\nexport -f ssh docker\n");

    $sortie = [];
    $code = 0;
    exec(
        'cd ' . escapeshellarg($dossier) . ' && USER=u HOST=h PROJECT_PATH=/tmp MODE=' . escapeshellarg($mode)
        . ' CONFIRMATION= bash -c ' . escapeshellarg('source ./simu.sh; source ./etape.sh') . ' 2>&1',
        $sortie,
        $code,
    );
    array_map('unlink', glob($dossier . '/*') ?: []);
    rmdir($dossier);

    return [implode("\n", $sortie), $code];
}

it('n\'affiche que les lignes de bilan quand la commande réussit', function (): void {
    [$journal, $code] = doublonsJournalLancer(false);

    expect($code)->toBe(0)
        ->and($journal)->toContain('| fiches_lues | 120 |')
        ->and($journal)->toContain('═══ BILAN DE LA DÉTECTION ═══')
        ->and($journal)->toContain('Verrous tenus au plus en fin de lot : 5')
        ->and($journal)->not->toContain('FUITE')
        ->and($journal)->not->toContain('PERSONNE-SECRETE')
        ->and($journal)->not->toContain('example.test');
});

it('en cas d\'échec n\'affiche que « échec, code N » et sort avec le vrai code', function (): void {
    [$journal, $code] = doublonsJournalLancer(true);

    expect($code)->toBe(7)
        ->and(trim($journal))->toBe('échec, code 7')
        ->and($journal)->not->toContain('SQLSTATE')
        ->and($journal)->not->toContain('SECRET')
        ->and($journal)->not->toContain('example.test');
});

it('ne laisse aucun affichage brut de la commande hors du filtre', function (): void {
    $etape = doublonsJournalEtape();

    expect($etape)->toContain('2>&1')
        ->and($etape)->toContain('grep -E')
        ->and($etape)->not->toMatch('/artisan[^\n]*<\s*\/dev\/null\s*$/m');
});
