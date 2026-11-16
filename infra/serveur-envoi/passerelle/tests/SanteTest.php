<?php

declare(strict_types=1);

namespace Passerelle\Tests;

use Passerelle\FauxKumo;
use Passerelle\Passerelle;
use Passerelle\Reponse;
use Passerelle\Requete;
use Passerelle\Sante\Mesureur;
use Passerelle\Sante\MesureurFixe;
use Passerelle\Signature;

/** C2-1 : GET /v1/sante et 401 signé « horloge_hors_fenetre ». Aucune écriture, aucun serveur réel. */
final class SanteTest extends PasserelleTestCase
{
    private function avecMesureur(Mesureur $m): void
    {
        $this->passerelle = new Passerelle(
            $this->stockage, new FauxKumo, [self::WS_A => self::CLE_A, self::WS_B => self::CLE_B],
            fn (): int => $this->maintenant, static fn (int $ms) => null, null, $m,
        );
    }

    private function signeValide(Reponse $rep, Requete $r, string $ws = self::WS_A, string $cle = self::CLE_A): bool
    {
        $sig = $rep->entetes['X-Envoi-Signature'] ?? null;

        return $sig !== null && Signature::verifier($cle, $sig, Signature::chargeReponse($r->entete('x-envoi-nonce') ?? '', $ws, $rep->statut, $rep->corps));
    }

    /** @return array<string,int> */
    private function compterLignes(): array
    {
        $pdo = new \PDO('sqlite:'.$this->fichier);
        $n = [];
        foreach ($pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'")->fetchAll(\PDO::FETCH_COLUMN) as $t) {
            if ($t === 'nonces') {
                continue; // le nonce est retenu comme pour toute route
            }
            $n[$t] = (int) $pdo->query('SELECT COUNT(*) FROM "'.$t.'"')->fetchColumn();
        }

        return $n;
    }

    public function test_sante_signee_avec_horloge(): void
    {
        $r = $this->signer('GET', '/v1/sante', '');
        $rep = $this->envoyer($r);
        $this->assertSame(200, $rep->statut);
        $this->assertTrue($this->signeValide($rep, $r));
        $d = json_decode($rep->corps, true);
        $this->assertSame(self::WS_A, $d['workspace_id']);
        $this->assertSame($this->maintenant, $d['mesures']['horloge_unix']);
    }

    public function test_cle_absente_du_mesureur_est_omise_et_exception_ne_donne_pas_500(): void
    {
        $this->avecMesureur(new MesureurFixe(['disque_libre_pct' => 63.5, 'inconnue' => 1]));
        $d = json_decode($this->envoyer($this->signer('GET', '/v1/sante', ''))->corps, true);
        $this->assertSame(63.5, $d['mesures']['disque_libre_pct']);
        $this->assertArrayNotHasKey('memoire_libre_pct', $d['mesures']);
        $this->assertArrayNotHasKey('inconnue', $d['mesures']);
        $this->assertArrayHasKey('horloge_unix', $d['mesures']);

        $this->avecMesureur(new MesureurFixe([], new \RuntimeException('boum')));
        $rep = $this->envoyer($this->signer('GET', '/v1/sante', ''));
        $this->assertSame(200, $rep->statut);
        $this->assertSame(['horloge_unix' => $this->maintenant], json_decode($rep->corps, true)['mesures']);
    }

    public function test_valeurs_non_finies_ou_hors_type_sont_omises(): void
    {
        $this->avecMesureur(new MesureurFixe(['memoire_libre_pct' => NAN, 'disque_libre_pct' => INF, 'tunnel_ok' => true]));
        $m = json_decode($this->envoyer($this->signer('GET', '/v1/sante', ''))->corps, true)['mesures'];
        $this->assertArrayNotHasKey('memoire_libre_pct', $m);
        $this->assertArrayNotHasKey('disque_libre_pct', $m);
        $this->assertTrue($m['tunnel_ok']);
    }

    public function test_horloge_hors_fenetre_rend_401_signe_sans_nonce_retenu(): void
    {
        $nonce = self::uuid();
        $hors = $this->signer('GET', '/v1/sante', '', ts: $this->maintenant - 301, nonce: $nonce);
        $rep = $this->envoyer($hors);
        $this->assertSame(401, $rep->statut);
        $this->assertTrue($this->signeValide($rep, $hors), '401 signé');
        $this->assertSame(['workspace_id' => self::WS_A, 'erreur' => 'horloge_hors_fenetre'], json_decode($rep->corps, true));

        // Même nonce, à l'heure : le nonce n'avait pas été retenu.
        $this->assertSame(200, $this->envoyer($this->signer('GET', '/v1/sante', '', nonce: $nonce))->statut);
    }

    public function test_horloge_hors_fenetre_signe_aussi_pour_les_autres_routes_sans_effet(): void
    {
        $avant = $this->compterLignes();
        $r = $this->commande('pause', ts: $this->maintenant + 301);
        $rep = $this->envoyer($r);
        $this->assertSame(401, $rep->statut);
        $this->assertTrue($this->signeValide($rep, $r));
        $this->assertSame([], $this->kumo->appels);
        $this->assertSame($avant, $this->compterLignes());
    }

    public function test_signature_fausse_hors_fenetre_reste_401_non_signe(): void
    {
        $r = $this->signer('GET', '/v1/sante', '', cle: 'mauvaise-cle', ts: $this->maintenant - 301);
        $rep = $this->envoyer($r);
        $this->assertSame(401, $rep->statut);
        $this->assertArrayNotHasKey('X-Envoi-Signature', $rep->entetes);
        $this->assertSame('non_autorise', json_decode($rep->corps, true)['erreur']);
    }

    public function test_methode_et_requete_refusees_sans_ecriture(): void
    {
        $avant = $this->compterLignes();
        $this->assertSame(405, $this->envoyer($this->signer('POST', '/v1/sante', '{}'))->statut);
        $this->assertSame(400, $this->envoyer($this->signer('GET', '/v1/sante?x=1', ''))->statut);
        $this->assertSame($avant, $this->compterLignes());
    }
}
