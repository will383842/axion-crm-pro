<?php

/**
 * GARDE : lot E1 « CI allégée » — OBSERVATION SEULE, la CI de mise en ligne reste rejouée.
 *
 * Sur `pull_request`, `backend-verdict` publie un artefact `ci-arbre-<arbre git>` ;
 * la mise en ligne regarde s'il existe pour l'arbre de `main` et l'ÉCRIT dans le
 * résumé, sans rien décider. Cette garde interdit que l'observation devienne une
 * décision : `ci` sans `if`, `deploy` toujours `needs: [ci]` + `success`, étape
 * de mesure non bloquante, sans secret ni SSH. NR-18 et H44-003 restent intacts.
 */

use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

uses(TestCase::class);

function workflowE1(string $nom): array
{
    $racine = realpath(base_path('..')) ?: base_path('..');
    $texte = (string) file_get_contents($racine . '/.github/workflows/' . $nom);

    return [$texte, (array) Yaml::parse($texte)];
}

/** @return list<array<string, mixed>> */
function etapesE1(array $workflow, string $job): array
{
    return array_values((array) ($workflow['jobs'][$job]['steps'] ?? []));
}

test('E1 — le verdict publie l artefact ci-arbre-<arbre> sur pull_request, après un verdict vert', function () {
    [, $ci] = workflowE1('ci.yml');
    $etapes = etapesE1($ci, 'backend-verdict');

    $publication = null;
    $indexVerdict = null;
    foreach ($etapes as $i => $etape) {
        if (str_contains((string) ($etape['name'] ?? ''), 'Verdict')) {
            $indexVerdict = $i;
        }
        if (str_contains((string) ($etape['uses'] ?? ''), 'upload-artifact') && str_contains((string) ($etape['with']['name'] ?? ''), 'ci-arbre-')) {
            $publication = $i;
        }
    }

    expect($publication)->not->toBeNull('aucune étape upload-artifact « ci-arbre-… »')
        ->and($indexVerdict)->not->toBeNull()
        ->and($publication)->toBeGreaterThan($indexVerdict);

    $etape = $etapes[$publication];
    $condition = (string) ($etape['if'] ?? '');
    expect($condition)->toContain("github.event_name == 'pull_request'")->toContain('success()');
    expect((string) $etape['with']['name'])->toContain('${{ steps.arbre.outputs.arbre }}');
    expect((int) ($etape['with']['retention-days'] ?? 0))->toBeGreaterThan(0)->toBeLessThanOrEqual(14);

    $calcul = collect($etapes)->first(fn ($e) => ($e['id'] ?? '') === 'arbre');
    expect($calcul)->not->toBeNull()
        ->and((string) $calcul['run'])->toContain('git rev-parse HEAD^{tree}')
        ->and((string) ($calcul['if'] ?? ''))->toContain("github.event_name == 'pull_request'");
});

test('E1 — le verdict garde if always() et ses contrôles (rien n est affaibli)', function () {
    [$texte, $ci] = workflowE1('ci.yml');

    expect($ci['jobs']['backend-verdict']['if'])->toBe('always()')
        ->and($ci['jobs']['backend-verdict']['name'])->toBe('Backend Laravel (install + PHPStan + Pint + Pest)');
    expect($texte)->toContain('Somme jouée (${somme}) ≠ total listé (${total})');
    // Aucun filtre de chemins sur ci.yml (un contrôle exigé resterait « en attente »).
    expect($ci['on']['pull_request'])->not->toHaveKey('paths');
});

test('E1 — la mise en ligne ne change PAS : ci sans if, deploy needs [ci] + success', function () {
    [$texte, $deploy] = workflowE1('deploy-direct-ssh.yml');

    expect($deploy['jobs']['ci'])->not->toHaveKey('if')
        ->and($deploy['jobs']['ci']['uses'])->toBe('./.github/workflows/ci.yml')
        ->and($deploy['jobs']['deploy']['needs'])->toBe(['ci'])
        ->and(trim((string) $deploy['jobs']['deploy']['if']))->toBe("\${{ needs.ci.result == 'success' }}")
        ->and(array_keys($deploy['jobs']))->toBe(['ci', 'deploy']);
    expect($texte)->not->toContain('ci-deja-verte');
});

test('E1 — première étape de deploy : mesure non bloquante, lecture seule, écrite dans le résumé', function () {
    [, $deploy] = workflowE1('deploy-direct-ssh.yml');
    $premiere = etapesE1($deploy, 'deploy')[0];

    expect((string) $premiere['name'])->toContain('CI réutilisable')
        ->and($premiere['continue-on-error'] ?? false)->toBeTrue();

    $script = (string) $premiere['run'];
    expect($script)->toContain('GITHUB_STEP_SUMMARY')
        ->and($script)->toContain('ci-arbre-')
        ->and($script)->toContain('"success"')
        ->and($script)->toContain('pull_request')
        ->and($script)->toContain('.github/workflows/ci.yml')
        ->and($script)->toContain('GITHUB_REPOSITORY')
        ->and($script)->toContain('head_repository')
        ->and($script)->toContain('la CI aurait pu être réutilisée')
        ->and($script)->not->toMatch('/\bexit\s+[1-9]/')
        ->and($script)->not->toMatch('/-X\s+(POST|PUT|PATCH|DELETE)/i')
        ->and($script)->not->toContain('secrets.')
        ->and($script)->not->toContain('ssh');

    // Droit de lecture des artefacts seulement, sur ce job-là.
    expect($deploy['jobs']['deploy']['permissions']['actions'] ?? null)->toBe('read')
        ->and($deploy['jobs']['deploy']['permissions']['contents'] ?? null)->toBe('read');
    expect($deploy['permissions'])->toBe(['contents' => 'read']);
});

test('E1 — le garde de NR-18 et H44-003 reste vrai sur les deux workflows de mise en ligne', function () {
    foreach (['deploy-direct-ssh.yml', 'deploy-staging.yml'] as $f) {
        [$texte] = workflowE1($f);
        expect(preg_match('/^\s+needs:\s*\[ci\]\s*$/m', $texte))->toBe(1, "{$f} doit garder « needs: [ci] ».");
    }
});

test('E1 — les trois étapes ajoutées au verdict sont non bloquantes (continue-on-error)', function () {
    [, $ci] = workflowE1('ci.yml');
    $etapes = collect(etapesE1($ci, 'backend-verdict'));
    $ajoutees = $etapes->filter(fn ($e) => str_contains((string) ($e['if'] ?? ''), "github.event_name == 'pull_request'"));

    expect($ajoutees)->toHaveCount(3);
    foreach ($ajoutees as $etape) {
        expect($etape['continue-on-error'] ?? false)->toBeTrue('« ' . $etape['name'] . ' » doit être en continue-on-error : une panne d\'observation ne rougit jamais le verdict.');
    }
});
