<?php

declare(strict_types=1);

namespace Passerelle\Tests;

use Passerelle\Requete;

final class AuthentificationTest extends PasserelleTestCase
{
    private function corps(): array
    {
        return ['commande_id' => 'cmd-1', 'workspace_id' => 'espace-a', 'params' => ['file' => 'f1']];
    }

    public function test_signature_valide_accepte(): void
    {
        $this->assertSame(200, $this->envoyer($this->commande('pause', $this->corps()))->statut);
    }

    public function test_signature_invalide_refusee(): void
    {
        $r = $this->commande('pause', $this->corps(), cle: 'mauvaise-cle');
        $rep = $this->envoyer($r);
        $this->assertSame(401, $rep->statut);
        $this->assertSame([], $this->kumo->appels);
    }

    public function test_signature_absente_ou_corps_modifie_refuses(): void
    {
        $r = $this->commande('pause', $this->corps());
        $sansSig = new Requete($r->methode, $r->chemin, $r->corps, array_diff_key($r->entetes, ['x-signature' => 1]));
        $this->assertSame(401, $this->envoyer($sansSig)->statut);

        $r = $this->commande('pause', $this->corps());
        $modifie = new Requete($r->methode, $r->chemin, str_replace('f1', 'f2', $r->corps), $r->entetes);
        $this->assertSame(401, $this->envoyer($modifie)->statut);
        $this->assertSame([], $this->kumo->appels);
    }

    public function test_horodatage_hors_fenetre_refuse(): void
    {
        foreach ([-301, 301, -86400] as $decalage) {
            $r = $this->commande('pause', $this->corps(), ts: $this->maintenant + $decalage, nonce: 'n-'.abs($decalage));
            $this->assertSame(401, $this->envoyer($r)->statut, "décalage $decalage");
        }
        $this->assertSame([], $this->kumo->appels);
    }

    public function test_horodatage_dans_la_fenetre_accepte(): void
    {
        $r = $this->commande('pause', $this->corps(), ts: $this->maintenant - 299);
        $this->assertSame(200, $this->envoyer($r)->statut);
    }

    public function test_rejeu_du_meme_nonce_refuse(): void
    {
        $r = $this->commande('pause', $this->corps());
        $this->assertSame(200, $this->envoyer($r)->statut);
        $this->assertSame(401, $this->envoyer($r)->statut);
        $this->assertCount(1, $this->kumo->appels);
    }

    public function test_workspace_falsifie_dans_l_entete_refuse(): void
    {
        // Requête signée avec la clé de A pour A, dont on change l'en-tête vers B.
        $r = $this->commande('pause', $this->corps());
        $e = $r->entetes;
        $e['x-workspace-id'] = ['espace-b'];
        $this->assertSame(401, $this->envoyer(new Requete($r->methode, $r->chemin, $r->corps, $e))->statut);
        $this->assertSame([], $this->kumo->appels);
    }

    public function test_cle_de_a_avec_workspace_b_signe_refuse(): void
    {
        $r = $this->commande('pause', ['commande_id' => 'c', 'workspace_id' => 'espace-b', 'params' => []], ws: 'espace-b', cle: self::CLE_A);
        $this->assertSame(401, $this->envoyer($r)->statut);
    }

    public function test_workspace_du_corps_different_de_la_charge_signee_refuse(): void
    {
        // Signature valide pour A, mais le corps (signé) prétend viser B.
        $r = $this->commande('pause', ['commande_id' => 'c', 'workspace_id' => 'espace-b', 'params' => []]);
        $this->assertSame(403, $this->envoyer($r)->statut);
        $this->assertSame([], $this->kumo->appels);
    }

    public function test_workspace_inconnu_ou_cle_vide_refuse(): void
    {
        $r = $this->commande('pause', ['commande_id' => 'c', 'workspace_id' => 'inconnu', 'params' => []], ws: 'inconnu', cle: 'x');
        $this->assertSame(401, $this->envoyer($r)->statut);

        $this->passerelle = new \Passerelle\Passerelle($this->stockage, $this->kumo, ['espace-a' => ''], fn () => $this->maintenant);
        $this->assertSame(401, $this->envoyer($this->commande('pause', $this->corps(), cle: ''))->statut);
    }

    public function test_entete_de_signature_en_double_refuse(): void
    {
        $r = $this->commande('pause', $this->corps());
        $e = $r->entetes;
        $e['x-signature'][] = $e['x-signature'][0];
        $this->assertSame(401, $this->envoyer(new Requete($r->methode, $r->chemin, $r->corps, $e))->statut);
    }

    public function test_signature_dans_l_url_refusee(): void
    {
        $r = $this->signer('GET', '/v1/evenements?curseur=0&signature=abc', '');
        $this->assertSame(400, $this->envoyer($r)->statut);
    }

    public function test_reponse_ne_fuit_rien(): void
    {
        $rep = $this->envoyer($this->commande('pause', $this->corps(), cle: 'mauvaise'));
        $this->assertSame('{"erreur":"non_autorise"}', $rep->corps);
        $this->assertArrayNotHasKey('server', array_change_key_case($rep->entetes));
    }

    public function test_commande_interdite_et_routes_inconnues(): void
    {
        $this->assertSame(403, $this->envoyer($this->commande('eteindre_temoins', $this->corps()))->statut);
        $this->assertSame(404, $this->envoyer($this->signer('GET', '/api/admin/x', ''))->statut);
        $this->assertSame(404, $this->envoyer($this->signer('POST', '/v1/commandes/../x', '{}'))->statut);
        $this->assertSame(405, $this->envoyer($this->signer('DELETE', '/v1/evenements', ''))->statut);
        $this->assertSame([], $this->kumo->appels);
    }

    public function test_vecteurs_communs_hmac(): void
    {
        $v = json_decode((string) file_get_contents(__DIR__.'/vecteurs-hmac.json'), true, 8, JSON_THROW_ON_ERROR);
        $this->assertNotEmpty($v['vecteurs']);
        foreach ($v['vecteurs'] as $x) {
            $this->assertSame($x['signature'], \Passerelle\Signature::signer($x['cle'], $x['workspace_id'], $x['timestamp'], $x['nonce'], $x['methode'], $x['chemin'], $x['corps']), $x['nom']);
        }
    }
}
