<?php

/**
 * GARDE : UN INDEX DOIT SERVIR LA REQUÊTE QU'ON LUI ATTRIBUE — constat `G41-003`.
 *
 * LE DÉFAUT, ET IL EST DE LA FAMILLE LA PLUS COÛTEUSE DU DÉPÔT.
 *
 * `G41-003` : *« le filtre par dénomination coûte 65 s au volume de production,
 * et l'index de 110 Mo censé le servir porte sur une AUTRE colonne. »* Personne
 * ne l'avait vu parce qu'un index **existe** : il est là, il pèse, `\d` le
 * montre. Il ne sert simplement à rien pour la requête qu'on écrit.
 *
 * 🔴 ET JE VIENS DE LE REFAIRE, LE 2026-08-20. En réparant la palette ⌘K
 * (`P6-UI-002`), j'ai écrit une migration créant
 * `idx_companies_denomination_trgm` **sur `denomination`**. Un index de ce nom
 * existait déjà depuis le 2026-05-16, **sur `denomination_normalized`**. Mon
 * `CREATE INDEX IF NOT EXISTS` a donc **silencieusement ne rien fait**, la
 * migration s'est déclarée passée, et la recherche est restée en `Seq Scan`.
 *
 * Mesuré sur 150 000 lignes, avant de comprendre :
 *
 *     WHERE denomination ILIKE '%…%' ............ Seq Scan       24,6 ms
 *     WHERE denomination_normalized ILIKE '%…%' . Bitmap Index    1,2 ms
 *
 * Vingt fois, et l'écart croît avec le volume. *Un `IF NOT EXISTS` sur un nom
 * déjà pris est un silence, pas une idempotence.*
 *
 * CE QUE CETTE GARDE MESURE, ET POURQUOI ELLE NE PEUT PAS ÊTRE UN `grep`.
 *
 * Elle demande à **Postgres lui-même**, par `EXPLAIN`, si le plan de la requête
 * réelle emploie un index. Aucune lecture de `pg_indexes` ne pourrait le dire :
 * l'index existait, portait le bon nom, et ne servait pas.
 *
 * ⚠️ Elle a besoin d'un VOLUME. Sur une table de trois lignes, le planificateur
 * choisit un balayage séquentiel — et il a raison. La garde peuple donc ce
 * qu'elle mesure, et **échoue si le volume n'est pas atteint** : une mesure de
 * performance faite sur une table vide ne mesure rien.
 */

use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/** En dessous, le planificateur préfère un Seq Scan, et il a raison. */
const VOLUME_MINIMAL = 20000;

function plan(string $sql): string
{
    return collect(DB::select('EXPLAIN ' . $sql))
        ->map(fn ($l) => (array) $l)
        ->flatten()
        ->implode("\n");
}

test('G41-003 — TEMOIN : sans volume, la mesure ne mesure rien', function () {
    // Ce test ne verifie pas le produit : il verifie que la garde ci-dessous
    // sait ce qu'elle fait. Sur trois lignes, Postgres balaie -- et c'est le bon
    // choix. Une garde de performance qui l'ignore rend un verdict faux.
    $espace = Workspace::create([
        'id' => (string) Str::uuid(), 'slug' => 'vol-' . Str::random(6), 'name' => 'Volume',
    ]);

    DB::table('companies')->insert([
        'workspace_id' => $espace->id,
        'denomination' => 'Une seule fiche',
        'siren' => '123456789',
        'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::statement('ANALYZE companies');

    $this->assertStringContainsString(
        'Seq Scan',
        plan("SELECT id FROM companies WHERE denomination_normalized ILIKE '%seule%' LIMIT 10"),
        "Sur une table minuscule, le planificateur DOIT balayer. Si ce test rougit, c'est "
        . 'que le seuil a change et que la garde ci-dessous mesure autre chose.'
    );
});

test('G41-003 — la recherche par denomination EMPLOIE bien son index trigrammes', function () {
    $espace = Workspace::create([
        'id' => (string) Str::uuid(), 'slug' => 'idx-' . Str::random(6), 'name' => 'Index',
    ]);

    // On peuple ce qu'on mesure. `generate_series` est mille fois plus rapide
    // qu'une boucle PHP, et c'est la difference entre une garde qu'on joue et
    // une garde qu'on desactive.
    DB::statement("
        INSERT INTO companies (workspace_id, denomination, siren, created_at, updated_at)
        SELECT ?, 'Entreprise ' || md5(g::text), lpad((100000000 + g)::text, 9, '0'), now(), now()
          FROM generate_series(1, ?) g
    ", [$espace->id, VOLUME_MINIMAL]);
    DB::statement('ANALYZE companies');

    $nombre = (int) DB::table('companies')->count();
    expect($nombre)->toBeGreaterThanOrEqual(
        VOLUME_MINIMAL,
        'Le volume attendu n\'est pas atteint : cette mesure ne prouverait rien.'
    );

    // LA requete que `GlobalSearchController::chercherEntreprises()` emet.
    //
    // ⚠️ QUATRE CARACTERES, ET LA MESURE DIT POURQUOI. Sur ce meme volume :
    //
    //     '%c3a%'   (3 car., ~2 % des lignes) ... Seq Scan
    //     '%c3a4%'  (4 car., ~0,01 %) ........... Bitmap Heap Scan
    //     '%c3a4b%' (5 car.) .................... Bitmap Heap Scan
    //
    // Ce n'est PAS un defaut, et il ne faut pas le corriger : sur un terme tres
    // commun, un balayage trouve ses dix resultats presque tout de suite, et le
    // planificateur a raison de le preferer. L'index sert les termes SELECTIFS,
    // qui sont precisement ceux ou un balayage couterait cher.
    //
    // On mesure donc le cas qui compte : une saisie distinctive.
    $plan = plan("SELECT id FROM companies WHERE denomination_normalized ILIKE '%c3a4%' LIMIT 10");

    $this->assertStringContainsString(
        'idx_companies_denomination_trgm',
        $plan,
        "La palette ⌘K se declenche a CHAQUE FRAPPE. Son plan n'emploie pas l'index "
        . "trigrammes :\n\n{$plan}\n\n"
        . "C'est le constat G41-003 : l'index existe, il pese, `\\d` le montre -- et il ne "
        . "sert pas la requete ecrite. Verifier que la COLONNE interrogee est bien celle que "
        . "l'index couvre. `denomination` et `denomination_normalized` sont deux colonnes "
        . 'differentes, et un seul des deux est indexe.'
    );
});

/**
 * LA PARADE GÉNÉRIQUE, ET C'EST ELLE QUI MANQUAIT AU DÉPÔT.
 *
 * Deux index ne doivent jamais porter le même nom pour des colonnes
 * différentes : `CREATE INDEX IF NOT EXISTS` devient alors un **silence**, et la
 * migration se déclare passée sans rien avoir fait. C'est exactement ce qui
 * s'est produit le 2026-08-20, et ce qui a produit `G41-003` avant lui.
 */
test('G41-003 — aucun nom d index n est reutilise pour une autre colonne', function () {
    $lignes = DB::select("
        SELECT indexname, indexdef
          FROM pg_indexes
         WHERE schemaname = 'public' AND indexdef LIKE '%trgm%'
    ");

    $vus = [];
    $collisions = [];

    foreach ($lignes as $ligne) {
        $nom = $ligne->indexname;
        // La colonne indexee, entre les parentheses de `USING gin (...)`.
        preg_match('/USING \w+ \(([^)]*)\)/', $ligne->indexdef, $m);
        $colonne = trim($m[1] ?? '');

        if (isset($vus[$nom]) && $vus[$nom] !== $colonne) {
            $collisions[] = "{$nom} : « {$vus[$nom]} » et « {$colonne} »";
        }
        $vus[$nom] = $colonne;
    }

    expect($collisions)->toBe(
        [],
        "Un meme nom d'index designe deux colonnes differentes. `CREATE INDEX IF NOT EXISTS` "
        . "devient alors un SILENCE : la migration se declare passee, l'index attendu n'existe "
        . "pas, et la requete reste en Seq Scan.\n\nCollisions :\n  - "
        . implode("\n  - ", $collisions)
    );
});
