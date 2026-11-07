<?php

use App\Envoi\ListesNoires\Alerteur;
use App\Envoi\ListesNoires\DepotControles;
use App\Envoi\ListesNoires\DetecteurResolveurPublic;
use App\Envoi\ListesNoires\Ressource;
use App\Envoi\ListesNoires\ResolveurDns;
use App\Envoi\ListesNoires\StatutListe;
use App\Envoi\ListesNoires\SurveillanceListes;

function fauxResolveur(string $adresse, array $reponses, bool $panne = false): ResolveurDns
{
    return new class($adresse, $reponses, $panne) implements ResolveurDns
    {
        public array $requetes = [];

        public function __construct(private string $a, private array $r, private bool $panne) {}

        public function adresse(): string
        {
            return $this->a;
        }

        public function interroger(string $nom): array
        {
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

        public function enregistrer(Ressource $r, StatutListe $statut, string $zone, ?string $reponse, bool $pause, DateTimeImmutable $controleLe, ?DateTimeImmutable $pauseLe = null): void
        {
            $this->lignes[] = compact('statut', 'zone', 'reponse', 'pause', 'controleLe', 'pauseLe');
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

        public function alerter(Ressource $r, string $zone, string $reponse): void
        {
            $this->alertes[] = [$r->valeur, $zone, $reponse];
        }
    };
}

function surveillance(ResolveurDns $res, DepotControles $d, Alerteur $a): SurveillanceListes
{
    return new SurveillanceListes($res, $d, $a, ['zen.spamhaus.org'], ['dbl.spamhaus.org'], fn () => new DateTimeImmutable('2026-11-11 10:00:00'));
}

test('réponse « listé » : pause immédiate (< 5 min) et alerte', function () {
    $d = fauxDepot();
    $a = fauxAlerteur();
    $res = fauxResolveur('127.0.0.1', ['4.3.2.1.zen.spamhaus.org' => ['127.0.0.2']]);
    $r = new Ressource('ws', Ressource::IP, '1.2.3.4');

    expect(surveillance($res, $d, $a)->controlerUne($r))->toBe(StatutListe::Liste);

    $l = $d->lignes[0];
    expect($l['pause'])->toBeTrue()
        ->and($l['pauseLe']->getTimestamp() - $l['controleLe']->getTimestamp())->toBeLessThan(300)
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
    $e = collect(app(Illuminate\Console\Scheduling\Schedule::class)->events())
        ->first(fn ($ev) => str_contains((string) $ev->command, 'envoi:listes-noires:controler'));
    expect($e)->not->toBeNull()->and($e->expression)->toBe('* * * * *');
})->uses(Tests\TestCase::class);
