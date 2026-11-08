<?php

declare(strict_types=1);

namespace Passerelle\Tests;

use Passerelle\Passerelle;
use Passerelle\Requete;
use Passerelle\Signature;

final class AuthentificationTest extends PasserelleTestCase
{
    public function test_signature_valide_accepte(): void
    {
        $this->assertSame(200, $this->envoyer($this->commande('pause'))->statut);
    }

    public function test_signature_invalide_refusee(): void
    {
        $rep = $this->envoyer($this->commande('pause', cle: 'mauvaise-cle'));
        $this->assertSame(401, $rep->statut);
        $this->assertSame([], $this->kumo->appels);
    }

    public function test_signature_absente_ou_corps_modifie_refuses(): void
    {
        $r = $this->commande('pause');
        $sansSig = new Requete($r->methode, $r->chemin, $r->corps, array_diff_key($r->entetes, ['x-envoi-signature' => 1]));
        $this->assertSame(401, $this->envoyer($sansSig)->statut);

        $r = $this->commande('pause');
        $modifie = new Requete($r->methode, $r->chemin, str_replace(self::ID_1, self::ID_2, $r->corps), $r->entetes);
        $this->assertSame(401, $this->envoyer($modifie)->statut);
        $this->assertSame([], $this->kumo->appels);
    }

    public function test_les_anciens_en_tetes_ne_sont_plus_lus(): void
    {
        $r = $this->commande('pause');
        $anciens = [];
        foreach ($r->entetes as $k => $v) {
            $anciens[str_replace('x-envoi-', 'x-', $k)] = $v;
        }
        $this->assertSame(401, $this->envoyer(new Requete($r->methode, $r->chemin, $r->corps, $anciens))->statut);
    }

    public function test_horodatage_hors_fenetre_refuse(): void
    {
        foreach ([-301, 301, -86400] as $decalage) {
            $r = $this->commande('pause', ts: $this->maintenant + $decalage);
            $this->assertSame(401, $this->envoyer($r)->statut, "décalage $decalage");
        }
        $this->assertSame([], $this->kumo->appels);
    }

    public function test_horodatage_dans_la_fenetre_accepte(): void
    {
        $this->assertSame(200, $this->envoyer($this->commande('pause', ts: $this->maintenant - 299))->statut);
    }

    public function test_rejeu_du_meme_nonce_refuse(): void
    {
        $r = $this->commande('pause');
        $this->assertSame(200, $this->envoyer($r)->statut);
        $this->assertSame(401, $this->envoyer($r)->statut);
        $this->assertCount(1, $this->kumo->appels);
    }

    public function test_nonce_non_uuid_refuse(): void
    {
        $this->assertSame(401, $this->envoyer($this->commande('pause', nonce: 'nonce-pas-un-uuid-12345'))->statut);
    }

    public function test_workspace_non_uuid_refuse(): void
    {
        $this->passerelle = new Passerelle($this->stockage, $this->kumo, ['espace-a' => self::CLE_A], fn () => $this->maintenant);
        $this->assertSame(401, $this->envoyer($this->commande('pause', ws: 'espace-a'))->statut);
    }

    public function test_workspace_falsifie_dans_l_entete_refuse(): void
    {
        // Signé pour A avec la clé de A ; l'en-tête est changé vers B : la charge signée contient l'espace.
        $r = $this->commande('pause');
        $e = $r->entetes;
        $e['x-envoi-workspace'] = [self::WS_B];
        $this->assertSame(401, $this->envoyer(new Requete($r->methode, $r->chemin, $r->corps, $e))->statut);
        $this->assertSame([], $this->kumo->appels);
    }

    public function test_cle_de_a_avec_workspace_b_refuse(): void
    {
        $this->assertSame(401, $this->envoyer($this->commande('pause', ws: self::WS_B, cle: self::CLE_A))->statut);
    }

    public function test_workspace_inconnu_ou_cle_vide_refuse(): void
    {
        $inconnu = '99999999-9999-4999-8999-999999999999';
        $this->assertSame(401, $this->envoyer($this->commande('pause', ws: $inconnu, cle: 'x'))->statut);

        $this->passerelle = new Passerelle($this->stockage, $this->kumo, [self::WS_A => ''], fn () => $this->maintenant);
        $this->assertSame(401, $this->envoyer($this->commande('pause', cle: ''))->statut);
    }

    public function test_entete_de_signature_en_double_refuse(): void
    {
        $r = $this->commande('pause');
        $e = $r->entetes;
        $e['x-envoi-signature'][] = $e['x-envoi-signature'][0];
        $this->assertSame(401, $this->envoyer(new Requete($r->methode, $r->chemin, $r->corps, $e))->statut);
    }

    public function test_formats_de_signature_acceptes_comme_k1(): void
    {
        foreach (['k=1,sha256=%s', 'sha256=%s', '%s'] as $forme) {
            $r = $this->commande('pause', id: self::uuid());
            [, $hex] = Signature::analyser($r->entetes['x-envoi-signature'][0]);
            $e = $r->entetes;
            $e['x-envoi-signature'] = [sprintf($forme, preg_replace('/^sha256=/', '', $hex))];
            $this->assertSame(200, $this->envoyer(new Requete($r->methode, $r->chemin, $r->corps, $e))->statut, $forme);
        }
        // Forme incohérente : refusée.
        $r = $this->commande('pause', id: self::uuid());
        $e = $r->entetes;
        $e['x-envoi-signature'] = ['k=1;sha256=zz'];
        $this->assertSame(401, $this->envoyer(new Requete($r->methode, $r->chemin, $r->corps, $e))->statut);
    }

    public function test_methode_et_chemin_sont_signes(): void
    {
        // Même corps et même charge sauf le chemin : une signature faite pour /pause ne vaut pas pour /retrait.
        $r = $this->commande('pause');
        $this->assertSame(401, $this->envoyer(new Requete('POST', '/v1/commandes/retrait', $r->corps, $r->entetes))->statut);
        $this->assertSame([], $this->kumo->appels);
    }

    public function test_get_signe_sur_la_chaine_de_requete_exacte(): void
    {
        $r = $this->signer('GET', '/v1/evenements?curseur=&depuis=2026-01-01T00%3A00%3A00%2B00%3A00&limite=100', '');
        $this->assertSame(200, $this->envoyer($r)->statut);
        // Requête modifiée après signature (même chemin) : refusée.
        $r = $this->signer('GET', '/v1/evenements?curseur=0&limite=100', '');
        $this->assertSame(401, $this->envoyer(new Requete('GET', '/v1/evenements?curseur=0&limite=500', '', $r->entetes))->statut);
    }

    public function test_signature_dans_l_url_refusee(): void
    {
        $this->assertSame(400, $this->envoyer($this->signer('GET', '/v1/evenements?curseur=0&signature=abc', ''))->statut);
    }

    public function test_reponse_ne_fuit_rien(): void
    {
        $rep = $this->envoyer($this->commande('pause', cle: 'mauvaise'));
        $this->assertSame('{"erreur":"non_autorise"}', $rep->corps);
        $this->assertArrayNotHasKey('server', array_change_key_case($rep->entetes));
    }

    public function test_commande_interdite_et_routes_inconnues(): void
    {
        $this->assertSame(403, $this->envoyer($this->commande('eteindre_temoins', commande: 'pause'))->statut);
        $this->assertSame(404, $this->envoyer($this->signer('GET', '/api/admin/x', ''))->statut);
        $this->assertSame(404, $this->envoyer($this->signer('POST', '/v1/commandes/../x', '{}'))->statut);
        $this->assertSame(405, $this->envoyer($this->signer('DELETE', '/v1/evenements', ''))->statut);
        $this->assertSame([], $this->kumo->appels);
    }

    public function test_reponse_signee_comme_k1_la_lit(): void
    {
        $nonce = self::uuid();
        $rep = $this->envoyer($this->signer('GET', '/v1/evenements?curseur=&depuis=&limite=10', '', nonce: $nonce));
        $this->assertSame(200, $rep->statut);
        [$id, $hex] = Signature::analyser($rep->entetes['X-Envoi-Signature']);
        $this->assertSame('1', $id, 'k=<id>,sha256=<hex>');
        $hex = preg_replace('/^sha256=/', '', $hex);
        $this->assertSame(Signature::signer(self::CLE_A, "REPONSE\n$nonce\n".self::WS_A."\n200\n".$rep->corps), $hex);
        $this->assertSame(self::WS_A, json_decode($rep->corps, true)['workspace_id'], 'workspace_id dans le JSON (K1 le contrôle)');
    }

    public function test_vecteurs_communs_k1_octet_pour_octet(): void
    {
        $k1 = __DIR__.'/contrat/vecteurs-hmac-k1.json';
        // Copie du fichier de K1 (envoi-serveur/contrat/vecteurs-hmac.json) : figée par son empreinte.
        $this->assertSame('c082c428c22b1ad13ad108d7683f98c37970a1d338c320857a9e3210ff21233a', hash_file('sha256', $k1));
        // Dès que K1 est fusionné, le fichier partagé du dépôt doit être IDENTIQUE à la copie.
        $partage = __DIR__.'/../../../../envoi-serveur/contrat/vecteurs-hmac.json';
        if (is_file($partage)) {
            $this->assertSame(hash_file('sha256', $k1), hash_file('sha256', $partage), 'la copie dérive du fichier de K1');
        }
    }

    public function test_vecteurs_communs_hmac(): void
    {
        $v = json_decode((string) file_get_contents(__DIR__.'/contrat/vecteurs-hmac-k1.json'), true, 8, JSON_THROW_ON_ERROR);
        $this->assertNotEmpty($v['requetes']);
        foreach ($v['requetes'] as $x) {
            $hex = Signature::signer($v['secret'], Signature::chargeRequete($x['methode'], $x['chemin'], $x['horodatage'], $x['nonce'], $v['workspace_id'], $x['corps']));
            $this->assertSame($x['signature'], $hex, $x['libelle']);
            $this->assertSame($x['entete'], Signature::entete('1', $hex), $x['libelle']);
        }
        foreach ($v['reponses'] as $x) {
            $this->assertSame($x['signature'], Signature::signer($v['secret'], Signature::chargeReponse($x['nonce'], $x['workspace_id'], $x['statut'], $x['corps'])), $x['libelle']);
        }
    }

    public function test_vecteur_k1_accepte_de_bout_en_bout(): void
    {
        // La requête du vecteur K1 « commande_pause » est acceptée telle quelle (horloge calée sur son horodatage).
        $v = json_decode((string) file_get_contents(__DIR__.'/contrat/vecteurs-hmac-k1.json'), true, 8, JSON_THROW_ON_ERROR);
        $x = $v['requetes'][0];
        $this->maintenant = (int) $x['horodatage'];
        $this->passerelle = new Passerelle($this->stockage, $this->kumo, [$v['workspace_id'] => $v['secret']], fn () => $this->maintenant);
        $r = new Requete($x['methode'], $x['chemin'], $x['corps'], [
            'x-envoi-workspace' => [$v['workspace_id']], 'x-envoi-timestamp' => [$x['horodatage']],
            'x-envoi-nonce' => [$x['nonce']], 'x-envoi-signature' => [$x['entete']],
        ]);
        $this->assertSame(200, $this->envoyer($r)->statut);
    }

    public function test_vecteurs_propres_a_la_passerelle(): void
    {
        $v = json_decode((string) file_get_contents(__DIR__.'/contrat/vecteurs-hmac-passerelle.json'), true, 8, JSON_THROW_ON_ERROR);
        $this->assertNotEmpty($v['vecteurs']);
        foreach ($v['vecteurs'] as $x) {
            $this->assertSame($x['signature'], Signature::signer($x['cle'], Signature::chargeRequete($x['methode'], $x['chemin'], $x['horodatage'], $x['nonce'], $x['workspace_id'], $x['corps'])), $x['libelle']);
        }
    }
}
