<?php

/**
 * Envoi L8-ORCH — planification de `envoi:cycle`. `envoi:perimer-reservations` n'est PAS planifiée (le cycle en appelle la logique).
 */

use App\Console\Commands\EnvoiCycle;
use App\Envoi\Desinscription\ArretParEntreprise;
use App\Envoi\Desinscription\RetraitEnvoisEnFile;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Tests\TestCase;

uses(TestCase::class);

/** @return list<Event> */
function planifieEvenements(string $commande): array
{
    return array_values(array_filter(
        app(Schedule::class)->events(),
        fn (Event $e): bool => str_contains((string) $e->command, ' ' . $commande) && ! str_contains((string) $e->command, $commande . '-'),
    ));
}

test('envoi:cycle est planifiée chaque minute, sans chevauchement, sur un seul serveur', function () {
    $evenements = planifieEvenements(EnvoiCycle::SIGNATURE_PLANIFIEE);

    expect($evenements)->toHaveCount(1)
        ->and($evenements[0]->expression)->toBe('* * * * *')
        ->and($evenements[0]->withoutOverlapping)->toBeTrue()
        ->and($evenements[0]->onOneServer)->toBeTrue();
});

test('la tâche n\'est pas due tant que la porte d\'envoi réel est fermée, et l\'est une fois ouverte', function () {
    [$evenement] = planifieEvenements(EnvoiCycle::SIGNATURE_PLANIFIEE);

    config(['envoi.reel_actif' => false]);
    expect($evenement->filtersPass(app()))->toBeFalse();

    config(['envoi.reel_actif' => true]);
    expect($evenement->filtersPass(app()))->toBeFalse(); // retrait absent : toujours fermée

    app()->instance(RetraitEnvoisEnFile::class, new class implements RetraitEnvoisEnFile
    {
        public function retirerPour(string $workspaceId, string $empreinteAdresse): int
        {
            return 0;
        }
    });
    app()->instance(ArretParEntreprise::class, new class implements ArretParEntreprise
    {
        public function declencher(string $workspaceId, string $empreinteAdresse): void {}
    });
    expect($evenement->filtersPass(app()))->toBeTrue();
});

test('envoi:perimer-reservations n\'est pas planifiée', function () {
    expect(planifieEvenements('envoi:perimer-reservations'))->toBe([]);
});

test('garde avec témoin : aucun fichier de routes/console ne contient perimer-reservations (le témoin le planifie et fait rougir)', function () {
    $contient = static fn (string $texte): bool => str_contains($texte, 'perimer-reservations');
    $reel = '';
    foreach (glob(base_path('routes/console/*.php')) ?: [] as $f) {
        $reel .= (string) file_get_contents($f);
    }
    $temoin = $reel . "\nSchedule::command('envoi:perimer-reservations')->everyMinute();\n";

    expect($contient($reel))->toBeFalse()->and($contient($temoin))->toBeTrue();
});
