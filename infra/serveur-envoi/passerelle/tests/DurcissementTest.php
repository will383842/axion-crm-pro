<?php

declare(strict_types=1);

namespace Passerelle\Tests;

use Passerelle\Configuration;
use Passerelle\GardeTls;
use Passerelle\Passerelle;
use Passerelle\Requete;
use Passerelle\Signature;
use Passerelle\Stockage;

final class DurcissementTest extends PasserelleTestCase
{
    private function lire(string $q, string $ws = self::WS_A): array
    {
        $rep = $this->envoyer($this->signer('GET', '/v1/evenements'.$q, '', $ws));

        return ['statut' => $rep->statut, 'json' => json_decode($rep->corps, true), 'rep' => $rep];
    }

    // --- B1 : curseur au-delà du dernier numéro, époque de la base ---

    public function test_curseur_superieur_au_dernier_numero_donne_409(): void
    {
        $this->stockage->ajouterEvenement(self::WS_A, 'unsubscribe', ['id_injection' => 'a']);
        $r = $this->lire('?curseur=5');
        $this->assertSame(409, $r['statut']);
        $this->assertSame('curseur_desynchronise', $r['json']['erreur']);
        $this->assertSame($this->stockage->epoque(), $r['json']['epoque']);
        $this->assertSame(200, $this->lire('?curseur=1')['statut'], 'curseur = dernier : valide');
    }

    public function test_base_recreee_vide_puis_curseur_ancien_donne_409(): void
    {
        $this->stockage->ajouterEvenement(self::WS_A, 'hard_bounce', []);
        $this->stockage->ajouterEvenement(self::WS_A, 'hard_bounce', []);
        $this->assertSame(200, $this->lire('?curseur=2')['statut']);
        foreach (['', '-wal', '-shm'] as $s) {
            @unlink($this->fichier.$s);
        }
        $this->demarrer(); // base recréée
        $this->assertSame(409, $this->lire('?curseur=2')['statut'], 'jamais de perte silencieuse');
    }

    public function test_epoque_aleatoire_stable_et_dans_event_id(): void
    {
        $e = $this->stockage->epoque();
        $this->assertMatchesRegularExpression('/^[0-9a-f]{16}$/', $e);
        $this->demarrer();
        $this->assertSame($e, $this->stockage->epoque(), 'stable au redémarrage');
        $this->stockage->ajouterEvenement(self::WS_A, 'delivery', ['id_injection' => 'x']);
        $r = $this->lire('?curseur=0');
        $this->assertSame(self::WS_A.':'.$e.':1', $r['json']['evenements'][0]['event_id']);
        $this->assertLessThanOrEqual(128, strlen($r['json']['evenements'][0]['event_id']));
        $this->assertSame($e, $r['json']['epoque']);
        foreach (['', '-wal', '-shm'] as $s) {
            @unlink($this->fichier.$s);
        }
        $this->demarrer();
        $this->assertNotSame($e, $this->stockage->epoque(), 'nouvelle base = nouvelle époque');
    }

    public function test_epoque_fournie_et_differente_donne_409(): void
    {
        $this->stockage->ajouterEvenement(self::WS_A, 'delivery', []);
        $this->assertSame(409, $this->lire('?curseur=0&epoque=0123456789abcdef')['statut']);
        $this->assertSame(200, $this->lire('?curseur=0&epoque='.$this->stockage->epoque())['statut']);
        $this->assertSame(400, $this->lire('?curseur=0&epoque=zz')['statut']);
    }

    // --- I1/I4 : nonce ---

    public function test_nonce_rejoue_a_599_s_avec_horodatage_300_s_refuse(): void
    {
        $r = $this->commande('pause', ts: $this->maintenant + 300);
        $this->assertSame(200, $this->envoyer($r)->statut);
        $this->maintenant += 599;
        $this->assertSame(409, $this->envoyer($r)->statut, 'RETENTION_NONCES couvre toute la fenêtre');
        $this->assertGreaterThan(599, Stockage::RETENTION_NONCES);
        $this->assertCount(1, $this->kumo->appels);
    }

    // --- I2 : rotation des clés ---

    public function test_deux_cles_actives_choix_par_k(): void
    {
        $v1 = 'ancienne-cle-0123456789abcdef-0123456789';
        $v2 = 'nouvelle-cle-0123456789abcdef-0123456789';
        $this->passerelle = new Passerelle($this->stockage, $this->kumo, [self::WS_A => ['1' => $v1, '2' => $v2]], fn () => $this->maintenant, static fn (int $ms) => null);
        foreach (['1' => $v1, '2' => $v2] as $id => $cle) {
            $r = $this->signerAvecId((string) $id, $cle);
            $rep = $this->envoyer($r);
            $this->assertSame(200, $rep->statut, "k=$id");
            [$idRep] = Signature::analyser($rep->entetes['X-Envoi-Signature']);
            $this->assertSame((string) $id, $idRep, 'réponse signée avec la clé choisie');
        }
        // k=1 annoncé mais signé avec la clé 2 : refus ; k inconnu : refus.
        $this->assertSame(401, $this->envoyer($this->signerAvecId('1', $v2))->statut);
        $this->assertSame(401, $this->envoyer($this->signerAvecId('3', $v2))->statut);
        // sans k avec deux clés actives : ambigu, refus
        $r = $this->signerAvecId('1', $v1);
        $e = $r->entetes;
        $e['x-envoi-signature'] = [explode(',', $e['x-envoi-signature'][0], 2)[1]];
        $this->assertSame(401, $this->envoyer(new Requete($r->methode, $r->chemin, $r->corps, $e))->statut);
    }

    private function signerAvecId(string $id, string $cle): Requete
    {
        $r = $this->commande('pause', id: self::uuid(), cle: $cle);
        $e = $r->entetes;
        $e['x-envoi-signature'] = [preg_replace('/^k=[^,]*/', 'k='.$id, $e['x-envoi-signature'][0])];

        return new Requete($r->methode, $r->chemin, $r->corps, $e);
    }

    public function test_configuration_cles_deux_formes_et_garde_de_longueur(): void
    {
        $longue = str_repeat('a', 32);
        $this->assertSame([self::WS_A => ['1' => $longue]], Configuration::cles(json_encode([self::WS_A => $longue])));
        $this->assertSame([self::WS_A => ['1' => $longue, '2' => str_repeat('b', 40)]], Configuration::cles(json_encode([self::WS_A => ['1' => $longue, '2' => str_repeat('b', 40)]])));
        $this->assertSame([], Configuration::cles(json_encode([self::WS_A => str_repeat('a', 31)])), 'moins de 32 octets : refusée');
        $this->assertSame([self::WS_A => ['2' => $longue]], Configuration::cles(json_encode([self::WS_A => ['1' => 'courte', '2' => $longue]])));
        $this->assertSame([], Configuration::cles(json_encode([self::WS_A => ['1' => $longue, '2' => $longue, '3' => $longue]])), 'plus de deux clés actives : espace refusé');
    }

    // --- Mineurs ---

    public function test_methode_en_minuscules_normalisee_une_seule_fois(): void
    {
        $r = new Requete('get', '/v1/evenements?curseur=', '', []);
        $this->assertSame('GET', $r->methode);
        $this->assertStringStartsWith('GET', Signature::chargeRequete('GET', '/x', '1', 'n', 'w', ''));
    }

    public function test_commande_interdite_declenche_une_alerte_de_derive(): void
    {
        $alertes = [];
        $this->passerelle = new Passerelle($this->stockage, $this->kumo, [self::WS_A => self::CLE_A], fn () => $this->maintenant, static fn (int $ms) => null, function (string $code, array $ctx) use (&$alertes): void {
            $alertes[] = [$code, $ctx];
        });
        $this->assertSame(403, $this->envoyer($this->commande('eteindre_temoins', commande: 'pause'))->statut);
        $this->assertSame([['commande_interdite', ['workspace_id' => self::WS_A, 'operation' => 'eteindre_temoins']]], $alertes);
        $this->assertSame([], $this->kumo->appels);
    }

    public function test_depuis_strictement_iso_8601(): void
    {
        foreach (['demain', '2026-01-01', '@1700000000', '2026-13-45T00:00:00%2B00:00', 'now', '1+day'] as $d) {
            $this->assertSame(400, $this->lire('?curseur=&depuis='.rawurlencode(rawurldecode($d)))['statut'], $d);
        }
        $this->assertSame(200, $this->lire('?curseur=&depuis='.rawurlencode('2026-01-01T00:00:00+00:00'))['statut']);
        $this->assertSame(200, $this->lire('?curseur=&depuis='.rawurlencode('2026-01-01T00:00:00Z'))['statut']);
    }

    public function test_base_illisible_donne_500_signe_et_propre(): void
    {
        $this->passerelle = new Passerelle(function (): Stockage {
            throw new \RuntimeException('chemin /secret/base impossible');
        }, $this->kumo, [self::WS_A => self::CLE_A], fn () => $this->maintenant, static fn (int $ms) => null);
        $nonce = self::uuid();
        $rep = $this->envoyer($this->signer('GET', '/v1/evenements?curseur=', '', nonce: $nonce));
        $this->assertSame(500, $rep->statut);
        $this->assertStringNotContainsString('secret', $rep->corps);
        $this->assertSame(Signature::signer(self::CLE_A, Signature::chargeReponse($nonce, self::WS_A, 500, $rep->corps)), preg_replace('/^k=1,sha256=/', '', $rep->entetes['X-Envoi-Signature']));
        // Non authentifié : 500 propre non signé, jamais d'exception.
        $this->assertSame(401, $this->envoyer($this->signer('GET', '/v1/evenements?curseur=', '', cle: 'mauvaise-cle-0123456789abcdef-012345'))->statut);
    }

    public function test_droits_0600_poses_par_le_code(): void
    {
        clearstatcache();
        $this->assertSame('0600', substr(sprintf('%o', fileperms($this->fichier)), -4));
        $this->stockage->ajouterEvenement(self::WS_A, 'delivery', []);
        clearstatcache();
        if (is_file($this->fichier.'-wal')) {
            $this->assertSame('0600', substr(sprintf('%o', fileperms($this->fichier.'-wal')), -4));
        }
    }

    // --- I5 : deux objets Stockage sur le même fichier ---

    public function test_concurrence_deux_stockages_meme_fichier(): void
    {
        $h = fn (): int => $this->maintenant;
        $s1 = new Stockage($this->fichier, $h);
        $s2 = new Stockage($this->fichier, $h);
        $e = Passerelle::empreinte('pause', self::ID_1);
        $this->assertSame('executer', $s1->reserverCommande(self::WS_A, self::ID_1, 'pause', $e)['action']);
        $this->assertSame('attendre', $s2->reserverCommande(self::WS_A, self::ID_1, 'pause', $e)['action'], 'une seule exécution');
        $this->assertSame('conflit', $s2->reserverCommande(self::WS_A, self::ID_1, 'retrait', Passerelle::empreinte('retrait', self::ID_1))['action']);
        $s1->terminerCommande(self::WS_A, self::ID_1, 'pause', 200, '{"statut":"ok"}');
        $this->assertSame('rejouer', $s2->reserverCommande(self::WS_A, self::ID_1, 'pause', $e)['action']);
        $this->assertSame(1, $s2->nombreCommandes());

        $n = self::uuid();
        $this->assertTrue($s1->retenirNonce(self::WS_A, $n));
        $this->assertFalse($s2->retenirNonce(self::WS_A, $n), 'nonce partagé entre objets');

        $seqs = [];
        for ($i = 0; $i < 6; $i++) {
            $seqs[] = ($i % 2 === 0 ? $s1 : $s2)->ajouterEvenement(self::WS_A, 'delivery', []);
        }
        $this->assertSame([1, 2, 3, 4, 5, 6], $seqs, 'séquence sans trou ni doublon');
        $this->assertSame([], $s1->trous(self::WS_A));
        $this->assertSame($s1->epoque(), $s2->epoque());
    }

    // --- TLS mutuel (garde par défaut) ---

    public function test_garde_tls_refuse_sans_client_verifie_quand_envoi_reel_actif(): void
    {
        $this->assertFalse(GardeTls::autorise([], 'true'));
        $this->assertFalse(GardeTls::autorise(['SSL_CLIENT_VERIFY' => 'NONE'], 'true'));
        $this->assertFalse(GardeTls::autorise(['SSL_CLIENT_VERIFY' => 'FAILED:unable to verify'], 'true'));
        $this->assertFalse(GardeTls::autorise(['SSL_CLIENT_VERIFY' => 'success'], 'true'));
        $this->assertFalse(GardeTls::autorise([], 'valeur-bizarre'), 'doute = envoi réel supposé actif');
        $this->assertTrue(GardeTls::autorise(['SSL_CLIENT_VERIFY' => 'SUCCESS'], 'true'));
        $this->assertTrue(GardeTls::autorise([], 'false'));
        // Audit #461 (3) : variable absente ou vide = envoi supposé ACTIF (php-fpm vide l'environnement) ; test durci, pas affaibli.
        $this->assertFalse(GardeTls::autorise([], null));
        $this->assertFalse(GardeTls::autorise([], ''));
    }

    public function test_index_php_refuse_401_sans_certificat_client(): void
    {
        $cmd = 'ENVOI_REEL_ACTIF=true PASSERELLE_BASE=/nonexistent/x.sqlite '.escapeshellarg(PHP_BINARY).' -d display_errors=0 '.escapeshellarg(__DIR__.'/../public/index.php').' 2>&1';
        exec($cmd, $sortie, $code);
        $this->assertSame('{"erreur":"non_autorise"}', trim(implode("\n", $sortie)));
    }
}
