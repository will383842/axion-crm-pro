<?php

use App\Envoi\Configuration\AlerteDerive;
use App\Envoi\Configuration\Applicateur;
use App\Envoi\Configuration\CibleConfiguration;
use App\Envoi\Configuration\CibleRepertoire;
use App\Envoi\Configuration\DepotVersions;
use App\Envoi\Configuration\DomaineConfig;
use App\Envoi\Configuration\GenerateurConfiguration;
use App\Envoi\Configuration\Paquet;
use App\Envoi\Configuration\SourceConfiguration;
use App\Envoi\Configuration\StatutVersion;
use Tests\TestCase;

const K18_CLE_PUBLIQUE = 'MIIBIjANBgkqhkiG9w0BAQEFAAOCAQ8AMIIBCgKCAQEAmodeleaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

function k18Source(array $domaines): SourceConfiguration
{
    return new class($domaines) implements SourceConfiguration
    {
        public function __construct(private array $d) {}

        public function domaines(): array
        {
            return $this->d;
        }
    };
}

function k18Domaine(string $nom = 'a.example.test', int $debit = 10, int $plafond = 15): DomaineConfig
{
    return new DomaineConfig($nom, 'sel1', K18_CLE_PUBLIQUE, $debit, $plafond);
}

function k18Brut(array $extra = []): array
{
    return array_merge(Paquet::depuis(k18Source([k18Domaine()]))->contenu, $extra);
}

/** Cible espion. */
function k18Cible(bool $valide = true, bool $echoue = false): object
{
    return new class($valide, $echoue) implements CibleConfiguration
    {
        public array $appels = [];

        public function __construct(private bool $valide, private bool $echoue) {}

        public function valider(Paquet $p): bool
        {
            $this->appels[] = 'valider';

            return $this->valide;
        }

        public function appliquer(Paquet $p): void
        {
            $this->appels[] = 'appliquer';
            if ($this->echoue) {
                throw new RuntimeException('échec');
            }
        }

        public function retablir(): void
        {
            $this->appels[] = 'retablir';
        }
    };
}

function k18Versions(): object
{
    return new class implements DepotVersions
    {
        public array $lignes = [];

        public function noter(string $workspaceId, string $empreinte, StatutVersion $statut, ?string $motif = null): void
        {
            $this->lignes[] = [$empreinte, $statut, $motif];
        }
    };
}

function k18Alerte(): object
{
    return new class implements AlerteDerive
    {
        public array $alertes = [];

        public function signaler(string $workspaceId, array $cles): void
        {
            $this->alertes[] = $cles;
        }
    };
}

function k18Appliquer(array $brut, object $cible, object $versions, ?object $alerte = null): StatutVersion
{
    return (new Applicateur($cible, $versions, $alerte ?? k18Alerte()))->appliquer('ws', $brut);
}

test('génération déterministe : même base, même empreinte, quel que soit l’ordre', function () {
    $a = Paquet::depuis(k18Source([k18Domaine('a.example.test'), k18Domaine('b.example.test')]));
    $b = Paquet::depuis(k18Source([k18Domaine('b.example.test'), k18Domaine('a.example.test')]));
    expect($a->empreinte())->toBe($b->empreinte())->toMatch('/^[0-9a-f]{64}$/');
    $autre = Paquet::depuis(k18Source([k18Domaine('a.example.test', 11), k18Domaine('b.example.test')]));
    expect($autre->empreinte())->not->toBe($a->empreinte());
    expect((new GenerateurConfiguration)->generer(k18Source([k18Domaine()]))->empreinte())
        ->toBe((new GenerateurConfiguration)->generer(k18Source([k18Domaine()]))->empreinte());
});

test('paquet valide : appliqué, version notée', function () {
    $cible = k18Cible();
    $v = k18Versions();
    expect(k18Appliquer(k18Brut(), $cible, $v))->toBe(StatutVersion::Appliquee);
    expect($cible->appels)->toBe(['valider', 'appliquer']);
    expect($v->lignes[0][1])->toBe(StatutVersion::Appliquee);
});

dataset('paquets_invalides', [
    'domaine invalide' => [fn () => ['domaines' => [['domaine' => 'pas un domaine', 'selecteur' => 's1', 'cle_publique' => K18_CLE_PUBLIQUE, 'debit_heure' => 5, 'plafond_jour' => 5]]]],
    'débit nul' => [fn () => ['domaines' => [['domaine' => 'a.example.test', 'selecteur' => 's1', 'cle_publique' => K18_CLE_PUBLIQUE, 'debit_heure' => 0, 'plafond_jour' => 5]]]],
    'clé privée' => [fn () => ['domaines' => [['domaine' => 'a.example.test', 'selecteur' => 's1', 'cle_publique' => '-----BEGIN PRIVATE KEY-----abc', 'debit_heure' => 5, 'plafond_jour' => 5]]]],
    'plafond souhaité au-dessus du plafond dur' => [fn () => ['domaines' => [['domaine' => 'a.example.test', 'selecteur' => 's1', 'cle_publique' => K18_CLE_PUBLIQUE, 'debit_heure' => 5, 'plafond_jour' => 100000]]]],
    'aucun domaine' => [fn () => ['domaines' => []]],
    'structure absente' => [fn () => ['x' => 1]],
]);

test('paquet invalide : rien appliqué', function (Closure $brut) {
    $cible = k18Cible();
    $v = k18Versions();
    expect(k18Appliquer(($brut)(), $cible, $v))->toBe(StatutVersion::Invalide);
    expect($cible->appels)->toBe([]);
    expect($v->lignes[0][1])->toBe(StatutVersion::Invalide);
})->with('paquets_invalides');

test('paquet refusé par la validation à côté du service : rien appliqué', function () {
    $cible = k18Cible(valide: false);
    expect(k18Appliquer(k18Brut(), $cible, k18Versions()))->toBe(StatutVersion::Invalide);
    expect($cible->appels)->toBe(['valider']);
});

test('retour arrière automatique si l’application échoue', function () {
    $cible = k18Cible(echoue: true);
    $v = k18Versions();
    expect(k18Appliquer(k18Brut(), $cible, $v))->toBe(StatutVersion::RetourArriere);
    expect($cible->appels)->toBe(['valider', 'appliquer', 'retablir']);
    expect($v->lignes[0][1])->toBe(StatutVersion::RetourArriere);
});

dataset('tentatives_socle', [
    'plafond dur' => [['plafond_dur' => 99999]],
    'homme mort' => [['homme_mort' => ['delai' => 9999]]],
    'mode témoins' => [['mode_temoins' => false]],
    'liste blanche' => [['liste_blanche' => ['x@example.test']]],
    'imbriqué' => [['domaines' => [['domaine' => 'a.example.test', 'selecteur' => 's1', 'cle_publique' => K18_CLE_PUBLIQUE, 'debit_heure' => 5, 'plafond_jour' => 5, 'options' => ['mode_temoins' => false]]]]],
    'casse et tirets' => [['Plafond-Dur' => 1]],
]);

test('tentative de changer le socle local : refus + alerte de dérive', function (array $extra) {
    $cible = k18Cible();
    $v = k18Versions();
    $alerte = k18Alerte();
    expect(k18Appliquer(array_replace(k18Brut(), $extra), $cible, $v, $alerte))->toBe(StatutVersion::DeriveRefusee);
    expect($cible->appels)->toBe([])->and($alerte->alertes)->toHaveCount(1)->and($alerte->alertes[0])->not->toBeEmpty();
    expect($v->lignes[0][1])->toBe(StatutVersion::DeriveRefusee);
})->with('tentatives_socle');

test('CibleRepertoire : appliquer, puis retablir restaure la version précédente', function () {
    $dir = sys_get_temp_dir() . '/k18-' . bin2hex(random_bytes(4));
    mkdir($dir);
    $cible = new CibleRepertoire($dir);
    $p1 = Paquet::depuis(k18Source([k18Domaine('a.example.test')]));
    $p2 = Paquet::depuis(k18Source([k18Domaine('b.example.test')]));
    expect($cible->valider($p1))->toBeTrue();
    $cible->appliquer($p1);
    $cible->appliquer($p2);
    expect(json_decode((string) file_get_contents($dir . '/domaines.json'), true)['domaines'][0]['domaine'])->toBe('b.example.test');
    $cible->retablir();
    expect(json_decode((string) file_get_contents($dir . '/domaines.json'), true)['domaines'][0]['domaine'])->toBe('a.example.test');
    array_map('unlink', glob($dir . '/{,.}*.json', GLOB_BRACE) ?: []);
    rmdir($dir);
});

test('aucune valeur réelle dans infra/serveur-envoi/config : que des marqueurs', function () {
    $racine = base_path('../infra/serveur-envoi/config');
    $fichiers = [];
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($racine, FilesystemIterator::SKIP_DOTS)) as $f) {
        $fichiers[] = $f->getPathname();
    }
    expect($fichiers)->not->toBeEmpty();
    foreach ($fichiers as $chemin) {
        $texte = (string) file_get_contents($chemin);
        expect(preg_match('/BEGIN [A-Z ]*KEY|[A-Za-z0-9+\/]{60,}/', $texte))->toBe(0, "{$chemin} : clé ou secret");
        expect(preg_match('/\b\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3}\b/', $texte))->toBe(0, "{$chemin} : adresse IP");
        preg_match_all('/\b[a-z0-9-]+(?:\.[a-z0-9-]+)*\.(?:com|net|org|fr|io|eu|email|app)\b/i', $texte, $m);
        expect($m[0])->toBe([], "{$chemin} : nom de domaine réel");
    }
});

test('aucune route n’expose l’application de configuration', function () {
    foreach (Route::getRoutes() as $route) {
        expect(strtolower($route->uri() . $route->getActionName()))->not->toContain('configuration\\')->not->toContain('config-appliquer');
    }
})->uses(TestCase::class);
