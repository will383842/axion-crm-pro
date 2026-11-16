<?php

declare(strict_types=1);

namespace Passerelle\Tests;

use Passerelle\Injection\RefusKumo;
use Passerelle\Kumo;
use Passerelle\Reponse;
use Passerelle\Requete;

/** C2-3 : traduction des refus du moteur et GET /v1/injections/{id}. Faux serveur seulement ; adresses en example.test. */
final class InjectionEtatTest extends PasserelleTestCase
{
    private const MAIL_FROM = 'rb-0123456789abcdef0123456789abcdef0123456789@bounce.client.example.test';

    private static function corps(string $id = self::ID_1): string
    {
        $m = "From: Contact <contact@client.example.test>\r\nTo: cible@client.example.test\r\nSubject: Essai\r\n"
            ."List-Unsubscribe: <https://client.example.test/u/abc>, <mailto:u@client.example.test>\r\n"
            ."List-Unsubscribe-Post: List-Unsubscribe=One-Click\r\n\r\nBonjour\r\n";

        return json_encode(['id_injection' => $id, 'mail_from' => self::MAIL_FROM, 'rcpt_to' => 'cible@client.example.test', 'message' => base64_encode($m)], JSON_THROW_ON_ERROR);
    }

    private function poster(string $ws = self::WS_A, string $id = self::ID_1): Reponse
    {
        return $this->envoyer($this->signer('POST', '/v1/injections', self::corps($id), $ws));
    }

    private function etat(string $id, string $ws = self::WS_A, string $requete = '', string $methode = 'GET'): Reponse
    {
        return $this->envoyer($this->signer($methode, '/v1/injections/'.$id.($requete !== '' ? '?'.$requete : ''), '', $ws));
    }

    /** @return array<string,mixed> */
    private function json(Reponse $r): array
    {
        return json_decode($r->corps, true, 8, JSON_THROW_ON_ERROR);
    }

    private function ligne(string $ws, string $id): ?string
    {
        $v = (new \PDO('sqlite:'.$this->fichier))->query("SELECT etat FROM injections WHERE workspace_id='$ws' AND id_injection='$id'")->fetchColumn();

        return $v === false ? null : (string) $v;
    }

    public function test_refus_4xx_donne_429_retry_after_et_rien_ecrit(): void
    {
        $this->kumo->refus = 451;
        $rep = $this->poster();
        $this->assertSame(429, $rep->statut);
        $this->assertSame('900', $rep->entetes['Retry-After'] ?? null);
        $this->assertSame(451, $this->json($rep)['code_smtp']);
        $this->assertSame('en_cours', $this->ligne(self::WS_A, self::ID_1), 'état en_file NON écrit');
        $this->assertSame('inconnu', $this->json($this->etat(self::ID_1))['etat']);
        // réservation rendue : le même id est réessayable tout de suite et passe quand le moteur accepte
        $this->kumo->refus = null;
        $this->assertSame(202, $this->poster()->statut);
        $this->assertSame('en_file', $this->ligne(self::WS_A, self::ID_1));
    }

    public function test_refus_5xx_donne_422_refus_definitif_etat_refuse_jamais_reexecute(): void
    {
        $this->kumo->refus = 550;
        $rep = $this->poster();
        $this->assertSame(422, $rep->statut);
        $d = $this->json($rep);
        $this->assertSame('refus_definitif', $d['erreur']);
        $this->assertSame(550, $d['code_smtp']);
        $this->assertSame('refuse', $this->ligne(self::WS_A, self::ID_1));
        $this->assertSame('refuse', $this->json($this->etat(self::ID_1))['etat']);
        $this->kumo->refus = null;
        $rejeu = $this->poster();
        $this->assertSame(422, $rejeu->statut);
        $this->assertCount(1, $this->kumo->appels, 'le refus définitif est rejoué, pas ré-exécuté');
    }

    public function test_moteur_injoignable_503_reservation_rendue(): void
    {
        $this->kumo->echec = true;
        $this->assertSame(503, $this->poster()->statut);
        $this->assertSame('inconnu', $this->json($this->etat(self::ID_1))['etat']);
        $this->kumo->echec = false;
        $this->assertSame(202, $this->poster()->statut);
    }

    public function test_lenteur_au_dela_du_delai_max_504_incertain_jamais_reexecute(): void
    {
        $this->kumo->pendant = function (): void {
            $this->maintenant += Kumo::DELAI_MAX_SECONDES + 1;
        };
        $rep = $this->poster();
        $this->assertSame(504, $rep->statut);
        $this->assertSame('issue_incertaine', $this->json($rep)['erreur']);
        $this->assertSame('incertain', $this->ligne(self::WS_A, self::ID_1));
        $this->assertSame('incertain', $this->json($this->etat(self::ID_1))['etat']);
        // rejeu bien après la réservation : toujours incertain, jamais ré-exécuté
        $this->kumo->pendant = null;
        $this->maintenant += 3600;
        $rejeu = $this->poster();
        $this->assertSame(504, $rejeu->statut);
        $this->assertCount(1, $this->kumo->appels);
    }

    public function test_appel_dans_le_delai_reste_202(): void
    {
        $this->kumo->pendant = function (): void {
            $this->maintenant += Kumo::DELAI_MAX_SECONDES;
        };
        $this->assertSame(202, $this->poster()->statut);
    }

    public function test_get_etat_en_file(): void
    {
        $this->poster();
        $rep = $this->etat(self::ID_1);
        $this->assertSame(200, $rep->statut);
        $d = $this->json($rep);
        $this->assertSame(self::ID_1, $d['id_injection']);
        $this->assertSame('en_file', $d['etat']);
        $this->assertSame(self::WS_A, $d['workspace_id']);
    }

    public function test_get_id_absent_inconnu_jamais_404(): void
    {
        $rep = $this->etat(self::ID_2);
        $this->assertSame(200, $rep->statut);
        $this->assertSame('inconnu', $this->json($rep)['etat']);
    }

    public function test_get_id_de_lespace_a_signe_par_b_inconnu(): void
    {
        $this->poster(self::WS_A);
        $this->assertSame('en_file', $this->json($this->etat(self::ID_1, self::WS_A))['etat']);
        $this->assertSame('inconnu', $this->json($this->etat(self::ID_1, self::WS_B))['etat']);
    }

    public function test_get_etat_retire_lu_tel_quel(): void
    {
        $this->poster();
        (new \PDO('sqlite:'.$this->fichier))->exec("UPDATE injections SET etat='retire'");
        $this->assertSame('retire', $this->json($this->etat(self::ID_1))['etat']);
    }

    public function test_get_requete_non_vide_400_methode_405_id_invalide_404(): void
    {
        $this->assertSame(400, $this->etat(self::ID_1, self::WS_A, 'x=1')->statut);
        $this->assertSame(405, $this->etat(self::ID_1, self::WS_A, '', 'POST')->statut);
        $this->assertSame(404, $this->etat('pas-un-uuid')->statut);
    }

    public function test_get_nenregistre_rien_et_nappelle_pas_le_moteur(): void
    {
        $this->etat(self::ID_1);
        $this->assertCount(0, $this->kumo->appels);
        $this->assertNull($this->ligne(self::WS_A, self::ID_1));
    }

    public function test_refus_kumo_borne_le_code_smtp(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new RefusKumo(250);
    }
}
