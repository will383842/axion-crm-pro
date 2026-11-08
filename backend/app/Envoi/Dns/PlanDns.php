<?php

namespace App\Envoi\Dns;

/**
 * Plan DNS d'un domaine d'envoi. Ne produit que des MODÈLES : l'adresse, la clé
 * DKIM et la boîte de rapports sont des jetons à remplir hors dépôt.
 */
final class PlanDns
{
    /**
     * Les 20 points de contrôle, dans l'ordre d'exécution de ControleurDns.
     *
     * @return list<string>
     */
    public static function codesPointsDeControle(): array
    {
        return [
            'spf_present', 'spf_unique', 'spf_se_termine_par_all', 'spf_10_resolutions_max', 'spf_autorise_ip',
            'dkim_present', 'dkim_cle_publique', 'dkim_cle_2048',
            'dmarc_present', 'dmarc_unique', 'dmarc_politique', 'dmarc_rua',
            'helo_est_un_nom', 'ptr_existe', 'ptr_egal_helo', 'helo_a_vers_ip',
            'mta_sts_enregistrement', 'mta_sts_politique', 'mta_sts_mode_testing',
            'tls_rpt',
        ];
    }

    /** @return list<EnregistrementDns> */
    public static function enregistrements(ConfigurationDomaine $c): array
    {
        return [
            new EnregistrementDns('A', $c->helo, '{IP_ENVOI}', 'nom du HELO'),
            new EnregistrementDns('PTR', '{IP_ENVOI}', $c->helo . '.', 'PTR = HELO = A'),
            new EnregistrementDns('TXT', $c->domaine, 'v=spf1 ip4:{IP_ENVOI} -all', 'SPF'),
            new EnregistrementDns('TXT', $c->selecteur . '._domainkey.' . $c->domaine, 'v=DKIM1; k=rsa; p={CLE_PUBLIQUE_DKIM}', 'DKIM'),
            new EnregistrementDns('TXT', '_dmarc.' . $c->domaine, 'v=DMARC1; p=none; rua=mailto:{BOITE_RAPPORTS_DMARC}', 'DMARC'),
            new EnregistrementDns('TXT', '_mta-sts.' . $c->domaine, 'v=STSv1; id={IDENTIFIANT_POLITIQUE}', 'MTA-STS'),
            new EnregistrementDns('TXT', '_smtp._tls.' . $c->domaine, 'v=TLSRPTv1; rua=mailto:{BOITE_RAPPORTS_TLS}', 'TLS-RPT'),
        ];
    }

    /**
     * Découpe une valeur TXT en chaînes de 255 octets au plus (limite d'une chaîne DNS) :
     * indispensable pour une clé DKIM RSA 2048 (~392 caractères). La concaténation redonne la valeur.
     *
     * @return list<string>
     */
    public static function decouperTxt(string $valeur, int $max = 255): array
    {
        if ($valeur === '') {
            return [''];
        }

        return str_split($valeur, max(1, min($max, 255)));
    }

    /**
     * Chaînes TXT à publier pour une ligne du plan, jetons remplacés (ex. la vraie clé, fournie hors dépôt).
     *
     * @param  array<string, string>  $jetons
     * @return list<string>
     */
    public static function chainesTxt(EnregistrementDns $e, array $jetons = []): array
    {
        return self::decouperTxt(strtr($e->valeurModele, $jetons));
    }

    /** Fichier de politique MTA-STS : toujours en mode testing au départ. */
    public static function politiqueMtaSts(ConfigurationDomaine $c): string
    {
        return "version: STSv1\nmode: testing\nmx: {$c->helo}\nmax_age: 86400\n";
    }
}
