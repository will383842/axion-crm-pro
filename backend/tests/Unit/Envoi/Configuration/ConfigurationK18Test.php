<?php

use App\Envoi\Configuration\AlerteDerive;
use App\Envoi\Configuration\Applicateur;
use App\Envoi\Configuration\DepotVersions;
use App\Envoi\Configuration\DomaineConfig;
use App\Envoi\Configuration\GenerateurConfiguration;
use App\Envoi\Configuration\Paquet;
use App\Envoi\Configuration\PaquetInvalide;
use App\Envoi\Configuration\SourceConfiguration;
use App\Envoi\Configuration\StatutVersion;
use App\Envoi\Configuration\VerdictConfiguration;
use Illuminate\Support\Facades\Log;
use Tests\Unit\Envoi\Configuration\FauxServeurEnvoiConfiguration;

const K18_A = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
const K18_B = 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb';

function k18Source(array $domaines): SourceConfiguration
{
    return new class($domaines) implements SourceConfiguration
    {
        public function __construct(private array $d) {}

        public function domaines(): array
        {
            return $this->d;
        }

        public function ignores(): array
        {
            return [];
        }
    };
}

function k18Domaine(string $nom = 'a.example.test', int $debit = 5, int $plafond = 8): DomaineConfig
{
    return new DomaineConfig($nom, $debit, $plafond);
}

function k18Brut(string $ws = K18_A, array $noms = ['a.example.test']): array
{
    return Paquet::pourEspace($ws, k18Source(array_map(fn ($n) => k18Domaine($n), $noms)))->contenu;
}

function k18Config(int $jour = 20, int $global = 20): void
{
    config(['envoi.plafond_jour' => $jour, 'envoi.plafond_global_jour' => $global]);
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

function k18Appliquer(array $brut, ?FauxServeurEnvoiConfiguration $faux = null, ?object $versions = null, ?object $alerte = null, string $ws = K18_A): StatutVersion
{
    k18Config();

    return (new Applicateur($faux ?? new FauxServeurEnvoiConfiguration, $versions ?? k18Versions(), $alerte ?? k18Alerte()))->appliquer($ws, $brut);
}

test('génération déterministe : même base, même empreinte, quel que soit l’ordre', function () {
    $a = Paquet::pourEspace(K18_A, k18Source([k18Domaine('a.example.test'), k18Domaine('b.example.test')]));
    $b = Paquet::pourEspace(K18_A, k18Source([k18Domaine('b.example.test'), k18Domaine('a.example.test')]));
    expect($a->empreinte())->toBe($b->empreinte())->toMatch('/^[0-9a-f]{64}$/');
    expect(Paquet::pourEspace(K18_A, k18Source([k18Domaine('a.example.test', 6), k18Domaine('b.example.test')]))->empreinte())->not->toBe($a->empreinte());
    expect((new GenerateurConfiguration)->generer(K18_A, k18Source([k18Domaine()]))->empreinte())
        ->toBe((new GenerateurConfiguration)->generer(K18_A, k18Source([k18Domaine()]))->empreinte());
});

test('même paquet, clés d’un domaine en ordre inverse : même empreinte (le tri des clés compte)', function () {
    k18Config();
    $brut = k18Brut();
    $inverse = $brut;
    $inverse['domaines'][0] = array_reverse($brut['domaines'][0], true);
    $inverse = array_reverse($inverse, true);
    expect(array_keys($inverse['domaines'][0]))->not->toBe(array_keys($brut['domaines'][0]));
    expect(Paquet::valide(K18_A, $inverse)->empreinte())->toBe(Paquet::valide(K18_A, $brut)->empreinte());
});

test('A puis B avec le faux : les deux états sont gardés et chaque paquet porte son espace', function () {
    $faux = new FauxServeurEnvoiConfiguration;
    expect(k18Appliquer(k18Brut(K18_A, ['a.example.test']), $faux, ws: K18_A))->toBe(StatutVersion::Appliquee);
    expect(k18Appliquer(k18Brut(K18_B, ['b.example.test']), $faux, ws: K18_B))->toBe(StatutVersion::Appliquee);
    expect(array_keys($faux->etat))->toBe([K18_A, K18_B]);
    expect($faux->etat[K18_A]['espace'])->toBe(K18_A)->and($faux->etat[K18_B]['espace'])->toBe(K18_B);
    expect($faux->etat[K18_A]['domaines'][0]['domaine'])->toBe('a.example.test');
    expect($faux->espacesVus)->toBe([K18_A, K18_B]);
});

test('espace du paquet différent de l’argument : Invalide, rien appliqué', function () {
    $faux = new FauxServeurEnvoiConfiguration;
    $v = k18Versions();
    expect(k18Appliquer(k18Brut(K18_B), $faux, $v, ws: K18_A))->toBe(StatutVersion::Invalide);
    expect($faux->appels)->toBe([])->and($v->lignes[0][1])->toBe(StatutVersion::Invalide);
});

test('même domaine dans deux espaces : le second est refusé par validerConfiguration, motif noté', function () {
    $faux = new FauxServeurEnvoiConfiguration;
    k18Appliquer(k18Brut(K18_A), $faux, ws: K18_A);
    $v = k18Versions();
    expect(k18Appliquer(k18Brut(K18_B), $faux, $v, ws: K18_B))->toBe(StatutVersion::Invalide);
    expect($faux->appels)->toBe(['valider', 'appliquer', 'valider']);
    expect($v->lignes[0][2])->toContain('déjà tenu par un autre espace');
    expect(array_keys($faux->etat))->toBe([K18_A]);
});

test('le serveur refuse la validation : aucun appel à appliquerConfiguration, motif du serveur noté', function () {
    $faux = new class extends FauxServeurEnvoiConfiguration
    {
        public function validerConfiguration(Paquet $paquet): VerdictConfiguration
        {
            $this->appels[] = 'valider';

            return new VerdictConfiguration(false, 'motif-serveur');
        }
    };
    $v = k18Versions();
    expect(k18Appliquer(k18Brut(), $faux, $v))->toBe(StatutVersion::Invalide);
    expect($faux->appels)->toBe(['valider'])->and($v->lignes[0][2])->toContain('motif-serveur');
});

test('le serveur refuse l’application : retour arrière, état identique', function () {
    $faux = new FauxServeurEnvoiConfiguration;
    k18Appliquer(k18Brut(K18_A, ['a.example.test']), $faux);
    $avant = $faux->etat;
    $faux->echouer = true;
    $v = k18Versions();
    expect(k18Appliquer(k18Brut(K18_A, ['c.example.test']), $faux, $v))->toBe(StatutVersion::RetourArriere);
    expect($faux->etat)->toBe($avant)->and($v->lignes[0][1])->toBe(StatutVersion::RetourArriere);
});

test('exception de transport : Incertaine (jamais Appliquee) et Log::critical', function () {
    Log::spy();
    $faux = new FauxServeurEnvoiConfiguration(transport: new RuntimeException('coupure'));
    $v = k18Versions();
    expect(k18Appliquer(k18Brut(), $faux, $v))->toBe(StatutVersion::Incertaine);
    expect($v->lignes[0][1])->toBe(StatutVersion::Incertaine)->and($faux->etat)->toBe([]);
    Log::shouldHaveReceived('critical')->atLeast()->once();
});

test('ligne de version impossible à écrire : Log::critical puis l’exception remonte', function () {
    Log::spy();
    $depot = new class implements DepotVersions
    {
        public function noter(string $workspaceId, string $empreinte, StatutVersion $statut, ?string $motif = null): void
        {
            throw new RuntimeException('base coupée');
        }
    };
    expect(fn () => k18Appliquer(k18Brut(), null, $depot))->toThrow(RuntimeException::class, 'base coupée');
    Log::shouldHaveReceived('critical')->once();
});

dataset('domaines_non_canoniques', [
    'majuscules' => 'A.Example.test',
    'point final' => 'a.example.test.',
    'espace devant' => ' a.example.test',
    'saut de ligne final' => "a.example.test\n",
    'cyrillique' => "\u{0430}.example.test",
    'sans point' => 'localhost',
    'UTF-8 invalide' => "a\xB1.example.test",
]);

test('domaine non canonique : Invalide, sans normalisation ni exception', function (string $nom) {
    $brut = k18Brut();
    $brut['domaines'][0]['domaine'] = $nom;
    $faux = new FauxServeurEnvoiConfiguration;
    expect(k18Appliquer($brut, $faux))->toBe(StatutVersion::Invalide);
    expect($faux->appels)->toBe([]);
})->with('domaines_non_canoniques');

test('empreinte ne lève jamais sur UTF-8 invalide', function () {
    $brut = k18Brut();
    $brut['domaines'][0]['domaine'] = "a\xB1.example.test";
    expect(Paquet::empreinteBrute($brut))->toMatch('/^[0-9a-f]{64}$/');
    expect(fn () => Paquet::valide(K18_A, $brut))->toThrow(PaquetInvalide::class);
});

test('domaine en double : Invalide', function () {
    $brut = k18Brut(K18_A, ['a.example.test', 'a.example.test']);
    expect(k18Appliquer($brut))->toBe(StatutVersion::Invalide);
});

dataset('paquets_invalides', [
    'sélecteur' => fn () => ['domaine' => 'a.example.test', 'debit_heure' => 5, 'plafond_jour' => 8, 'selecteur' => 's1'],
    'cle_publique' => fn () => ['domaine' => 'a.example.test', 'debit_heure' => 5, 'plafond_jour' => 8, 'cle_publique' => 'MIIB'],
    'clé neutre options' => fn () => ['domaine' => 'a.example.test', 'debit_heure' => 5, 'plafond_jour' => 8, 'options' => 1],
    'débit nul' => fn () => ['domaine' => 'a.example.test', 'debit_heure' => 0, 'plafond_jour' => 8],
    'débit en chaîne' => fn () => ['domaine' => 'a.example.test', 'debit_heure' => '5', 'plafond_jour' => 8],
    'débit au-dessus du plafond du domaine' => fn () => ['domaine' => 'a.example.test', 'debit_heure' => 9, 'plafond_jour' => 8],
    'plafond au-dessus du plafond dur' => fn () => ['domaine' => 'a.example.test', 'debit_heure' => 5, 'plafond_jour' => 21],
    'domaine absent' => fn () => ['debit_heure' => 5, 'plafond_jour' => 8],
]);

test('domaine mal formé : Invalide, rien appliqué', function (Closure $d) {
    $faux = new FauxServeurEnvoiConfiguration;
    $v = k18Versions();
    expect(k18Appliquer(['version' => 1, 'espace' => K18_A, 'domaines' => [($d)()]], $faux, $v))->toBe(StatutVersion::Invalide);
    expect($faux->appels)->toBe([])->and($v->lignes[0][1])->toBe(StatutVersion::Invalide);
})->with('paquets_invalides');

test('structure du haut', function () {
    $ok = k18Brut();
    foreach ([
        'version "1"' => array_replace($ok, ['version' => '1']),
        'version 2' => array_replace($ok, ['version' => 2]),
        'espace en majuscules' => array_replace($ok, ['espace' => strtoupper(K18_A)]),
        'domaines vide' => array_replace($ok, ['domaines' => []]),
        'domaines objet' => array_replace($ok, ['domaines' => ['x' => $ok['domaines'][0]]]),
        'sans espace' => array_diff_key($ok, ['espace' => 1]),
        'clé en trop' => $ok + ['extra' => 1],
        'structure absente' => ['x' => 1],
    ] as $nom => $brut) {
        expect(k18Appliquer($brut))->toBe(StatutVersion::Invalide, $nom);
    }
});

test('profondeur et nombre de domaines bornés AVANT tout parcours', function () {
    $profond = [];
    for ($i = 0; $i < 50; $i++) {
        $profond = ['x' => $profond];
    }
    expect(k18Appliquer(['version' => 1, 'espace' => K18_A, 'domaines' => [$profond]]))->toBe(StatutVersion::Invalide);
    $abyme = [];
    for ($i = 0; $i < 5000; $i++) {
        $abyme = [$abyme];
    }
    expect(k18Appliquer($abyme))->toBe(StatutVersion::Invalide);
    $noms = array_map(fn ($i) => "d{$i}.example.test", range(1, 51));
    k18Config(1000, 1000);
    $brut = array_map(fn ($n) => ['domaine' => $n, 'debit_heure' => 1, 'plafond_jour' => 1], $noms);
    expect((new Applicateur(new FauxServeurEnvoiConfiguration, k18Versions(), k18Alerte()))->appliquer(K18_A, ['version' => 1, 'espace' => K18_A, 'domaines' => $brut]))->toBe(StatutVersion::Invalide);
});

test('plafonds : min(plafond_jour, plafond_global_jour), dans les deux sens', function () {
    $brut = ['version' => 1, 'espace' => K18_A, 'domaines' => [['domaine' => 'a.example.test', 'debit_heure' => 5, 'plafond_jour' => 50]]];
    foreach ([[10, 100], [100, 10]] as [$jour, $global]) {
        k18Config($jour, $global);
        expect((new Applicateur(new FauxServeurEnvoiConfiguration, k18Versions(), k18Alerte()))->appliquer(K18_A, $brut))->toBe(StatutVersion::Invalide);
    }
    k18Config(100, 100);
    expect((new Applicateur(new FauxServeurEnvoiConfiguration, k18Versions(), k18Alerte()))->appliquer(K18_A, $brut))->toBe(StatutVersion::Appliquee);
    k18Config(0, 100);
    expect((new Applicateur(new FauxServeurEnvoiConfiguration, k18Versions(), k18Alerte()))->appliquer(K18_A, $brut))->toBe(StatutVersion::Invalide);
});

test('somme des plafonds des domaines au-dessus du plafond dur : Invalide', function () {
    $d = fn ($n) => ['domaine' => $n, 'debit_heure' => 1, 'plafond_jour' => 8];
    $brut = ['version' => 1, 'espace' => K18_A, 'domaines' => [$d('a.example.test'), $d('b.example.test')]];
    k18Config(10, 10);
    expect((new Applicateur(new FauxServeurEnvoiConfiguration, k18Versions(), k18Alerte()))->appliquer(K18_A, $brut))->toBe(StatutVersion::Invalide);
    k18Config(16, 16);
    expect((new Applicateur(new FauxServeurEnvoiConfiguration, k18Versions(), k18Alerte()))->appliquer(K18_A, $brut))->toBe(StatutVersion::Appliquee);
});

dataset('tentatives_socle', [
    'plafondDur' => [['plafondDur' => 9]],
    'plafond.dur' => [['plafond.dur' => 9]],
    'plafond_dur' => [['plafond_dur' => 9]],
    'homme_mort espaces' => [['homme_mort  ' => 1]],
    'hommeMort' => [['hommeMort' => 1]],
    'registre' => [['registre' => 1]],
    'domaine_principal' => [['domaine_principal' => 1]],
    'domaines_interdits' => [['domaines-interdits' => 1]],
    'mode_temoins' => [['Mode_Temoins' => 1]],
    'liste blanche' => [['liste_blanche' => 1]],
    'whitelist' => [['WhiteList' => 1]],
]);

test('tentative de changer le socle local : refus + alerte de dérive, en haut et imbriquée', function (array $extra) {
    foreach (['haut', 'domaine'] as $niveau) {
        $brut = k18Brut();
        if ($niveau === 'haut') {
            $brut += $extra;
        } else {
            $brut['domaines'][0] += $extra;
        }
        $faux = new FauxServeurEnvoiConfiguration;
        $v = k18Versions();
        $alerte = k18Alerte();
        expect(k18Appliquer($brut, $faux, $v, $alerte))->toBe(StatutVersion::DeriveRefusee, $niveau);
        expect($faux->appels)->toBe([])->and($alerte->alertes)->toHaveCount(1)->and($alerte->alertes[0])->not->toBeEmpty();
        expect($v->lignes[0][1])->toBe(StatutVersion::DeriveRefusee);
    }
})->with('tentatives_socle');

test('étape invalide ne doit pas alerter : une clé neutre est seulement Invalide', function () {
    $brut = k18Brut() + ['options' => 1];
    $alerte = k18Alerte();
    expect(k18Appliquer($brut, null, null, $alerte))->toBe(StatutVersion::Invalide);
    expect($alerte->alertes)->toBe([]);
});

test('aucun fichier écrit sous app/Envoi/Configuration (analyse statique)', function () {
    $interdits = ['file_put_contents', 'rename', 'fopen', 'fwrite', 'unlink', 'copy', 'touch', 'mkdir', 'tempnam', 'symlink', 'chmod'];
    $trouves = [];
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path('Envoi/Configuration'), FilesystemIterator::SKIP_DOTS)) as $f) {
        foreach (token_get_all((string) file_get_contents($f->getPathname())) as $t) {
            if (is_array($t) && $t[0] === T_STRING && in_array(strtolower($t[1]), $interdits, true)) {
                $trouves[] = basename($f->getPathname()) . ':' . $t[1];
            }
        }
    }
    expect($trouves)->toBe([]);
});

/** @return list<string> jetons qui ressemblent à un domaine réel */
function k18JetonsDomaineReel(string $texte): array
{
    $extensionsFichier = ['md', 'json', 'modele', 'php', 'py', 'pem', 'conf', 'txt', 'sh', 'yml', 'yaml', 'toml', 'lua', 'precedent'];
    preg_match_all('/\b[a-z0-9_-]+(?:\.[a-z0-9_-]+)*\.([a-z]{2,63})\b/i', $texte, $m);
    $reels = [];
    foreach ($m[0] as $i => $jeton) {
        $fin = strtolower($m[1][$i]);
        if (! in_array($fin, ['test', 'example', 'invalid', ...$extensionsFichier], true)) {
            $reels[] = $jeton;
        }
    }

    return $reels;
}

test('le motif « domaine réel » attrape .de .ovh .cloud .pro .ai .com .fr', function () {
    foreach (['a.de', 'serveur.ovh', 'x.cloud', 'cabinet.pro', 'modele.ai', 'z.com', 'y.fr', 'sous.domaine.eu'] as $reel) {
        expect(k18JetonsDomaineReel("hôte {$reel} ici"))->toBe([$reel]);
    }
    expect(k18JetonsDomaineReel('a.example.test b.invalid c.example espace.json.modele'))->toBe([]);
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
        expect(k18JetonsDomaineReel($texte))->toBe([], "{$chemin} : nom de domaine réel");
    }
    expect(file_exists($racine . '/modeles/espace.json.modele'))->toBeTrue();
    expect(file_exists($racine . '/modeles/domaines.json.modele'))->toBeFalse();
});

test('aucune route n’expose l’application de configuration', function () {
    foreach (Route::getRoutes() as $route) {
        expect(strtolower($route->uri()))->not->toContain('config-appliquer');
        expect($route->getActionName())->not->toContain('App\\Envoi\\Configuration');
    }
});
