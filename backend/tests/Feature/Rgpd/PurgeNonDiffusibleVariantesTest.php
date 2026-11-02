<?php

/**
 * GARDE : LA PURGE DE RATTRAPAGE DES « NON DIFFUSIBLES » NE RECONNAIT QU'UNE
 * PARTIE DES MARQUAGES QUE LA COLLECTE A PU ECRIRE — second volet de `C19-010`.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * CE QUE MESURE CETTE GARDE, ET POURQUOI ELLE N'EST PAS VERTE
 * ═══════════════════════════════════════════════════════════════════════════
 *
 * Le premier volet de `C19-010` est REFERME : les trois voies de
 * `HttpInseeClient` ecartent desormais une unite opposee
 * (`tests/Feature/Rgpd/OppositionInseeNonDiffusibleTest.php`, 9 verts). Plus
 * aucune fiche masquee ne peut ENTRER.
 *
 * Reste ce qui est DEJA ENTRE. `prospection:purge-non-diffusible` est la
 * commande de rattrapage. Sa condition, mot pour mot :
 *
 *     DB::table('companies')->where('denomination', '[ND]')
 *
 * Une egalite stricte sur UNE chaine. Or les voies non filtrees pouvaient
 * ecrire TROIS formes distinctes, toutes mesurees sur le banc le 2026-08-20
 * AVANT reparation (sortie complete au journal du lot) :
 *
 *   FORME 1 · `denomination = '[ND]'`
 *       Personne MORALE opposee : `denominationUniteLegale` est masque.
 *       Les deux voies non filtrees la produisaient.
 *       ✅ RECONNUE par la purge.
 *
 *   FORME 2 · `denomination = '[ND] [ND]'`
 *       Personne PHYSIQUE opposee, voie `fetchBySiren()` : la denomination est
 *       absente, le code retombe sur `trim(prenom1 . ' ' . nom)` — deux champs
 *       masques, donc la chaine « [ND] [ND] » (avec l'espace du milieu).
 *       C'est la sortie litterale du ROUGE :
 *           'denomination' => '[ND] [ND]'
 *       ✅ RECONNUE DEPUIS LE 2026-08-21 : l'egalite stricte est remplacee
 *          par `position('[ND]' in denomination) > 0`.
 *
 *   FORME 3 · `denomination IS NULL`
 *       Personne PHYSIQUE opposee, voie `/siren` : cette branche ne lit QUE
 *       `denominationUniteLegale`, sans repli sur prenom/nom. Une personne
 *       physique n'en a pas → la fiche entre avec un siren, un NAF, un
 *       effectif… et pas de nom.
 *       ❌ NON RECONNUE, et NON RECONNAISSABLE par la denomination : un
 *          entrepreneur individuel LEGITIME et diffusible arrive lui aussi
 *          sans denomination par cette meme voie. Purger sur `IS NULL` serait
 *          exactement le piege `B15-004` (« legal_form IS NULL ») qui a failli
 *          effacer la base entiere.
 *
 * ⚠️ LE CONSTAT ANNONCE « UNE VARIANTE SUR CINQ ». J'en mesure TROIS, pas cinq.
 * Je n'ai pas su reproduire les deux autres depuis le code de ce depot, et je
 * ne les invente pas. Le chiffre honnete est : 1 reconnue sur 3 mesurees.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * CE QUI A ETE FERME LE 2026-08-21, ET CE QUI RESTE
 * ═══════════════════════════════════════════════════════════════════════════
 *
 * La FORME 2 est FERMEE. `ProspectionPurgeNonDiffusible` emploie desormais
 * `position('[ND]' in denomination) > 0`, qui couvre les FORMES 1 et 2 sans
 * dependre d'un echappement `LIKE` — en SQL, `[` n'est pas un metacaractere,
 * mais l'oublier est le genre de detail qui se paie six mois plus tard.
 *
 * La vague precedente avait MESURE ce correctif et l'avait laisse en attente :
 * le fichier etait hors de son perimetre ecrit, et trois agents partagent ce
 * depot. Il est dans le perimetre de la vague 15.
 *
 * ⚠️ ET LA CONDITION ETAIT ECRITE DEUX FOIS — une pour compter, une pour
 * supprimer. Qui n'en corrigeait qu'une faisait mentir le plafond de
 * `RefuseUneSuppressionMassive` : la garde aurait autorise une suppression sur
 * la foi d'un decompte plus etroit qu'elle. Il n'y a plus qu'une definition.
 *
 * ── CE QUI RESTE : LA FORME 3, ET ELLE NE DOIT PAS ETRE FERMEE ICI ─────────
 *
 * Une fiche SANS DENOMINATION n'est pas une preuve d'opposition : un
 * entrepreneur individuel LEGITIME et diffusible arrive lui aussi sans
 * denomination par la voie `/siren`. Purger sur `denomination IS NULL` serait
 * exactement le piege `B15-004` qui a failli effacer la base entiere.
 *
 * Son rattrapage demande de rejouer l'INSEE sur les fiches sans denomination
 * issues de `discovery_source = 'insee'`, et d'archiver celles que l'API rend
 * desormais `null`. C'est un travail de commande, pas d'une condition —
 * arbitrage a Will.
 */

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

function espaceNonDiffusible(): string
{
    $id = (string) Str::uuid();
    DB::table('workspaces')->insert([
        'id' => $id, 'slug' => 'nd-' . Str::random(8), 'name' => 'Espace ND',
        'settings' => '{}', 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
    ]);

    return $id;
}

function fichesND(string $espace, ?string $denomination, int $nombre = 1): void
{
    for ($i = 0; $i < $nombre; $i++) {
        DB::table('companies')->insert([
            'workspace_id' => $espace,
            'denomination' => $denomination,
            'siren' => (string) random_int(100000000, 999999999),
            'discovery_source' => 'insee',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}

// L3c-0 (ADR contrat v3 § 6, F1) : la purge des fiches [ND] est neutralisee.
// Les fiches [ND] sont des entrepreneurs individuels, donc des personnes ; elles
// ne sont plus supprimees, sous aucune forme (« [ND] », « [ND] [ND] », sans nom).

test('C19-010 / L3c-0 — aucune forme de fiche [ND] n est supprimee, meme avec --force', function () {
    $espace = espaceNonDiffusible();
    fichesND($espace, '[ND]', 1);
    fichesND($espace, '[ND] [ND]', 1);
    fichesND($espace, null, 1);
    fichesND($espace, 'Fabrique Lumiere SAS', 7);

    $code = Artisan::call('prospection:purge-non-diffusible', ['--force' => true]);

    expect($code)->toBe(1);
    expect(DB::table('companies')->count())->toBe(10);
    expect(DB::table('companies')->whereRaw("position('[ND]' in denomination) > 0")->count())->toBe(2);
});
