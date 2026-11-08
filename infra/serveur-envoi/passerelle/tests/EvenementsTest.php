<?php

declare(strict_types=1);

namespace Passerelle\Tests;

use PDO;
use Passerelle\Signature;

final class EvenementsTest extends PasserelleTestCase
{
    private function lire(string $q, string $ws = 'espace-a'): array
    {
        $rep = $this->envoyer($this->signer('GET', '/v1/evenements'.$q, '', $ws));

        return ['statut' => $rep->statut, 'json' => json_decode($rep->corps, true), 'rep' => $rep];
    }

    private function ajouter(int $n, string $ws = 'espace-a'): void
    {
        for ($i = 0; $i < $n; $i++) {
            $this->stockage->ajouterEvenement($ws, 'delivery', ['i' => $i]);
        }
    }

    public function test_sequence_sans_trou_ni_doublon_par_espace(): void
    {
        $this->ajouter(5);
        $this->ajouter(2, 'espace-b');
        $r = $this->lire('?curseur=0');
        $this->assertSame([1, 2, 3, 4, 5], array_column($r['json']['evenements'], 'sequence'));
        $this->assertSame([1, 2], array_column($this->lire('?curseur=0', 'espace-b')['json']['evenements'], 'sequence'));
        $this->assertSame([], $this->stockage->trous('espace-a'));
    }

    public function test_echec_en_cours_d_ajout_ne_cree_pas_de_trou(): void
    {
        $this->ajouter(2);
        try {
            $this->stockage->ajouterEvenement('espace-a', 'delivery', ['x' => NAN]); // échoue après réservation du numéro
            $this->fail('attendu');
        } catch (\Throwable) {
        }
        $this->ajouter(1);
        $this->assertSame([1, 2, 3], array_column($this->lire('?curseur=0')['json']['evenements'], 'sequence'));
        $this->assertSame([], $this->stockage->trous('espace-a'));
    }

    public function test_redemarrage_continue_la_sequence(): void
    {
        $this->ajouter(3);
        $this->demarrer();
        $this->ajouter(2);
        $this->assertSame([1, 2, 3, 4, 5], array_column($this->lire('?curseur=0')['json']['evenements'], 'sequence'));
        $this->assertSame([], $this->stockage->trous('espace-a'));
    }

    public function test_trou_detecte(): void
    {
        $this->ajouter(4);
        $pdo = new PDO('sqlite:'.$this->fichier);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec("DROP TRIGGER IF EXISTS evenements_no_delete_recent"); // sabotage de test
        $pdo->exec("DELETE FROM evenements WHERE sequence = 2");
        $this->assertSame([[2, 2]], $this->stockage->trous('espace-a'));
        $this->assertSame(500, $this->lire('?curseur=0')['statut'], 'une lecture refuse de servir une séquence trouée');
    }

    public function test_curseur_et_limite(): void
    {
        $this->ajouter(5);
        $this->assertSame([4, 5], array_column($this->lire('?curseur=3')['json']['evenements'], 'sequence'));
        $this->assertSame([1, 2], array_column($this->lire('?curseur=0&limite=2')['json']['evenements'], 'sequence'));
        foreach (['-1', 'abc', '1.5', '99999999999999999999', ''] as $c) {
            $this->assertSame(400, $this->lire('?curseur='.$c)['statut'], "curseur $c");
        }
        $this->assertSame(400, $this->lire('?curseur=0&limite=501')['statut']);
        $this->assertSame(400, $this->lire('')['statut']);
    }

    public function test_rejeu_sur_72h_puis_refus_au_dela(): void
    {
        $this->stockage->ajouterEvenement('espace-a', 'old', [], $this->maintenant - 80 * 3600);
        $this->stockage->ajouterEvenement('espace-a', 'mid', [], $this->maintenant - 71 * 3600);
        $this->stockage->ajouterEvenement('espace-a', 'new', [], $this->maintenant);
        $this->assertSame([2, 3], array_column($this->lire('?curseur=1')['json']['evenements'], 'sequence'));
        $r = $this->lire('?curseur=0');
        $this->assertSame(410, $r['statut']);
        $this->assertSame(1, $r['json']['curseur_minimal']);
    }

    public function test_purge_garde_30_jours_et_n_efface_rien_de_recent(): void
    {
        $this->stockage->ajouterEvenement('espace-a', 'vieux', [], $this->maintenant - 31 * 86400);
        $this->stockage->ajouterEvenement('espace-a', 'recent', [], $this->maintenant - 29 * 86400);
        $this->assertSame(1, $this->stockage->purger());
        $this->assertSame([2], array_map(fn ($e) => $e['sequence'], $this->stockage->evenementsApres('espace-a', 0, 10)));
        $this->ajouter(1);
        $this->assertSame([], $this->stockage->trous('espace-a'));
        $this->assertSame(3, $this->stockage->dernierSequence('espace-a'));
    }

    public function test_evenement_recent_ne_peut_etre_ni_modifie_ni_supprime(): void
    {
        $this->ajouter(1);
        $pdo = new PDO('sqlite:'.$this->fichier);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        foreach (['UPDATE evenements SET type = \'x\'', 'DELETE FROM evenements'] as $sql) {
            try {
                $pdo->exec($sql);
                $this->fail($sql);
            } catch (\PDOException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_reponse_signee_avec_le_nonce_de_la_requete(): void
    {
        $this->ajouter(1);
        $req = $this->signer('GET', '/v1/evenements?curseur=0', '', nonce: 'nonce-fixe');
        $rep = $this->envoyer($req);
        $attendu = Signature::signerReponse(self::CLE_A, 'espace-a', 'nonce-fixe', $rep->statut, $rep->corps);
        $this->assertSame($attendu, $rep->entetes['X-Signature']);
    }

    public function test_espace_pris_de_la_cle_pas_de_l_url(): void
    {
        $this->ajouter(1, 'espace-b');
        $this->assertSame(400, $this->lire('?curseur=0&workspace_id=espace-b')['statut']);
        $this->assertSame([], $this->lire('?curseur=0')['json']['evenements']);
    }
}
