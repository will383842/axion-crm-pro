<?php

/**
 * GARDE : L'OPPOSITION « NON DIFFUSIBLE » DE L'INSEE VAUT SUR LES DEUX VOIES
 * DE COLLECTE — constat `C19-010` (S1).
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * LE DEFAUT, ET IL EST DE LA FAMILLE LA PLUS COUTEUSE DU DEPOT : LE CORRECTIF
 * EXISTE DEJA, IL N'A PAS ETE PORTE.
 * ═══════════════════════════════════════════════════════════════════════════
 *
 * `HttpInseeClient` a TROIS points d'entree vers l'API Sirene v3.11, et un
 * seul filtrait l'opposition a diffusion :
 *
 *   1. `iterateByCriteria()` avec filtre geographique  → endpoint `/siret`
 *      → ligne 152 : `if (($u['statutDiffusionUniteLegale'] ?? 'O') !== 'O') continue;`
 *      ✅ FILTRE PRESENT depuis l'origine.
 *
 *   2. `iterateByCriteria()` SANS filtre geographique  → endpoint `/siren`
 *      → branche `else` (lignes 188-200 avant reparation) : AUCUN filtre.
 *      ❌ Toute unite legale opposee etait rendue, puis ecrite dans `companies`
 *         par `prospection:collect` (upsert, ligne 71 de `ProspectionCollect`).
 *
 *   3. `fetchBySiren()` (lignes 30-62 avant reparation) : AUCUN filtre.
 *      ❌ Consomme par `WaterfallOrchestrator::step1_insee()` qui ECRIT
 *         `denomination` sur la fiche, et par
 *         `FranceTravailDiscoveryClient::filterActiveByInsee()`.
 *
 * Une personne qui a demande a l'INSEE de ne pas etre diffusee et qu'on
 * collecte quand meme, c'est un manquement direct — art. A123-96 du code de
 * commerce, et art. 6/14 RGPD pour ce qui est de la base legale.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * CE QUE CETTE GARDE MESURE
 * ═══════════════════════════════════════════════════════════════════════════
 *
 * Elle ne lit pas le code : elle fait PARLER l'API (via `Http::fake()`) avec
 * une reponse INSEE reelle en forme — unite legale de personne physique dont
 * `statutDiffusionUniteLegale` vaut `P` (diffusion partielle) ou `N`, et dont
 * les champs nominatifs sont remplaces par la chaine `[ND]` — et demande au
 * client ce qu'il en fait.
 *
 * ⚠️ TEMOINS. Chaque garde a son jumeau « diffusible » : sans lui, un client
 * qui rendrait TOUJOURS vide passerait pour irreprochable, et la collecte
 * serait morte sans que personne ne le voie.
 */

use App\Services\Insee\HttpInseeClient;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

uses(TestCase::class);

/**
 * Pose une cle d'API pour que `authHttp()` ne parte pas chercher un jeton
 * OAuth2. `env()` de Laravel lit `$_ENV` / `$_SERVER` / `getenv()` : on pose
 * les trois, la suite de ce depot tournant en environnement `local` (cf. le
 * piege consigne dans `SsrfGuard::surBancDeTest()`).
 */
beforeEach(function () {
    $_ENV['INSEE_API_KEY'] = 'cle-de-banc';
    $_SERVER['INSEE_API_KEY'] = 'cle-de-banc';
    putenv('INSEE_API_KEY=cle-de-banc');
});

afterEach(function () {
    unset($_ENV['INSEE_API_KEY'], $_SERVER['INSEE_API_KEY']);
    putenv('INSEE_API_KEY');
});

/**
 * Une unite legale telle que Sirene v3.11 la rend.
 *
 * @param  string  $statut  'O' diffusible · 'P' diffusion partielle · 'N' non diffusible
 * @return array<string, mixed>
 */
function uniteLegaleInsee(string $siren, string $statut, bool $personnePhysique = false): array
{
    // Sur une unite OPPOSEE, l'INSEE ne retire pas la fiche : il MASQUE les
    // champs nominatifs par la chaine litterale « [ND] ». C'est ce masquage
    // qui, faute de filtre, se retrouve tel quel dans `companies.denomination`.
    $masque = $statut !== 'O';

    $periode = $personnePhysique
        ? [
            'denominationUniteLegale' => null,
            'prenom1UniteLegale' => $masque ? '[ND]' : 'Camille',
            'nomUniteLegale' => $masque ? '[ND]' : 'Durand',
            'activitePrincipaleUniteLegale' => '62.01Z',
            'categorieJuridiqueUniteLegale' => '1000',
            'etatAdministratifUniteLegale' => 'A',
        ]
        : [
            'denominationUniteLegale' => $masque ? '[ND]' : 'Fabrique Lumiere SAS',
            'activitePrincipaleUniteLegale' => '62.01Z',
            'categorieJuridiqueUniteLegale' => '5710',
            'etatAdministratifUniteLegale' => 'A',
        ];

    return [
        'siren' => $siren,
        'statutDiffusionUniteLegale' => $statut,
        'trancheEffectifsUniteLegale' => '11',
        'dateCreationUniteLegale' => '2015-03-01',
        'etatAdministratifUniteLegale' => 'A',
        'periodesUniteLegale' => [$periode],
    ];
}

/**
 * Un etablissement tel que Sirene v3.11 le rend sur l'endpoint `/siret`.
 *
 * @return array<string, mixed>
 */
function etablissementInsee(string $siren, string $statut, ?string $statutEtab = null): array
{
    return [
        'siren' => $siren,
        'siret' => $siren . '00015',
        'etablissementSiege' => true,
        'statutDiffusionEtablissement' => $statutEtab ?? 'O',
        'adresseEtablissement' => [
            'numeroVoieEtablissement' => '3',
            'typeVoieEtablissement' => 'RUE',
            'libelleVoieEtablissement' => 'DES LILAS',
            'codePostalEtablissement' => '38000',
            'libelleCommuneEtablissement' => 'GRENOBLE',
            'codeCommuneEtablissement' => '38185',
        ],
        'uniteLegale' => uniteLegaleInsee($siren, $statut) + ['etatAdministratifUniteLegale' => 'A'],
    ];
}

// ── VOIE 3 : fetchBySiren() ──────────────────────────────────────────────────

test('C19-010 — fetchBySiren REFUSE une unite legale non diffusible', function () {
    Http::fake([
        'api.insee.fr/api-sirene/3.11/siren/*' => Http::response([
            'uniteLegale' => uniteLegaleInsee('111111111', 'N', personnePhysique: true),
        ], 200),
    ]);

    $rendu = (new HttpInseeClient)->fetchBySiren('111111111');

    // `WaterfallOrchestrator::step1_insee()` ECRIT `denomination` a partir de
    // ce retour. Rendre la fiche masquee, c'est ecrire « [ND] [ND] » sur une
    // personne qui a demande a ne pas etre diffusee.
    expect($rendu)->toBeNull();
});

test('C19-010 — fetchBySiren REFUSE aussi la diffusion PARTIELLE (statut P)', function () {
    // Depuis 2023 l'INSEE distingue 'N' (non diffusible) et 'P' (partiellement
    // diffusible). Le filtre de la ligne 152 s'ecrit `!== 'O'` : il couvre les
    // deux. Cette garde interdit qu'une reparation le retrecisse en `=== 'N'`.
    Http::fake([
        'api.insee.fr/api-sirene/3.11/siren/*' => Http::response([
            'uniteLegale' => uniteLegaleInsee('222222222', 'P', personnePhysique: true),
        ], 200),
    ]);

    expect((new HttpInseeClient)->fetchBySiren('222222222'))->toBeNull();
});

test('C19-010 — TEMOIN : fetchBySiren rend bien une unite DIFFUSIBLE', function () {
    // Sans ce temoin, un `return null` inconditionnel passerait la garde
    // ci-dessus — et la voie d'enrichissement serait morte en silence.
    Http::fake([
        'api.insee.fr/api-sirene/3.11/siren/*' => Http::response([
            'uniteLegale' => uniteLegaleInsee('333333333', 'O'),
        ], 200),
    ]);

    $rendu = (new HttpInseeClient)->fetchBySiren('333333333');

    expect($rendu)->not->toBeNull();
    expect($rendu->denomination)->toBe('Fabrique Lumiere SAS');
});

test('C19-010 — TEMOIN : une unite SANS statut de diffusion reste collectee', function () {
    // Le defaut par defaut est `'O'` (cf. `?? 'O'`). Certaines reponses de
    // rejeu / de mock ne portent pas le champ : les ecarter ferait taire la
    // collecte entiere sur un detail de forme.
    $u = uniteLegaleInsee('444444444', 'O');
    unset($u['statutDiffusionUniteLegale']);

    Http::fake(['api.insee.fr/api-sirene/3.11/siren/*' => Http::response(['uniteLegale' => $u], 200)]);

    expect((new HttpInseeClient)->fetchBySiren('444444444'))->not->toBeNull();
});

// ── VOIE 2 : iterateByCriteria() sans filtre geographique → /siren ───────────

test('C19-010 — la voie /siren ecarte les non diffusibles', function () {
    // Pas de `department` ni de `commune` → `$hasGeo` est faux → endpoint
    // `/siren` → branche `else`, celle qui n'avait AUCUN filtre.
    Http::fake([
        'api.insee.fr/api-sirene/3.11/siren*' => Http::response([
            'unitesLegales' => [
                uniteLegaleInsee('555555555', 'O'),
                uniteLegaleInsee('666666666', 'N', personnePhysique: true),
                uniteLegaleInsee('777777777', 'P'),
            ],
            // Pas de `header.curseurSuivant` → une seule page, pas de boucle.
        ], 200),
    ]);

    $sirens = [];
    foreach ((new HttpInseeClient)->iterateByCriteria(['naf' => '62.01Z', 'req_delay_ms' => 0]) as $c) {
        $sirens[] = $c->siren;
    }

    // TEMOIN INTEGRE : la page contenait bien TROIS unites. Si le fake n'avait
    // rien rendu, `$sirens` serait vide et l'assertion « pas de 666666666 »
    // passerait sur du neant.
    expect($sirens)->toBe(['555555555']);
});

test('C19-010 — TEMOIN : la voie /siren rend TOUT quand tout est diffusible', function () {
    Http::fake([
        'api.insee.fr/api-sirene/3.11/siren*' => Http::response([
            'unitesLegales' => [
                uniteLegaleInsee('888888888', 'O'),
                uniteLegaleInsee('999999999', 'O'),
            ],
        ], 200),
    ]);

    $sirens = [];
    foreach ((new HttpInseeClient)->iterateByCriteria(['naf' => '62.01Z', 'req_delay_ms' => 0]) as $c) {
        $sirens[] = $c->siren;
    }

    expect($sirens)->toBe(['888888888', '999999999']);
});

// ── VOIE 1 : iterateByCriteria() avec filtre geographique → /siret ───────────

test('C19-010 — TEMOIN : la voie /siret ecartait DEJA les non diffusibles', function () {
    // Cette voie etait la seule correcte. Le temoin verrouille l'acquis : une
    // reparation qui factorise le filtre ne doit pas le PERDRE ici.
    $etab = etablissementInsee(...);

    Http::fake([
        'api.insee.fr/api-sirene/3.11/siret*' => Http::response([
            'etablissements' => [$etab('101010101', 'O'), $etab('202020202', 'N')],
        ], 200),
    ]);

    $sirens = [];
    foreach ((new HttpInseeClient)->iterateByCriteria(['department' => '38', 'req_delay_ms' => 0]) as $c) {
        $sirens[] = $c->siren;
    }

    expect($sirens)->toBe(['101010101']);
});

test('C19-010 — RENFORT : l ETABLISSEMENT porte son PROPRE statut de diffusion', function () {
    // Il n'etait lu par personne : l'unite legale pouvait etre diffusible et
    // l'etablissement collecte, lui, non diffusible.
    // ⚠️ PIEGE PAYE, ET CONSIGNE : deux `Http::fake()` dans le MEME test ne se
    // remplacent pas — le premier motif enregistre gagne, et la seconde page
    // n'est jamais servie. Mesure : la boucle rendait « 101010101 », c'est-a-dire
    // la reponse du fake PRECEDENT. D'ou un test distinct.
    $etab = etablissementInsee(...);
    Http::fake([
        'api.insee.fr/api-sirene/3.11/siret*' => Http::response([
            'etablissements' => [
                $etab('303030303', 'O', 'O'),
                $etab('404040404', 'O', 'N'),
                $etab('505050505', 'O', 'P'),
            ],
        ], 200),
    ]);

    $sirens = [];
    foreach ((new HttpInseeClient)->iterateByCriteria(['department' => '38', 'req_delay_ms' => 0]) as $c) {
        $sirens[] = $c->siren;
    }

    expect($sirens)->toBe(['303030303']);
});

test('C19-010 — TEMOIN : un etablissement SANS statut de diffusion reste collecte', function () {
    // Meme raison que pour l'unite legale : le defaut est `'O'`. Ecarter les
    // reponses muettes ferait taire la collecte entiere sur un detail de forme.
    Http::fake([
        'api.insee.fr/api-sirene/3.11/siret*' => Http::response([
            'etablissements' => [[
                'siren' => '606060606',
                'siret' => '60606060600015',
                'etablissementSiege' => true,
                // PAS de `statutDiffusionEtablissement`.
                'adresseEtablissement' => ['codeCommuneEtablissement' => '38185'],
                'uniteLegale' => uniteLegaleInsee('606060606', 'O'),
            ]],
        ], 200),
    ]);

    $sirens = [];
    foreach ((new HttpInseeClient)->iterateByCriteria(['department' => '38', 'req_delay_ms' => 0]) as $c) {
        $sirens[] = $c->siren;
    }

    expect($sirens)->toBe(['606060606']);
});
