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

function passerelle(?FauxServeurEnvoi $faux = null, ?DepotMemoire $depot = null, bool|Closure $reel = true, int|Closure $plafond = 5, ?string $espace = null): PasserelleLocale
{
    return new PasserelleLocale(
        $faux ?? new FauxServeurEnvoi,
        $depot ?? new DepotMemoire,
        $reel,
        $plafond,
        fn () => new DateTimeImmutable('2026-11-10 15:30:00'),
        $espace ?? WS,
    );
}

// Le kit tourne aussi sur la passerelle (drapeau ouvert, témoin autorisé).
kitConformiteServeurEnvoi(fn () => passerelle(plafond: 1000));

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
    $etat = true;
    $faux = new FauxServeurEnvoi;
    $p = passerelle($faux, reel: fn () => $etat);
    expect($p->injecter(msg())->accepte)->toBeTrue();
    $etat = false;
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
