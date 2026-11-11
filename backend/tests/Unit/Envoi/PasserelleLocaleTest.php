<?php

use App\Envoi\Contrat\EtatMessage;
use App\Envoi\Contrat\MessageAEnvoyer;
use App\Envoi\Contrat\ServeurEnvoi;
use App\Envoi\Exceptions\EnvoiRefuse;
use App\Envoi\PasserelleLocale;
use App\Envoi\Simulateur\FauxServeurEnvoi;
use Tests\Unit\Envoi\DepotMemoire;

require_once __DIR__ . '/../../Envoi/KitConformiteServeurEnvoi.php';
require_once __DIR__ . '/DepotMemoire.php';

const WS = '00000000-0000-4000-8000-000000000001';

function msg(string $dest = 'temoin@example.test', string $cle = 'c1'): MessageAEnvoyer
{
    return new MessageAEnvoyer(WS, $dest, 'from@example.test', 'S', 'C', $cle);
}

function passerelle(?FauxServeurEnvoi $faux = null, ?DepotMemoire $depot = null, bool|Closure $reel = true, int|Closure $plafond = 5, ?string $espace = null, ?Closure $maintenant = null, int|Closure|null $global = null, ?Closure $contexte = null): PasserelleLocale
{
    return new PasserelleLocale(
        $faux ?? new FauxServeurEnvoi,
        $depot ?? new DepotMemoire,
        $reel,
        $plafond,
        $maintenant ?? fn () => new DateTimeImmutable('2026-11-10 15:30:00'),
        $espace ?? WS,
        $global,
        $contexte,
    );
}

// Le kit tourne aussi sur la passerelle (drapeau ouvert, témoin autorisé).
kitConformiteServeurEnvoi(fn () => passerelle(plafond: 300));

test('drapeau éteint : exception et ZÉRO appel au serveur', function () {
    $faux = new FauxServeurEnvoi;
    $p = passerelle($faux, reel: false);
    expect(fn () => $p->injecter(msg()))->toThrow(EnvoiRefuse::class, 'Envoi réel inactif');
    expect($faux->appels)->toBe(0);
});

test('drapeau éteint : lectures neutres, aucune délégation', function () {
    $faux = new FauxServeurEnvoi;
    $p = passerelle($faux, reel: false);
    $p->pause();
    $p->reprise();
    expect($p->etatEnvoi('x'))->toBe(EtatMessage::Inconnu);
    expect($p->etat()->enPause)->toBeTrue();
    expect($p->sante()->ok)->toBeFalse();
    expect($p->lireEvenements('7')->curseurSuivant)->toBe('7');
    expect($faux->appels)->toBe(0)->and($faux->etat()->enPause)->toBeFalse();
});

test('le drapeau est relu à chaque injection (closure)', function () {
    $etat = new ArrayObject(['actif' => true]);
    $faux = new FauxServeurEnvoi;
    $p = passerelle($faux, reel: fn () => $etat['actif']);
    expect($p->injecter(msg())->accepte)->toBeTrue();
    $etat['actif'] = false;
    expect(fn () => $p->injecter(msg(cle: 'c2')))->toThrow(EnvoiRefuse::class);
    expect($faux->appels)->toBe(1);
});

test('mode témoins : refuse hors liste, accepte un témoin', function () {
    $faux = new FauxServeurEnvoi;
    $p = passerelle($faux);
    expect(fn () => $p->injecter(msg('inconnu@example.test')))->toThrow(EnvoiRefuse::class, 'témoins');
    expect($faux->appels)->toBe(0);
    expect($p->injecter(msg())->accepte)->toBeTrue();
});

test('mode témoins éteint : une adresse hors liste passe', function () {
    $depot = new DepotMemoire;
    $depot->eteindreTemoins(WS, 'will');
    expect(passerelle(depot: $depot)->injecter(msg('autre@example.test'))->accepte)->toBeTrue();
    expect($depot->journal)->toBe([['temoins_off', 'will']]);
});

test('plafond dur : au-delà, report au lendemain sans appel', function () {
    $faux = new FauxServeurEnvoi;
    $p = passerelle($faux, plafond: fn () => 2);
    $p->injecter(msg(cle: 'a'));
    $p->injecter(msg(cle: 'b'));
    $r = $p->injecter(msg(cle: 'c'));
    expect($r->accepte)->toBeFalse()->and($r->reporteAu->format('Y-m-d'))->toBe('2026-11-11');
    expect($faux->appels)->toBe(2);
});

test('refus du serveur : le compteur du jour n’avance pas', function () {
    $faux = new FauxServeurEnvoi;
    $depot = new DepotMemoire;
    $p = passerelle($faux, $depot);
    $faux->pause();
    expect($p->injecter(msg())->accepte)->toBeFalse();
    expect($depot->compteurs)->toBe([]);
});

test('tout arrêter : rien ne part, y compris après une première injection', function () {
    $faux = new FauxServeurEnvoi;
    $depot = new DepotMemoire;
    $p = passerelle($faux, $depot);
    expect($p->injecter(msg(cle: 'a'))->accepte)->toBeTrue();
    $depot->arret = true;
    expect(fn () => $p->injecter(msg(cle: 'b')))->toThrow(EnvoiRefuse::class, 'Tout arrêter');
    expect($faux->appels)->toBe(1);
});

test('mode témoins de la passerelle : fermé par défaut, lu du dépôt sinon', function () {
    expect(passerelle(espace: '')->modeTemoins())->toBeTrue();
    $depot = new DepotMemoire;
    $depot->temoinsAllumes = false;
    expect(passerelle(depot: $depot)->modeTemoins())->toBeFalse();
});

test('la passerelle respecte le contrat', function () {
    expect(passerelle())->toBeInstanceOf(ServeurEnvoi::class);
});

test('injection : tout se passe sous le verrou du jour', function () {
    $depot = new DepotMemoire;
    passerelle(depot: $depot)->injecter(msg());
    // L8-F14a : deux transactions COURTES sous le verrou (A : réservation ; C : conclusion), le serveur est appelé entre les deux, hors verrou.
    expect($depot->verrous)->toBe(2);
});

test('réinjection idempotente (même clé) : le compteur n’avance pas', function () {
    $depot = new DepotMemoire;
    $p = passerelle(depot: $depot, plafond: 1);
    expect($p->injecter(msg(cle: 'k'))->accepte)->toBeTrue();
    expect($p->injecter(msg(cle: 'k'))->accepte)->toBeTrue(); // plafond atteint, mais rejeu : pas de report
    expect($depot->compteurs)->toBe(['2026-11-10' => 1]);
    expect($p->injecter(msg(cle: 'autre'))->accepte)->toBeFalse();
});

test('plafond global en plus du plafond par espace', function () {
    $faux = new FauxServeurEnvoi;
    $depot = new DepotMemoire;
    $p = passerelle($faux, $depot, plafond: 10, global: 1);
    expect($p->injecter(msg(cle: 'a'))->accepte)->toBeTrue();
    expect($p->injecter(msg(cle: 'b'))->accepte)->toBeFalse();
    expect($faux->appels)->toBe(1);
});

test('plafond hors borne (> 300 ou négatif) : refusé, rien ne part', function (int $valeur) {
    $faux = new FauxServeurEnvoi;
    expect(fn () => passerelle($faux, plafond: $valeur)->injecter(msg()))->toThrow(EnvoiRefuse::class, 'Plafond');
    expect(fn () => passerelle($faux, global: $valeur)->injecter(msg()))->toThrow(EnvoiRefuse::class, 'Plafond');
    expect($faux->appels)->toBe(0);
})->with([301, 100000, -1]);

test('plafond à 300 : accepté (borne incluse)', function () {
    expect(passerelle(plafond: 300, global: 300)->injecter(msg())->accepte)->toBeTrue();
});

test('message d’un autre espace que le contexte courant : refusé', function () {
    $faux = new FauxServeurEnvoi;
    $autre = '00000000-0000-4000-8000-000000000002';
    expect(fn () => passerelle($faux, contexte: fn () => $autre)->injecter(msg()))->toThrow(EnvoiRefuse::class, 'autre espace');
    expect(fn () => passerelle($faux, contexte: fn () => null)->injecter(msg()))->toThrow(EnvoiRefuse::class, 'autre espace');
    expect($faux->appels)->toBe(0);
    expect(passerelle($faux, contexte: fn () => WS)->injecter(msg())->accepte)->toBeTrue();
});

test('le jour est celui d’Europe/Paris, y compris aux bornes de minuit', function (string $instantUtc, string $jourAttendu) {
    $depot = new DepotMemoire;
    $p = passerelle(depot: $depot, maintenant: fn () => new DateTimeImmutable($instantUtc, new DateTimeZone('UTC')));
    $p->injecter(msg());
    expect(array_keys($depot->compteurs))->toBe([$jourAttendu]);
})->with([
    'hiver, 22:59 UTC = 23:59 Paris' => ['2026-11-10 22:59:59', '2026-11-10'],
    'hiver, 23:00 UTC = minuit Paris' => ['2026-11-10 23:00:00', '2026-11-11'],
    'été, 21:59 UTC = 23:59 Paris' => ['2026-07-10 21:59:59', '2026-07-10'],
    'été, 22:00 UTC = minuit Paris' => ['2026-07-10 22:00:00', '2026-07-11'],
]);

test('config : ENVOI_REEL_ACTIF non/off/faux/0/vide = éteint ; seul true/1/on/oui... reconnu par filter_var', function (string $valeur, bool $attendu) {
    putenv('ENVOI_REEL_ACTIF=' . $valeur);
    $_ENV['ENVOI_REEL_ACTIF'] = $_SERVER['ENVOI_REEL_ACTIF'] = $valeur;
    try {
        $config = require base_path('config/envoi.php');
    } finally {
        putenv('ENVOI_REEL_ACTIF');
        unset($_ENV['ENVOI_REEL_ACTIF'], $_SERVER['ENVOI_REEL_ACTIF']);
    }
    expect($config['reel_actif'])->toBe($attendu);
})->with([
    ['non', false], ['off', false], ['faux', false], ['false', false], ['0', false], ['', false], ['n’importe quoi', false],
    ['true', true], ['1', true], ['on', true],
]);
