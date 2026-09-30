<?php

namespace App\Crm\Relations;

use App\Crm\Ingest\SiteSyncClassifier;
use App\Crm\Taxonomy;

/**
 * LA RÈGLE DE PROMOTION du statut de relation — écrite une fois, lue par
 * `crm:relations:importer` (chantier B, 2026-10-01).
 *
 * ── Le type de relation (`relation_type`) ───────────────────────────────────
 *
 * On ne rétrograde JAMAIS. Ordre (du plus engageant au moins engageant) :
 *
 *     client > investisseur > partenaire > presse_media > conference
 *            > fournisseur > prospect > newsletter
 *
 * C'est l'ordre canonique `Taxonomy::BUSINESS_RELATION_PRIORITY`, à UNE
 * différence près, assumée : `prospect` y passe du 2ᵉ rang à l'avant-dernier.
 * Dans le canal site → CRM, `prospect` est un type DÉCLARÉ (la personne a
 * rempli un formulaire) ; ici il est la valeur PAR DÉFAUT des 4,3 M de fiches
 * collectées, qui ne dit rien. Laisser `prospect` au 2ᵉ rang rendrait l'import
 * inopérant : aucune fiche ne deviendrait jamais partenaire. `newsletter` reste
 * sous `prospect`, comme dans l'ordre canonique : une inscription à la lettre
 * ne fait pas perdre un statut de prospect.
 *
 * `client` l'emporte donc toujours.
 *
 * ── L'étape (`lifecycle_stage`) ─────────────────────────────────────────────
 *
 * `SiteSyncClassifier::mergeLifecycleStage` — la règle du canal site, reprise
 * telle quelle : nouveau < qualifie < opportunite < client ; `perdu` est
 * terminal pour tout automatisme ; `dormant` se réveille. Une fiche qui
 * devient `client` (type) passe au moins à l'étape `client` ; une ligne qui
 * annonce l'étape `client` fait de la fiche une `client` (type).
 *
 * L'import ne pose ni `dormant` ni `perdu` : ce sont des constats humains, pas
 * des promotions (ligne rejetée, motif `etape_non_importable`).
 *
 * ── Posée à la main ─────────────────────────────────────────────────────────
 *
 * Une fiche dont la relation a été posée à la main (`relation_saisie_manuelle_at`)
 * n'est JAMAIS modifiée par l'import — ni le type ni l'étape.
 */
final class PromotionRelation
{
    /** @var list<string> du plus engageant au moins engageant */
    public const ORDRE_RELATION = [
        'client',
        'investisseur',
        'partenaire',
        'presse_media',
        'conference',
        'fournisseur',
        'prospect',
        'newsletter',
    ];

    /** @var list<string> étapes qu'un import peut poser */
    public const ETAPES_IMPORTABLES = ['nouveau', 'qualifie', 'opportunite', 'client'];

    /**
     * Le type retenu entre l'actuel et le demandé.
     */
    public static function relation(string $actuelle, ?string $demandee): string
    {
        if ($demandee === null || $demandee === $actuelle) {
            return $actuelle;
        }
        $rangActuel = array_search($actuelle, self::ORDRE_RELATION, true);
        $rangDemande = array_search($demandee, self::ORDRE_RELATION, true);
        if ($rangDemande === false) {
            return $actuelle;
        }
        if ($rangActuel === false) {
            // Valeur hors vocabulaire (impossible sous le CHECK) : on la remplace.
            return $demandee;
        }

        return $rangDemande < $rangActuel ? $demandee : $actuelle;
    }

    /**
     * L'étape retenue, connaissant le type retenu.
     */
    public static function etape(string $actuelle, ?string $demandee, string $relationRetenue): string
    {
        $classeur = new SiteSyncClassifier;
        $etape = $demandee === null ? $actuelle : $classeur->mergeLifecycleStage($actuelle, $demandee);
        if ($relationRetenue === 'client') {
            $etape = $classeur->mergeLifecycleStage($etape, 'client');
        }

        return $etape;
    }

    /**
     * @return array{relation_type: string, lifecycle_stage: string}
     */
    public static function appliquer(string $relation, string $etape, ?string $relationDemandee, ?string $etapeDemandee): array
    {
        // « client » l'emporte dans les DEUX sens : une ligne qui dit « étape
        // client » décrit un client, quel que soit le type qu'elle annonce.
        if ($etapeDemandee === 'client') {
            $relationDemandee = 'client';
        }
        $relationRetenue = self::relation($relation, $relationDemandee);

        return [
            'relation_type' => $relationRetenue,
            'lifecycle_stage' => self::etape($etape, $etapeDemandee, $relationRetenue),
        ];
    }

    /** L'ordre couvre-t-il EXACTEMENT le vocabulaire fermé ? (garde de test) */
    public static function ordreCompletPour(): bool
    {
        $a = self::ORDRE_RELATION;
        $b = Taxonomy::BUSINESS_RELATION_TYPES;
        sort($a);
        sort($b);

        return $a === $b;
    }
}
