<?php

/**
 * UN SEUL ÉMETTEUR DE REQUÊTES BRAVE — et il réserve avant d'envoyer.
 *
 * Le plafond mensuel ne tient que si AUCUN chemin n'interroge Brave sans
 * passer par `QuotaBrave`. Jusqu'au 29/09, `DomainFinderService::searchBrave()`
 * appelait l'API lui-même, hors compteur, avec `->retry(2)`. Cette garde relit
 * `app/` : l'hôte de l'API et l'en-tête de la clé n'apparaissent que dans
 * `RechercheBrave`, dont `chercher()` réserve avant d'envoyer (prouvé par le
 * comportement dans `DomainFinderBraveQuotaTest` et `FederationsSitesBraveTest`).
 *
 * Angle mort assumé : une lecture de TEXTE. Une URL fabriquée par morceaux lui
 * échapperait ; les gardes de comportement couvrent les chemins existants.
 */

use App\Crm\Brave\RechercheBrave;

/** @return list<string> fichiers de `app/` (relatifs) qui citent l'API Brave */
function bueFichiersQuiCitentBrave(): array
{
    $trouves = [];
    $iterateur = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(base_path('app'), FilesystemIterator::SKIP_DOTS));
    foreach ($iterateur as $fichier) {
        if (! $fichier instanceof SplFileInfo || $fichier->getExtension() !== 'php') {
            continue;
        }
        $source = (string) file_get_contents($fichier->getPathname());
        if (preg_match('/search\.brave\.com|X-Subscription-Token/i', $source) === 1) {
            $trouves[] = str_replace('\\', '/', substr($fichier->getPathname(), strlen(base_path()) + 1));
        }
    }
    sort($trouves);

    return $trouves;
}

test('seul RechercheBrave cite l API Brave ou l en-tete de sa cle', function () {
    expect(bueFichiersQuiCitentBrave())->toBe(['app/Crm/Brave/RechercheBrave.php']);
});

test('RechercheBrave reserve dans le quota AVANT d emettre, et sans retry', function () {
    $source = (string) file_get_contents((string) (new ReflectionClass(RechercheBrave::class))->getFileName());
    $reservation = strpos($source, '->reserver($usage)');
    $emission = strpos($source, 'Http::');

    expect($reservation)->not->toBeFalse()
        ->and($emission)->not->toBeFalse()
        ->and($reservation < $emission)->toBeTrue()
        ->and(str_contains($source, '->retry('))->toBeFalse();
});
