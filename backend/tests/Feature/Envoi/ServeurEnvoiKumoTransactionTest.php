<?php

use App\Envoi\Contrat\MessageAEnvoyer;
use App\Envoi\Serveur\Contrat\CommandeurServeur;
use App\Envoi\Serveur\Kumo\RegistreMemoire;
use App\Envoi\Serveur\ServeurEnvoiKumo;
use App\Envoi\Serveur\ServeurEnvoiRefuse;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use Tests\Unit\Envoi\DepotMemoire;

uses(TestCase::class);

require_once __DIR__ . '/../../Unit/Envoi/Kumo/FauxTransportKumo.php';

// Comme L4 : « injection_en_cours » doit être COMMITÉ avant le réseau, sinon un rollback effacerait la trace d'un
// envoi parti. Le refus passe AVANT toute écriture et toute connexion.
test('injecter() est refusé dans une transaction ouverte : zéro connexion, rien enregistré', function () {
    config(['envoi.reel_actif' => true, 'envoi.k1_actif' => true, 'envoi.smtp_tolere_transaction_englobante' => false]);
    $t = new FauxTransportKumo;
    $reg = new RegistreMemoire;
    $ws = '00000000-0000-4000-8000-000000000001';
    $s = new ServeurEnvoiKumo($ws, 's', $t, $t, $reg, new class implements CommandeurServeur
    {
        public function commander(string $workspaceId, string $source, string $commandeId, string $commande): bool
        {
            return true;
        }
    }, new DepotMemoire, str_repeat('A', 40));

    DB::beginTransaction();
    try {
        expect(fn () => $s->injecter(new MessageAEnvoyer($ws, 'temoin@example.test', 'from@example.test', 'S', 'C', 'k')))
            ->toThrow(ServeurEnvoiRefuse::class);
    } finally {
        DB::rollBack();
    }
    expect($t->connexions)->toBe(0)->and($reg->tout($ws))->toBe([]);
});
