<?php

namespace App\Envoi\FeuVert;

/**
 * Catalogue VERSIONNÉ des 18 points manuels du feu vert (spécification 26 § 8).
 * Les codes sont stables et permanents : on n'en renomme ni n'en supprime jamais ; un ajout incrémente VERSION.
 */
final class CatalogueManuels
{
    public const VERSION = 1;

    public const PREMIER = 'm8_01_port25';

    /** @var array<string, string> code => libellé */
    public const POINTS = [
        'm8_01_port25' => 'Port 25 ouvert par écrit par l’hébergeur et usage déclaré accepté',
        'm8_02_ptr_fcrdns' => 'PTR réel, PTR = HELO = A',
        'm8_03_dns_publies' => 'Publication DNS réelle SPF, DKIM, DMARC, MTA-STS, TLS-RPT et propagation',
        'm8_04_arrivee_boite' => 'Arrivée en boîte de réception chez les 6 témoins, 3 jours de suite',
        'm8_05_bouton_desabonner' => 'Bouton Se désabonner de Gmail et POST RFC 8058 d’une vraie messagerie',
        'm8_06_rapports_dmarc' => 'Rapports DMARC et TLS-RPT réels',
        'm8_07_listes_noires_reelles' => 'Absence réelle des listes noires, IP neuve',
        'm8_08_textes_rebonds' => 'Textes réels des rebonds Microsoft, Orange, OVH… pour ajuster la table des motifs',
        'm8_09_hebergeur_boites' => 'Comportement réel de l’hébergeur des boîtes : boîte fourre-tout sur sous-domaine, IMAP IDLE, limites de connexions',
        'm8_10_antispam_hebergeur' => 'Anti-spam de l’hébergeur du serveur, coupure possible en pleine chauffe',
        'm8_11_contre_pression' => 'Contre-pression sous la vraie mémoire et le vrai disque',
        'm8_12_certificats_tls' => 'Certificat TLS réel de mta-sts.<domaine> et go.<domaine>',
        'm8_13_inscriptions' => 'Inscriptions SNDS, JMRP, CFL, Postmaster',
        'm8_14_delais_rebonds' => 'Délais réels des rebonds asynchrones et des plaintes',
        'm8_15_relais_tiers' => 'Relais tiers si activé : conditions d’utilisation, désactivation du suivi, alignement DKIM',
        'm8_16_sonde_externe' => 'Aucun relais ouvert, aucun port public autre que WireGuard',
        'm8_17_capacite_reponses' => 'Capacité réelle du responsable à traiter les réponses, fixe le plafond de volume',
        'm8_18_homme_mort' => 'Homme mort et Tout arrêter prouvés en production',
    ];

    /** @return list<string> */
    public static function codes(): array
    {
        return array_keys(self::POINTS);
    }
}
