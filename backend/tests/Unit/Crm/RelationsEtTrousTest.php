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
        ->and(count(PromotionRelation::ORDRE_RELATION))->toBe(count(Taxonomy::BUSINESS_RELATION_TYPES));
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
        ->and(PromotionRelation::relation('prospect', 'fournisseur'))->toBe('fournisseur')
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
        ->and(Joignabilite::etatEntreprise([null, null], true))->toBe(Joignabilite::SANS_EMAIL_AVEC_TELEPHONE)
        ->and(Joignabilite::etatEntreprise([null], false))->toBe(Joignabilite::SANS_CONTACT);
});

test('une adresse a la syntaxe fausse ou au statut invalid est invalide, jamais valide', function () {
    expect(Joignabilite::etatAdresse('pas-une-adresse', null, null, []))->toBe(Joignabilite::EMAIL_INVALIDE)
        ->and(Joignabilite::etatAdresse('zz@zz-societe.example', 'invalid', null, []))->toBe(Joignabilite::EMAIL_INVALIDE)
        ->and(Joignabilite::etatAdresse('zz@zz-societe.example', 'disposable', null, []))->toBe(Joignabilite::EMAIL_INVALIDE)
        // Statut `valid` posé par un AUTRE outil : non vérifiée au sens de la campagne.
        ->and(Joignabilite::etatAdresse('zz@zz-societe.example', 'valid', null, []))->toBe(Joignabilite::EMAIL_NON_VERIFIE)
        ->and(Joignabilite::etatAdresse(null, null, null, []))->toBeNull()
        ->and(Joignabilite::etatAdresse('  ', null, null, []))->toBeNull();
});
