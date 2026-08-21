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
 *       ❌ NON RECONNUE : l'egalite stricte ne la voit pas.
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
 * POURQUOI CE TEST EST « INCOMPLET » ET NON REPARE
 * ═══════════════════════════════════════════════════════════════════════════
 *
 * Le correctif tient en une ligne, dans
 * `app/Console/Commands/ProspectionPurgeNonDiffusible.php` — un fichier HORS
 * du perimetre ecrit de ce lot, et trois agents partagent ce depot. La forme
 * mesuree comme correcte, a soumettre a Will :
 *
 *     ->where(function ($q) {
 *         $q->where('denomination', '[ND]')
 *           ->orWhere('denomination', 'like', '%[[]ND]%');   // `[` echappe en LIKE
 *     })
 *
 * (`LIKE '%[[]ND]%'` — en SQL standard `[` n'est pas un metacaractere, mais
 * l'ecrire ainsi reste lisible ; `position('[ND]' in denomination) > 0` est
 * equivalent et plus sur. Les deux couvrent FORME 1 et FORME 2.)
 *
 * La FORME 3 ne se repare PAS par la purge : elle demande de rejouer l'INSEE
 * sur les fiches sans denomination issues de `discovery_source = 'insee'`, et
 * d'archiver celles que l'API rend desormais `null` (le nouveau filtre). C'est
 * un travail de commande, pas d'une ligne — arbitrage a Will.
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

test('C19-010 — TEMOIN : la purge SAIT effacer la forme qu elle reconnait', function () {
    // Sans ce temoin, la garde suivante passerait aussi bien sur une commande
    // qui ne supprime jamais rien -- et le rattrapage serait mort en silence.
    $espace = espaceNonDiffusible();
    fichesND($espace, '[ND]', 2);
    fichesND($espace, 'Fabrique Lumiere SAS', 8);   // 2 sur 10 = 20 %, sous le plafond

    Artisan::call('prospection:purge-non-diffusible', ['--force' => true]);

    expect(DB::table('companies')->count())->toBe(8);
});

test('C19-010 — RESTE OUVERT : la purge ne voit ni « [ND] [ND] » ni la fiche sans nom', function () {
    // ⚠️ TEST DELIBEREMENT « INCOMPLET » : ni vert, ni rouge.
    //
    // Le declarer vert serait un mensonge -- des fiches nees d'une opposition
    // restent en base. Le laisser rouge ferait de la suite un feu permanent
    // que quelqu'un finirait par eteindre, et le defaut deviendrait invisible.
    // C'est la convention deja employee par `G41-002` dans ce depot.
    $espace = espaceNonDiffusible();
    fichesND($espace, '[ND]', 1);          // FORME 1 — reconnue
    fichesND($espace, '[ND] [ND]', 1);     // FORME 2 — non reconnue
    fichesND($espace, null, 1);            // FORME 3 — non reconnaissable
    fichesND($espace, 'Fabrique Lumiere SAS', 7);

    Artisan::call('prospection:purge-non-diffusible', ['--force' => true]);

    $restantes = DB::table('companies')
        ->where(function ($q) {
            $q->where('denomination', 'like', '%ND%')->orWhereNull('denomination');
        })
        ->count();

    $this->assertGreaterThan(
        0,
        $restantes,
        'BONNE NOUVELLE, et il faut la traiter : la purge efface DESORMAIS toutes les '
        . 'formes de marquage. Le defaut documente ici est referme -- retirer le '
        . '`markTestIncomplete` ci-dessous et transformer cette garde en assertion '
        . 'positive (`expect($restantes)->toBe(0)`).',
    );

    // La mesure exacte, pour que le rapport ne soit pas une impression.
    $this->assertSame(
        2,
        $restantes,
        'Le nombre de fiches issues d\'une opposition qui SURVIVENT a la purge a change. '
        . 'Mesure du 2026-08-20 : 2 sur 3 (« [ND] [ND] » et la fiche sans denomination). '
        . 'Si ce nombre bouge, c\'est que la purge OU la collecte a change : refaire la mesure.',
    );

    $this->markTestIncomplete(
        'C19-010 n\'est ferme qu\'a moitie. L\'ENTREE est fermee (les trois voies de '
        . 'HttpInseeClient ecartent les unites opposees, 9 gardes vertes), mais le '
        . 'RATTRAPAGE ne reconnait qu\'une forme de marquage sur les 3 mesurees : '
        . '« [ND] [ND] » et la fiche sans denomination survivent a '
        . '`prospection:purge-non-diffusible`. Le correctif tient en une ligne mais '
        . 'porte sur `ProspectionPurgeNonDiffusible.php`, hors du perimetre ecrit de '
        . 'ce lot. Forme proposee dans l\'en-tete de ce fichier.',
    );
});
