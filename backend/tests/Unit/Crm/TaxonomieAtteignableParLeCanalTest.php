<?php

declare(strict_types=1);

/**
 * =============================================================================
 * GARDE B13-008 — la liste des types de relation BUSINESS et ce que le canal
 * site → CRM sait réellement produire ne doivent jamais diverger en silence.
 * =============================================================================
 *
 * Pourquoi cette garde existe.
 *
 * Mesure du 2026-08-22 : `Taxonomy::BUSINESS_RELATION_TYPES` déclarait huit
 * valeurs, dont `fournisseur` (Taxonomy.php:44) — repris dans l'ordre de
 * priorité (:60) — alors que `SiteSyncClassifier::relationType()` n'a aucune
 * branche qui le rende : ses deux `default` retombent sur `prospect`. La valeur
 * était donc INATTEIGNABLE par le canal, sans que rien ne le dise. Une liste
 * qui annonce plus que ce que le code sait faire est une promesse silencieuse :
 * on la rend explicite, et on la garde.
 *
 * La garde tient les deux bouts, pour ne pas certifier ce qu'elle n'inspecte
 * pas :
 *   1. ce qui est déclaré « saisie manuelle » est vraiment hors de portée du
 *      canal (sinon la note de `Taxonomy` ment) ;
 *   2. TOUT le reste est vraiment atteignable (sinon un nouveau type est entré
 *      dans la liste sans branche ni note — le défaut d'origine, à nouveau) ;
 *   3. le classifieur ne rend jamais une valeur hors de la liste canonique
 *      (c'est le CHECK SQL qui la porte : une valeur inconnue serait rejetée en
 *      base, pas ici).
 *
 * AUCUNE valeur n'est écrite à la main : l'espace des événements est parcouru
 * depuis `SiteSyncEvent::EVENT_TYPES` × `SiteSyncEvent::FORM_TYPES`, et les
 * attendus depuis les constantes de `Taxonomy`. Une valeur ajoutée d'un côté
 * sans l'autre fait rougir — c'est tout l'objet.
 *
 * Test unitaire : ni base ni migration. `SiteSyncClassifier` est une classe
 * pure et `SiteSyncEvent::fromArray()` ne fait que valider son entrée.
 */

use App\Crm\Ingest\SiteSyncClassifier;
use App\Crm\Ingest\SiteSyncEvent;
use App\Crm\Taxonomy;

/**
 * Un événement minimal VALIDE pour le couple demandé, ou `null` si le contrat
 * d'entrée interdit la combinaison (`form_submission` exige un `form_type`).
 */
function evenementDuCanal(string $eventType, ?string $formType): ?SiteSyncEvent
{
    if ($eventType === 'form_submission' && $formType === null) {
        return null;
    }

    return SiteSyncEvent::fromArray([
        'schema_version' => 1,
        'event_id' => 'b13-008-' . $eventType . '-' . ($formType ?? 'nul'),
        'event_type' => $eventType,
        'form_type' => $formType,
        'occurred_at' => '2026-08-22T09:00:00+02:00',
        'subject_ref' => 'site:submission:b13-008',
        'person' => [
            'person_key' => hash('sha256', 'b13-008@example.test'),
            'email' => 'b13-008@example.test',
        ],
    ]);
}

/**
 * Tous les types de relation que le canal sait poser dans l'univers BUSINESS.
 *
 * Les événements de l'univers VIVIER sont écartés : leur `relationType()` n'est
 * jamais celui d'une fiche entreprise, le compter ici rendrait la garde plus
 * laxiste qu'elle ne doit l'être.
 *
 * @return list<string>
 */
function typesDeRelationAtteignablesParLeCanal(): array
{
    $classifieur = new SiteSyncClassifier;
    $atteints = [];

    $formTypes = array_merge([null], SiteSyncEvent::FORM_TYPES);

    foreach (SiteSyncEvent::EVENT_TYPES as $eventType) {
        foreach ($formTypes as $formType) {
            $evenement = evenementDuCanal($eventType, $formType);
            if ($evenement === null || $classifieur->universe($evenement) !== 'business') {
                continue;
            }

            $atteints[] = $classifieur->relationType($evenement);
        }
    }

    return array_values(array_unique($atteints));
}

test('B13-008 — le parcours du canal produit vraiment des types (témoin)', function () {
    // Témoin : sans lui, une erreur de construction d'événement viderait la
    // liste et les deux gardes suivantes seraient satisfaites pour rien.
    $atteints = typesDeRelationAtteignablesParLeCanal();

    expect(count($atteints) > 1)->toBeTrue(
        'B13-008 : le parcours des événements ne produit plus qu\'un type (ou aucun) — '
        . 'les trois gardes suivantes seraient donc vertes SANS RIEN MESURER. Geste : '
        . 'vérifie `evenementDuCanal()` ci-dessus, le contrat `SiteSyncEvent::fromArray()` '
        . 'a probablement changé et rejette les événements fabriqués ici. Types lus : « '
        . implode(' · ', $atteints) . ' ».'
    );
});

test('B13-008 — les types déclarés « saisie manuelle » sont bien hors de portée du canal', function () {
    $atteints = typesDeRelationAtteignablesParLeCanal();

    foreach (Taxonomy::BUSINESS_RELATION_TYPES_SAISIE_MANUELLE as $type) {
        expect(in_array($type, $atteints, true))->toBeFalse(
            "B13-008 : le type « {$type} » est déclaré réservé à la saisie manuelle dans "
            . '`Taxonomy::BUSINESS_RELATION_TYPES_SAISIE_MANUELLE`, mais `SiteSyncClassifier::relationType()` '
            . 'le rend désormais pour un événement du site. La note du docbloc est devenue '
            . "fausse. Geste : retire « {$type} » de BUSINESS_RELATION_TYPES_SAISIE_MANUELLE "
            . 'et mets à jour son docbloc — ou supprime la branche du classifieur si elle '
            . 'a été ajoutée par accident.'
        );
    }
});

test('B13-008 — tout autre type BUSINESS est réellement atteignable par un événement du site', function () {
    $atteints = typesDeRelationAtteignablesParLeCanal();

    $attendus = array_values(array_diff(
        Taxonomy::BUSINESS_RELATION_TYPES,
        Taxonomy::BUSINESS_RELATION_TYPES_SAISIE_MANUELLE,
    ));

    $orphelins = array_values(array_diff($attendus, $atteints));

    // `toBe($attendu, $message)` n'existe pas : on assertionne un booléen pour
    // que le message reste un message (piège Pest déjà payé sur `toContain`).
    expect($orphelins === [])->toBeTrue(sprintf(
        'B13-008 : %d type(s) de relation BUSINESS ne sont produits par AUCUN événement du '
        . "site — « %s ». C'est exactement le défaut d'origine : la liste canonique promet "
        . 'un type que le canal ne sait pas poser. Geste : soit ajoute la branche dans '
        . '`SiteSyncClassifier::relationType()`, soit déclare la valeur dans '
        . '`Taxonomy::BUSINESS_RELATION_TYPES_SAISIE_MANUELLE` avec la raison, comme '
        . '`fournisseur`.',
        count($orphelins),
        implode(' · ', $orphelins),
    ));
});

test('B13-008 — le classifieur ne rend jamais un type hors de la liste canonique', function () {
    $atteints = typesDeRelationAtteignablesParLeCanal();

    $inconnus = array_values(array_diff($atteints, Taxonomy::BUSINESS_RELATION_TYPES));

    expect($inconnus === [])->toBeTrue(sprintf(
        'B13-008 : `SiteSyncClassifier::relationType()` rend %d valeur(s) absente(s) de '
        . '`Taxonomy::BUSINESS_RELATION_TYPES` — « %s ». Le CHECK SQL de `companies.relation_type` '
        . "les rejettera à l'insertion : le lead sera perdu en production, pas ici. Geste : "
        . 'ajoute la valeur à la taxonomie (migration du CHECK comprise) ou corrige la branche.',
        count($inconnus),
        implode(' · ', $inconnus),
    ));
});
