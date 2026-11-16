<?php

declare(strict_types=1);

namespace Passerelle\Tests;

use Passerelle\FauxKumo;
use Passerelle\KumoInactif;
use Passerelle\Passerelle;
use Passerelle\Reponse;
use Passerelle\Requete;

/** C2-2 : POST /v1/injections. Faux serveur seulement : aucun envoi réel, adresses en example.test. */
final class InjectionTest extends PasserelleTestCase
{
    private const MAIL_FROM = 'rb-0123456789abcdef0123456789abcdef0123456789@bounce.client.example.test';

    private static function message(string $ajoutEntetes = '', bool $unsub = true, bool $post = true, string $from = 'contact@client.example.test', string $corps = "Bonjour\r\n"): string
    {
        $e = "From: Contact <{$from}>\r\nTo: cible@client.example.test\r\nSubject: Essai\r\nMessage-ID: <1@client.example.test>\r\n";
        $e .= $unsub ? "List-Unsubscribe: <https://client.example.test/u/abc>, <mailto:u@client.example.test>\r\n" : '';
        $e .= $post ? "List-Unsubscribe-Post: List-Unsubscribe=One-Click\r\n" : '';

        return $e.$ajoutEntetes."\r\n".$corps;
    }

    /** @param array<string,mixed> $surcharge */
    private static function corps(?string $message = null, array $surcharge = []): string
    {
        return json_encode($surcharge + [
            'id_injection' => self::ID_1, 'mail_from' => self::MAIL_FROM, 'rcpt_to' => 'cible@client.example.test',
            'message' => base64_encode($message ?? self::message()),
        ], JSON_THROW_ON_ERROR);
    }

    private function poster(string $corps, string $ws = self::WS_A): Requete
    {
        return $this->signer('POST', '/v1/injections', $corps, $ws);
    }

    private function nbLignes(): int
    {
        return (int) (new \PDO('sqlite:'.$this->fichier))->query('SELECT COUNT(*) FROM injections')->fetchColumn();
    }

    /** @return array<string,mixed> */
    private function json(Reponse $r): array
    {
        return json_decode($r->corps, true, 8, JSON_THROW_ON_ERROR);
    }

    public function test_injection_acceptee_202_un_appel_faux_kumo(): void
    {
        $rep = $this->envoyer($this->poster(self::corps()));
        $this->assertSame(202, $rep->statut);
        $d = $this->json($rep);
        $this->assertSame(self::ID_1, $d['id_injection']);
        $this->assertSame('en_file', $d['etat']);
        $this->assertSame(self::WS_A, $d['workspace_id']);
        $this->assertCount(1, $this->kumo->appels);
        $a = $this->kumo->appels[0];
        $this->assertSame('injecter', $a['operation']);
        $this->assertSame(self::WS_A, $a['workspace_id']);
        $this->assertSame(self::MAIL_FROM, $a['params']['mail_from']);
        $this->assertSame('cible@client.example.test', $a['params']['rcpt_to']);
        $this->assertSame(self::message(), $a['params']['message']); // octets décodés, tels quels
    }

    public function test_rejeu_identique_meme_reponse_un_seul_appel(): void
    {
        $premiere = $this->envoyer($this->poster(self::corps()));
        $seconde = $this->envoyer($this->poster(self::corps()));
        $this->assertSame(202, $seconde->statut);
        $this->assertSame($this->json($premiere), $this->json($seconde));
        $this->assertCount(1, $this->kumo->appels);
    }

    public function test_meme_id_autre_corps_409(): void
    {
        $this->envoyer($this->poster(self::corps()));
        $rep = $this->envoyer($this->poster(self::corps(null, ['rcpt_to' => 'autre@client.example.test'])));
        $this->assertSame(409, $rep->statut);
        $this->assertSame('id_injection_deja_utilise', $this->json($rep)['erreur']);
        $this->assertCount(1, $this->kumo->appels);
    }

    public function test_deux_requetes_concurrentes_un_seul_appel(): void
    {
        $imbrique = null;
        $this->kumo->pendant = function () use (&$imbrique): void {
            $this->kumo->pendant = null;
            $imbrique = $this->envoyer($this->poster(self::corps())); // arrive PENDANT l'appel : réservation en cours
        };
        $rep = $this->envoyer($this->poster(self::corps()));
        $this->assertSame(202, $rep->statut);
        $this->assertSame(503, $imbrique?->statut);
        $this->assertCount(1, $this->kumo->appels);
        $this->assertSame(202, $this->envoyer($this->poster(self::corps()))->statut); // puis rejeu normal
        $this->assertCount(1, $this->kumo->appels);
    }

    public function test_espace_b_rejoue_id_de_a_traite_comme_nouveau(): void
    {
        $this->envoyer($this->poster(self::corps()));
        $rep = $this->envoyer($this->poster(self::corps(), self::WS_B));
        $this->assertSame(202, $rep->statut);
        $this->assertSame(self::WS_B, $this->json($rep)['workspace_id']);
        $this->assertCount(2, $this->kumo->appels);
        $this->assertSame(self::WS_B, $this->kumo->appels[1]['workspace_id']);
    }

    public function test_espace_dans_le_corps_est_refuse(): void
    {
        $rep = $this->envoyer($this->poster(self::corps(null, ['workspace_id' => self::WS_B])));
        $this->assertSame(400, $rep->statut);
        $this->assertSame('corps', $this->json($rep)['erreur']);
        $this->assertSame([], $this->kumo->appels);
    }

    public function test_corps_de_1_2_mo_signe_accepte_et_3_mo_refuse_401_avant_lecture(): void
    {
        $gros = self::message('', true, true, 'contact@client.example.test', str_repeat(str_repeat('A', 76)."\r\n", 11_000)); // ~850 Ko
        $corps = self::corps($gros);
        $this->assertGreaterThan(1_100_000, strlen($corps));
        $this->assertSame(202, $this->envoyer($this->poster($corps))->statut);

        $enorme = $this->poster(str_repeat('x', 3_000_000));
        $rep = $this->envoyer($enorme);
        $this->assertSame(401, $rep->statut);
        $this->assertArrayNotHasKey('X-Envoi-Signature', $rep->entetes);
        $this->assertSame(1, $this->nbLignes()); // le 3 Mo n'a rien réservé
        // Le plafond d'avant authentification reste 2 × 65 536 hors injections.
        $this->assertSame(401, $this->envoyer($this->signer('POST', '/v1/commandes/pause', str_repeat('x', 200_000)))->statut);
    }

    public function test_message_decode_au_dela_de_1_mio_400(): void
    {
        $m = self::message('', true, true, 'contact@client.example.test', str_repeat(str_repeat('A', 76)."\r\n", 13_600)); // > 1 048 576
        $this->assertGreaterThan(1_048_576, strlen($m));
        $rep = $this->envoyer($this->poster(self::corps($m)));
        $this->assertSame(400, $rep->statut);
        $this->assertSame('message_trop_gros', $this->json($rep)['erreur']);
        $this->assertSame(0, $this->nbLignes());
    }

    public function test_kumo_inactif_503_reservation_rendue_rejouable(): void
    {
        $inactif = new Passerelle($this->stockage, new KumoInactif, [self::WS_A => self::CLE_A], fn (): int => $this->maintenant, static fn (int $ms) => null);
        $rep = $inactif->traiter($this->poster(self::corps()));
        $this->assertSame(503, $rep->statut);
        $this->assertSame('indisponible', $this->json($rep)['erreur']);
        // Rendue : le même id est rejouable tout de suite, sans attendre la fin de réservation.
        $this->assertSame(202, $this->envoyer($this->poster(self::corps()))->statut);
        $this->assertCount(1, $this->kumo->appels);
    }

    public function test_panne_faux_kumo_rend_la_reservation(): void
    {
        $this->kumo->echec = true;
        $this->assertSame(503, $this->envoyer($this->poster(self::corps()))->statut);
        $this->kumo->echec = false;
        $this->assertSame(202, $this->envoyer($this->poster(self::corps()))->statut);
    }

    public function test_route_commande_injecter_reste_404_et_methodes(): void
    {
        $this->assertSame(404, $this->envoyer($this->commande('injecter'))->statut);
        $this->assertSame(405, $this->envoyer($this->signer('GET', '/v1/injections', ''))->statut);
        $this->assertSame(400, $this->envoyer($this->signer('POST', '/v1/injections?x=1', self::corps()))->statut);
        $this->assertSame([], $this->kumo->appels);
    }

    public function test_aucune_adresse_ni_message_stockes(): void
    {
        $this->envoyer($this->poster(self::corps(self::message('', true, true, 'contact@client.example.test', "SECRET-CONTENU\r\n"))));
        $this->stockage->purger();
        $octets = '';
        foreach (['', '-wal'] as $s) {
            $octets .= is_file($this->fichier.$s) ? (string) file_get_contents($this->fichier.$s) : '';
        }
        $this->assertStringNotContainsString('cible@client.example.test', $octets);
        $this->assertStringNotContainsString('SECRET-CONTENU', $octets);
        $this->assertStringNotContainsString(base64_encode("SECRET-CONTENU\r\n"), $octets);
        $this->assertStringNotContainsString('rb-0123456789', $octets);
        $this->assertSame(1, $this->nbLignes());
        $this->expectException(\PDOException::class); // table en ajout seul : jamais vidée
        (new \PDO('sqlite:'.$this->fichier, null, null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]))->exec('DELETE FROM injections');
    }

    /** @return iterable<string,array{string,int,string}> */
    public static function refus(): iterable
    {
        $b64 = static fn (string $m): string => base64_encode($m);
        yield 'json invalide' => ['pas du json', 400, 'corps'];
        yield 'cle manquante' => [json_encode(['id_injection' => self::ID_1]), 400, 'corps'];
        yield 'cle en trop' => [self::corps(null, ['extra' => 1]), 400, 'corps'];
        yield 'id non uuid' => [self::corps(null, ['id_injection' => 'pas-un-uuid']), 400, 'id_injection'];
        yield 'id majuscules' => [self::corps(null, ['id_injection' => strtoupper(self::ID_1)]), 400, 'id_injection'];
        yield 'mail_from non verp' => [self::corps(null, ['mail_from' => 'contact@client.example.test']), 400, 'mail_from'];
        yield 'mail_from verp court' => [self::corps(null, ['mail_from' => 'rb-abc@bounce.client.example.test']), 400, 'mail_from'];
        yield 'rcpt deux adresses' => [self::corps(null, ['rcpt_to' => 'a@client.example.test,b@client.example.test']), 400, 'rcpt_to'];
        yield 'rcpt crlf' => [self::corps(null, ['rcpt_to' => "a@client.example.test\r\nRCPT TO:<b@client.example.test>"]), 400, 'rcpt_to'];
        yield 'rcpt non ascii' => [self::corps(null, ['rcpt_to' => "é@client.example.test"]), 400, 'rcpt_to'];
        yield 'rcpt avec nom' => [self::corps(null, ['rcpt_to' => 'A <a@client.example.test>']), 400, 'rcpt_to'];
        yield 'base64 invalide' => [self::corps(null, ['message' => '***pas base64***']), 400, 'message'];
        yield 'base64 non strict' => [self::corps(null, ['message' => 'QUJD ']), 400, 'message'];
        yield 'LF nu' => [self::corps("From: a@client.example.test\nSubject: x\n\ncorps\n"), 400, 'fin_de_ligne'];
        yield 'LF nu dans le corps' => [self::corps(self::message('', true, true, 'contact@client.example.test', "ligne\nlf nu\r\n")), 400, 'fin_de_ligne'];
        yield 'CR nu' => [self::corps(self::message('', true, true, 'contact@client.example.test', "ligne\rcr nu\r\n")), 400, 'fin_de_ligne'];
        yield 'X-Envoi-Jeton' => [self::corps(self::message("X-Envoi-Jeton: abc\r\n")), 422, 'conformite'];
        yield 'x-envoi minuscules' => [self::corps(self::message("x-envoi-autre: 1\r\n")), 422, 'conformite'];
        yield 'DKIM-Signature' => [self::corps(self::message("DKIM-Signature: v=1; a=rsa-sha256\r\n")), 422, 'conformite'];
        yield 'sans List-Unsubscribe' => [self::corps(self::message('', false)), 422, 'conformite'];
        yield 'sans One-Click' => [self::corps(self::message('', true, false)), 422, 'conformite'];
        yield 'One-Click faux' => [self::corps(self::message("List-Unsubscribe-Post: autre\r\n", true, false)), 422, 'conformite'];
        yield 'alignement' => [self::corps(self::message('', true, true, 'contact@autre.example.test')), 422, 'alignement'];
        yield 'alignement sous-domaine' => [self::corps(self::message('', true, true, 'contact@sous.client.example.test')), 422, 'alignement'];
        yield 'sans From' => [self::corps("To: a@client.example.test\r\nList-Unsubscribe: <https://client.example.test/u>\r\nList-Unsubscribe-Post: List-Unsubscribe=One-Click\r\n\r\nx\r\n"), 422, 'alignement'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('refus')]
    public function test_refus_de_validation_rien_stocke_rien_envoye(string $corps, int $statut, string $motif): void
    {
        $rep = $this->envoyer($this->poster($corps));
        $this->assertSame($statut, $rep->statut);
        $this->assertSame($motif, $this->json($rep)['erreur']);
        $this->assertSame(0, $this->nbLignes());
        $this->assertSame([], $this->kumo->appels);
    }

    public function test_reservation_abandonnee_se_reprend_apres_delai(): void
    {
        $this->kumo->pendant = function (): void {
            throw new \Error('processus mort'); // la réservation reste « en_cours » sans être rendue proprement
        };
        try {
            $this->envoyer($this->poster(self::corps()));
        } catch (\Throwable) {
        }
        $this->kumo->pendant = null;
        $this->maintenant += \Passerelle\Stockage::DELAI_RESERVATION + 1;
        $this->assertSame(202, $this->envoyer($this->poster(self::corps()))->statut);
    }
}
