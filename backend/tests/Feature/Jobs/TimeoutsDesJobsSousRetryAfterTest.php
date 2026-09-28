<?php

/**
 * CHAQUE JOB FINIT AVANT QUE LA FILE LE CROIE PERDU (relecture R1 de la
 * PR #255, 2026-09-29).
 *
 * Laravel relance une tâche dont il n'a pas de nouvelles après `retry_after`
 * secondes (connexion `redis` : 600 s, `config/queue.php`). Un job dont le
 * `$timeout` atteint ou dépasse cette valeur tourne donc EN DOUBLE, puis en
 * triple, tant que la première copie n'a pas fini. `VerifierEffacementRgpd`
 * était à 1 800 s : jusqu'à trois preuves simultanées sur 4,3 M de fiches.
 *
 * La garde lit chaque job de `app/Jobs` (catalogue, jamais une liste à la
 * main) et exige `$timeout < retry_after` de SA connexion — celle de
 * production (`redis`) quand le job n'en nomme pas.
 *
 * ⚠️ DETTE EXISTANTE, NON CORRIGÉE ICI : quatre jobs antérieurs à cette PR
 * sont à `timeout >= retry_after`. Ils sont listés avec leur valeur ACTUELLE :
 * la garde rougit s'ils empirent, s'ils disparaissent (liste périmée), ou si
 * un NOUVEAU job entre dans le cas. Les corriger change leur comportement de
 * production ; c'est hors du périmètre de la PR des fédérations.
 */

use Illuminate\Contracts\Queue\ShouldQueue;
use Tests\TestCase;

uses(TestCase::class);

/** @var array<string, int> job → timeout toléré, tel que mesuré le 2026-09-29 */
const TDJ_DETTE = [
    'App\\Jobs\\EnrichCompanyJob' => 600,
    'App\\Jobs\\LaunchCampaignJob' => 600,
    'App\\Jobs\\LaunchZoneScrapingJob' => 1800,
    'App\\Jobs\\RefreshAudienceChunkJob' => 600,
];

test('R1 — le timeout de chaque job est sous le retry_after de sa connexion', function () {
    $jobs = [];
    foreach ((array) glob(app_path('Jobs') . '/*.php') as $fichier) {
        $classe = 'App\\Jobs\\' . basename((string) $fichier, '.php');
        if (! class_exists($classe)) {
            continue;
        }
        $reflet = new ReflectionClass($classe);
        if ($reflet->isAbstract() || ! $reflet->implementsInterface(ShouldQueue::class)) {
            continue;
        }
        $jobs[$classe] = $reflet->getDefaultProperties();
    }
    // TÉMOIN DE COUVERTURE : le balayage voit bien les jobs.
    expect(count($jobs))->toBeGreaterThanOrEqual(7)
        ->and($jobs)->toHaveKey('App\\Jobs\\VerifierEffacementRgpd');

    $fautifs = [];
    foreach ($jobs as $classe => $defauts) {
        $timeout = $defauts['timeout'] ?? null;
        if (! is_int($timeout)) {
            continue; // pas de timeout propre : celui du worker s'applique
        }
        $connexion = is_string($defauts['connection'] ?? null) ? $defauts['connection'] : 'redis';
        $retryAfter = (int) config("queue.connections.{$connexion}.retry_after", 0);
        expect($retryAfter)->toBeGreaterThan(0);

        if ($timeout < $retryAfter) {
            continue;
        }
        if (isset(TDJ_DETTE[$classe]) && $timeout <= TDJ_DETTE[$classe]) {
            continue;
        }
        $fautifs[] = "{$classe} (timeout {$timeout} >= retry_after {$retryAfter} de « {$connexion} »)";
    }

    expect($fautifs)->toBe([]);
    // Une dette qui ne correspond plus à un job est une dette qui ment.
    foreach (array_keys(TDJ_DETTE) as $classe) {
        expect($jobs)->toHaveKey($classe);
    }
});
