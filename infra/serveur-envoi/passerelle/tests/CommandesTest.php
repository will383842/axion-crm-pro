<?php

declare(strict_types=1);

namespace Passerelle\Tests;

use PDO;

final class CommandesTest extends PasserelleTestCase
{
    private function corps(string $id = 'cmd-1', string $file = 'f1'): array
    {
        return ['commande_id' => $id, 'workspace_id' => 'espace-a', 'params' => ['file' => $file]];
    }

    public function test_commande_rejouee_meme_reponse_sans_refaire_l_action(): void
    {
        $a = $this->envoyer($this->commande('pause', $this->corps()));
        $b = $this->envoyer($this->commande('pause', $this->corps())); // nouveau nonce, même commande_id
        $this->assertSame(200, $b->statut);
        $this->assertSame($a->corps, $b->corps);
        $this->assertCount(1, $this->kumo->appels);
    }

    public function test_meme_commande_id_autre_corps_donne_409(): void
    {
        $this->envoyer($this->commande('pause', $this->corps()));
        $rep = $this->envoyer($this->commande('pause', $this->corps('cmd-1', 'autre')));
        $this->assertSame(409, $rep->statut);
        $this->assertCount(1, $this->kumo->appels);
    }

    public function test_commande_id_isole_par_espace(): void
    {
        $this->envoyer($this->commande('pause', $this->corps()));
        $b = ['commande_id' => 'cmd-1', 'workspace_id' => 'espace-b', 'params' => ['file' => 'f1']];
        $this->assertSame(200, $this->envoyer($this->commande('pause', $b, ws: 'espace-b'))->statut);
        $this->assertCount(2, $this->kumo->appels);
    }

    public function test_redemarrage_ne_perd_ni_journal_ni_nonces_ni_reponses(): void
    {
        $r = $this->commande('pause', $this->corps());
        $a = $this->envoyer($r);
        $this->demarrer();
        $this->assertSame(401, $this->envoyer($r)->statut, 'nonce rejoué après redémarrage');
        $b = $this->envoyer($this->commande('pause', $this->corps()));
        $this->assertSame($a->corps, $b->corps, 'même réponse après redémarrage');
        $this->assertCount(1, $this->kumo->appels);
        $this->assertSame(1, $this->stockage->nombreCommandes());
    }

    public function test_journal_non_modifiable(): void
    {
        $this->envoyer($this->commande('pause', $this->corps()));
        $pdo = new PDO('sqlite:'.$this->fichier);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        foreach (['UPDATE journal_commandes SET statut = 500', 'DELETE FROM journal_commandes'] as $sql) {
            try {
                $pdo->exec($sql);
                $this->fail("accepté : $sql");
            } catch (\PDOException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->assertSame(1, $this->stockage->nombreCommandes());
    }

    public function test_commande_en_echec_kumo_est_journalisee_et_rejouee_a_l_identique(): void
    {
        $this->kumo->echec = true;
        $a = $this->envoyer($this->commande('pause', $this->corps()));
        $this->assertSame(502, $a->statut);
        $this->kumo->echec = false;
        $b = $this->envoyer($this->commande('pause', $this->corps()));
        $this->assertSame(502, $b->statut);
        $this->assertCount(0, $this->kumo->appels);
    }

    public function test_corps_invalide_ou_trop_gros(): void
    {
        $this->assertSame(400, $this->envoyer($this->signer('POST', '/v1/commandes/pause', 'pas du json'))->statut);
        $this->assertSame(413, $this->envoyer($this->signer('POST', '/v1/commandes/pause', str_repeat('a', 70000)))->statut);
        $this->assertSame(400, $this->envoyer($this->commande('pause', ['workspace_id' => 'espace-a']))->statut);
    }
}
