<?php

/**
 * GARDE DU CONSTAT X39-024 (S0) — la valeur d'un critère d'audience doit
 * survivre à la validation de la requête de création.
 *
 * ── CE QUI S'EST PASSÉ ──────────────────────────────────────────────────────
 *
 * `StoreEmailAudienceRequest` déclarait `criteria.*.field` et `criteria.*.op`,
 * pour les trois blocs — et JAMAIS `criteria.*.value`. Or
 * `$request->validated()` ne rend que les clés couvertes par une règle : la
 * valeur de chaque condition était donc **retirée en silence** du tableau que
 * `store()` persiste, alors que le client l'avait bien envoyée.
 *
 * Mesuré le 2026-08-23 sur l'écran, pas au `curl` :
 *   l'écran envoie  {"field":"sector_main","op":"in","value":["it_saas"]}
 *   le serveur rend {"op":"in","field":"sector_main"}
 *
 * ── POURQUOI C'EST UN S0 ────────────────────────────────────────────────────
 *
 * Ce service décide À QUI PART UN COURRIEL, et le mode de défaillance dépend de
 * l'opérateur, ce qui le rend invisible à l'œil :
 *
 *   `in`  → l'audience ne vise PERSONNE       (l'aperçu annonçait 2 entreprises)
 *   `neq` → elle vise TOUT L'ESPACE DE TRAVAIL (l'aperçu annonçait 3)
 *   `eq`  → 1 membre, MAIS LA MAUVAISE FICHE   (l'aperçu annonçait 1, la bonne)
 *
 * Le troisième est le pire : le compte est juste, la fiche est fausse.
 *
 * Et l'aperçu, lui, était JUSTE — `AudiencesController::preview()` lit
 * `$request->input('criteria')`, l'entrée brute. Le chiffre que l'utilisateur
 * regarde pour décider d'envoyer n'était pas celui qu'il obtenait.
 *
 * ── CE QUE CETTE GARDE VÉRIFIE ──────────────────────────────────────────────
 *
 * Elle fait passer une charge utile par les VRAIES règles de la requête, puis
 * relit `validated()`. Elle ne lit aucun fichier et ne compte aucune ligne :
 * elle mesure ce que le validateur RESTITUE.
 */

use App\Http\Requests\StoreEmailAudienceRequest;
use Illuminate\Support\Facades\Validator;

/** Fait passer $charge par les vraies règles de la requête et rend `validated()`. */
function passerParLesReglesDeCreation(array $charge): array
{
    $validateur = Validator::make($charge, (new StoreEmailAudienceRequest)->rules());

    expect($validateur->fails())->toBeFalse(
        'La charge utile est refusee alors qu elle est bien formee : '
        . json_encode($validateur->errors()->toArray(), JSON_UNESCAPED_UNICODE),
    );

    return $validateur->validated();
}

/** Combien de conditions du bloc `all` ont GARDÉ leur clé `value` ? */
function valeursConservees(array $valide): int
{
    $n = 0;
    foreach ($valide['criteria']['all'] ?? [] as $condition) {
        if (array_key_exists('value', $condition)) {
            $n++;
        }
    }

    return $n;
}

test('X39-024 — la valeur d un critere survit a la validation, en tableau ET en scalaire', function () {
    // Exactement ce que `AudienceBuilderPage.tsx:128-134` envoie : `in` porte un
    // TABLEAU, `gte` porte un SCALAIRE. Les deux formes doivent survivre.
    $valide = passerParLesReglesDeCreation([
        'name' => 'Garde X39-024',
        'criteria' => ['all' => [
            ['field' => 'sector_main', 'op' => 'in', 'value' => ['it_saas']],
            ['field' => 'quality_score', 'op' => 'gte', 'value' => 40],
        ]],
    ]);

    expect(valeursConservees($valide))->toBe(
        2,
        "La validation EFFACE la valeur des criteres d audience.\n\n"
        . '`validated()` ne rend que les cles couvertes par une regle : sans '
        . '`criteria.*.value` declaree dans StoreEmailAudienceRequest, chaque condition '
        . 'perd sa valeur en silence, et l audience enregistree n est PAS celle que l '
        . "apercu a montree a l utilisateur.\n\n"
        . 'Selon l operateur : `in` ne vise personne, `neq` vise tout l espace de travail, '
        . "`eq` vise les mauvaises fiches avec un compte qui a l air juste.\n\n"
        . 'Ce service decide a qui part un courriel. Correctif : reposer les trois lignes '
        . '`criteria.{all,any,not}.*.value => [sometimes]`.',
    );

    // Et la valeur doit être IDENTIQUE, pas seulement présente.
    expect($valide['criteria']['all'][0]['value'])->toBe(['it_saas']);
    expect($valide['criteria']['all'][1]['value'])->toBe(40);
});

test('X39-024 — les blocs `any` et `not` sont couverts eux aussi', function () {
    // Les trois blocs portaient le meme defaut. En garder deux sur trois
    // laisserait la porte ouverte sur les audiences baties par exclusion.
    $valide = passerParLesReglesDeCreation([
        'name' => 'Garde X39-024 bis',
        'criteria' => [
            'any' => [['field' => 'department_code', 'op' => 'in', 'value' => ['75', '92']]],
            'not' => [['field' => 'prospection_status', 'op' => 'eq', 'value' => 'blacklisted']],
        ],
    ]);

    expect($valide['criteria']['any'][0]['value'] ?? null)->toBe(
        ['75', '92'],
        'Le bloc `any` perd la valeur de ses criteres.',
    );
    expect($valide['criteria']['not'][0]['value'] ?? null)->toBe(
        'blacklisted',
        'Le bloc `not` perd la valeur de ses criteres — une audience batie par EXCLUSION '
        . 'devient alors une audience qui n exclut rien.',
    );
});

test('X39-024 — TEMOIN NEGATIF : la garde SAIT voir la valeur disparaitre', function () {
    // Sans ce temoin, la garde pourrait passer verte en ne mesurant rien.
    // On rejoue les regles D ORIGINE, celles qui ne declarent pas `value`.
    $reglesDOrigine = array_filter(
        (new StoreEmailAudienceRequest)->rules(),
        fn (string $cle) => ! str_ends_with($cle, '.value'),
        ARRAY_FILTER_USE_KEY,
    );

    $validateur = Validator::make([
        'name' => 'Temoin',
        'criteria' => ['all' => [['field' => 'sector_main', 'op' => 'in', 'value' => ['it_saas']]]],
    ], $reglesDOrigine);

    expect($validateur->fails())->toBeFalse();

    $condition = $validateur->validated()['criteria']['all'][0];

    // C'est le coeur du constat : la charge utile est PARFAITEMENT bien formee,
    // la validation REUSSIT, et la valeur a pourtant disparu.
    expect(array_key_exists('value', $condition))->toBeFalse(
        'Le temoin ne reproduit plus le defaut : soit `validated()` a change de '
        . 'comportement, soit la regle `value` a fui dans le jeu filtre. Dans les deux '
        . 'cas la garde ne prouve plus rien et doit etre revue.',
    );
});
