<?php

declare(strict_types=1);

namespace Passerelle\Tests;

use Passerelle\Reponse;
use Passerelle\Requete;
use Passerelle\Stockage;
use PDO;

/** C3-1 : contrat v3 du retrait (cibles), liste de retrait de 30 jours, 409 « retire » à l'injection. Faux serveur seulement. */
final class RetraitTest extends PasserelleTestCase
{
    private const CMD_1 = 'dddddddd-dddd-4ddd-8ddd-dddddddddddd';
    private const CMD_2 = 'eeeeeeee-eeee-4eee-8eee-eeeeeeeeeeee';
    private const INJ_1 = 'aaaaaaaa-0000-4000-8000-000000000001';
    private const INJ_2 = 'aaaaaaaa-0000-4000-8000-000000000002';
    private const INJ_3 = 'aaaaaaaa-0000-4000-8000-000000000003';

    /** @param array<string,mixed>|null $corps null : corps standard avec `cibles` */
    private function retrait(?array $corps = null, string $ws = self::WS_A, string $id = self::CMD_1, ?array $cibles = null): Requete
    {
        $corps ??= ['commande_id' => $id, 'commande' => 'retrait', 'cibles' => $cibles ?? [self::INJ_1, self::INJ_2]];

        return $this->signer('POST', '/v1/commandes/retrait', json_encode($corps, JSON_THROW_ON_ERROR), $ws);
    }

    private function injection(string $id, string $ws = self::WS_A): Requete
    {
        $message = "From: Contact <contact@client.example.test>\r\nTo: cible@client.example.test\r\nSubject: Essai\r\nMessage-ID: <1@client.example.test>\r\n"
            ."List-Unsubscribe: <https://client.example.test/u/abc>, <mailto:u@client.example.test>\r\nList-Unsubscribe-Post: List-Unsubscribe=One-Click\r\n\r\nBonjour\r\n";
        $corps = json_encode([
            'id_injection' => $id, 'mail_from' => 'rb-0123456789abcdef0123456789abcdef0123456789@bounce.client.example.test',
            'rcpt_to' => 'cible@client.example.test', 'message' => base64_encode($message),
        ], JSON_THROW_ON_ERROR);

        return $this->signer('POST', '/v1/injections', $corps, $ws);
    }

    /** @return array<string,mixed> */
    private function json(Reponse $r): array
    {
        return json_decode($r->corps, true, 8, JSON_THROW_ON_ERROR);
    }

    private function nbRetraits(?string $ws = null): int
    {
        $pdo = new PDO('sqlite:'.$this->fichier);

        return (int) $pdo->query('SELECT COUNT(*) FROM retraits'.($ws === null ? '' : " WHERE workspace_id='{$ws}'"))->fetchColumn();
    }

    /** @return list<string> */
    private function operations(): array
    {
        return array_column($this->kumo->appels, 'operation');
    }

    public function test_retrait_de_deux_cibles_puis_injection_de_l_une_donne_409_retire(): void
    {
        $rep = $this->envoyer($this->retrait());
        $this->assertSame(200, $rep->statut);
        $this->assertSame(2, $this->json($rep)['resultat']['retirees']);
        $this->assertSame(['cibles' => [self::INJ_1, self::INJ_2]], $this->kumo->appels[0]['params']);

        $r = $this->envoyer($this->injection(self::INJ_1));
        $this->assertSame(409, $r->statut);
        $this->assertSame('retire', $this->json($r)['erreur']);
        $this->assertSame(409, $this->envoyer($this->injection(self::INJ_2))->statut);
        $this->assertSame(['retrait'], $this->operations(), 'aucun appel du moteur pour une injection retirée');
        // une autre injection de l'espace n'est pas touchée
        $this->assertSame(202, $this->envoyer($this->injection(self::INJ_3))->statut);
        $this->assertSame(['retrait', 'injecter'], $this->operations());
    }

    public function test_etat_d_une_injection_retiree(): void
    {
        $this->envoyer($this->retrait());
        $r = $this->envoyer($this->signer('GET', '/v1/injections/'.self::INJ_1, ''));
        $this->assertSame('retire', $this->json($r)['etat']);
        $r = $this->envoyer($this->signer('GET', '/v1/injections/'.self::INJ_3, ''));
        $this->assertSame('inconnu', $this->json($r)['etat']);
    }

    public function test_injection_deja_acceptee_puis_retiree_n_est_pas_rejouee_en_202(): void
    {
        $this->assertSame(202, $this->envoyer($this->injection(self::INJ_1))->statut);
        $this->envoyer($this->retrait());
        $this->assertSame(409, $this->envoyer($this->injection(self::INJ_1))->statut);
        $r = $this->envoyer($this->signer('GET', '/v1/injections/'.self::INJ_1, ''));
        $this->assertSame('retire', $this->json($r)['etat']);
        $this->assertCount(2, $this->kumo->appels, 'injecter puis retrait, rien de plus');
    }

    public function test_retrait_sans_cibles_donne_400_cibles_exigees(): void
    {
        $r = $this->envoyer($this->retrait(['commande_id' => self::CMD_1, 'commande' => 'retrait']));
        $this->assertSame(400, $r->statut);
        $this->assertSame('cibles_exigees', $this->json($r)['erreur']);
        $this->assertSame([], $this->kumo->appels);
        $this->assertSame(0, $this->nbRetraits());
    }

    public function test_cibles_invalides_donnent_400_et_rien_n_est_inscrit(): void
    {
        $cent_un = array_map(static fn (int $i): string => sprintf('aaaaaaaa-0000-4000-8000-%012d', $i), range(1, 101));
        $cas = [
            'vide' => [],
            '101 cibles' => $cent_un,
            'pas un uuid' => [self::INJ_1, 'pas-un-uuid'],
            'majuscules' => [strtoupper(self::INJ_1)],
            'doublon' => [self::INJ_1, self::INJ_1],
            'objet' => ['a' => self::INJ_1],
            'chaine' => self::INJ_1,
            'entier' => [123],
        ];
        foreach ($cas as $nom => $cibles) {
            $r = $this->envoyer($this->retrait(['commande_id' => self::uuid(), 'commande' => 'retrait', 'cibles' => $cibles]));
            $this->assertSame(400, $r->statut, $nom);
        }
        $this->assertSame([], $this->kumo->appels);
        $this->assertSame(0, $this->nbRetraits());
    }

    public function test_cent_cibles_acceptees(): void
    {
        $cent = array_map(static fn (int $i): string => sprintf('aaaaaaaa-0000-4000-8000-%012d', $i), range(1, 100));
        $r = $this->envoyer($this->retrait(cibles: $cent));
        $this->assertSame(200, $r->statut);
        $this->assertSame(100, $this->json($r)['resultat']['retirees']);
        $this->assertSame(100, $this->nbRetraits());
    }

    public function test_cibles_interdites_pour_pause_et_reprise(): void
    {
        foreach (['pause', 'reprise'] as $nom) {
            $corps = json_encode(['commande_id' => self::uuid(), 'commande' => $nom, 'cibles' => [self::INJ_1]]);
            $this->assertSame(400, $this->envoyer($this->signer('POST', '/v1/commandes/'.$nom, $corps))->statut, $nom);
        }
        $this->assertSame([], $this->kumo->appels);
    }

    public function test_meme_commande_id_autres_cibles_donne_409_et_n_inscrit_rien(): void
    {
        $this->assertSame(200, $this->envoyer($this->retrait())->statut);
        $r = $this->envoyer($this->retrait(cibles: [self::INJ_1, self::INJ_3]));
        $this->assertSame(409, $r->statut);
        $this->assertSame('commande_id_deja_utilise', $this->json($r)['erreur']);
        $this->assertSame(202, $this->envoyer($this->injection(self::INJ_3))->statut, 'la cible du refus n\'a pas été inscrite');
        $this->assertSame(2, $this->nbRetraits());
    }

    public function test_meme_cibles_dans_un_autre_ordre_est_le_meme_corps_et_se_rejoue(): void
    {
        $a = $this->envoyer($this->retrait(cibles: [self::INJ_2, self::INJ_1]));
        $b = $this->envoyer($this->retrait(cibles: [self::INJ_1, self::INJ_2]));
        $this->assertSame(200, $b->statut);
        $this->assertSame($a->corps, $b->corps);
        $this->assertSame(['retrait'], $this->operations(), 'rejeu : pas de second appel');
    }

    public function test_les_cibles_d_un_espace_n_affectent_pas_l_autre(): void
    {
        $this->envoyer($this->retrait(ws: self::WS_A));
        $this->assertSame(202, $this->envoyer($this->injection(self::INJ_1, self::WS_B))->statut);
        $this->assertSame(409, $this->envoyer($this->injection(self::INJ_1, self::WS_A))->statut);
        $this->assertSame('inconnu', $this->json($this->envoyer($this->signer('GET', '/v1/injections/'.self::INJ_2, '', self::WS_B)))['etat']);
        $this->assertSame(2, $this->nbRetraits(self::WS_A));
        $this->assertSame(0, $this->nbRetraits(self::WS_B));
    }

    public function test_moteur_en_panne_les_cibles_sont_quand_meme_inscrites_et_la_commande_se_rejoue(): void
    {
        $this->kumo->echec = true;
        $r = $this->envoyer($this->retrait());
        $this->assertSame(502, $r->statut);
        $this->assertSame(409, $this->envoyer($this->injection(self::INJ_1))->statut, 'échec fermé : refusée malgré la panne');
        $this->assertSame([], $this->kumo->appels);

        $this->kumo->echec = false;
        $r = $this->envoyer($this->retrait()); // même commande_id : rejouable
        $this->assertSame(200, $r->statut);
        $this->assertSame(['retrait'], $this->operations());
        $this->assertSame(2, $this->nbRetraits(), 'pas de doublon à la reprise');
    }

    public function test_liste_de_retrait_gardee_30_jours_puis_levee(): void
    {
        $this->envoyer($this->retrait());
        $this->maintenant += Stockage::RETENTION_RETRAITS - 1;
        $this->assertSame(409, $this->envoyer($this->injection(self::INJ_1))->statut);
        $this->maintenant += 2;
        $this->assertSame(202, $this->envoyer($this->injection(self::INJ_1))->statut);
        $this->assertSame(2, $this->nbRetraits(), 'rien n\'est effacé : la ligne reste, seule sa portée expire');
    }

    public function test_la_liste_est_en_ajout_seul(): void
    {
        $this->envoyer($this->retrait());
        $pdo = new PDO('sqlite:'.$this->fichier);
        foreach (['DELETE FROM retraits', "UPDATE retraits SET retire_le = 0"] as $sql) {
            try {
                $pdo->exec($sql);
                $this->fail($sql.' aurait dû être refusé');
            } catch (\PDOException $e) {
                $this->assertStringContainsString('ajout seul', $e->getMessage());
            }
        }
        $this->assertSame(2, $this->nbRetraits());
    }

    public function test_retrait_et_injection_survivent_a_un_redemarrage(): void
    {
        $this->envoyer($this->retrait());
        $this->demarrer();
        $this->assertSame(409, $this->envoyer($this->injection(self::INJ_2))->statut);
    }
}
