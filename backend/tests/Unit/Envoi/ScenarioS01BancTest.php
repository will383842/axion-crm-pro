<?php

use App\Envoi\Contrat\EtatMessage;
use App\Envoi\Contrat\MessageAEnvoyer;
use App\Envoi\Simulateur\FauxServeurEnvoi;

/**
 * Scénario S01 du banc K10 (infra/banc-envoi) : injection nominale vers le
 * simulateur, sans aucun envoi réel — acceptée, idempotente, état Envoye,
 * mode témoins actif, jamais d'événement d'ouverture.
 */
test('S01 : injection nominale contre FauxServeurEnvoi', function () {
    $s = new FauxServeurEnvoi;
    $m = new MessageAEnvoyer('00000000-0000-4000-8000-000000000001', 'temoin@example.test', 'from@example.test', 'S', 'C', 's01');

    $a = $s->injecter($m);
    $b = $s->injecter($m);

    expect($a->accepte)->toBeTrue()
        ->and($b->idInjection)->toBe($a->idInjection)
        ->and($s->etatEnvoi($a->idInjection))->toBe(EtatMessage::Envoye)
        ->and($s->modeTemoins())->toBeTrue();
    foreach ($s->lireEvenements(null, 10)->evenements as $e) {
        expect($e->type)->not->toBe('ouverture');
    }
});
