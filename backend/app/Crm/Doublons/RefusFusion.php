<?php

namespace App\Crm\Doublons;

use RuntimeException;

/**
 * Une fusion (ou son annulation) REFUSÉE : rien n'a été écrit. `$raison` est
 * un code stable (compteurs, API) ; le message ne cite jamais une donnée de
 * fiche (journaux des workflows : dépôt public).
 */
final class RefusFusion extends RuntimeException
{
    public const MESSAGES = [
        'fiche_introuvable' => "L'une des deux fiches n'existe pas dans cet espace.",
        'fiche_a_la_corbeille' => "L'une des deux fiches est déjà à la corbeille.",
        'meme_fiche' => 'Une fiche ne se fusionne pas avec elle-même.',
        'sirens_differents' => 'Les deux fiches portent deux SIREN différents : ce sont deux entités juridiques, jamais fusionnées.',
        'deja_traitee' => 'Cette paire a déjà été traitée (fusionnée ou écartée).',
        'paire_inconnue' => 'Cette paire ne correspond pas à la file de vérification.',
        'preuve_insuffisante' => "La preuve n'est plus certaine sur les données du moment : la paire reste dans la file de vérification.",
        'preuve_ambigue' => 'La preuve désigne plusieurs fiches à SIREN : impossible de choisir seul, la paire reste dans la file de vérification.',
        'homonyme_supprime_sur_la_fiche_gardee' => 'Une personne de la fiche absorbée a un homonyme SUPPRIMÉ sur la fiche gardée : elle disparaîtrait de la vue. À régler à la main.',
        'deux_federations' => 'Les deux fiches sont chacune une fédération : à rapprocher à la main.',
        'lien_de_reseau_entre_les_deux' => "L'une des deux fiches est la tête de réseau de l'autre : ce ne sont pas des doublons.",
        'personnes_homonymes_en_conflit' => 'Deux personnes homonymes, une sur chaque fiche, ont des coordonnées différentes : à régler à la main d\'abord.',
        'protection_perdue' => "La fiche gardée n'hériterait pas de la protection de la fiche absorbée.",
        'erreur_base' => 'La base a refusé une écriture : rien n\'a été fait.',
        'presse_verification_humaine' => 'Une fiche de presse ne se fusionne jamais automatiquement : la paire reste dans la file de vérification.',
        'journaliste_homonyme_sur_la_fiche_gardee' => 'Un journaliste de la fiche absorbée a un homonyme (hors presse) sur la fiche gardée : ses coordonnées y seraient recopiées sans la marque presse. À régler à la main d\'abord.',
        'fusion_introuvable' => 'Fusion introuvable dans cet espace.',
        'deja_annulee' => 'Cette fusion est déjà annulée.',
        'absorbee_modifiee' => "La fiche absorbée n'est plus dans l'état laissé par la fusion (sortie de la corbeille ?) : annulation refusée.",
        'gardee_introuvable' => "La fiche gardée n'existe plus : annulation impossible.",
    ];

    public function __construct(public readonly string $raison, ?string $detail = null)
    {
        parent::__construct((self::MESSAGES[$raison] ?? $raison) . ($detail !== null ? " ({$detail})" : ''));
    }
}
