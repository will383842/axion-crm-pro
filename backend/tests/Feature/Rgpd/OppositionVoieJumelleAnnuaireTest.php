<?php

/**
 * GARDE : L'OPPOSITION REFUSEE PAR L'INSEE EST RATTRAPEE PAR LA SOURCE JUMELLE
 * — troisieme volet de `C19-010`, DECOUVERT en fermant les deux premiers.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * CE QUE J'AI FERME, ET PAR OU CA FUIT ENCORE
 * ═══════════════════════════════════════════════════════════════════════════
 *
 * `HttpInseeClient` ecarte desormais les unites opposees sur ses TROIS voies
 * (`OppositionInseeNonDiffusibleTest`, 9 gardes vertes le 2026-08-20). Sur la
 * voie d'ENRICHISSEMENT, cela veut dire que `fetchBySiren()` rend `null`.
 *
 * Or `WaterfallOrchestrator::step1_insee()` traite ce `null` comme « INSEE n'a
 * rien a dire » et rend `'ok'` (`WaterfallOrchestrator.php:117-119`). L'appelant
 * enchaine alors, sans condition, sur `step2_annuaire()` (`:85`) — qui interroge
 * une SECONDE source publique sur le MEME siren :
 * `recherche-entreprises.api.gouv.fr`, via `HttpAnnuaireEntreprisesClient`.
 *
 * Mesure du 2026-08-20, jouee dans le conteneur `a35r` :
 *
 *     grep -rn "statut_diffusion" --include=*.php --include=*.json backend/
 *     → AUCUNE ligne.
 *
 * `statutDiffusionUniteLegale` / `statutDiffusionEtablissement` n'existent que
 * dans `HttpInseeClient`. La voie jumelle ne lit AUCUN champ de diffusion :
 * elle rend ce que l'API rend, sans se demander si la personne s'est opposee.
 *
 * Et ce qu'elle rend n'est pas anodin : `step2_annuaire()` (`:174-191`) INSERE
 * chaque dirigeant dans `contacts` — `first_name`, `last_name`, et le bloc
 * `metadata` complet, qui porte `birth_date`, la DATE DE NAISSANCE
 * (`HttpAnnuaireEntreprisesClient.php:35-42`).
 *
 * 🔴 Autrement dit : sur une unite opposee, le refus INSEE ne protege plus
 * grand-chose. La fiche n'entre pas par l'INSEE, mais l'identite nominative de
 * son dirigeant entre par l'annuaire, avec sa date de naissance.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * POURQUOI CETTE GARDE EST « INCOMPLETE » ET NON REPAREE
 * ═══════════════════════════════════════════════════════════════════════════
 *
 * Le correctif ne vit PAS dans `app/Services/Insee/` — le perimetre ecrit de ce
 * lot. Il vit dans `app/Services/AnnuaireEntreprises/` et/ou dans
 * `app/Services/Waterfall/`, et quatorze agents partagent ce depot.
 *
 * Il change en outre une SEMANTIQUE (regle 10 du mandat) : arreter le waterfall
 * sur un `null` INSEE, c'est cesser d'enrichir toutes les fiches dont l'INSEE
 * est simplement muet — pannes, sirens radies, quota. Ce n'est pas un
 * arbitrage a rendre au detour d'un correctif.
 *
 * LES DEUX FORMES POSSIBLES, a soumettre a Will :
 *
 *   A. Distinguer « l'INSEE ne sait pas » de « la personne s'est opposee ».
 *      `fetchBySiren()` rend `null` dans les deux cas : c'est CE point qui rend
 *      la reparation impossible en aval. Il faudrait un retour distinct
 *      (exception dediee, ou drapeau sur la fiche), puis un court-circuit du
 *      waterfall sur l'opposition SEULE.
 *
 *   B. Porter le filtre sur la source jumelle, comme on l'a porte sur les trois
 *      voies INSEE — a condition d'avoir MESURE le nom exact du champ de
 *      diffusion rendu par `recherche-entreprises.api.gouv.fr`. Je ne l'ai pas
 *      mesure et je ne l'invente pas : cette garde n'affirme donc RIEN sur le
 *      contenu de cette API. Elle mesure seulement ce que fait NOTRE code de ce
 *      qu'on lui donne.
 *
 * ⚠️ TEMOIN. La garde a son jumeau positif : sans lui, un client qui rendrait
 * toujours `null` passerait pour irreprochable et l'enrichissement serait mort
 * en silence.
 */

use App\Services\AnnuaireEntreprises\HttpAnnuaireEntreprisesClient;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

uses(TestCase::class);

/**
 * Une entree telle que `recherche-entreprises.api.gouv.fr/search` la rend.
 *
 * @param  array<string, mixed>  $marquage  champs supplementaires poses a la racine
 *                                          ou dans `siege` — c'est la ou un marquage
 *                                          d'opposition se trouverait.
 * @return array<string, mixed>
 */
function entreeAnnuaireJumelle(string $siren, array $marquage = []): array
{
    return array_merge([
        'siren' => $siren,
        'nom_complet' => 'Camille Durand',
        'nom_raison_sociale' => null,
        'activite_principale' => '62.01Z',
        'siege' => array_merge([
            'geo_adresse' => '3 RUE DES LILAS 38000 GRENOBLE',
            'code_postal' => '38000',
            'libelle_commune' => 'GRENOBLE',
        ], $marquage['siege'] ?? []),
        // Le point sensible : des PERSONNES PHYSIQUES, nommees, avec leur date
        // de naissance. C'est ce bloc que `step2_annuaire()` insere dans
        // `contacts` (WaterfallOrchestrator.php:174-191).
        'dirigeants' => [[
            'qualite' => 'gerant',
            'prenoms' => 'Camille',
            'nom' => 'Durand',
            'date_naissance' => '1979-04',
        ]],
    ], array_diff_key($marquage, ['siege' => null]));
}

test('C19-010 — TEMOIN : la voie jumelle rend bien une fiche sur une reponse normale', function () {
    // Sans ce temoin, la garde suivante passerait aussi bien sur un client qui
    // rendrait TOUJOURS null — et l'enrichissement legal serait mort en silence
    // sans que rien ne le dise.
    Http::fake([
        'recherche-entreprises.api.gouv.fr/*' => Http::response([
            'results' => [entreeAnnuaireJumelle('111111111')],
        ], 200),
    ]);

    $rendu = (new HttpAnnuaireEntreprisesClient)->fetchBySiren('111111111');

    expect($rendu)->not->toBeNull();
    expect($rendu->denomination)->toBe('Camille Durand');
});

test('C19-010 — RESTE OUVERT : la voie jumelle collecte quel que soit le marquage', function () {
    // ⚠️ TEST DELIBEREMENT « INCOMPLET » : ni vert, ni rouge. Meme convention
    // que `PurgeNonDiffusibleVariantesTest` et `G41-002` dans ce depot.
    //
    // Le declarer vert serait un mensonge : l'identite du dirigeant d'une unite
    // opposee entre toujours. Le laisser rouge ferait de la suite un feu
    // permanent que quelqu'un finirait par eteindre, et le defaut redeviendrait
    // invisible — c'est exactement ainsi que `C19-010` a survecu jusqu'ici.

    // On pose TOUS les marquages d'opposition plausibles, a la racine ET dans
    // `siege`. Peu importe lequel l'API emploie reellement : ce client n'en lit
    // AUCUN, donc aucun ne change quoi que ce soit. C'est precisement le
    // constat, et il ne depend d'aucune hypothese sur l'API.
    Http::fake([
        'recherche-entreprises.api.gouv.fr/*' => Http::response([
            'results' => [entreeAnnuaireJumelle('222222222', [
                'statut_diffusion' => 'N',
                'statut_diffusion_unite_legale' => 'N',
                'siege' => ['statut_diffusion_etablissement' => 'N'],
            ])],
        ], 200),
    ]);

    $rendu = (new HttpAnnuaireEntreprisesClient)->fetchBySiren('222222222');

    // Mesure 1 — la fiche entre.
    $this->assertNotNull(
        $rendu,
        'BONNE NOUVELLE, et il faut la traiter : la voie jumelle ecarte DESORMAIS '
        . 'les unites opposees. Retirer le markTestIncomplete ci-dessous et '
        . 'transformer cette garde en assertion positive.',
    );

    // Mesure 2 — et elle entre AVEC l'identite nominative du dirigeant.
    // Sous-chaines SANS LETTRE ACCENTUEE (regle 5 du mandat).
    $this->assertCount(1, $rendu->representatives);
    $this->assertSame('Durand', $rendu->representatives[0]['last_name']);
    $this->assertSame('Camille', $rendu->representatives[0]['first_name']);

    // Mesure 3 — la DATE DE NAISSANCE, que `step2_annuaire()` recopie telle
    // quelle dans `contacts.metadata`.
    $this->assertSame('1979-04', $rendu->representatives[0]['birth_date']);

    $this->markTestIncomplete(
        'C19-010, troisieme volet. L ENTREE PAR L INSEE est fermee (9 gardes vertes), '
        . 'mais le waterfall enchaine sur `step2_annuaire()` sans condition quand '
        . '`fetchBySiren()` rend null : l identite du dirigeant d une unite opposee '
        . 'entre par `recherche-entreprises.api.gouv.fr`, date de naissance comprise. '
        . 'Mesure : `statut_diffusion` n apparait NULLE PART dans le depot. Le '
        . 'correctif porte sur `Services/AnnuaireEntreprises/` et/ou '
        . '`Services/Waterfall/` — hors du perimetre ecrit de ce lot — et change une '
        . 'semantique (regle 10). Les deux formes possibles sont dans l en-tete.',
    );
});
