<?php

/**
 * X2-T0 — le branchement du transport réel est PRÉPARÉ, jamais actif : drapeau éteint par défaut, porte fermée même avec une
 * implémentation réelle liée, aucune fabrique appelée, aucune requête HTTP. Domaines en `*.example.test`. Aucun réseau.
 */

use App\Envoi\Contrat\MessageAEnvoyer;
use App\Envoi\Contrat\ServeurEnvoi;
use App\Envoi\Desinscription\RetraitEnvoisEnFile;
use App\Envoi\Exceptions\EnvoiRefuse;
use App\Envoi\FeuVert\CatalogueManuels;
use App\Envoi\FeuVert\DecisionFeuVert;
use App\Envoi\FeuVert\DepotPreuves;
use App\Envoi\FeuVert\ListeFeuVert;
use App\Envoi\FeuVert\PointFeuVert;
use App\Envoi\Orchestration\PorteEnvoiReelle;
use App\Envoi\Sante\DepotSante;
use App\Envoi\Serveur\AiguilleurServeurEnvoi;
use App\Envoi\Simulateur\FauxServeurEnvoi;
use App\Support\WorkspaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Support\DoublonsFixtures as F;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class)->group('envoi');

/** Retrait « réel » (double) : sa seule présence ne doit JAMAIS suffire à ouvrir la porte. */
final class BiRetraitReel implements RetraitEnvoisEnFile
{
    public function retirerPour(string $workspaceId, string $empreinteAdresse): int
    {
        return 0;
    }
}

/**
 * Montage : domaine en adaptateur kumo, feu vert simulé accordé (par DecisionFeuVert), contrôle DNS vert du jour, fabriques espionnes.
 *
 * @return array{ws: string, domaine: string, compteur: ArrayObject<int, string>}
 */
function biMontage(): array
{
    Http::preventStrayRequests();
    $ws = F::espace('zz-bi');
    $domaine = 'bi-' . Str::lower(Str::random(6)) . '.example.test';
    DB::table('envoi_domaines')->insert(['workspace_id' => $ws, 'nom' => $domaine, 'achete_le' => '2026-01-01']);
    WorkspaceContext::run($ws, fn () => DB::selectOne('SELECT envoi_domaine_adaptateur_changer(?, ?, ?, ?)', [$ws, $domaine, 'kumo', 'auteur-test']));
    app()->bind(ListeFeuVert::class, fn () => new class(app(DepotPreuves::class), app(DepotSante::class)) extends ListeFeuVert
    {
        public function points(string $ws): array
        {
            $r = [];
            foreach (CatalogueManuels::POINTS as $code => $c) {
                $r[] = new PointFeuVert($code, $c['libelle'], str_starts_with($code, 'auto.'), true, 'simulé', $c['source'], $c['groupe'], 'fait');
            }

            return $r;
        }
    });
    $liste = app(ListeFeuVert::class);
    expect(app(DecisionFeuVert::class)->decider($ws, 'accorde', 'Décision de test après relecture, réf. exemple-01.', 'responsable', $liste->empreinte($ws, $liste->points($ws)))['statut'])->toBe(200);
    WorkspaceContext::run($ws, fn () => DB::table('envoi_controles_dns')->insert(['workspace_id' => $ws, 'domaine' => $domaine, 'controle_le' => (new DateTimeImmutable)->format('c'), 'pret' => true, 'pause' => false]));
    $compteur = new ArrayObject;
    $fabrique = function (string $espace, string $d) use ($compteur): ServeurEnvoi {
        $compteur[] = 'fabrique';

        return new FauxServeurEnvoi;
    };
    app()->bind('envoi.serveur_interne', fn ($app) => new AiguilleurServeurEnvoi(
        new FauxServeurEnvoi,
        ['kumo' => $fabrique, 'smtp' => $fabrique],
        fn (): bool => $app->make(PorteEnvoiReelle::class)->ouverte(),
        fn (): ListeFeuVert => $app->make(ListeFeuVert::class),
    ));
    app()->forgetInstance(ServeurEnvoi::class);
    WorkspaceContext::set($ws);

    return ['ws' => $ws, 'domaine' => $domaine, 'compteur' => $compteur];
}

afterEach(function () {
    try {
        WorkspaceContext::clear();
    } catch (Throwable) {
    }
});

test('(1) reel_actif est faux sans variable d\'environnement ; la porte reste fermée même avec un retrait réel lié', function () {
    expect(config('envoi.reel_actif'))->toBeFalse();
    $defaut = (static function (): array {
        $ancien = getenv('ENVOI_REEL_ACTIF');
        $dansEnv = array_key_exists('ENVOI_REEL_ACTIF', $_ENV);
        $dansServer = array_key_exists('ENVOI_REEL_ACTIF', $_SERVER);
        $env = $_ENV['ENVOI_REEL_ACTIF'] ?? null;
        $server = $_SERVER['ENVOI_REEL_ACTIF'] ?? null;
        putenv('ENVOI_REEL_ACTIF');
        unset($_ENV['ENVOI_REEL_ACTIF'], $_SERVER['ENVOI_REEL_ACTIF']);
        try {
            return require base_path('config/envoi.php');
        } finally {
            // Restauration à l'identique des trois sources (putenv, $_ENV, $_SERVER) : le test ne laisse aucune trace.
            if ($ancien !== false) {
                putenv('ENVOI_REEL_ACTIF=' . $ancien);
            }
            if ($dansEnv) {
                $_ENV['ENVOI_REEL_ACTIF'] = $env;
            }
            if ($dansServer) {
                $_SERVER['ENVOI_REEL_ACTIF'] = $server;
            }
        }
    })();
    expect($defaut['reel_actif'])->toBeFalse();
    app()->instance(RetraitEnvoisEnFile::class, new BiRetraitReel);
    expect(app(RetraitEnvoisEnFile::class))->toBeInstanceOf(BiRetraitReel::class)->and(app(PorteEnvoiReelle::class)->ouverte())->toBeFalse();
});

test('(2) kumo + feu vert + DNS vert, porte fermée : injecter refusé (envoi_reel_inactif) ; les autres méthodes ne construisent rien et ne font aucune requête HTTP', function () {
    $m = biMontage();
    app()->instance(RetraitEnvoisEnFile::class, new BiRetraitReel);
    $message = new MessageAEnvoyer($m['ws'], 'dest@client.example.test', 'from@' . $m['domaine'], 'Sujet', 'Corps', 'cle-bi-1');
    $refus = null;
    try {
        app('envoi.serveur_interne')->injecter($message);
    } catch (EnvoiRefuse $e) {
        $refus = $e;
    }
    expect($refus)->not->toBeNull()->and($refus->getMessage())->toContain('envoi_reel_inactif');
    expect(fn () => app(ServeurEnvoi::class)->injecter($message))->toThrow(EnvoiRefuse::class, 'inactif');
    $serveur = app(ServeurEnvoi::class);
    $serveur->pause();
    $serveur->reprise();
    $serveur->sante();
    $serveur->etat();
    $serveur->etatEnvoi((string) Str::uuid());
    $serveur->lireEvenements(null);
    $interne = app('envoi.serveur_interne'); // l'aiguilleur lui-même : mêmes méthodes, porte fermée
    $interne->reprise();
    $interne->sante();
    $interne->etat();
    $interne->etatEnvoi((string) Str::uuid());
    $interne->lireEvenements(null);
    expect($m['compteur']->count())->toBe(0); // le compteur reste à 0 via la passerelle (pause incluse : porte fermée, rien transmis)
    Http::assertNothingSent();
});

test('(2 bis) l\'aiguilleur lui-même : pause() construit les adaptateurs portés par un domaine MÊME porte fermée (stop = sens sûr), sans requête HTTP', function () {
    $m = biMontage();
    $passerelle = app(ServeurEnvoi::class);
    $passerelle->pause();
    expect($m['compteur']->count())->toBe(0); // via la passerelle : porte fermée, rien n'est construit
    app('envoi.serveur_interne')->pause();
    expect($m['compteur']->count())->toBe(1); // en direct : le stop construit la fabrique kumo même porte fermée (documenté)
    Http::assertNothingSent();
});

test('(2 ter) envoi.serveur_interne : l\'aiguilleur de AiguillageServiceProvider gagne sur le faux serveur de EnvoiServiceProvider', function () {
    $reflexion = fn (object $o, string $p) => (new ReflectionProperty($o, $p))->getValue($o);
    $interne = $reflexion(app(ServeurEnvoi::class), 'serveur');
    expect($interne)->toBeInstanceOf(AiguilleurServeurEnvoi::class)->and(app('envoi.serveur_interne'))->toBeInstanceOf(AiguilleurServeurEnvoi::class)
        ->and($reflexion($interne, 'fabriques'))->toBe([]);
});

test('(3) dans app/, seul le fournisseur qui construit PasserelleLocale résout envoi.serveur_interne', function () {
    $resolutions = [];
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path(), FilesystemIterator::SKIP_DOTS)) as $f) {
        if ($f->getExtension() === 'php' && preg_match('/(?:make|app|resolve)\(\s*[\'"]envoi\.serveur_interne[\'"]/', (string) file_get_contents($f->getPathname())) === 1) {
            $resolutions[] = $f;
        }
    }
    expect(array_map(fn ($f) => $f->getFilename(), $resolutions))->toBe(['EnvoiServiceProvider.php'])
        ->and(file_get_contents($resolutions[0]->getPathname()))->toContain('new PasserelleLocale(' . "\n" . '            $app->make(\'envoi.serveur_interne\')');
});

test('(4) une fabrique réelle instanciée ne fait aucune connexion', function () {
    Http::preventStrayRequests();
    // À remplir quand des fabriques réelles (kumo, smtp) seront liées : instancier, puis Http::assertNothingSent() et aucun socket ouvert.
    expect(true)->toBeTrue();
})->skip('aucune fabrique réelle n\'existe encore (liste vide dans AiguillageServiceProvider)');
