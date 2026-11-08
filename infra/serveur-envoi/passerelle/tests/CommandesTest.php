<?php

declare(strict_types=1);

namespace Passerelle\Tests;

use PDO;

final class CommandesTest extends PasserelleTestCase
{
    public function test_noms_k1_et_corps_sans_workspace_id(): void
    {
        foreach (['pause', 'reprise', 'retrait'] as $i => $nom) {
            $rep = $this->envoyer($this->commande($nom, self::uuid()));
            $this->assertSame(200, $rep->statut, $nom);
            $this->assertSame(self::WS_A, json_decode($rep->corps, true)['workspace_id']);
            $this->assertSame($nom, $this->kumo->appels[$i]['operation']);
            $this->assertSame(self::WS_A, $this->kumo->appels[$i]['workspace_id'], 'l\'espace vient de la signature');
        }
    }

    public function test_anciens_noms_et_corps_avec_workspace_id_refuses(): void
    {
        $this->assertSame(404, $this->envoyer($this->commande('reprendre', commande: 'reprise'))->statut);
        $this->assertSame(404, $this->envoyer($this->commande('retirer', commande: 'retrait'))->statut);
        $corps = json_encode(['commande_id' => self::ID_1, 'commande' => 'pause', 'workspace_id' => self::WS_A]);
        $this->assertSame(400, $this->envoyer($this->signer('POST', '/v1/commandes/pause', $corps))->statut);
        $this->assertSame([], $this->kumo->appels);
    }

    public function test_commande_id_doit_etre_un_uuid_et_la_commande_celle_du_chemin(): void
    {
        $this->assertSame(400, $this->envoyer($this->commande('pause', 'cmd-1'))->statut);
        $this->assertSame(400, $this->envoyer($this->commande('pause', commande: 'retrait'))->statut);
        $this->assertSame([], $this->kumo->appels);
    }

    public function test_commande_rejouee_meme_reponse_sans_refaire_l_action(): void
    {
        $a = $this->envoyer($this->commande('pause'));
        $b = $this->envoyer($this->commande('pause')); // nouveau nonce, même commande_id
        $this->assertSame(200, $b->statut);
        $this->assertSame($a->corps, $b->corps);
        $this->assertCount(1, $this->kumo->appels);
    }

    public function test_meme_commande_id_autre_commande_donne_409(): void
    {
        $this->envoyer($this->commande('pause'));
        $this->assertSame(409, $this->envoyer($this->commande('retrait'))->statut);
        $this->assertCount(1, $this->kumo->appels);
    }

    public function test_commande_id_isole_par_espace(): void
    {
        $this->envoyer($this->commande('pause'));
        $this->assertSame(200, $this->envoyer($this->commande('pause', ws: self::WS_B))->statut);
        $this->assertCount(2, $this->kumo->appels);
    }

    public function test_redemarrage_ne_perd_ni_journal_ni_nonces_ni_reponses(): void
    {
        $r = $this->commande('pause');
        $a = $this->envoyer($r);
        $this->demarrer();
        $this->assertSame(401, $this->envoyer($r)->statut, 'nonce rejoué après redémarrage');
        $b = $this->envoyer($this->commande('pause'));
        $this->assertSame($a->corps, $b->corps, 'même réponse après redémarrage');
        $this->assertCount(1, $this->kumo->appels);
        $this->assertSame(1, $this->stockage->nombreCommandes());
    }

    public function test_journal_non_modifiable(): void
    {
        $this->envoyer($this->commande('pause'));
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

    // --- (5) double exécution : réservation AVANT l'appel Kumo ---

    public function test_commande_id_est_reserve_avant_l_appel_kumo(): void
    {
        $vu = null;
        $this->kumo->pendant = function () use (&$vu): void {
            $vu = $this->stockage->etatCommande(self::WS_A, self::ID_1);
        };
        $this->envoyer($this->commande('pause'));
        $this->assertSame('en_cours', $vu);
        $this->assertSame('ok', $this->stockage->etatCommande(self::WS_A, self::ID_1));
    }

    public function test_requete_concurrente_meme_commande_id_un_seul_appel_kumo_meme_reponse(): void
    {
        $concurrente = null;
        $this->kumo->pendant = function () use (&$concurrente): void {
            $this->kumo->pendant = null;
            // Pendant que la 1re requête est chez Kumo, la 2re arrive ; elle attend (sans second appel).
            $this->passerelle = $this->passerelleAvecAttente(function (): void {});
            $concurrente = $this->envoyer($this->commande('pause'));
        };
        $premiere = $this->envoyer($this->commande('pause'));
        $this->assertSame(200, $premiere->statut);
        $this->assertSame(503, $concurrente->statut, 'tant que la 1re n\'a pas fini : réessayable, jamais un second appel');
        $this->assertCount(1, $this->kumo->appels);
        $apres = $this->envoyer($this->commande('pause'));
        $this->assertSame($premiere->corps, $apres->corps);
        $this->assertCount(1, $this->kumo->appels);
    }

    public function test_requete_concurrente_qui_attend_recoit_la_meme_reponse(): void
    {
        $empreinte = \Passerelle\Passerelle::empreinte('pause', self::ID_1);
        $this->assertSame('executer', $this->stockage->reserverCommande(self::WS_A, self::ID_1, 'pause', $empreinte)['action']);
        $this->passerelle = $this->passerelleAvecAttente(function (): void {
            $this->stockage->terminerCommande(self::WS_A, self::ID_1, 'pause', 200, '{"workspace_id":"'.self::WS_A.'","commande_id":"'.self::ID_1.'","statut":"ok"}');
        });
        $rep = $this->envoyer($this->commande('pause'));
        $this->assertSame(200, $rep->statut);
        $this->assertSame(self::ID_1, json_decode($rep->corps, true)['commande_id']);
        $this->assertSame([], $this->kumo->appels, 'la requête qui attend ne rappelle jamais Kumo');
    }

    public function test_reservation_abandonnee_est_reprise_apres_delai(): void
    {
        $this->assertSame('executer', $this->stockage->reserverCommande(self::WS_A, self::ID_1, 'pause', 'e1')['action']);
        $this->assertSame('attendre', $this->stockage->reserverCommande(self::WS_A, self::ID_1, 'pause', 'e1')['action']);
        $this->maintenant += 61; // le processus a disparu entre la réservation et la fin
        $this->assertSame('executer', $this->stockage->reserverCommande(self::WS_A, self::ID_1, 'pause', 'e1')['action']);
    }

    public function test_reserver_deux_fois_ne_donne_qu_une_execution(): void
    {
        $a = $this->stockage->reserverCommande(self::WS_A, self::ID_1, 'pause', 'e1');
        $b = $this->stockage->reserverCommande(self::WS_A, self::ID_1, 'pause', 'e1');
        $this->assertSame(['executer', 'attendre'], [$a['action'], $b['action']]);
    }

    // --- (6) échec Kumo : le commande_id reste utilisable ---

    public function test_echec_kumo_502_puis_reessai_avec_le_meme_commande_id(): void
    {
        $this->kumo->echec = true;
        $a = $this->envoyer($this->commande('pause'));
        $this->assertSame(502, $a->statut);
        $this->assertSame('echec', $this->stockage->etatCommande(self::WS_A, self::ID_1));
        $this->assertSame(0, $this->stockage->nombreCommandes(), 'un échec n\'est pas une réponse définitive');

        $this->kumo->echec = false;
        $b = $this->envoyer($this->commande('pause')); // K1 rejoue avec le MÊME identifiant
        $this->assertSame(200, $b->statut);
        $this->assertCount(1, $this->kumo->appels);
        $this->assertSame('ok', $this->stockage->etatCommande(self::WS_A, self::ID_1));

        $c = $this->envoyer($this->commande('pause'));
        $this->assertSame($b->corps, $c->corps);
        $this->assertCount(1, $this->kumo->appels, 'plus de nouvel appel une fois réussie');
    }

    public function test_echec_puis_autre_commande_meme_id_reste_refuse(): void
    {
        $this->kumo->echec = true;
        $this->envoyer($this->commande('pause'));
        $this->assertSame(409, $this->envoyer($this->commande('retrait'))->statut);
    }

    public function test_erreur_inattendue_de_kumo_libere_la_reservation(): void
    {
        $this->kumo->pendant = function (): void {
            throw new \LogicException('imprévu');
        };
        $this->assertSame(503, $this->envoyer($this->commande('pause'))->statut);
        $this->assertSame('echec', $this->stockage->etatCommande(self::WS_A, self::ID_1));
        $this->kumo->pendant = null;
        $this->assertSame(200, $this->envoyer($this->commande('pause'))->statut);
    }

    public function test_corps_invalide_ou_trop_gros(): void
    {
        $this->assertSame(400, $this->envoyer($this->signer('POST', '/v1/commandes/pause', 'pas du json'))->statut);
        $this->assertSame(413, $this->envoyer($this->signer('POST', '/v1/commandes/pause', str_repeat('a', 70000)))->statut);
        $this->assertSame(400, $this->envoyer($this->signer('POST', '/v1/commandes/pause', '{"commande":"pause"}'))->statut);
    }
}
