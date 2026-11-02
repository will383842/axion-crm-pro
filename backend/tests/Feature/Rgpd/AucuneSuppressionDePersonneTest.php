<?php

/**
 * L3c-0 (ADR contrat v3 § 6, « AUCUNE PURGE ») — la garde de fond.
 *
 * Aucune tâche planifiée, aucune commande et aucune route ne supprime une
 * personne, un contact ni un candidat ; aucun DELETE dur sur `companies` (les
 * contacts partent avec, en cascade). Une opposition est une mise à l'écart
 * (`opposition_at`), jamais une suppression.
 *
 * Ce fichier ne touche pas la base : il lit le planificateur, les commandes et
 * le code applicatif.
 */

use App\Models\Company;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

uses(TestCase::class);

const AUCUNE_SUPPRESSION_COMMANDES = [
    'rgpd:purge-vivier',
    'rgpd:purge-business-prospects',
    'rgpd:purge-personnes',
    'prospection:purge-non-diffusible',
    'prospection:purge-non-commercial',
];

test('aucune tâche planifiée ne purge de personne, de contact ni de candidat', function () {
    Artisan::call('list', ['--format' => 'txt']); // charge routes/console.php

    $noms = array_map(
        static fn (Event $e): string => (string) $e->command,
        array_values(app(Schedule::class)->events()),
    );

    // TÉMOIN : le planificateur n'est pas vide.
    expect(count($noms))->toBeGreaterThan(20);

    foreach ($noms as $nom) {
        foreach (AUCUNE_SUPPRESSION_COMMANDES as $commande) {
            expect(str_contains($nom, $commande))->toBeFalse("{$commande} est planifiée");
        }
    }
});

test('les 5 commandes de purge refusent sans condition, même avec --force', function () {
    foreach (AUCUNE_SUPPRESSION_COMMANDES as $commande) {
        expect(Artisan::call($commande, ['--force' => true]))->toBe(1, $commande);
        expect(Artisan::call($commande))->toBe(1, $commande);
    }
});

test('aucun code applicatif ne supprime durement une personne, un contact, un candidat ou une entreprise', function () {
    $racine = dirname(__DIR__, 3) . '/app';
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($racine, FilesystemIterator::SKIP_DOTS));

    $fichiers = 0;
    $hits = [];
    foreach ($it as $fichier) {
        if (! str_ends_with((string) $fichier, '.php')) {
            continue;
        }
        $fichiers++;
        $source = (string) file_get_contents((string) $fichier);
        $court = str_replace($racine . '/', '', (string) $fichier);

        if (preg_match("/table\\('(contacts|candidates|personnes|abonnements|companies)'\\)[^;]*?->(delete|forceDelete|truncate)\\(/s", $source, $m) === 1) {
            $hits[] = "{$court} : suppression dure de {$m[1]}";
        }
        if (preg_match('/DELETE\\s+FROM\\s+(public\\.)?(contacts|candidates|personnes|abonnements|companies)\\b/i', $source, $m) === 1) {
            $hits[] = "{$court} : DELETE FROM {$m[2]}";
        }
        if (preg_match('/->forceDelete\\(/', $source) === 1) {
            $hits[] = "{$court} : forceDelete";
        }
    }

    // TÉMOIN DE COUVERTURE : le balayage a bien vu le code.
    expect($fichiers)->toBeGreaterThan(300);
    expect($hits)->toBe([]);
});

test('DELETE /companies/{id} reste une suppression douce (corbeille), jamais définitive', function () {
    expect(in_array(SoftDeletes::class, class_uses_recursive(Company::class), true))->toBeTrue();

    $source = (string) file_get_contents(dirname(__DIR__, 3) . '/app/Http/Controllers/Api/CompaniesController.php');
    expect($source)->not->toContain('forceDelete');
});
