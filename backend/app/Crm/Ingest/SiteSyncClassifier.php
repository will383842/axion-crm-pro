<?php

namespace App\Crm\Ingest;

use App\Crm\Taxonomy;

/**
 * CLASSEMENT AUTOMATIQUE À LA SOURCE.
 *
 * Le classement d'une fiche n'est pas laissé à l'appelant : le site décrit ce
 * qui s'est PASSÉ (un formulaire d'audit a été soumis), le CRM en déduit ce que
 * la fiche DEVIENT (type de relation, étape de cycle de vie, base légale, tags
 * de provenance). Deux raisons :
 *
 *  1. la taxonomie est une décision du CRM — si le site pouvait envoyer
 *     `relation_type: client`, il suffirait d'un bug de formulaire pour qu'un
 *     inconnu devienne client ;
 *  2. les règles restent au même endroit que les CHECK SQL qui les portent
 *     (`App\Crm\Taxonomy`), donc vérifiables par un test.
 *
 * Deux règles d'or, directement issues du plan §2.2b et de l'audit
 * d'harmonisation §B.2 :
 *  - **on ne recule jamais** : un client qui remplit un formulaire de contact
 *    reste client, une opportunité ne redevient pas « nouveau » ;
 *  - **le froid ne se réchauffe pas tout seul** : seule une interaction
 *    ENTRANTE fait monter le cycle de vie. Aucun batch, aucun enrichissement
 *    réussi ne le fait — c'est précisément ce que l'ingestion doit garantir,
 *    puisqu'elle est le point d'entrée des interactions entrantes.
 */
final class SiteSyncClassifier
{
    /**
     * Univers VIVIER : recrutement uniquement. Un scrapé n'est jamais un
     * candidat, un candidat n'entre jamais dans la base commerciale.
     */
    public function universe(SiteSyncEvent $event): string
    {
        if ($event->eventType === 'application_submitted') {
            return 'vivier';
        }

        if ($event->eventType === 'form_submission' && $event->formType === 'recrutement') {
            return 'vivier';
        }

        return 'business';
    }

    /**
     * Type de relation VISÉ par l'événement (avant arbitrage avec l'existant).
     */
    public function relationType(SiteSyncEvent $event): string
    {
        return match ($event->eventType) {
            'newsletter_optin', 'newsletter_optout' => 'newsletter',
            // L'auteur d'un avis est un client réel (il le certifie dans le
            // formulaire d'avis) : c'est le seul événement entrant du site qui
            // porte cette qualité par lui-même.
            'review_posted' => 'client',
            'form_submission' => match ($event->formType) {
                'partenariat' => 'partenaire',
                'presse' => 'presse_media',
                'investisseur' => 'investisseur',
                'speaker' => 'conference',
                default => 'prospect',
            },
            default => 'prospect',
        };
    }

    /**
     * Étape de cycle de vie VISÉE. Une demande concrète (audit, devis,
     * implémentation, formation, un-à-un) ou un RDV pris valent « opportunité »
     * — c'est la règle du plan §2.2b, pas une appréciation.
     */
    public function lifecycleStage(SiteSyncEvent $event): string
    {
        return match ($event->eventType) {
            'review_posted' => 'client',
            'calendly_booked', 'calendly_completed' => 'opportunite',
            'form_submission' => in_array($event->formType, ['audit', 'devis', 'implementation', 'formation', 'un_a_un'], true)
                ? 'opportunite'
                : 'qualifie',
            // Un RDV annulé / non honoré, une inscription newsletter : la
            // personne s'est manifestée, rien de plus.
            default => 'qualifie',
        };
    }

    /**
     * Base légale. La newsletter est la seule catégorie où l'envoi dépend d'un
     * consentement ; tout le reste est précontractuel (la personne a demandé à
     * être recontactée), ce qui est PLUS protecteur que l'intérêt légitime des
     * fiches collectées — d'où la bascule décrite par l'audit §B.4.5.
     */
    public function legalBasis(SiteSyncEvent $event): string
    {
        return match ($event->eventType) {
            'newsletter_optin', 'newsletter_optout' => 'consent',
            default => 'precontractual',
        };
    }

    /**
     * Vocabulaire FERMÉ de la timeline (`activities.kind`).
     *
     * Les types d'événement du contrat d'entrée sont, par construction, un
     * sous-ensemble de `Taxonomy::ACTIVITY_KINDS` : ils portent le même nom.
     * Le `default` n'est donc pas décoratif — il est le garde-fou du jour où
     * quelqu'un ajoutera un type d'événement sans l'ajouter au vocabulaire de
     * la timeline (le CHECK SQL refuserait la ligne avec une QueryException
     * illisible ; ici le refus est explicite).
     */
    public function activityKind(SiteSyncEvent $event): string
    {
        if (! in_array($event->eventType, Taxonomy::ACTIVITY_KINDS, true)) {
            throw SiteSyncRejection::invalid(
                'unknown_activity_kind',
                "Type de timeline hors vocabulaire fermé : « {$event->eventType} ».",
            );
        }

        return $event->eventType;
    }

    /**
     * Tags de PROVENANCE (`src:`) + service d'intérêt (`svc:`), auxquels
     * s'ajoutent les tags explicitement envoyés par le site. Multi-valués : une
     * fiche déjà collectée garde ses `src:scraping-*` et gagne son `src:site-*`
     * — l'historique des provenances se cumule, il ne s'écrase pas.
     *
     * @return list<string>
     */
    public function tags(SiteSyncEvent $event): array
    {
        $tags = [];

        $source = $event->sourceSlug;
        if ($source !== null) {
            $tags[] = 'src:' . $source;
        } else {
            $tags[] = match ($event->eventType) {
                'form_submission' => 'src:site-formulaire-' . str_replace('_', '-', (string) $event->formType),
                'calendly_booked', 'calendly_completed', 'calendly_canceled', 'calendly_no_show' => 'src:calendly',
                'newsletter_optin', 'newsletter_optout' => 'src:newsletter',
                'review_posted' => 'src:avis-client',
                default => 'src:site-formulaire-autre',
            };
        }

        $service = match ($event->formType) {
            'audit' => 'svc:audit',
            'formation' => 'svc:formation',
            'implementation' => 'svc:implementation',
            'speaker' => 'svc:conference',
            default => null,
        };
        if ($service !== null) {
            $tags[] = $service;
        }

        $offer = $event->str('candidate', 'offer_slug');
        if ($offer !== null) {
            $tags[] = 'cand-offre:' . $offer;
        }

        foreach ($event->tags as $tag) {
            $tags[] = $tag;
        }

        return array_values(array_unique($tags));
    }

    /**
     * Arbitrage avec l'existant : on garde le type le PLUS ENGAGEANT déjà
     * atteint (ordre de priorité du plan §2.2a).
     */
    public function mergeRelationType(?string $current, string $incoming): string
    {
        if ($current === null || $current === '') {
            return $incoming;
        }

        $priority = Taxonomy::BUSINESS_RELATION_PRIORITY;
        $rankCurrent = array_search($current, $priority, true);
        $rankIncoming = array_search($incoming, $priority, true);

        if ($rankCurrent === false) {
            return $incoming;
        }
        if ($rankIncoming === false) {
            return $current;
        }

        return $rankIncoming < $rankCurrent ? $incoming : $current;
    }

    /**
     * Arbitrage du cycle de vie : on ne recule jamais.
     *
     *  - `perdu` est TERMINAL pour l'automatisme : une opposition gèle la fiche,
     *    seule une action humaine journalisée peut la rouvrir ;
     *  - `dormant` (12 mois sans interaction) se réveille dès la première
     *    interaction entrante — c'est exactement ce qu'est un événement de
     *    synchro.
     */
    public function mergeLifecycleStage(?string $current, string $incoming): string
    {
        if ($current === null || $current === '') {
            return $incoming;
        }
        if ($current === 'perdu') {
            return 'perdu';
        }

        $order = ['nouveau' => 0, 'qualifie' => 1, 'opportunite' => 2, 'client' => 3];

        if ($current === 'dormant') {
            return $incoming === 'nouveau' ? 'qualifie' : $incoming;
        }

        $rankCurrent = $order[$current] ?? 0;
        $rankIncoming = $order[$incoming] ?? 0;

        return $rankIncoming > $rankCurrent ? $incoming : $current;
    }

    /**
     * Base légale : `precontractual` et `consent` (la personne s'est
     * manifestée) l'emportent sur `legitimate_interest_b2b` (fiche collectée).
     * On ne redescend jamais vers une base moins protectrice.
     */
    public function mergeLegalBasis(?string $current, string $incoming): string
    {
        if ($current === null || $current === '' || $current === 'legitimate_interest_b2b') {
            return $incoming;
        }
        if (in_array($current, ['contract', 'legal_obligation'], true)) {
            return $current;
        }

        return $current === 'consent' && $incoming === 'precontractual' ? 'consent' : $incoming;
    }
}
