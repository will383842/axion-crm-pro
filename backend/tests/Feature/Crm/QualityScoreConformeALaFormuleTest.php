<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/**
 * C21-004 — LE SCORE STOCKE CONTRE LA FORMULE QUI EST CENSEE LE PRODUIRE.
 *
 * L'audit 360 (agent-21) a mesure sur les 4 295 349 fiches de production que
 * 82,58 % des `companies.quality_score` stockes ne valent PAS ce que rend
 * `public.recompute_company_quality_score()`. La copie locale de ce jeu de
 * donnees a depuis ete purgee : ce fichier ne rejoue donc PAS le pourcentage,
 * il rejoue la MECANIQUE qui le produit, sur un echantillon fabrique ici.
 *
 * Deux causes, mesurees separement plus bas :
 *
 *  1. `companies_recompute_score` est `AFTER UPDATE OF …` — jamais `INSERT`.
 *     Une fiche creee par import garde le `DEFAULT 0` de la colonne tant
 *     qu'aucune des colonnes ecoutees n'est modifiee. Les deux chemins
 *     d'ingestion du depot (`ScrapedRecordIngestService`, `SiteSyncIngestService`)
 *     ecrivent d'ailleurs `'quality_score' => 0` en dur a l'insertion.
 *
 *  2. La migration 2026_07_09_000002 a REECRIT la formule et lui a ajoute
 *     cinq entrees — `email_generic`, `address`, `lat`/`lon`, `enseigne` —
 *     sans toucher a la liste de colonnes du declencheur, restee celle de
 *     2026_05_16_000009 : `website, phone, linkedin_url, signals`. Modifier
 *     l'adresse d'une fiche change donc son score theorique sans que rien ne
 *     le recalcule.
 *
 * ── Comment ces gardes comparent ─────────────────────────────────────────────
 *
 * Elles ne reecrivent PAS la formule (une copie deriverait a la premiere
 * retouche du bareme). Elles lisent le score STOCKE, puis appellent
 * `SELECT recompute_company_quality_score(id)` — qui RETOURNE le score
 * recalcule — et comparent les deux. La comparaison est donc vraie quelle que
 * soit la formule du jour.
 *
 * ⚠️ L'appel ECRIT aussi la valeur : chaque fiche ne doit etre mesuree qu'UNE
 * fois, sinon la deuxieme lecture trouve le stock deja aligne par la premiere.
 */
function qsWorkspace(): string
{
    $id = (string) Str::uuid();

    DB::table('workspaces')->insert([
        'id' => $id,
        'slug' => 'qs-' . substr($id, 0, 8),
        'name' => 'QS C21-004',
        'settings' => '{}',
        'cost_cap_eur' => 100,
        'is_active' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return $id;
}

/**
 * @param  array<string, mixed>  $attrs
 */
function qsInsertCompany(string $workspaceId, array $attrs = []): int
{
    return (int) DB::table('companies')->insertGetId(array_merge([
        'workspace_id' => $workspaceId,
        'siren' => str_pad((string) random_int(0, 999999999), 9, '0', STR_PAD_LEFT),
        'denomination' => 'ZZ C21-004',
        'signals' => '{}',
        'metadata' => '{}',
        'field_origins' => '{}',
        'created_at' => now(),
        'updated_at' => now(),
    ], $attrs));
}

function qsStocke(int $id): int
{
    return (int) DB::table('companies')->where('id', $id)->value('quality_score');
}

/**
 * Score que la formule rend AUJOURD'HUI pour cette fiche.
 *
 * ⚠️ Effet de bord assume : l'appel realigne le stock. On lit donc TOUJOURS le
 * stocke AVANT.
 */
function qsRecalcule(int $id): int
{
    return (int) DB::selectOne('SELECT recompute_company_quality_score(?) AS s', [$id])->s;
}

// ─────────────────────────────────────────────────────────────────────────────
// TEMOINS — sans eux, tout ce fichier peut passer au vert sur du vide.
// ─────────────────────────────────────────────────────────────────────────────

test('TEMOIN — la formule existe, elle est branchee, et elle sait rendre autre chose que zero', function () {
    $fonctions = DB::select(
        "SELECT proname FROM pg_proc WHERE proname = 'recompute_company_quality_score'",
    );
    expect($fonctions)->toHaveCount(1, 'La fonction de score est absente de la base : la comparaison n aurait aucun sens.');

    $ws = qsWorkspace();

    // Fiche nue : la formule doit rendre 0.
    $nue = qsInsertCompany($ws);
    expect(qsRecalcule($nue))->toBe(0);

    // Fiche garnie : la formule doit rendre STRICTEMENT plus que 0, sinon la
    // comparaison « stocke vs recalcule » serait 0 = 0 partout, donc aveugle.
    $garnie = qsInsertCompany($ws, [
        'website' => 'https://exemple.test',
        'phone' => '+33100000000',
        'address' => '1 rue du Test, 75001 Paris',
    ]);
    expect(qsRecalcule($garnie))->toBeGreaterThan(0);
});

test('TEMOIN — la comparaison SAIT voir une divergence quand on en fabrique une', function () {
    $ws = qsWorkspace();
    $id = qsInsertCompany($ws, ['website' => 'https://exemple.test']);

    // On aligne le stock sur la formule…
    $reference = qsRecalcule($id);
    expect(qsStocke($id))->toBe($reference);

    // …puis on le desaligne A LA MAIN, par une colonne que le declencheur
    // n'ecoute pas et ne doit jamais ecouter (sinon : recursion).
    DB::table('companies')->where('id', $id)->update(['quality_score' => $reference + 7]);

    $stocke = qsStocke($id);
    expect($stocke)->toBe($reference + 7);
    expect($stocke)->not->toBe(qsRecalcule($id), 'Le comparateur ne voit pas une divergence pourtant posee a la main : il est aveugle.');
});

// ─────────────────────────────────────────────────────────────────────────────
// CAUSE 1 — l'INSERT n'est ecoute par personne
// ─────────────────────────────────────────────────────────────────────────────

test('une fiche CREEE porte deja le score que la formule rend pour elle', function () {
    $ws = qsWorkspace();

    $id = qsInsertCompany($ws, [
        'website' => 'https://exemple.test',
        'phone' => '+33100000000',
        'email_generic' => 'contact@exemple.test',
        'address' => '1 rue du Test, 75001 Paris',
        'lat' => 48.8566,
        'lon' => 2.3522,
        'enseigne' => 'Exemple',
    ]);

    $stocke = qsStocke($id);
    $recalcule = qsRecalcule($id);

    expect($stocke)->toBe(
        $recalcule,
        "Fiche creee avec 7 criteres renseignes : la formule rend {$recalcule}, la base a garde {$stocke}. "
        . 'Le declencheur companies_recompute_score est AFTER UPDATE OF … et n ecoute pas l INSERT (C21-004).',
    );
});

test('le badge derive suit des la CREATION, comme il suit une modification', function () {
    $ws = qsWorkspace();

    // Fiche creee riche : le badge doit deja refleter son score.
    $creee = qsInsertCompany($ws, [
        'website' => 'https://exemple.test',
        'phone' => '+33100000000',
        'email_generic' => 'contact@exemple.test',
        'address' => '1 rue du Test, 75001 Paris',
        'lat' => 48.8566,
        'lon' => 2.3522,
    ]);
    $score = qsStocke($creee);
    expect($score)->toBeGreaterThan(0);

    // Reference : une fiche nue dont on pose le MEME score a la main.
    $reference = qsInsertCompany($ws);
    DB::table('companies')->where('id', $reference)->update(['quality_score' => $score]);

    // ⚠️ On ne fige AUCUN seuil ici : les paliers de `quality_badge` sont
    // eux-memes en cause (C21-005) et doivent pouvoir etre re-etalonnes. On
    // verifie seulement que le chemin CREATION rend le meme badge que le chemin
    // MODIFICATION pour un score identique — c est ce que C21-004 cassait.
    $badgeCree = DB::table('companies')->where('id', $creee)->value('quality_badge');
    $badgeReference = DB::table('companies')->where('id', $reference)->value('quality_badge');

    expect($badgeCree)->toBe($badgeReference)->not->toBeNull();
});

test('une fiche creee NUE porte bien zero — le zero legitime ne doit pas rougir', function () {
    $ws = qsWorkspace();
    $id = qsInsertCompany($ws);

    expect(qsStocke($id))->toBe(qsRecalcule($id))->toBe(0);
});

// ─────────────────────────────────────────────────────────────────────────────
// CAUSE 2 — la formule a gagne 5 entrees en juillet, le declencheur non
// ─────────────────────────────────────────────────────────────────────────────

/**
 * @return array<string, array<string, mixed>>
 */
function qsColonnesDeLaFormule(): array
{
    return [
        'email_generic' => ['email_generic' => 'contact@exemple.test'],
        'website' => ['website' => 'https://exemple.test'],
        'phone' => ['phone' => '+33100000000'],
        'linkedin_url' => ['linkedin_url' => 'https://linkedin.test/company/x'],
        'address' => ['address' => '1 rue du Test, 75001 Paris'],
        'lat/lon' => ['lat' => 48.8566, 'lon' => 2.3522],
        'enseigne' => ['enseigne' => 'Exemple'],
        'signals' => ['signals' => '{"recent": [{"type": "recrutement"}]}'],
    ];
}

test('modifier une colonne qui entre dans la formule recalcule le score', function () {
    $ws = qsWorkspace();
    $ecarts = [];

    foreach (qsColonnesDeLaFormule() as $etiquette => $modification) {
        // Fiche nue, stock aligne sur la formule (0 = 0) avant la mesure.
        $id = qsInsertCompany($ws);
        qsRecalcule($id);
        expect(qsStocke($id))->toBe(0);

        DB::table('companies')->where('id', $id)->update($modification);

        $stocke = qsStocke($id);
        $recalcule = qsRecalcule($id);

        if ($stocke !== $recalcule) {
            $ecarts[] = "{$etiquette} : stocke {$stocke}, formule {$recalcule}";
        }
    }

    expect($ecarts)->toBe(
        [],
        'Ces colonnes entrent dans recompute_company_quality_score() mais ne sont pas dans la liste du '
        . 'declencheur companies_recompute_score, restee celle de 2026_05_16_000009 alors que la formule a '
        . 'ete elargie par 2026_07_09_000002 : ' . implode(' | ', $ecarts),
    );
});

test('un contact joignable qui arrive recalcule le score de son entreprise', function () {
    $ws = qsWorkspace();
    $id = qsInsertCompany($ws);
    qsRecalcule($id);
    expect(qsStocke($id))->toBe(0);

    DB::table('contacts')->insert([
        'workspace_id' => $ws,
        'company_id' => $id,
        'last_name' => 'ZZ TEST',
        'email' => 'jean@exemple.test',
        'email_status' => 'valid',
        'sources' => '[]',
        'metadata' => '{}',
        'field_origins' => '{}',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $stocke = qsStocke($id);
    expect($stocke)->toBe(qsRecalcule($id))->toBeGreaterThan(0);
});

// ─────────────────────────────────────────────────────────────────────────────
// LA GARDE DEMANDEE — echantillon, stocke contre recalcule
// ─────────────────────────────────────────────────────────────────────────────

test('sur un echantillon de fiches, aucun score stocke ne contredit la formule', function () {
    $ws = qsWorkspace();
    $criteres = qsColonnesDeLaFormule();
    $etiquettes = array_keys($criteres);
    $ids = [];

    // 32 fiches : les 8 criteres pris seuls, puis 24 combinaisons deterministes
    // (pas de hasard — un echantillon qui change d une execution a l autre rend
    // le rouge irreproductible).
    foreach ($etiquettes as $etiquette) {
        $ids[] = qsInsertCompany($ws, $criteres[$etiquette]);
    }

    for ($n = 0; $n < 24; $n++) {
        $attrs = [];
        foreach ($etiquettes as $rang => $etiquette) {
            if ((($n + 1) >> $rang & 1) === 1) {
                $attrs += $criteres[$etiquette];
            }
        }
        $ids[] = qsInsertCompany($ws, $attrs);
    }

    // Un contact joignable sur une fiche sur quatre.
    foreach ($ids as $rang => $id) {
        if ($rang % 4 !== 0) {
            continue;
        }
        DB::table('contacts')->insert([
            'workspace_id' => $ws,
            'company_id' => $id,
            'last_name' => 'ZZ TEST ' . $rang,
            'email' => "c{$rang}@exemple.test",
            'email_status' => 'catchall',
            'sources' => '[]',
            'metadata' => '{}',
            'field_origins' => '{}',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    // ── Mesure : on lit TOUT le stock d abord, on recalcule ENSUITE ──────────
    $stocke = DB::table('companies')->whereIn('id', $ids)->pluck('quality_score', 'id');
    $recalcule = [];
    foreach ($ids as $id) {
        $recalcule[$id] = qsRecalcule($id);
    }

    // TEMOIN — l echantillon existe et il DISCRIMINE : sans ces deux lignes,
    // zero fiche ou un echantillon tout a zero passerait au vert.
    expect(count($ids))->toBe(32);
    expect(count(array_unique($recalcule)))->toBeGreaterThan(3, 'Echantillon degenere : la formule y rend moins de 4 valeurs distinctes, il ne peut rien prouver.');
    expect(max($recalcule))->toBeGreaterThan(50, 'Echantillon degenere : aucune fiche n atteint le haut du bareme.');

    $divergents = [];
    foreach ($ids as $id) {
        if ((int) $stocke[$id] !== $recalcule[$id]) {
            $divergents[] = "#{$id} stocke " . (int) $stocke[$id] . " formule {$recalcule[$id]}";
        }
    }

    $part = round(count($divergents) / count($ids) * 100, 2);

    expect($divergents)->toBe(
        [],
        count($divergents) . ' fiches sur ' . count($ids) . " ({$part} %) portent un quality_score que la "
        . 'formule ne rend pas. C est le defaut C21-004, mesure a 82,58 % sur les 4 295 349 fiches de '
        . 'production le 2026-08-19. Detail : ' . implode(' | ', array_slice($divergents, 0, 10)),
    );
});

// ─────────────────────────────────────────────────────────────────────────────
// LA CAUSE 2, PRISE A LA RACINE — la liste du declencheur contre le bareme
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Les gardes ci-dessus attrapent la divergence par le COMPORTEMENT, une colonne
 * a la fois, a partir de `qsColonnesDeLaFormule()` — une liste ecrite A LA MAIN
 * dans ce fichier. C'est exactement la faute qui a produit C21-004 : la
 * connaissance « voici les colonnes qui font le score » recopiee a un endroit
 * de plus, et qui derive. Une dixieme entree ajoutee au bareme demain ne serait
 * dans aucune des deux listes, et tout resterait vert.
 *
 * Ces deux gardes-ci ne recopient rien : elles LISENT le catalogue Postgres.
 * Les colonnes citees par le corps de `company_quality_score_calcul(companies)`
 * sont comparees a celles que `companies_recompute_score` ecoute reellement.
 *
 * `id` est exclu : la formule le cite (`ct.company_id = c.id`) mais une cle
 * primaire ne bouge pas, et la mettre sous `UPDATE OF` n'aurait aucun sens.
 */
function qsColonnesDuDeclencheur(): array
{
    $lignes = DB::select(<<<'SQL'
        SELECT a.attname AS colonne
        FROM   pg_trigger t
        CROSS  JOIN LATERAL unnest(string_to_array(t.tgattr::text, ' ')::int[]) AS col(attnum)
        JOIN   pg_attribute a ON a.attrelid = t.tgrelid AND a.attnum = col.attnum
        WHERE  t.tgrelid = 'companies'::regclass
          AND  t.tgname  = 'companies_recompute_score'
        SQL);

    $colonnes = array_map(static fn ($l): string => $l->colonne, $lignes);
    sort($colonnes);

    return $colonnes;
}

/** @return list<string> */
function qsColonnesCiteesParLeBareme(): array
{
    $def = DB::selectOne(<<<'SQL'
        SELECT pg_get_functiondef(p.oid) AS def
        FROM   pg_proc p
        JOIN   pg_namespace n ON n.oid = p.pronamespace
        WHERE  p.proname = 'company_quality_score_calcul'
          AND  n.nspname = 'public'
        SQL);

    if ($def === null) {
        return [];
    }

    preg_match_all('/\bc\.([a-z_][a-z0-9_]*)/i', $def->def, $trouves);

    $colonnes = array_values(array_unique(array_diff(
        array_map('strtolower', $trouves[1]),
        ['id'],
    )));
    sort($colonnes);

    return $colonnes;
}

test('TEMOIN — le bareme et le declencheur sont bien lisibles dans le catalogue', function () {
    $bareme = qsColonnesCiteesParLeBareme();
    $declencheur = qsColonnesDuDeclencheur();

    // Sans ces deux bornes, la comparaison qui suit passerait au vert sur deux
    // ensembles VIDES — une fonction absente, un declencheur supprime.
    expect(count($bareme))->toBeGreaterThanOrEqual(
        8,
        'Le bareme company_quality_score_calcul() cite moins de 8 colonnes de companies : '
        . 'soit il est absent, soit la lecture du catalogue ne lit plus rien. '
        . 'Colonnes lues : ' . implode(', ', $bareme),
    );
    expect(count($declencheur))->toBeGreaterThanOrEqual(
        8,
        'Le declencheur companies_recompute_score ecoute moins de 8 colonnes : '
        . implode(', ', $declencheur),
    );

    // Et il doit couvrir l'INSERT : c'est la cause 1 de C21-004.
    $definition = (string) DB::selectOne(<<<'SQL'
        SELECT pg_get_triggerdef(t.oid) AS d
        FROM   pg_trigger t
        WHERE  t.tgrelid = 'companies'::regclass
          AND  t.tgname  = 'companies_recompute_score'
        SQL)?->d;

    $this->assertStringContainsString(
        'BEFORE INSERT OR UPDATE OF',
        $definition,
        'Le declencheur n ecoute pas l INSERT : une fiche creee gardera le DEFAULT 0 de la colonne. '
        . 'Definition lue : ' . $definition,
    );
});

test('toute colonne qui entre dans le bareme est ecoutee par le declencheur', function () {
    $bareme = qsColonnesCiteesParLeBareme();
    $declencheur = qsColonnesDuDeclencheur();

    // ⚠️ Un `array_diff` de deux ensembles vides rend un ensemble vide, donc un
    // VERT. Une formule absente ferait passer cette garde alors que le score
    // n est plus calcule du tout. On refuse le degenere ICI, en plus du TEMOIN
    // d au-dessus, pour que la garde tienne meme jouee seule.
    expect($bareme)->not->toBe([], 'Le bareme company_quality_score_calcul() est introuvable : la comparaison serait vide contre vide, donc verte a tort.');
    expect($declencheur)->not->toBe([], 'Le declencheur companies_recompute_score n ecoute aucune colonne : la comparaison serait aveugle.');

    $oubliees = array_values(array_diff($bareme, $declencheur));

    $this->assertSame(
        [],
        $oubliees,
        'Ces colonnes changent le score theorique d une fiche mais ne reveillent PAS '
        . 'companies_recompute_score : les modifier laissera le stock mentir. C est le defaut C21-004, '
        . 'ne en juillet quand 2026_07_09_000002 a elargi le bareme sans toucher au UPDATE OF du '
        . 'declencheur. Oubliees : ' . implode(', ', $oubliees)
        . ' | bareme : ' . implode(', ', $bareme)
        . ' | declencheur : ' . implode(', ', $declencheur),
    );

    // L'inverse merite un mot, pas une rougeur : une colonne ecoutee en trop ne
    // fausse aucun score, elle coute seulement un recalcul inutile. En revanche
    // `quality_score` lui-meme n'a RIEN a faire dans cette liste — ce serait la
    // recursion.
    expect($declencheur)->not->toContain('quality_score');
});
