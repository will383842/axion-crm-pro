<?php

/**
 * CHANTIERS B et C — les règles pures : promotion du statut de relation,
 * lecture d'une ligne d'import, nature déduite, région d'une fiche française.
 *
 * Fixtures FICTIVES (dépôt public) : domaines `.example` / `.invalid`.
 */

use App\Crm\Joignabilite\Joignabilite;
use App\Crm\Referentiels\Classement;
use App\Crm\Relations\LigneRelation;
use App\Crm\Relations\PromotionRelation;
use App\Crm\Relations\RelationsProspection;
use App\Crm\Taxonomy;

// ── Promotion : on ne rétrograde jamais ─────────────────────────────────────

test('l ordre de promotion couvre EXACTEMENT le vocabulaire ferme des relations', function () {
    expect(PromotionRelation::ordreCompletPour())->toBeTrue()
        ->and(count(Taxonomy::BUSINESS_RELATION_PRIORITY))->toBe(count(Taxonomy::BUSINESS_RELATION_TYPES));
});

test('R1 — aucune promotion ne fait sortir une fiche de HORS_PROSPECTION vers un type prospectable', function () {
    $sorties = [];
    foreach (RelationsProspection::HORS_PROSPECTION as $hors) {
        foreach (Taxonomy::BUSINESS_RELATION_TYPES as $demande) {
            $retenu = PromotionRelation::relation($hors, $demande);
            if (! in_array($retenu, RelationsProspection::HORS_PROSPECTION, true)) {
                $sorties[] = "{$hors} -> {$demande} = {$retenu}";
            }
        }
    }
    // Et l'ordre le dit aussi : chaque type hors prospection est AU-DESSUS de
    // chaque type prospectable.
    $rang = array_flip(Taxonomy::BUSINESS_RELATION_PRIORITY);
    $maxHors = max(array_map(static fn (string $t): int => $rang[$t], RelationsProspection::HORS_PROSPECTION));
    $minAutres = min(array_map(static fn (string $t): int => $rang[$t], array_diff(Taxonomy::BUSINESS_RELATION_TYPES, RelationsProspection::HORS_PROSPECTION)));

    expect($sorties)->toBe([])
        ->and(PromotionRelation::relation('fournisseur', 'conference'))->toBe('fournisseur')
        ->and($maxHors)->toBeLessThan($minAutres);
});

test('B13-008 — un automatisme ne pose JAMAIS un type reserve a la saisie manuelle', function () {
    foreach (Taxonomy::BUSINESS_RELATION_TYPES_SAISIE_MANUELLE as $manuel) {
        foreach (array_diff(Taxonomy::BUSINESS_RELATION_TYPES, [$manuel]) as $actuel) {
            expect(PromotionRelation::relation($actuel, $manuel))->toBe($actuel);
        }
    }
});

test('client l emporte sur tout, et rien ne fait redescendre un client', function () {
    foreach (Taxonomy::BUSINESS_RELATION_TYPES as $type) {
        expect(PromotionRelation::relation($type, 'client'))->toBe('client')
            ->and(PromotionRelation::relation('client', $type))->toBe('client');
    }
});

test('prospect (la valeur par defaut) est remplace par un type engageant, jamais par newsletter', function () {
    expect(PromotionRelation::relation('prospect', 'partenaire'))->toBe('partenaire')
        ->and(PromotionRelation::relation('prospect', 'presse_media'))->toBe('presse_media')
        ->and(PromotionRelation::relation('prospect', 'conference'))->toBe('conference')
        ->and(PromotionRelation::relation('prospect', 'newsletter'))->toBe('prospect')
        ->and(PromotionRelation::relation('newsletter', 'prospect'))->toBe('prospect')
        // Pas de recul entre deux types établis.
        ->and(PromotionRelation::relation('investisseur', 'partenaire'))->toBe('investisseur')
        ->and(PromotionRelation::relation('partenaire', 'investisseur'))->toBe('investisseur')
        ->and(PromotionRelation::relation('partenaire', 'prospect'))->toBe('partenaire')
        ->and(PromotionRelation::relation('partenaire', null))->toBe('partenaire');
});

test('l etape ne recule jamais, perdu reste perdu, un client passe a l etape client', function () {
    expect(PromotionRelation::appliquer('prospect', 'opportunite', null, 'qualifie'))
        ->toBe(['relation_type' => 'prospect', 'lifecycle_stage' => 'opportunite'])
        ->and(PromotionRelation::appliquer('prospect', 'nouveau', null, 'opportunite'))
        ->toBe(['relation_type' => 'prospect', 'lifecycle_stage' => 'opportunite'])
        ->and(PromotionRelation::appliquer('prospect', 'nouveau', 'client', null))
        ->toBe(['relation_type' => 'client', 'lifecycle_stage' => 'client'])
        // L'étape « client » fait de la fiche une cliente.
        ->and(PromotionRelation::appliquer('partenaire', 'qualifie', 'prospect', 'client'))
        ->toBe(['relation_type' => 'client', 'lifecycle_stage' => 'client'])
        ->and(PromotionRelation::appliquer('prospect', 'perdu', 'client', null))
        ->toBe(['relation_type' => 'client', 'lifecycle_stage' => 'perdu'])
        ->and(PromotionRelation::appliquer('prospect', 'dormant', null, 'qualifie'))
        ->toBe(['relation_type' => 'prospect', 'lifecycle_stage' => 'qualifie']);
});

// ── Ligne d'import ──────────────────────────────────────────────────────────

test('une ligne valide est lue, SIREN nettoye, e-mail normalise', function () {
    $l = LigneRelation::lire(3, '{"source":"site-client","siren":"123 456 789","denomination":"ZZ","email":"Achat@ZZ-Societe.example","relation_type":"client","lifecycle_stage":null}');
    expect($l)->toBeInstanceOf(LigneRelation::class);
    assert($l instanceof LigneRelation);
    expect($l->numero)->toBe(3)
        ->and($l->siren)->toBe('123456789')
        ->and($l->email)->toBe('achat@zz-societe.example')
        ->and($l->relationType)->toBe('client')
        ->and($l->lifecycleStage)->toBeNull()
        ->and($l->domaine())->toBe('zz-societe.example');
});

test('chaque ligne fautive est rejetee avec SON motif', function (string $brut, string $motif) {
    expect(LigneRelation::lire(1, $brut))->toBe($motif);
})->with([
    'json' => ['{pas du json', 'json_invalide'],
    'liste' => ['["a"]', 'pas_un_objet'],
    'faute de frappe' => ['{"source":"site-client","siren":"123456789","relation":"client"}', 'cle_inconnue'],
    'source absente' => ['{"siren":"123456789","relation_type":"client"}', 'source_invalide'],
    'source majuscule' => ['{"source":"Site","siren":"123456789","relation_type":"client"}', 'source_invalide'],
    'siren court' => ['{"source":"site-client","siren":"12345","relation_type":"client"}', 'siren_invalide'],
    'relation inconnue' => ['{"source":"site-client","siren":"123456789","relation_type":"vip"}', 'relation_inconnue'],
    'etape perdu' => ['{"source":"site-client","siren":"123456789","lifecycle_stage":"perdu"}', 'etape_non_importable'],
    'etape inconnue' => ['{"source":"site-client","siren":"123456789","lifecycle_stage":"chaud"}', 'etape_inconnue'],
    'ni siren ni email' => ['{"source":"site-client","siren":null,"email":null,"relation_type":"client"}', 'ni_siren_ni_email'],
    'rien a poser' => ['{"source":"site-client","siren":"123456789"}', 'rien_a_poser'],
    'fournisseur (saisie manuelle)' => ['{"source":"site-client","siren":"123456789","relation_type":"fournisseur"}', 'relation_saisie_manuelle'],
]);

// ── Défaut de prospection ───────────────────────────────────────────────────

test('la prospection exclut par defaut clients, partenaires, presse, fournisseurs, investisseurs — pas les prospects', function () {
    expect(RelationsProspection::HORS_PROSPECTION)->toBe(['client', 'partenaire', 'presse_media', 'fournisseur', 'investisseur'])
        ->and(array_diff(RelationsProspection::HORS_PROSPECTION, Taxonomy::BUSINESS_RELATION_TYPES))->toBe([])
        ->and(RelationsProspection::conditionExclusion())
        ->toBe(['field' => 'relation_type', 'op' => 'in', 'value' => RelationsProspection::HORS_PROSPECTION]);
});

// ── Nature déduite : rien d'inventé ─────────────────────────────────────────

test('la nature se deduit de la forme juridique, puis du NAF, puis du SIREN seul', function () {
    expect(Classement::natureDeduite('9220', null, null, '123456789'))->toBe(['association', 'forme_juridique'])
        ->and(Classement::natureDeduite('5710', '94.12Z', null, null))->toBe(['entreprise', 'forme_juridique'])
        ->and(Classement::natureDeduite('1000', null, null, null))->toBe(['entreprise', 'forme_juridique'])
        ->and(Classement::natureDeduite(null, null, '94.12Z', '123456789'))->toBe(['federation', 'naf'])
        ->and(Classement::natureDeduite(null, '9420Z', null, null))->toBe(['federation', 'naf'])
        ->and(Classement::natureDeduite(null, null, '94.99Z', null))->toBe(['association', 'naf'])
        ->and(Classement::natureDeduite(null, null, '84.11Z', null))->toBe(['institution', 'naf'])
        ->and(Classement::natureDeduite(null, null, '25.62B', '123456789'))->toBe(['entreprise', 'siren'])
        ->and(Classement::natureDeduite(null, null, null, '123456789'))->toBe(['entreprise', 'siren']);
});

test('sans donnee qui tranche, la nature reste VIDE', function () {
    // Rien du tout.
    expect(Classement::natureDeduite(null, null, null, null))->toBe([null, null])
        // Un SIREN, mais une catégorie juridique de droit public : pas « entreprise ».
        ->and(Classement::natureDeduite('7389', null, null, '123456789'))->toBe([null, null])
        // Un SIREN, mais un code NAF d'organisation non tranché (99 : extraterritorial).
        ->and(Classement::natureDeduite(null, null, '99.00Z', '123456789'))->toBe([null, null])
        // Un SIREN mal formé.
        ->and(Classement::natureDeduite(null, null, null, '12345'))->toBe([null, null]);
});

// ── Région d'une fiche française ────────────────────────────────────────────

test('la region vient du departement, sinon du code postal, sinon de rien', function () {
    expect(Classement::regionFrancaise('38', null))->toBe(['84', 'departement'])
        ->and(Classement::regionFrancaise(null, '38000'))->toBe(['84', 'code_postal'])
        ->and(Classement::regionFrancaise(null, '20090'))->toBe(['94', 'code_postal'])
        ->and(Classement::regionFrancaise(null, '20200'))->toBe(['94', 'code_postal'])
        ->and(Classement::regionFrancaise(null, '97400'))->toBe(['04', 'code_postal'])
        // Saint-Pierre-et-Miquelon : collectivité sans région.
        ->and(Classement::regionFrancaise(null, '97500'))->toBe([null, null])
        ->and(Classement::regionFrancaise(null, '3800'))->toBe([null, null])
        ->and(Classement::regionFrancaise(null, null))->toBe([null, null])
        ->and(Classement::departementDuCodePostal('20100'))->toBe('2A')
        ->and(Classement::departementDuCodePostal('20600'))->toBe('2B');
});

// ── Joignabilité : l'agrégation ─────────────────────────────────────────────

test('une entreprise est joignable par sa MEILLEURE adresse, sinon par le telephone', function () {
    expect(Joignabilite::etatEntreprise([Joignabilite::EMAIL_INVALIDE, Joignabilite::EMAIL_VALIDE], false))->toBe(Joignabilite::EMAIL_VALIDE)
        ->and(Joignabilite::etatEntreprise([Joignabilite::EMAIL_INTERDIT, Joignabilite::EMAIL_INVALIDE], true))->toBe(Joignabilite::EMAIL_INVALIDE)
        ->and(Joignabilite::etatEntreprise([Joignabilite::EMAIL_INVALIDE, Joignabilite::EMAIL_NON_VERIFIE], false))->toBe(Joignabilite::EMAIL_NON_VERIFIE)
        ->and(Joignabilite::etatEntreprise([Joignabilite::EMAIL_PERSONNEL, Joignabilite::EMAIL_PARTAGE], false))->toBe(Joignabilite::EMAIL_PARTAGE)
        ->and(Joignabilite::etatEntreprise([null, null], true))->toBe(Joignabilite::SANS_EMAIL_AVEC_TELEPHONE)
        ->and(Joignabilite::etatEntreprise([null], false))->toBe(Joignabilite::SANS_CONTACT);
});

test('R3 — la decision par adresse, dans l ordre de l envoi (opposition d abord)', function () {
    $valide = ['statut' => null, 'verification' => 'valide', 'perso' => false];
    $a = 'zz@zz-societe.example';
    expect(Joignabilite::decider($a, [$valide], false, false))->toBe(Joignabilite::EMAIL_VALIDE)
        // Opposée ET invalide : l'opposition se lit telle quelle.
        ->and(Joignabilite::decider($a, [['statut' => 'invalid', 'verification' => 'invalide', 'perso' => false]], true, false))->toBe(Joignabilite::EMAIL_INTERDIT)
        // UNE occurrence condamnée condamne l'adresse, même vérifiée ailleurs.
        ->and(Joignabilite::decider($a, [$valide, ['statut' => 'invalid', 'verification' => null, 'perso' => false]], false, false))->toBe(Joignabilite::EMAIL_INVALIDE)
        ->and(Joignabilite::decider($a, [$valide, ['statut' => null, 'verification' => 'jetable', 'perso' => false]], false, false))->toBe(Joignabilite::EMAIL_INVALIDE)
        // `valid` posé par un autre outil : non vérifiée au sens de la campagne.
        ->and(Joignabilite::decider($a, [['statut' => 'valid', 'verification' => null, 'perso' => false]], false, false))->toBe(Joignabilite::EMAIL_NON_VERIFIE)
        // La syntaxe de la campagne (`FILTER_VALIDATE_EMAIL`).
        ->and(Joignabilite::decider('pas-une-adresse', [$valide], false, false))->toBe(Joignabilite::EMAIL_INVALIDE)
        // Messagerie grand public ou adresse marquée personnelle.
        ->and(Joignabilite::decider('zz-fictif@yahoo.zz', [$valide], false, false))->toBe(Joignabilite::EMAIL_PERSONNEL)
        ->and(Joignabilite::decider($a, [$valide, ['statut' => null, 'verification' => null, 'perso' => true]], false, false))->toBe(Joignabilite::EMAIL_PERSONNEL)
        // Adresse partagée (cabinet, domiciliation) : exclue par défaut.
        ->and(Joignabilite::decider($a, [$valide], false, true))->toBe(Joignabilite::EMAIL_PARTAGE);
});
