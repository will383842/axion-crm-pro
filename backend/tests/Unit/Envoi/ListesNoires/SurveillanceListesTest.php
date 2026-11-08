<?php

use App\Envoi\ListesNoires\Alerteur;
use App\Envoi\ListesNoires\DepotControles;
use App\Envoi\ListesNoires\DetecteurResolveurPublic;
use App\Envoi\ListesNoires\ResolveurDns;
use App\Envoi\ListesNoires\ResolveurSysteme;
use App\Envoi\ListesNoires\Ressource;
use App\Envoi\ListesNoires\SourceRessources;
use App\Envoi\ListesNoires\StatutListe;
use App\Envoi\ListesNoires\SurveillanceListes;
use Illuminate\Console\Scheduling\Schedule;
use Tests\TestCase;

/** @param  string|list<string>  $adresses  serveurs de noms du système */
function fauxResolveur(string|array $adresses, array $reponses, bool $panne = false, bool $sondeOk = true): ResolveurDns
{
    return new class((array) $adresses, $reponses, $panne, $sondeOk) implements ResolveurDns
    {
        public array $requetes = [];

        public function __construct(private array $a, private array $r, private bool $panne, private bool $sondeOk) {}

        public function adresses(): array
        {
            return $this->a;
        }

        public function interroger(string $nom): array
        {
            if ($nom === SurveillanceListes::NOM_SONDE) {
                return $this->sondeOk ? ['127.0.0.2'] : [];
            }
            $this->requetes[] = $nom;
            if ($this->panne) {
                throw new RuntimeException('timeout');
            }

            return $this->r[$nom] ?? [];
        }
    };
}

function fauxDepot(): DepotControles
{
    return new class implements DepotControles
    {
        public array $lignes = [];

        public ?string $casse = null;

        public function enregistrer(Ressource $r, StatutListe $statut, string $zone, ?string $reponse, bool $pause, DateTimeImmutable $controleLe, ?DateTimeImmutable $pauseLe = null): bool
        {
            if ($r->valeur === $this->casse) {
                throw new RuntimeException('base indisponible');
            }
            $d = end($this->lignes);
            // même dédoublonnage horaire que DepotControlesDb pour un état inchangé non « listé »
            if ($d !== false && ! $pause && $d['statut'] === $statut && $d['zone'] === $zone
                && $controleLe->getTimestamp() - $d['controleLe']->getTimestamp() < 3600) {
                return false;
            }
            $this->lignes[] = compact('statut', 'zone', 'reponse', 'pause', 'controleLe', 'pauseLe');

            return true;
        }

        public function dernierStatut(Ressource $r): ?StatutListe
        {
            $d = end($this->lignes);

            return $d === false ? null : $d['statut'];
        }

        public function enPause(Ressource $r): bool
        {
            return (bool) end($this->lignes)['pause'];
        }
    };
}

function fauxAlerteur(): Alerteur
{
    return new class implements Alerteur
    {
        public array $alertes = [];

        public array $nonFiables = [];

        public function alerter(Ressource $r, string $zone, string $reponse): void
        {
            $this->alertes[] = [$r->valeur, $zone, $reponse];
        }

        public function alerterNonFiable(Ressource $r, string $raison): void
        {
            $this->nonFiables[] = [$r->valeur, $raison];
        }
    };
}

function surveillance(ResolveurDns $res, DepotControles $d, Alerteur $a, string $quand = '2026-11-11 10:00:00'): SurveillanceListes
{
    return new SurveillanceListes($res, $d, $a, ['zen.spamhaus.org'], ['dbl.spamhaus.org'], fn () => new DateTimeImmutable($quand));
}

test('réponse « listé » : pause immédiate (même passage) et alerte', function () {
    $d = fauxDepot();
    $a = fauxAlerteur();
    $res = fauxResolveur('127.0.0.1', ['4.3.2.1.zen.spamhaus.org' => ['127.0.0.2']]);
    $r = new Ressource('ws', Ressource::IP, '1.2.3.4');

    expect(surveillance($res, $d, $a)->controlerUne($r))->toBe(StatutListe::Liste);

    $l = $d->lignes[0];
    expect($l['pause'])->toBeTrue()
        ->and($l['pauseLe'])->toEqual($l['controleLe'])
        ->and($d->enPause($r))->toBeTrue()
        ->and($a->alertes)->toBe([['1.2.3.4', 'zen.spamhaus.org', '127.0.0.2']]);
});

test('domaine listé : pause + alerte', function () {
    $d = fauxDepot();
    $a = fauxAlerteur();
    $res = fauxResolveur('10.0.0.53', ['mauvais.example.dbl.spamhaus.org' => ['127.0.1.2']]);

    expect(surveillance($res, $d, $a)->controlerUne(new Ressource('ws', Ressource::DOMAINE, 'Mauvais.Example')))->toBe(StatutListe::Liste);
    expect($a->alertes)->toHaveCount(1)->and($d->lignes[0]['pause'])->toBeTrue();
});

test('résolveur public détecté : non fiable, jamais vert, aucune requête', function (string $ip) {
    $d = fauxDepot();
    $res = fauxResolveur($ip, []);

    expect(surveillance($res, $d, fauxAlerteur())->controlerUne(new Ressource('ws', Ressource::IP, '1.2.3.4')))->toBe(StatutListe::NonFiable);
    expect($d->lignes[0]['statut'])->toBe(StatutListe::NonFiable)->and($d->lignes[0]['pause'])->toBeFalse()->and($res->requetes)->toBe([]);
})->with(['8.8.8.8', '1.1.1.1', '9.9.9.9', '203.0.113.7', 'pas-une-ip', '']);

test('détecteur : locaux fiables', function (string $ip) {
    expect(DetecteurResolveurPublic::estPublic($ip))->toBeFalse();
})->with(['127.0.0.1', '10.1.2.3', '172.20.0.2', '192.168.1.1', '::1']);

test('172.32.x est public', fn () => expect(DetecteurResolveurPublic::estPublic('172.32.0.1'))->toBeTrue());

test('non listé : vert seulement avec un résolveur local', function () {
    $d = fauxDepot();
    expect(surveillance(fauxResolveur('127.0.0.1', []), $d, fauxAlerteur())->controlerUne(new Ressource('ws', Ressource::IP, '1.2.3.4')))->toBe(StatutListe::NonListe);
});

test('panne DNS ou code 127.255.255.x : non fiable, pas vert, pas de pause', function () {
    $r = new Ressource('ws', Ressource::IP, '1.2.3.4');
    $d = fauxDepot();
    expect(surveillance(fauxResolveur('127.0.0.1', [], true), $d, fauxAlerteur())->controlerUne($r))->toBe(StatutListe::NonFiable);
    $d2 = fauxDepot();
    $res = fauxResolveur('127.0.0.1', ['4.3.2.1.zen.spamhaus.org' => ['127.255.255.254']]);
    expect(surveillance($res, $d2, fauxAlerteur())->controlerUne($r))->toBe(StatutListe::NonFiable);
    expect($d2->lignes[0]['pause'])->toBeFalse();
});

test('planification : toutes les minutes (< 5 min)', function () {
    $e = collect(app(Schedule::class)->events())
        ->first(fn ($ev) => str_contains((string) $ev->command, 'envoi:listes-noires:controler'));
    expect($e)->not->toBeNull()->and($e->expression)->toBe('* * * * *');
})->uses(TestCase::class);

test('M2 : un seul serveur de noms public, relayé (127.0.0.11) ou absent => non fiable, aucune requête', function (array $adresses) {
    $d = fauxDepot();
    $res = fauxResolveur($adresses, ['4.3.2.1.zen.spamhaus.org' => ['127.0.0.2']]);

    expect(surveillance($res, $d, fauxAlerteur())->controlerUne(new Ressource('ws', Ressource::IP, '1.2.3.4')))->toBe(StatutListe::NonFiable);
    expect($res->requetes)->toBe([])->and($d->lignes[0]['pause'])->toBeFalse();
})->with([[['127.0.0.1', '8.8.8.8']], [['127.0.0.11']], [['10.0.0.53', '1.1.1.1']], [[]]]);

test('M2 : sonde de contrôle muette ou refusée => non fiable (même résolveur « local »)', function (bool $ok, array $adresses) {
    $d = fauxDepot();
    $res = fauxResolveur($adresses, [], false, $ok);

    expect(surveillance($res, $d, fauxAlerteur())->controlerUne(new Ressource('ws', Ressource::IP, '1.2.3.4')))->toBe($ok ? StatutListe::NonListe : StatutListe::NonFiable);
    expect($d->lignes[0]['reponse'])->toBe($ok ? null : 'sonde_ko');
})->with([[false, ['127.0.0.1']], [true, ['127.0.0.1']]]);

test('M2 : ResolveurSysteme lit les nameserver de resolv.conf', function () {
    $f = tempnam(sys_get_temp_dir(), 'rc');
    file_put_contents($f, "# test\nsearch example.invalid\nnameserver 10.0.0.53\nnameserver  127.0.0.11 \noptions ndots:0\nnameserver pas-une-ip\n");
    expect((new ResolveurSysteme($f))->adresses())->toBe(['10.0.0.53', '127.0.0.11']);
    expect((new ResolveurSysteme('/inexistant/resolv.conf'))->adresses())->toBe([]);
    unlink($f);
});

test('M2 : détecteur — 127.0.0.11 (relais Docker) et liste vide ne sont pas fiables', function () {
    expect(DetecteurResolveurPublic::estPublic('127.0.0.11'))->toBeTrue()
        ->and(DetecteurResolveurPublic::estFiable([]))->toBeFalse()
        ->and(DetecteurResolveurPublic::estFiable(['127.0.0.1', '10.0.0.2']))->toBeTrue()
        ->and(DetecteurResolveurPublic::estFiable(['127.0.0.1', '127.0.0.11']))->toBeFalse();
});

test('M3 : alerte dès le premier passage non fiable, puis une fois par heure au plus', function () {
    $d = fauxDepot();
    $a = fauxAlerteur();
    $r = new Ressource('ws', Ressource::IP, '1.2.3.4');
    $res = fauxResolveur('127.0.0.1', [], false, false);

    surveillance($res, $d, $a, '2026-11-11 10:00:00')->controlerUne($r);
    surveillance($res, $d, $a, '2026-11-11 10:01:00')->controlerUne($r);
    surveillance($res, $d, $a, '2026-11-11 10:30:00')->controlerUne($r);
    expect($a->nonFiables)->toBe([['1.2.3.4', 'sonde_ko']]);

    surveillance($res, $d, $a, '2026-11-11 11:00:01')->controlerUne($r);
    expect($a->nonFiables)->toHaveCount(2);
});

test('M3 : la commande échoue (FAILURE) s\'il y a au moins un non fiable ou une ressource rejetée', function (array $adresses, array $rejets, int $code) {
    $d = fauxDepot();
    $s = surveillance(fauxResolveur($adresses, []), $d, fauxAlerteur());
    $source = new class($rejets) implements SourceRessources
    {
        public function __construct(private array $rejets) {}

        public function toutes(): array
        {
            return [new Ressource('ws', Ressource::IP, '1.2.3.4')];
        }

        public function rejets(): array
        {
            return $this->rejets;
        }
    };
    $this->app->instance(SurveillanceListes::class, $s);
    $this->app->instance(SourceRessources::class, $source);

    $this->artisan('envoi:listes-noires:controler')->assertExitCode($code);
})->with([
    'tout sain' => [['127.0.0.1'], [], 0],
    'non fiable' => [['8.8.8.8'], [], 1],
    'configuration rejetée' => [['127.0.0.1'], ['ip@pas-un-uuid'], 1],
])->uses(TestCase::class);

test('m1 : alerte seulement à la TRANSITION vers listé (pas à chaque minute)', function () {
    $d = fauxDepot();
    $a = fauxAlerteur();
    $r = new Ressource('ws', Ressource::IP, '1.2.3.4');
    $listé = fauxResolveur('127.0.0.1', ['4.3.2.1.zen.spamhaus.org' => ['127.0.0.2']]);
    $propre = fauxResolveur('127.0.0.1', []);

    surveillance($listé, $d, $a)->controlerUne($r);
    surveillance($listé, $d, $a)->controlerUne($r);
    surveillance($listé, $d, $a)->controlerUne($r);
    expect($a->alertes)->toHaveCount(1);

    surveillance($propre, $d, $a)->controlerUne($r);
    surveillance($listé, $d, $a)->controlerUne($r);
    expect($a->alertes)->toHaveCount(2);
});

test('m3 : IP invalide => non fiable (ressource_invalide), aucune requête DNS', function (string $ip) {
    $d = fauxDepot();
    $res = fauxResolveur('127.0.0.1', []);

    expect(surveillance($res, $d, fauxAlerteur())->controlerUne(new Ressource('ws', Ressource::IP, $ip)))->toBe(StatutListe::NonFiable);
    expect($d->lignes[0]['reponse'])->toBe('ressource_invalide')->and($res->requetes)->toBe([]);
})->with(['999.1.1.1', 'abc', '1.2.3', '1.2.3.4.5', '']);

test('m4 : seules les plages de listage connues comptent « listé », le reste est non fiable', function (string $code, StatutListe $attendu) {
    $d = fauxDepot();
    $a = fauxAlerteur();
    $res = fauxResolveur('127.0.0.1', ['4.3.2.1.zen.spamhaus.org' => [$code]]);

    expect(surveillance($res, $d, $a)->controlerUne(new Ressource('ws', Ressource::IP, '1.2.3.4')))->toBe($attendu);
    expect($d->lignes[0]['pause'])->toBe($attendu === StatutListe::Liste);
})->with([
    ['127.0.0.2', StatutListe::Liste], ['127.0.0.4', StatutListe::Liste], ['127.0.0.11', StatutListe::Liste],
    ['127.0.0.1', StatutListe::NonFiable], ['127.0.0.12', StatutListe::NonFiable], ['203.0.113.9', StatutListe::NonFiable],
    ['127.0.1.255', StatutListe::NonFiable], ['127.255.255.254', StatutListe::NonFiable],
]);

test('m2 : une ressource qui plante est isolée, marquée non fiable ; les suivantes sont contrôlées', function () {
    $d = fauxDepot();
    $d->casse = '1.1.1.9';
    $a = fauxAlerteur();
    $source = new class implements SourceRessources
    {
        public function toutes(): array
        {
            return [new Ressource('ws', Ressource::IP, '1.1.1.9'), new Ressource('ws', Ressource::DOMAINE, 'ok.example')];
        }

        public function rejets(): array
        {
            return [];
        }
    };

    $bilan = surveillance(fauxResolveur('127.0.0.1', []), $d, $a)->controler($source);

    expect($bilan)->toBe(['ip:1.1.1.9' => StatutListe::NonFiable, 'domaine:ok.example' => StatutListe::NonListe])
        ->and($a->nonFiables)->toBe([['1.1.1.9', 'erreur_controle']]);
});
