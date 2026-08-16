<?php

use App\Console\Commands\MediaEnrich;
use App\Services\Email\EmailConfidenceService;
use App\Services\Email\EmailFinderService;
use App\Services\Email\HunterEmailVerifier;
use App\Services\Email\MxEmailValidator;
use App\Services\Legal\MentionsLegalesScraperService;
use App\Services\Scraping\GooglePlacesClient;

/**
 * VERROU DU PIÈGE DU CONTENEUR LARAVEL 11+.
 *
 * Depuis Laravel 11, `Container::resolveClass()` rend la VALEUR PAR DÉFAUT dès
 * qu'un défaut existe et que la classe n'est pas explicitement bindée — il
 * n'auto-résout plus. Une dépendance déclarée `?Foo $x = null` vaut donc
 * `null` EN PERMANENCE, sans la moindre erreur.
 *
 * Ce piège a coûté quatre garde-fous à ce projet, découverts en deux fois :
 *   · 2026-08-16 (matin) : `WaterfallOrchestrator::$googlePlaces` — tout
 *     l'enrichissement Google Places était mort ;
 *   · 2026-08-16 (après-midi) : `MentionsLegalesScraperService::$emailValidator`,
 *     `MediaEnrich::$mx` et `MediaEnrich::$confidence` — la validation MX et le
 *     score de confiance n'ont jamais tourné.
 *
 * 🔑 Aucun test ne pouvait les attraper, parce que les tests construisent ces
 * classes À LA MAIN (`new MentionsLegalesScraperService;`) et reproduisent donc
 * exactement le bug. Il faut interroger LE CONTENEUR, comme le fait la
 * production.
 *
 * Ce test rougit si quelqu'un retire un binding d'`AppServiceProvider`.
 */
function dependanceResolue(string $classe, string $propriete): mixed
{
    $instance = app($classe);
    $reflet = new ReflectionProperty($instance, $propriete);
    $reflet->setAccessible(true);

    return $reflet->getValue($instance);
}

test('les dépendances optionnelles sont RÉELLEMENT injectées par le conteneur', function (string $classe, string $propriete, string $attendu) {
    expect(dependanceResolue($classe, $propriete))->toBeInstanceOf($attendu);
})->with([
    'validation MX du scraping mentions-légales' => [MentionsLegalesScraperService::class, 'emailValidator', MxEmailValidator::class],
    'validation MX de media:enrich' => [MediaEnrich::class, 'mx', MxEmailValidator::class],
    'score de confiance de media:enrich' => [MediaEnrich::class, 'confidence', EmailConfidenceService::class],
    'vérificateur Hunter du chercheur d\'emails' => [EmailFinderService::class, 'hunterVerifier', HunterEmailVerifier::class],
]);

test('le conteneur sait résoudre les classes concrètes bindées', function (string $classe) {
    expect(app($classe))->toBeInstanceOf($classe);
})->with([
    GooglePlacesClient::class,
    MxEmailValidator::class,
    EmailConfidenceService::class,
]);
