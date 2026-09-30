<?php

namespace App\Crm\Relations;

use App\Crm\Ingest\SiteSyncClassifier;
use App\Crm\Taxonomy;

/**
 * LA RÈGLE DE PROMOTION du statut de relation — UNE pour tous les
 * automatismes : le canal site (`SiteSyncClassifier::mergeRelationType`),
 * `crm:relations:importer`, et la presse (#264, qui lit
 * `RelationsProspection::HORS_PROSPECTION`).
 *
 * ── Le type de relation (`relation_type`) ───────────────────────────────────
 *
 * On ne rétrograde JAMAIS, selon l'ordre UNIQUE
 * `Taxonomy::BUSINESS_RELATION_PRIORITY` :
 *
 *     client > investisseur > partenaire > presse_media > fournisseur
 *            > conference > prospect > newsletter
 *
 * Trois règles, gardées par des tests :
 *
 *  1. `client` l'emporte toujours ;
 *  2. une promotion ne fait JAMAIS sortir une fiche de
 *     `RelationsProspection::HORS_PROSPECTION` vers un type prospectable : tous
 *     les types hors prospection sont au-dessus des autres dans l'ordre, et la
 *     règle est AUSSI écrite en toutes lettres ici — un ordre réécrit un jour
 *     ne la ferait pas tomber en silence ;
 *  3. un automatisme ne pose JAMAIS un type réservé à la saisie manuelle
 *     (`Taxonomy::BUSINESS_RELATION_TYPES_SAISIE_MANUELLE`, B13-008 :
 *     `fournisseur`). La demande est refusée, comptée, et la fiche garde son
 *     type.
 *
 * ── L'étape (`lifecycle_stage`) ─────────────────────────────────────────────
 *
 * `SiteSyncClassifier::mergeLifecycleStage` : nouveau < qualifie < opportunite
 * < client ; `perdu` est terminal pour tout automatisme ; `dormant` se
 * réveille. Une fiche qui devient `client` (type) passe au moins à l'étape
 * `client` ; une ligne qui annonce l'étape `client` fait de la fiche une
 * `client` (type) — sous réserve des règles de confiance de l'appelant.
 *
 * L'import ne pose ni `dormant` ni `perdu` : ce sont des constats humains.
 *
 * ── Posée à la main ─────────────────────────────────────────────────────────
 *
 * Une fiche dont la relation a été posée à la main (`relation_saisie_manuelle_at`)
 * n'est modifiée par AUCUN automatisme — ni le type ni l'étape. La règle est
 * appliquée par chaque appelant (import, canal site).
 */
final class PromotionRelation
{
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
        // Règle 3 : jamais un type réservé à la saisie manuelle.
        if (in_array($demandee, Taxonomy::BUSINESS_RELATION_TYPES_SAISIE_MANUELLE, true)) {
            return $actuelle;
        }
        // Règle 2 : on ne sort jamais de la liste hors prospection.
        if (in_array($actuelle, RelationsProspection::HORS_PROSPECTION, true)
            && ! in_array($demandee, RelationsProspection::HORS_PROSPECTION, true)) {
            return $actuelle;
        }
        $ordre = Taxonomy::BUSINESS_RELATION_PRIORITY;
        $rangActuel = array_search($actuelle, $ordre, true);
        $rangDemande = array_search($demandee, $ordre, true);
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
     * Le type retenu quand la demande vient d'une source DÉCLARATIVE (un
     * formulaire public du site, un avis, une ligne d'import non confirmée)
     * et vise une fiche EXISTANTE : jamais un type hors prospection.
     *
     * Veto de la relecture sécurité de #265 : un formulaire anonyme
     * « partenariat » qui porte le SIREN public d'une fiche la sortait de
     * toute prospection, sans retour. Le déclaratif ne peut promouvoir que
     * vers un type PROSPECTABLE (`conference`, par un formulaire
     * d'intervenant) ; le reste passe par une source de confiance ou par la
     * saisie manuelle.
     */
    public static function relationDeclarative(string $actuelle, ?string $demandee): string
    {
        if ($demandee !== null && in_array($demandee, RelationsProspection::HORS_PROSPECTION, true)) {
            return $actuelle;
        }

        return self::relation($actuelle, $demandee);
    }

    /**
     * L'étape demandée par une source DÉCLARATIVE sur une fiche existante :
     * jamais `client` — un avis ou un formulaire public ne fait pas une
     * cliente. Plafonnée à `qualifie` (la personne s'est manifestée).
     */
    public static function etapeDeclarative(?string $demandee): ?string
    {
        return $demandee === 'client' ? 'qualifie' : $demandee;
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
        $a = Taxonomy::BUSINESS_RELATION_PRIORITY;
        $b = Taxonomy::BUSINESS_RELATION_TYPES;
        sort($a);
        sort($b);

        return $a === $b;
    }
}
