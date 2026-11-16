<?php

use App\Providers\Envoi\MaterialisationServiceProvider;
use App\Providers\Envoi\ReactionsServiceProvider;
use App\Providers\Envoi\ReleveRetoursServiceProvider;
use App\Providers\Envoi\RetentionServiceProvider;
use App\Providers\Envoi\TransportServiceProvider;
use App\Providers\Envoi\VerdictsAdresseServiceProvider;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

uses(TestCase::class)->group('envoi');

/** V0 : points d'entrée préparés pour les lots parallèles. Aucun changement de comportement. */
const V0_FICHIERS = ['materialisation', 'verdicts', 'transport', 'releve', 'reactions'];

test('les cinq nouveaux sous-fournisseurs sont chargés une seule fois, après les précédents', function () {
    $nouveaux = [
        MaterialisationServiceProvider::class, VerdictsAdresseServiceProvider::class, TransportServiceProvider::class,
        ReleveRetoursServiceProvider::class, ReactionsServiceProvider::class,
    ];
    $charges = array_keys(app()->getLoadedProviders());
    $positions = array_map(fn (string $c) => array_search($c, $charges, true), $nouveaux);
    expect($positions)->each->not->toBeFalse();
    $triees = $positions;
    sort($triees);
    expect($positions)->toBe($triees)
        ->and(min($positions))->toBeGreaterThan(array_search(RetentionServiceProvider::class, $charges, true));
    foreach ($nouveaux as $classe) {
        expect(count(array_filter(app()->getProviders($classe))))->toBe(1);
    }
});

test('chaque fichier routes/console/envoi-*.php de V0 existe, est requis par routes/console.php et ne déclare aucune tâche', function () {
    $console = (string) file_get_contents(base_path('routes/console.php'));
    foreach (V0_FICHIERS as $f) {
        $chemin = base_path("routes/console/envoi-{$f}.php");
        expect(file_exists($chemin))->toBeTrue($f)
            ->and($console)->toContain("/console/envoi-{$f}.php")
            // `verdicts` (VERDICTS-CODE-2) et `transport` (C3-3, envoi des retraits) portent chacun UNE tâche ; les autres restent vides.
            ->and(substr_count((string) file_get_contents($chemin), 'Schedule::'))->toBe(in_array($f, ['verdicts', 'transport'], true) ? 1 : 0)
            ->and(substr_count($console, "/console/envoi-{$f}.php"))->toBe(1);
    }
    // Même ordre que le modèle : orchestration, retention, puis les cinq de V0.
    $ordre = array_map(fn ($f) => strpos($console, "/console/envoi-{$f}.php"), V0_FICHIERS);
    $tri = $ordre;
    sort($tri);
    expect($ordre)->toBe($tri)->and(min($ordre))->toBeGreaterThan(strpos($console, '/console/envoi-retention.php'));
});

test('le planificateur ne contient aucune tâche venue des fichiers V0', function () {
    Artisan::call('list', ['--format' => 'txt']);
    $evenements = app(Schedule::class)->events();
    foreach ($evenements as $e) {
        // Seule exception : la tâche de VERDICTS-CODE-2 (une seule, nommée exactement).
        $commande = (string) $e->command;
        if (str_contains($commande, 'envoi:verdicts:rafraichir') || str_contains($commande, 'envoi:retraits:envoyer')) {
            continue;
        }
        expect($commande)->not->toMatch('/materialisation|verdicts|transport|releve-retours|reactions/i');
    }
    expect(count(array_filter($evenements, fn ($e) => str_contains((string) $e->command, 'envoi:verdicts:rafraichir'))))->toBe(1);
    expect(count(array_filter($evenements, fn ($e) => str_contains((string) $e->command, 'envoi:retraits:envoyer'))))->toBe(1);
    // Les instantanés NR-07 et NR-20 restent la référence du nombre de tâches et de routes (une entrée de plus pour VERDICTS-CODE-2).
    $instantane = json_decode((string) file_get_contents(base_path('tests/Feature/NonRegression/instantanes/nr07-taches-planifiees.json')), true);
    expect(count($evenements))->toBe(count($instantane['taches'] ?? $instantane));
});
