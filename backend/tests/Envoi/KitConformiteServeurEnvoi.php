<?php

use App\Envoi\Contrat\EtatMessage;
use App\Envoi\Contrat\MessageAEnvoyer;
use App\Envoi\Contrat\ServeurEnvoi;

/**
 * KIT DE CONFORMITÉ réutilisable : tout implémenteur de ServeurEnvoi doit le
 * passer. Usage : `kitConformiteServeurEnvoi(fn () => new MonServeur)`.
 *
 * @param  Closure(): ServeurEnvoi  $fabrique
 */
function kitConformiteServeurEnvoi(Closure $fabrique): void
{
    $msg = fn (string $cle = 'k1') => new MessageAEnvoyer('00000000-0000-4000-8000-000000000001', 'temoin@example.test', 'from@example.test', 'S', 'C', $cle);

    test('kit : injecter est idempotent sur la clé', function () use ($fabrique, $msg) {
        $s = $fabrique();
        $a = $s->injecter($msg());
        $b = $s->injecter($msg());
        expect($a->accepte)->toBeTrue()->and($a->idInjection)->toBe($b->idInjection);
        expect($s->etatEnvoi($a->idInjection))->toBe(EtatMessage::Envoye);
    });

    test('kit : état inconnu pour une injection inconnue', function () use ($fabrique) {
        expect($fabrique()->etatEnvoi('nexiste-pas'))->toBe(EtatMessage::Inconnu);
    });

    test('kit : pause puis reprise', function () use ($fabrique, $msg) {
        $s = $fabrique();
        $s->pause();
        expect($s->etat()->enPause)->toBeTrue();
        expect($s->injecter($msg('p'))->accepte)->toBeFalse();
        $s->reprise();
        expect($s->etat()->enPause)->toBeFalse();
        expect($s->injecter($msg('p'))->accepte)->toBeTrue();
    });

    test('kit : santé, mode témoins et événements paginés', function () use ($fabrique, $msg) {
        $s = $fabrique();
        expect($s->sante()->ok)->toBeTrue();
        expect($s->modeTemoins())->toBeTrue();
        $s->injecter($msg('a'));
        $s->injecter($msg('b'));
        $lot = $s->lireEvenements(null, 1);
        expect($lot->evenements)->toHaveCount(1);
        $suite = $s->lireEvenements($lot->curseurSuivant, 10);
        expect($suite->evenements)->toHaveCount(1);
        $fin = $s->lireEvenements($suite->curseurSuivant, 10);
        expect($fin->evenements)->toBe([])->and($fin->curseurSuivant)->toBe($suite->curseurSuivant);
        // Jamais d'événement d'ouverture.
        foreach (array_merge($lot->evenements, $suite->evenements) as $e) {
            expect($e->type)->not->toBe('ouverture');
        }
    });
}
