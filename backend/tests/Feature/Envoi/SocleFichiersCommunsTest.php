<?php

use App\Envoi\Desinscription\ArretParEntreprise;
use App\Envoi\Desinscription\ArretParEntrepriseAbsent;
use App\Envoi\Desinscription\RetraitEnvoisEnFile;
use App\Envoi\Desinscription\RetraitEnvoisEnFileAbsent;
use App\Envoi\Orchestration\PorteEnvoiReelle;
use App\Envoi\Serveur\AiguilleurServeurEnvoi;
use App\Envoi\Simulateur\FauxServeurEnvoi;
use App\Providers\Envoi\AiguillageServiceProvider;
use App\Providers\Envoi\FeuVertDecisionServiceProvider;
use App\Providers\Envoi\OppositionsImportServiceProvider;
use App\Providers\Envoi\OrchestrationServiceProvider;
use App\Providers\Envoi\RetentionServiceProvider;
use Illuminate\Support\Facades\Route;
use Symfony\Component\Yaml\Yaml;
use Tests\Support\NonServiAuPalier0;
use Tests\TestCase;

uses(TestCase::class)->group('envoi');

/** S0 : socle des fichiers communs. Ne vérifie que ce qui reste vrai après les PR suivantes. */
test('les cinq sous-fournisseurs sont enregistrés, dans l’ordre', function () {
    $attendus = [
        OrchestrationServiceProvider::class, AiguillageServiceProvider::class, OppositionsImportServiceProvider::class,
        RetentionServiceProvider::class, FeuVertDecisionServiceProvider::class,
    ];
    $charges = array_keys(app()->getLoadedProviders());
    $positions = array_map(fn (string $c) => array_search($c, $charges, true), $attendus);
    expect($positions)->each->not->toBeFalse();
    $triees = $positions;
    sort($triees);
    expect($positions)->toBe($triees);
});

test('routes/envoi/socle.php existe et routes/envoi.php le requiert', function () {
    expect(file_exists(base_path('routes/envoi/socle.php')))->toBeTrue()
        ->and((string) file_get_contents(base_path('routes/envoi.php')))->toContain("'socle'");
});

test('routes/console.php requiert les deux fichiers routes/console/envoi-*.php', function () {
    foreach (['envoi-orchestration', 'envoi-retention'] as $f) {
        expect(file_exists(base_path("routes/console/{$f}.php")))->toBeTrue($f)
            ->and((string) file_get_contents(base_path('routes/console.php')))->toContain("/console/{$f}.php");
    }
});

test('angle mort des gardes de texte : routes/console/*.php ne porte aucun nom gardé par un test de lecture', function () {
    $interdits = ['crm:referentiels:reclasser', 'chercher-site-prouve', 'email-site-verifie', 'crm:entreprises:verifier-sites', 'perimer-reservations'];
    foreach (glob(base_path('routes/console/*.php')) as $fichier) {
        foreach ($interdits as $nom) {
            expect(file_get_contents($fichier))->not->toContain($nom);
        }
    }
});

/** @return list<string> */
function couplesR19(): array
{
    return [
        'POST /envoi/boites', 'GET /envoi/boites', 'GET /envoi/boites/{id}', 'POST /envoi/boites/test-connexion',
        'POST /envoi/boites/{id}/reactiver', 'POST /envoi/boites/{id}/suspendre', 'PUT /envoi/boites/{id}/reglages',
        'GET /envoi/chauffe', 'POST /envoi/chauffe/{id}/reculer',
        'GET /envoi/sequences', 'GET /envoi/sequences/{id}', 'POST /envoi/sequences/{id}/pause', 'POST /envoi/sequences/{id}/reprise',
        'GET /envoi/etat', 'GET /envoi/compteurs', 'POST /envoi/tout-arreter', 'POST /envoi/reprendre',
        'POST /envoi/sante/test', 'POST /notifications/{id}/lue',
    ];
}

/**
 * Couples R19 perdus : ni trou déclaré, ni route réelle, ni non-servi, et pourtant présents au contrat.
 *
 * @param  list<string>  $trous
 * @param  list<string>  $routes  clés normalisées (« {} » pour les paramètres)
 * @param  list<string>  $nonServis
 * @param  list<string>  $contrat
 * @return list<string>
 */
function couplesR19Perdus(array $trous, array $routes, array $nonServis, array $contrat): array
{
    $perdus = [];
    foreach (couplesR19() as $couple) {
        $cle = preg_replace('/\{[^}]+\}/', '{}', $couple);
        $ok = in_array($couple, $trous, true) || in_array($cle, $routes, true) || in_array($couple, $nonServis, true)
            || ! in_array($couple, $contrat, true);
        if (! $ok) {
            $perdus[] = $couple;
        }
    }

    return $perdus;
}

test('chacun des 19 couples R19 est dans un trous/*.php, une route réelle, un non-servi, ou retiré du contrat (D1, D2)', function () {
    $trous = [];
    foreach (glob(base_path('tests/Support/Couverture/trous/*.php')) as $f) {
        $trous = array_merge($trous, array_keys(require $f));
    }
    $routes = [];
    foreach (Route::getRoutes() as $r) {
        foreach (array_diff($r->methods(), ['HEAD']) as $m) {
            $routes[] = $m . ' ' . preg_replace('/\{[^}]+\}/', '{}', preg_replace('#^/?api/v1#', '', '/' . $r->uri()));
        }
    }
    $nonServis = array_keys(NonServiAuPalier0::entrees());
    $contrat = [];
    foreach (glob(base_path('openapi/envoi/*.yaml')) as $f) {
        foreach ((Yaml::parseFile($f)['paths'] ?? []) as $chemin => $ops) {
            foreach (array_keys($ops) as $m) {
                $contrat[] = strtoupper($m) . ' ' . $chemin;
            }
        }
    }

    expect(couplesR19Perdus($trous, $routes, $nonServis, $contrat))->toBe([]);

    // Un couple comblé sort de trous/*.php et devient une route réelle : les deux états sont légitimes.
    // Chaque couple doit donc être « encore un trou » OU « couvert par une route réelle » (ou non-servi), jamais aucun.
    foreach (couplesR19() as $couple) {
        $cle = preg_replace('/\{[^}]+\}/', '{}', $couple);
        $etat = in_array($couple, $trous, true) || in_array($cle, $routes, true) || in_array($couple, $nonServis, true);
        expect($etat || ! in_array($couple, $contrat, true))->toBeTrue($couple);
    }

    // Témoin : retiré à la fois des trous et des routes, un couple du contrat est bien déclaré perdu.
    $temoins = array_values(array_filter(couplesR19(), fn (string $c) => in_array($c, $contrat, true)));
    expect($temoins)->not->toBe([]);
    foreach ($temoins as $temoin) {
        $cleTemoin = preg_replace('/\{[^}]+\}/', '{}', $temoin);
        $perdus = couplesR19Perdus(
            array_values(array_diff($trous, [$temoin])),
            array_values(array_diff($routes, [$cleTemoin])),
            array_values(array_diff($nonServis, [$temoin])),
            $contrat,
        );
        expect($perdus)->toContain($temoin);
    }
});

test('envoi.serveur_interne vaut l\'aiguilleur par domaine (K17-AIG), dont le seul adaptateur construit est FauxServeurEnvoi', function () {
    expect(app('envoi.serveur_interne'))->toBeInstanceOf(AiguilleurServeurEnvoi::class)
        ->and(app(FauxServeurEnvoi::class))->toBeInstanceOf(FauxServeurEnvoi::class);
});

test('PorteEnvoiReelle::ouverte() = ancienne fermeture, pour les huit combinaisons', function () {
    foreach ([false, true] as $reel) {
        foreach ([false, true] as $retraitBranche) {
            foreach ([false, true] as $arretBranche) {
                config(['envoi.reel_actif' => $reel]);
                app()->bind(RetraitEnvoisEnFile::class, $retraitBranche ? fn () => Mockery::mock(RetraitEnvoisEnFile::class) : RetraitEnvoisEnFileAbsent::class);
                app()->bind(ArretParEntreprise::class, $arretBranche ? fn () => Mockery::mock(ArretParEntreprise::class) : ArretParEntrepriseAbsent::class);
                $ancienne = (bool) config('envoi.reel_actif')
                    && ! (app(RetraitEnvoisEnFile::class) instanceof RetraitEnvoisEnFileAbsent)
                    && ! (app(ArretParEntreprise::class) instanceof ArretParEntrepriseAbsent);
                expect(app(PorteEnvoiReelle::class)->ouverte())->toBe($ancienne);
                expect($ancienne)->toBe($reel && $retraitBranche && $arretBranche);
            }
        }
    }
});
