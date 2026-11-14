<?php

namespace App\Envoi\FeuVert;

/**
 * Catalogue VERSIONNÉ UNIQUE des points du feu vert (ADR E4 : la nature se lit dans le préfixe du code).
 *  - `auto.<code>`   : point CALCULÉ (lu dans le système, jamais écrit, jamais prouvé à la main) ;
 *  - `manuel.<code>` : preuve humaine (spécification 26 § 8, puis cases A4 à A12 du plan R07), déposée via DepotPreuves.
 * Les codes sont stables et permanents : on n'en renomme ni n'en supprime jamais (seule exception, v3 : `manuel.inscriptions` est éclaté puis retiré) ; un ajout incrémente VERSION
 * (v2 : F15 ajoute `auto.temoins_3_jours` ; v3 : R07 ajoute les cases A4 à A12 et éclate `manuel.inscriptions`).
 * Signal Spam (A11) est « non bloquant » dans le plan, mais le contrat impose `bloquant` = non `fait` : par prudence il bloque (noté R07).
 * `serveur_reel` = le point n'est vérifiable qu'avec le vrai serveur (K17).
 */
final class CatalogueManuels
{
    public const VERSION = 3;

    public const PREFIXE_AUTO = 'auto.';

    public const PREFIXE_MANUEL = 'manuel.';

    public const PREMIER = 'manuel.port25';

    public const GROUPES = ['verrous', 'banc', 'dns', 'serveur', 'temoins', 'conformite', 'exploitation'];

    /** @var array<string, array{libelle: string, groupe: string, source: string, serveur_reel: bool}> */
    public const POINTS = [
        'auto.reel_actif_faux' => ['libelle' => 'Envoi réel éteint', 'groupe' => 'verrous', 'source' => 'config envoi.reel_actif', 'serveur_reel' => false],
        'auto.mode_temoins_vrai' => ['libelle' => 'Mode témoins actif', 'groupe' => 'verrous', 'source' => 'table envoi_drapeaux.mode_temoins', 'serveur_reel' => false],
        'auto.signature_absente' => ['libelle' => 'Aucune signature de feu vert', 'groupe' => 'verrous', 'source' => 'table envoi_drapeaux.feu_vert_signature', 'serveur_reel' => false],
        'auto.temoins_fournisseurs' => ['libelle' => 'Tous les fournisseurs témoins sont couverts', 'groupe' => 'temoins', 'source' => 'table envoi_temoins.fournisseur (fiche F03)', 'serveur_reel' => false],
        'auto.temoins_3_jours' => ['libelle' => 'Les 6 témoins sont verts 3 jours de suite (réception, authentification, bouton, POST)', 'groupe' => 'temoins', 'source' => 'F15 : table envoi_temoins_journees', 'serveur_reel' => false],
        'auto.arret_entreprise' => ['libelle' => 'Arrêt par entreprise branché', 'groupe' => 'verrous', 'source' => 'conteneur ArretParEntreprise', 'serveur_reel' => false],
        'auto.retrait_file' => ['libelle' => 'Retrait des envois en file branché', 'groupe' => 'verrous', 'source' => 'conteneur RetraitEnvoisEnFile', 'serveur_reel' => false],
        'auto.cle_desinscription' => ['libelle' => 'Clé de désinscription posée (32 caractères au moins)', 'groupe' => 'verrous', 'source' => 'config envoi.cle_desinscription (valeur jamais lue ici)', 'serveur_reel' => false],
        'auto.hotes_desinscription' => ['libelle' => 'Hôtes de désinscription posés et valides', 'groupe' => 'verrous', 'source' => 'config envoi.desinscription_hote(s) (valeurs jamais lues ici)', 'serveur_reel' => false],
        'auto.releve_imap' => ['libelle' => 'Relève IMAP planifiée', 'groupe' => 'exploitation', 'source' => 'config envoi.releve_actif', 'serveur_reel' => false],
        'auto.domaines_prets' => ['libelle' => 'Domaines prêts (K12)', 'groupe' => 'dns', 'source' => 'table envoi_controles_dns', 'serveur_reel' => false],
        'auto.listes_noires' => ['libelle' => 'Hors listes noires (K13)', 'groupe' => 'dns', 'source' => 'table envoi_controles_listes', 'serveur_reel' => false],
        'auto.supervision_k15' => ['libelle' => 'Supervision K15 active', 'groupe' => 'exploitation', 'source' => 'config envoi.sante.actif', 'serveur_reel' => false],
        'auto.frein_temoins_branche' => ['libelle' => 'Frein « témoins » branché : relève des indésirables liée et planifiée', 'groupe' => 'temoins', 'source' => '30 A4', 'serveur_reel' => false],
        'auto.frein_reponses_baisse' => ['libelle' => 'Frein « réponses en baisse » : évaluation planifiée de reponsesEnBaisse', 'groupe' => 'exploitation', 'source' => '30 A4', 'serveur_reel' => false],
        'auto.separation_lettre_prospection' => ['libelle' => 'Aucun domaine ni adresse IP partagé entre la lettre et la prospection', 'groupe' => 'dns', 'source' => '30 A12', 'serveur_reel' => false],
        'manuel.port25' => ['libelle' => 'Port 25 ouvert par écrit par l’hébergeur et usage déclaré accepté', 'groupe' => 'serveur', 'source' => '26§8.1', 'serveur_reel' => true],
        'manuel.ptr_fcrdns' => ['libelle' => 'PTR réel, PTR = HELO = A', 'groupe' => 'dns', 'source' => '26§8.2', 'serveur_reel' => true],
        'manuel.dns_publies' => ['libelle' => 'Publication DNS réelle SPF, DKIM, DMARC, MTA-STS, TLS-RPT et propagation', 'groupe' => 'dns', 'source' => '26§8.3', 'serveur_reel' => true],
        'manuel.arrivee_boite' => ['libelle' => 'Arrivée en boîte de réception chez les 6 témoins, 3 jours de suite', 'groupe' => 'temoins', 'source' => '26§8.4', 'serveur_reel' => true],
        'manuel.bouton_desabonner' => ['libelle' => 'Bouton Se désabonner de Gmail et POST RFC 8058 d’une vraie messagerie', 'groupe' => 'conformite', 'source' => '26§8.5', 'serveur_reel' => true],
        'manuel.rapports_dmarc' => ['libelle' => 'Rapports DMARC et TLS-RPT réels', 'groupe' => 'dns', 'source' => '26§8.6', 'serveur_reel' => true],
        'manuel.listes_noires_reelles' => ['libelle' => 'Absence réelle des listes noires, IP neuve', 'groupe' => 'dns', 'source' => '26§8.7', 'serveur_reel' => true],
        'manuel.textes_rebonds' => ['libelle' => 'Textes réels des rebonds Microsoft, Orange, OVH… pour ajuster la table des motifs', 'groupe' => 'banc', 'source' => '26§8.8', 'serveur_reel' => true],
        'manuel.hebergeur_boites' => ['libelle' => 'Comportement réel de l’hébergeur des boîtes : boîte fourre-tout sur sous-domaine, IMAP IDLE, limites de connexions', 'groupe' => 'serveur', 'source' => '26§8.9', 'serveur_reel' => true],
        'manuel.antispam_hebergeur' => ['libelle' => 'Anti-spam de l’hébergeur du serveur, coupure possible en pleine chauffe', 'groupe' => 'serveur', 'source' => '26§8.10', 'serveur_reel' => true],
        'manuel.contre_pression' => ['libelle' => 'Contre-pression sous la vraie mémoire et le vrai disque', 'groupe' => 'banc', 'source' => '26§8.11', 'serveur_reel' => true],
        'manuel.certificats_tls' => ['libelle' => 'Certificat TLS réel de mta-sts.<domaine> et go.<domaine>', 'groupe' => 'dns', 'source' => '26§8.12', 'serveur_reel' => true],
        'manuel.delais_rebonds' => ['libelle' => 'Délais réels des rebonds asynchrones et des plaintes', 'groupe' => 'banc', 'source' => '26§8.14', 'serveur_reel' => true],
        'manuel.relais_tiers' => ['libelle' => 'Relais tiers si activé : conditions d’utilisation, désactivation du suivi, alignement DKIM', 'groupe' => 'conformite', 'source' => '26§8.15', 'serveur_reel' => true],
        'manuel.sonde_externe' => ['libelle' => 'Aucun relais ouvert, aucun port public autre que WireGuard', 'groupe' => 'serveur', 'source' => '26§8.16', 'serveur_reel' => true],
        'manuel.capacite_reponses' => ['libelle' => 'Capacité réelle du responsable à traiter les réponses, fixe le plafond de volume', 'groupe' => 'exploitation', 'source' => '26§8.17', 'serveur_reel' => false],
        'manuel.homme_mort' => ['libelle' => 'Homme mort et Tout arrêter prouvés en production', 'groupe' => 'exploitation', 'source' => '26§8.18', 'serveur_reel' => true],
        'manuel.seuils_a4_banc' => ['libelle' => 'Seuils des freins confirmés en banc', 'groupe' => 'banc', 'source' => '30 A4', 'serveur_reel' => false],
        'manuel.desinscription_get_sans_effet' => ['libelle' => 'Désinscription : un GET seul est sans effet (scénario S22 vert, lien du passage CI en preuve)', 'groupe' => 'conformite', 'source' => '30 A5', 'serveur_reel' => false],
        'manuel.anti_doublon_redemarrage' => ['libelle' => 'Anti-doublon au redémarrage (scénario S21 vert)', 'groupe' => 'exploitation', 'source' => '30 A6', 'serveur_reel' => false],
        'manuel.runbook_serveur_perdu' => ['libelle' => 'Procédure « serveur perdu » écrite et répétée', 'groupe' => 'serveur', 'source' => '30 A7', 'serveur_reel' => false],
        'manuel.dkim_secours_publie' => ['libelle' => 'Clé DKIM de secours publiée dans le DNS', 'groupe' => 'dns', 'source' => '30 A7', 'serveur_reel' => true],
        'manuel.rattrapage_oppositions' => ['libelle' => 'Rattrapage des oppositions après restauration (test de rejeu)', 'groupe' => 'conformite', 'source' => '30 A8', 'serveur_reel' => false],
        'manuel.message_compose_conforme' => ['libelle' => 'Message composé conforme (scénario S02 étendu)', 'groupe' => 'conformite', 'source' => '30 A9', 'serveur_reel' => false],
        'manuel.postmaster_google' => ['libelle' => 'Inscription Google Postmaster Tools', 'groupe' => 'conformite', 'source' => '30 A11', 'serveur_reel' => true],
        'manuel.snds_jmrp' => ['libelle' => 'Inscription SNDS et JMRP (Microsoft)', 'groupe' => 'conformite', 'source' => '30 A11', 'serveur_reel' => true],
        'manuel.orange_cfl' => ['libelle' => 'Inscription Orange (boucle de plaintes CFL)', 'groupe' => 'conformite', 'source' => '30 A11', 'serveur_reel' => true],
        'manuel.signal_spam' => ['libelle' => 'Inscription Signal Spam (si elle existe)', 'groupe' => 'conformite', 'source' => '30 A11', 'serveur_reel' => true],
    ];

    /** @return list<string> tous les codes (auto.* puis manuel.*) */
    public static function codes(): array
    {
        return array_keys(self::POINTS);
    }

    /** @return list<string> */
    public static function codesManuels(): array
    {
        return array_values(array_filter(self::codes(), static fn (string $c): bool => self::estManuel($c)));
    }

    /** Vrai pour un code `manuel.*` PRÉSENT au catalogue : seul cas où une preuve est acceptable. */
    public static function estManuel(string $code): bool
    {
        return str_starts_with($code, self::PREFIXE_MANUEL) && array_key_exists($code, self::POINTS);
    }
}
