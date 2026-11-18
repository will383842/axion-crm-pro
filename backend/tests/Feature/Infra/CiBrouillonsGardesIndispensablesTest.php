<?php

/**
 * LOT G du plan « CI allégée » (#680) : une PR en brouillon ne lance que les
 * gardes indispensables (gitleaks, données nominatives). Au passage « prêt pour
 * relecture » (`ready_for_review`), tout tourne sur le dernier commit.
 * Les 4 contrôles exigés gardent leur nom et se terminent toujours : un job
 * sauté par un `if:` de job rend un verdict vert, jamais « en attente ».
 */

use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

uses(TestCase::class);

function workflowLotG(string $nom): array
{
    $racine = realpath(base_path('..')) ?: base_path('..');

    return Yaml::parseFile($racine . '/.github/workflows/' . $nom . '.yml');
}

function declencheursLotG(array $wf): array
{
    return $wf[true] ?? $wf['on'];
}

const GARDE_BROUILLON_LOT_G = "github.event_name != 'pull_request' || github.event.pull_request.draft == false";

/**
 * CI-F1 : les workflows qui appellent le workflow `$nom` (jobs `uses: ./.github/workflows/<nom>.yml`).
 *
 * @return list<string>
 */
function appelantsLotG(string $nom): array
{
    $racine = realpath(base_path('..')) ?: base_path('..');
    $appelants = [];
    foreach (glob($racine . '/.github/workflows/*.yml') ?: [] as $chemin) {
        $wf = Yaml::parseFile($chemin);
        foreach ($wf['jobs'] ?? [] as $job) {
            if (is_array($job) && ($job['uses'] ?? null) === './.github/workflows/' . $nom . '.yml') {
                $appelants[] = basename($chemin, '.yml');
            }
        }
    }

    return array_values(array_unique($appelants));
}

it('chaque workflow de PR écoute ready_for_review', function () {
    $types = ['opened', 'synchronize', 'reopened', 'ready_for_review'];
    foreach (['ci', 'security', 'a11y', 'e2e', 'demo'] as $nom) {
        $declencheurs = declencheursLotG(workflowLotG($nom));
        if (isset($declencheurs['pull_request'])) {
            // Tout workflow qui a ses propres déclencheurs PR garde les 4 types.
            expect($declencheurs['pull_request']['types'])->toBe($types, $nom);

            continue;
        }
        // CI-F1 : un workflow seulement appelé (`workflow_call`) n'a pas de
        // déclencheur propre ; au moins un appelant doit porter les 4 types.
        expect(array_key_exists('workflow_call', $declencheurs))->toBeTrue($nom . ' : ni pull_request ni workflow_call, il ne tournerait jamais');
        $appelants = appelantsLotG($nom);
        expect($appelants !== [])->toBeTrue($nom . ' : workflow_call mais aucun appelant');
        $porteurs = array_filter(
            $appelants,
            fn (string $appelant) => (declencheursLotG(workflowLotG($appelant))['pull_request']['types'] ?? null) === $types,
        );
        expect($porteurs !== [])->toBeTrue($nom . ' : aucun appelant ne porte ' . implode(',', $types));
    }
});

it('les jobs lourds sautent en brouillon, jamais hors PR', function () {
    $lourds = [
        'ci' => ['filet-non-regression', 'gardes-infra', 'backend'],
        'a11y' => ['axe-playwright', 'lighthouse'],
        'e2e' => ['suites-cablees'],
        'demo' => ['demo'],
    ];
    foreach ($lourds as $nom => $jobs) {
        foreach ($jobs as $job) {
            $condition = (string) (workflowLotG($nom)['jobs'][$job]['if'] ?? '');
            expect($condition)->toContain('pull_request.draft == false');
            if ($job !== 'lighthouse') {
                expect($condition)->toContain(GARDE_BROUILLON_LOT_G);
            }
        }
    }
});

it('les audits de dépendances combinent brouillon et changements', function () {
    foreach (['composer-audit', 'pnpm-audit'] as $job) {
        $condition = (string) workflowLotG('security')['jobs'][$job]['if'];
        expect($condition)->toContain('always()')
            ->toContain("github.event_name != 'pull_request'")
            ->toContain('github.event.pull_request.draft == false')
            ->toContain("needs.changements.outputs.deps == 'true'");
    }
});

it('gitleaks, semgrep et les données nominatives tournent toujours, brouillon ou non', function () {
    foreach (['gitleaks', 'semgrep'] as $job) {
        expect(workflowLotG('security')['jobs'][$job])->not->toHaveKey('if')->not->toHaveKey('needs');
    }
    expect(workflowLotG('ci')['jobs']['donnees-personnelles'])->not->toHaveKey('if')->not->toHaveKey('needs');
});

it('les 4 contrôles exigés gardent leur nom exact', function () {
    $ci = workflowLotG('ci')['jobs'];
    expect($ci['backend-verdict']['name'])->toBe('Backend Laravel (install + PHPStan + Pint + Pest)')
        ->and($ci['frontend']['name'])->toBe('Frontend React/Vite')
        ->and($ci['donnees-personnelles']['name'])->toBe('Aucune donnée nominative dans ce dépôt public')
        ->and($ci['workers']['name'])->toBe('Workers Node + Playwright');
});

it('aucun filtre paths sur ci.yml (un workflow non déclenché laisserait le contrôle en attente)', function () {
    expect(declencheursLotG(workflowLotG('ci'))['pull_request'])->not->toHaveKey('paths');
});

it('le verdict backend reste rouge quand une garde échoue (non-draft)', function () {
    $verdict = workflowLotG('ci')['jobs']['backend-verdict'];
    expect((string) $verdict['if'])->toContain('always()');
    $script = (string) collect($verdict['steps'])->pluck('run')->filter()->implode("\n");
    expect($script)->toContain('!= "success"')->toContain('exit "$ko"');
});

it('les 4 contrôles exigés ne sautent JAMAIS : en brouillon ils échouent vite, ils ne passent pas au vert sans tourner', function () {
    $jobs = workflowLotG('ci')['jobs'];
    foreach (['backend-verdict', 'frontend', 'workers', 'donnees-personnelles'] as $nom) {
        $condition = trim((string) ($jobs[$nom]['if'] ?? ''));
        // Aucune condition de job ne peut sauter le contexte exigé (seul `always()` est admis).
        expect($condition === '' || $condition === 'always()')->toBeTrue("$nom : condition de saut interdite ($condition)");
    }
    foreach (['backend-verdict', 'frontend', 'workers'] as $nom) {
        $premiere = $jobs[$nom]['steps'][0];
        expect((string) $premiere['if'])->toContain('github.event.pull_request.draft == true');
        expect((string) $premiere['run'])->toContain('exit 1')->toContain('PR en brouillon');
    }
});
