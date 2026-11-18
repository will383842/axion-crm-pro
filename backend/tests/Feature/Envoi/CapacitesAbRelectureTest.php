<?php

use App\Envoi\Campagnes\ConstructionRefusee;
use App\Http\Controllers\Api\EnvoiMessagesController;

// MSG-CAP : `ab` n'est vrai que si la sonde n'est refusée par aucun motif.
// Aucune donnée d'espace n'est lue ni écrite : les tests tournent à l'identique sous le propriétaire et sous pgsql_app.

test('msg-cap 1 : un refus d\'un autre motif laisse ab faux', function (string $motif) {
    $sonde = static function () use ($motif): void {
        throw new ConstructionRefusee($motif, false, 'refus');
    };
    expect(EnvoiMessagesController::abApplique($sonde))->toBeFalse();
})->with(['sequence_incoherente', 'etapes_invalides', 'jours_non_applicables', 'erreur_base', 'concurrence']);

test('msg-cap 2 : le refus objet_b_non_applicable laisse ab faux', function () {
    $sonde = static function (): void {
        throw new ConstructionRefusee('objet_b_non_applicable', false, 'refus');
    };
    expect(EnvoiMessagesController::abApplique($sonde))->toBeFalse();
});

test('msg-cap 3 : sonde acceptée ⇒ ab vrai', function () {
    expect(EnvoiMessagesController::abApplique(static fn () => null))->toBeTrue();
});

test('msg-cap 4 : aucun @phpstan-ignore dans app/Envoi/Campagnes', function () {
    $dir = __DIR__ . '/../../../app/Envoi/Campagnes';
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)) as $f) {
        if ($f->getExtension() === 'php') {
            expect(str_contains((string) file_get_contents($f->getPathname()), '@phpstan-ignore'))->toBeFalse($f->getFilename());
        }
    }
});
