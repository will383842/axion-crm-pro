<?php

declare(strict_types=1);

namespace Passerelle\Tests;

use Passerelle\Configuration;
use Passerelle\Stockage;
use PDO;

final class EvenementsTest extends PasserelleTestCase
{
    private function lire(string $q, string $ws = self::WS_A): array
    {
        $rep = $this->envoyer($this->signer('GET', '/v1/evenements'.$q, '', $ws));

        return ['statut' => $rep->statut, 'json' => json_decode($rep->corps, true), 'rep' => $rep];
    }

    private function ajouter(int $n, string $ws = self::WS_A, string $type = 'delivery'): void
    {
        for ($i = 0; $i < $n; $i++) {
            $this->stockage->ajouterEvenement($ws, $type, ['id_injection' => 'inj-'.$i, 'adresse' => 'personne@example.test']);
        }
    }

    private function seqs(array $r): array
    {
        return array_column($r['json']['evenements'], 'seq');
    }

    private function pdo(): PDO
    {
        $pdo = new PDO('sqlite:'.$this->fichier);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        return $pdo;
    }

    private function activerPurge(): void
    {
        $this->purgeActive = true;
        $this->demarrer();
    }

    // --- format lu par K1 ---

    public function test_format_k1_event_id_seq_type_id_injection_curseur_suivant(): void
    {
        $this->ajouter(3);
        $r = $this->lire('?curseur=&depuis=2000-01-01T00%3A00%3A00%2B00%3A00&limite=2');
        $this->assertSame(200, $r['statut']);
        $this->assertSame(self::WS_A, $r['json']['workspace_id']);
        $e = $r['json']['evenements'][0];
        $cles = array_keys($e);
        sort($cles);
        $this->assertSame(['event_id', 'id_injection', 'seq', 'type'], $cles);
        $this->assertSame(1, $e['seq']);
        $this->assertSame('delivery', $e['type']);
        $this->assertSame('inj-0', $e['id_injection']);
        $this->assertTrue(is_string($e['event_id']) && $e['event_id'] !== '' && strlen($e['event_id']) <= 128);
        $this->assertSame('2', $r['json']['curseur_suivant'], 'chaîne, comme K1 la lit');
        $this->assertSame(1, preg_match('/^[a-z][a-z0-9_]{0,63}$/', $e['type']));
    }

    public function test_aucune_donnee_personnelle_dans_la_reponse(): void
    {
        $this->ajouter(1);
        $this->assertStringNotContainsString('example.test', $this->lire('?curseur=')['rep']->corps);
    }

    public function test_curseur_vide_au_premier_tirage_et_curseur_suivant_sans_evenement(): void
    {
        $r = $this->lire('?curseur=&depuis=2000-01-01T00%3A00%3A00%2B00%3A00&limite=100');
        $this->assertSame(200, $r['statut']);
        $this->assertSame([], $r['json']['evenements']);
        $this->assertSame('0', $r['json']['curseur_suivant']);
        $this->ajouter(2);
        $this->assertSame([1, 2], $this->seqs($this->lire('?curseur=')));
        // curseur donné sans événement neuf : curseur_suivant = curseur (K1 refuse qu'il recule).
        $this->assertSame('2', $this->lire('?curseur=2&limite=10')['json']['curseur_suivant']);
    }

    public function test_depuis_ne_sert_qu_au_premier_tirage(): void
    {
        $this->maintenant = 1_800_000_000;
        $this->ajouter(2);
        $this->maintenant += 3600;
        $this->ajouter(2);
        $depuis = rawurlencode(gmdate('c', $this->maintenant - 1800));
        $this->assertSame([3, 4], $this->seqs($this->lire('?curseur=&depuis='.$depuis)), 'premier tirage : événements depuis la date');
        $this->assertSame([2, 3, 4], $this->seqs($this->lire('?curseur=1&depuis='.$depuis)), 'avec un curseur, depuis est ignoré');
        $this->assertSame(400, $this->lire('?curseur=&depuis=pas-une-date')['statut']);
    }

    public function test_type_incompatible_avec_k1_refuse_a_l_ecriture(): void
    {
        foreach (['Delivery', '1abc', 'a-b', '', str_repeat('a', 65), 'é'] as $t) {
            try {
                $this->stockage->ajouterEvenement(self::WS_A, $t, []);
                $this->fail("type accepté : $t");
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->assertSame(0, $this->stockage->dernierSequence(self::WS_A), 'aucun numéro consommé');
    }

    // --- séquence ---

    public function test_sequence_sans_trou_ni_doublon_par_espace(): void
    {
        $this->ajouter(5);
        $this->ajouter(2, self::WS_B);
        $this->assertSame([1, 2, 3, 4, 5], $this->seqs($this->lire('?curseur=0')));
        $this->assertSame([1, 2], $this->seqs($this->lire('?curseur=0', self::WS_B)));
        $this->assertSame([], $this->stockage->trous(self::WS_A));
    }

    public function test_echec_en_cours_d_ajout_ne_cree_pas_de_trou(): void
    {
        $this->ajouter(2);
        try {
            $this->stockage->ajouterEvenement(self::WS_A, 'delivery', ['x' => NAN]);
            $this->fail('attendu');
        } catch (\Throwable) {
        }
        $this->ajouter(1);
        $this->assertSame([1, 2, 3], $this->seqs($this->lire('?curseur=0')));
        $this->assertSame([], $this->stockage->trous(self::WS_A));
    }

    public function test_redemarrage_continue_la_sequence(): void
    {
        $this->ajouter(3);
        $this->demarrer();
        $this->ajouter(2);
        $this->assertSame([1, 2, 3, 4, 5], $this->seqs($this->lire('?curseur=0')));
    }

    public function test_trou_detecte(): void
    {
        $this->ajouter(4);
        $pdo = $this->pdo();
        $pdo->exec('DROP TRIGGER IF EXISTS evenements_no_delete_recent'); // sabotage de test
        $pdo->exec('DELETE FROM evenements WHERE sequence = 2');
        $this->assertSame([[2, 2]], $this->stockage->trous(self::WS_A));
        $this->assertSame(500, $this->lire('?curseur=0')['statut']);
    }

    public function test_curseur_et_limite(): void
    {
        $this->ajouter(5);
        $this->assertSame([4, 5], $this->seqs($this->lire('?curseur=3')));
        $this->assertSame([1, 2], $this->seqs($this->lire('?curseur=0&limite=2')));
        foreach (['-1', 'abc', '1.5', '99999999999999999999'] as $c) {
            $this->assertSame(400, $this->lire('?curseur='.$c)['statut'], "curseur $c");
        }
        $this->assertSame(400, $this->lire('?curseur=0&limite=501')['statut']);
        $this->assertSame(400, $this->lire('')['statut']);
        $this->assertSame(400, $this->lire('?curseur=1&curseur=2')['statut']);
    }

    public function test_espace_pris_de_la_signature_pas_de_l_url(): void
    {
        $this->ajouter(1, self::WS_B);
        $this->assertSame(400, $this->lire('?curseur=0&workspace_id='.self::WS_B)['statut']);
        $this->assertSame([], $this->lire('?curseur=0')['json']['evenements']);
    }

    // --- rejeu 72 h ---

    public function test_rejeu_sur_72h_puis_410_au_dela(): void
    {
        $this->maintenant = 1_800_000_000;
        $t = $this->maintenant;
        $this->maintenant = $t - 80 * 3600;
        $this->ajouter(1); // 1 : vieux
        $this->maintenant = $t - 71 * 3600;
        $this->ajouter(1); // 2
        $this->maintenant = $t;
        $this->ajouter(1); // 3
        $this->assertSame([2, 3], $this->seqs($this->lire('?curseur=1')));
        $r = $this->lire('?curseur=0');
        $this->assertSame(410, $r['statut']);
        $this->assertSame(1, $r['json']['curseur_minimal']);
    }

    // --- (8) purge inactive par défaut ---

    public function test_purge_inactive_par_defaut_ne_supprime_rien(): void
    {
        $this->maintenant = 1_800_000_000 - 40 * 86400;
        $this->ajouter(3);
        $this->maintenant = 1_800_000_000;
        $this->assertSame(0, $this->stockage->purger());
        $this->assertCount(3, $this->stockage->evenementsApres(self::WS_A, 0, 10));
    }

    public function test_drapeau_de_purge_lu_de_l_environnement(): void
    {
        $this->assertFalse(Configuration::purgeActive(null));
        $this->assertFalse(Configuration::purgeActive(''));
        foreach (['false', '0', 'oui', 'TRUE ', 'yes', 'tru'] as $v) {
            $this->assertFalse(Configuration::purgeActive($v), $v);
        }
        $this->assertTrue(Configuration::purgeActive('true'));
    }

    // --- purge par numéro (4) ---

    public function test_purge_par_numero_garde_30_jours(): void
    {
        $this->activerPurge();
        $t = $this->maintenant;
        $this->maintenant = $t - 31 * 86400;
        $this->ajouter(1); // 1 vieux
        $this->maintenant = $t - 29 * 86400;
        $this->ajouter(1); // 2 récent
        $this->maintenant = $t;
        $this->assertSame(1, $this->stockage->purger());
        $this->assertSame([2], array_column($this->stockage->evenementsApres(self::WS_A, 0, 10), 'sequence'));
        $this->ajouter(1);
        $this->assertSame([], $this->stockage->trous(self::WS_A));
        $this->assertSame(3, $this->stockage->dernierSequence(self::WS_A));
        $this->assertSame(1, $this->stockage->dernierPurge(self::WS_A));
    }

    public function test_cree_le_n_est_jamais_libre(): void
    {
        $this->assertSame(3, (new \ReflectionMethod(Stockage::class, 'ajouterEvenement'))->getNumberOfParameters(), 'plus de paramètre cree_le');
        $this->maintenant = 1_800_000_000;
        $this->ajouter(1);
        $this->assertSame($this->maintenant, $this->stockage->evenementsApres(self::WS_A, 0, 1)[0]['cree_le']);
    }

    public function test_horloge_qui_recule_ne_cree_pas_de_trou_a_la_purge(): void
    {
        $this->activerPurge();
        $m = 1_800_000_000;
        $this->maintenant = $m;
        $this->ajouter(1); // 1 : date M
        $this->maintenant = $m - 40 * 86400; // l'horloge recule
        $this->ajouter(1); // 2 : date M-40 j (vieille)
        $this->maintenant = $m + 86400;
        $this->assertSame(0, $this->stockage->purger(), 'le n°1 est récent : on ne purge pas le n°2 au-delà');
        $this->assertSame([], $this->stockage->trous(self::WS_A));
        $this->assertSame(200, $this->lire('?curseur=')['statut'], 'jamais de sequence_incoherente permanente');
        $this->assertSame([1, 2], $this->seqs($this->lire('?curseur=')));
    }

    public function test_purge_ne_supprime_jamais_hors_du_prefixe_contigu(): void
    {
        $this->activerPurge();
        $m = 1_800_000_000;
        foreach ([-40, -10, -40, -35] as $jours) { // 1 vieux, 2 récent, 3 et 4 « vieux » mais derrière le n°2
            $this->maintenant = $m + $jours * 86400;
            $this->ajouter(1);
        }
        $this->maintenant = $m;
        $this->assertSame(1, $this->stockage->purger());
        $this->assertSame([2, 3, 4], array_column($this->stockage->evenementsApres(self::WS_A, 0, 10), 'sequence'));
        $this->assertSame([], $this->stockage->trous(self::WS_A));
    }

    public function test_une_suppression_hors_purge_est_toujours_refusee(): void
    {
        $this->activerPurge();
        $t = $this->maintenant;
        $this->maintenant = $t - 40 * 86400;
        $this->ajouter(1);
        $this->maintenant = $t;
        $this->assertSame(1, $this->stockage->purger()); // pose la borne de purge
        $this->ajouter(1);
        foreach (['UPDATE evenements SET type = \'x\'', 'DELETE FROM evenements', 'UPDATE purge_borne SET dernier_purge = 0', 'DELETE FROM purge_borne'] as $sql) {
            try {
                $this->pdo()->exec($sql);
                $this->fail($sql);
            } catch (\PDOException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    // --- (3) curseur ancien après purge ---

    public function test_client_absent_longtemps_apres_purge_recoit_410_et_ne_saute_rien(): void
    {
        $this->activerPurge();
        $t = $this->maintenant;
        $this->maintenant = $t - 100 * 86400;
        $this->ajouter(5, type: 'unsubscribe'); // 1..5 : le client n'a lu que jusqu'au n°2
        $this->maintenant = $t;
        $this->assertSame(5, $this->stockage->purger()); // tout ce qui a plus de 72 h (et 30 j) est purgé
        $this->ajouter(2); // 6, 7
        $this->assertSame(5, $this->stockage->dernierPurge(self::WS_A));

        $r = $this->lire('?curseur=2');
        $this->assertSame(410, $r['statut'], 'jamais 200 en sautant les n°3 à 5 purgés');
        $this->assertSame(5, $r['json']['curseur_minimal']);
        $this->assertSame(410, $this->lire('?curseur=4')['statut']);
        $this->assertSame([6, 7], $this->seqs($this->lire('?curseur=5')), 'le curseur exactement à la borne est valide');
        $this->assertSame([6, 7], $this->seqs($this->lire('?curseur=')), 'premier tirage : tout ce qui reste');
    }

    public function test_borne_de_purge_survit_au_redemarrage_et_est_par_espace(): void
    {
        $this->activerPurge();
        $t = $this->maintenant;
        $this->maintenant = $t - 40 * 86400;
        $this->ajouter(3);
        $this->ajouter(1, self::WS_B);
        $this->maintenant = $t;
        $this->stockage->purger();
        $this->demarrer();
        $this->assertSame(410, $this->lire('?curseur=1')['statut']);
        $this->assertSame(3, $this->stockage->dernierPurge(self::WS_A));
        $this->assertSame(1, $this->stockage->dernierPurge(self::WS_B));
        $this->ajouter(1, self::WS_B);
        $this->assertSame([2], $this->seqs($this->lire('?curseur=1', self::WS_B)));
    }

    public function test_reponse_signee_avec_le_nonce_de_la_requete(): void
    {
        $this->ajouter(1);
        $nonce = self::uuid();
        $rep = $this->envoyer($this->signer('GET', '/v1/evenements?curseur=0', '', nonce: $nonce));
        [, $hex] = \Passerelle\Signature::analyser($rep->entetes['X-Envoi-Signature']);
        $hex = preg_replace('/^sha256=/', '', $hex);
        $this->assertSame(\Passerelle\Signature::signer(self::CLE_A, "REPONSE\n$nonce\n".self::WS_A."\n200\n".$rep->corps), $hex);
    }
}
