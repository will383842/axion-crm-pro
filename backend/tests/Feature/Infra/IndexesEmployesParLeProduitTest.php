<?php

/**
 * GARDE : NE SUPPRIMEZ PAS CES INDEX — LE PRODUIT S'EN SERT.
 * Contre-expertise du constat `G41-008` (S1, audit 360).
 *
 * ── CE QUE DIT LE CONSTAT, ET CE QUE J'AI TROUVE ────────────────────────────
 *
 * `G41-008` : « neuf index que rien ne lit multiplient par 2,7 le cout
 * d'ecriture de `companies` », et : « aucune requete du produit ne peut les
 * utiliser (croisement `grep` sur `app/`, `routes/`, `database/`) ».
 *
 * 🔴 **La seconde phrase est fausse pour SEPT des neuf.** Verifie le 2026-08-20
 * par `EXPLAIN` sur `axion_crm_perf4m` -- la base de mesure de l'audit
 * lui-meme, 2 800 000 fiches -- avec la requete REELLE que le produit emet :
 *
 *   idx_companies_ws_stage_updated_id ... Index Only Scan  (file par etape,
 *                                         triee par date de derniere touche)
 *   idx_companies_denomination_trgm ..... Bitmap Index Scan (la recherche par
 *                                         denomination, REPAREE le 2026-08-20 :
 *                                         c'est le correctif de `G41-003` qui
 *                                         a rendu cet index vivant)
 *   idx_companies_workspace_country_nature  Index Scan  (`filter[country_code]`
 *                                         + `filter[entity_nature]`, la
 *                                         prospection internationale)
 *   idx_companies_archive_reason ........ Index Scan  (`RescrapeArchivesCommand`
 *                                         :54 et `ObservabilityController`:174)
 *   idx_companies_best_email_confidence . Index Scan  (`filter[best_email_
 *                                         confidence]`, `CompanyQueryFilters`:38)
 *   idx_companies_revalidate ............ Index Scan  (`ProspectionFindWebsites`
 *                                         :127, la revalidation des sites)
 *   idx_companies_signals ............... Bitmap Index Scan (avec `?` / `@>`)
 *
 * Pourquoi l'audit ne les a pas vus : son instrument etait
 * `pg_stat_user_indexes.idx_scan` apres une campagne de **32 requetes
 * d'ecran**. Or ces sept-la ne servent AUCUN ecran : ils servent des commandes
 * en lot, des filtres rares et une recherche. `idx_scan = 0` disait
 * exactement ce qu'il pouvait dire -- « ces 32 requetes-la ne les ont pas
 * touches » -- et pas ce qu'on lui a fait dire.
 *
 * ⚠️ Et `idx_scan` ne dit meme plus cela aujourd'hui : sur `axion_crm_perf4m`,
 * le 2026-08-20, les **27** index de `companies` sont a `idx_scan = 0`,
 * `companies_pkey` COMPRIS, et `sum(seq_scan)` vaut 0 sur toutes les tables.
 * Les compteurs ont ete perdus au redemarrage du conteneur. **L'instrument est
 * muet : il ne peut aujourd'hui distinguer un index mort d'un index vital.**
 * C'est pour cela que cette garde interroge le PLAN, pas un compteur.
 *
 * ── LE HUITIEME, ET LE NEUVIEME ─────────────────────────────────────────────
 *
 *   - `idx_companies_workspace_dept` (30 Mo) : reellement inutilise -- la seule
 *     requete produit sur `postcode` est un `LIKE '%…%'` qu'aucun btree ne sert
 *     (`Seq Scan` verifie). Gardé quand meme : son retrait n'apporte aucun gain
 *     mesurable, et il redevient utile si le filtre passe en `exact`.
 *   - `idx_companies_geo` (114 Mo) : le seul retire, par la migration
 *     `2026_08_20_160000`. `geo_point` est ECRITE trois fois et LUE nulle part.
 *
 * ── CE QUE CETTE GARDE MESURE ───────────────────────────────────────────────
 *
 * Elle demande a Postgres, par `EXPLAIN`, si le plan de la requete produit
 * NOMME l'index. Aucune lecture de `pg_indexes` ne le dirait : `G41-003` a
 * etabli qu'un index peut exister, porter le bon nom, peser 110 Mo -- et ne pas
 * servir la requete ecrite.
 *
 * `SET enable_seqscan = off` : sur une table de test a quelques lignes, le
 * planificateur prefere TOUJOURS un balayage, et il a raison. On lui retire ce
 * choix pour lui poser la seule question qui compte et qui vaudra a 4,29 M de
 * lignes : « cette requete a-t-elle un index qui la couvre ? ». La garde ne
 * depend donc d'AUCUN seuil de volume -- une garde de performance adossee a un
 * volume mesure l'histoire du banc, pas le produit.
 */

use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/** Le plan de `$sql`, index forces, rendu en une seule chaine. */
function planForce(string $sql, array $liaisons = []): string
{
    DB::statement('SET enable_seqscan = off');

    try {
        return collect(DB::select('EXPLAIN ' . $sql, $liaisons))
            ->map(static fn ($ligne): string => (string) reset($ligne))
            ->implode(PHP_EOL);
    } finally {
        DB::statement('SET enable_seqscan = on');
    }
}

/** Une fiche minimale, pour que la table ne soit pas vide. */
function ficheIndex(string $workspaceId, string $siren, array $extra = []): void
{
    DB::table('companies')->insert(array_merge([
        'workspace_id' => $workspaceId,
        'siren' => $siren,
        'denomination' => 'ZZ INDEX ' . $siren,
        'signals' => '{}',
        'metadata' => '{}',
        'field_origins' => '{}',
        'created_at' => now(),
        'updated_at' => now(),
    ], $extra));
}

/**
 * LE JEU D'ESSAI DES MESURES DE PLAN — et son absence a fait rougir ce fichier.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 *
 * Mesure du 2026-08-21 : `G41-008 — la reprise des fiches archivees EMPLOIE son
 * index` a rougi dans la suite complete, sur un commit qui ne touchait ni
 * `companies`, ni ses index, ni ce fichier. Jouee seule, elle restait verte.
 *
 * ── LA CAUSE ───────────────────────────────────────────────────────────────
 *
 * Le `beforeEach` n'ecrivait qu'UNE fiche, et sans aucun des attributs que ces
 * gardes interrogent. Or presque tous les index vises sont PARTIELS :
 *
 *   idx_companies_archive_reason ....... WHERE archive_reason IS NOT NULL
 *   idx_companies_workspace_country_... WHERE country_code <> 'FR'
 *   idx_companies_best_email_confidence  WHERE best_email_confidence IS NOT NULL
 *   idx_companies_revalidate ........... WHERE website_status = 'found'
 *                                          AND website_revalidated_at IS NULL
 *
 * Sur une fiche qui ne remplit AUCUN de ces predicats, ces index sont VIDES.
 * Aucun n'est meilleur qu'un autre, le planificateur departage a egalite de
 * cout — c'est-a-dire arbitrairement — et le nom attendu apparait ou n'apparait
 * pas selon l'etat physique que les tests voisins ont laisse dans la table.
 *
 * Mesure : table propre, la garde `archive` obtenait
 * `Index Scan using idx_companies_workspace_lifecycle_stage` + un `Filter`.
 * Le nom attendu n'etait nulle part. *Cette garde ne prouvait pas ce qu'elle
 * annoncait ; elle etait verte par chance.*
 *
 * ── CE QUE CE JEU D'ESSAI RETABLIT ─────────────────────────────────────────
 *
 * 200 fiches, reparties pour que chaque index partiel soit REELLEMENT le plus
 * selectif pour sa requete :
 *
 *   3 portent un motif d'archivage ....... l'index partiel a 3 entrees sur 200
 *   3 sont etrangeres (RO / association) .. idem
 *   3 portent une confiance e-mail ........ idem
 *   3 portent le signal `google_places_pending` (l'index GIN)
 *   100 sont en `website_status = 'found'`, dont 3 SEULEMENT jamais revalidees
 *
 * ⚠️ Ce dernier desequilibre est le plus delicat, et il a demande trois
 * mesures. `idx_companies_revalidate` (partiel) concurrence
 * `companies_website_status_index` (plein) : tant que les deux portaient le meme
 * nombre de lignes, le plein gagnait avec un `Filter`, et la garde tombait. Il
 * faut des fiches DEJA revalidees — celles que la production accumule — pour que
 * le partiel soit franchement plus petit et gagne pour une VRAIE raison.
 *
 * ⚠️ Et `ANALYZE` est indispensable : sans lui, la meme garde rend le BON index
 * sur une table ballonnee et le MAUVAIS sur une table neuve. Les statistiques
 * ne sont pas annulees entre deux tests ; une garde qui n'etablit pas les
 * siennes herite de celles de sa voisine.
 *
 * Verifie table neuve ET table ballonnee (8 000 tuples morts), deux passages,
 * plans identiques : les six gardes nomment leur index dans les deux cas.
 */
function fixtureIndexes(string $workspaceId): void
{
    $lot = [];

    for ($i = 1; $i <= 200; $i++) {
        $lot[] = [
            'workspace_id' => $workspaceId,
            'siren' => str_pad((string) (932000000 + $i), 9, '0'),
            'denomination' => 'ZZ FIXTURE ' . $i,
            'signals' => $i >= 22 && $i <= 24 ? '{"google_places_pending":true}' : '{}',
            'metadata' => '{}',
            'field_origins' => '{}',
            'archive_reason' => $i <= 3 ? 'no_email' : null,
            'country_code' => $i >= 4 && $i <= 6 ? 'RO' : 'FR',
            'entity_nature' => $i >= 4 && $i <= 6 ? 'association' : 'entreprise',
            'best_email_confidence' => $i >= 7 && $i <= 9 ? 'A' : null,
            'website_status' => $i >= 10 && $i <= 109 ? 'found' : 'unknown',
            // NULL pour 10..12 seulement : trois fiches `found` jamais
            // revalidees, contre 97 qui le sont. C'est ce rapport qui rend
            // l'index partiel meilleur que l'index plein.
            'website_revalidated_at' => ($i <= 9 || $i >= 13) ? now() : null,
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }

    foreach (array_chunk($lot, 100) as $paquet) {
        DB::table('companies')->insert($paquet);
    }

    // Sans cette ligne, le verdict depend des statistiques laissees par le test
    // precedent. Avec elle, il ne depend que de ce que ce test vient d'ecrire.
    DB::statement('ANALYZE companies');
}

beforeEach(function () {
    $this->workspace = Workspace::create([
        'id' => (string) Str::uuid(), 'slug' => 'ws-idx-' . Str::random(5), 'name' => 'Index', 'settings' => [],
    ]);
    ficheIndex($this->workspace->id, '930000001');
});

// ── TEMOIN — l'instrument discrimine ────────────────────────────────────────

test('G41-008 — TEMOIN : une colonne NUE ne fait citer aucun de ces index', function () {
    fixtureIndexes($this->workspace->id);

    // `legal_form` ne porte aucun index. Meme avec `enable_seqscan = off`, le
    // plan ne peut nommer aucun des index de la liste ci-dessous. Sans ce
    // temoin, les gardes suivantes pourraient etre vertes sur n'importe quoi --
    // par exemple si `EXPLAIN` rendait le meme texte pour toute requete.
    $plan = planForce("SELECT id FROM companies WHERE legal_form = 'SAS' LIMIT 10");

    foreach ([
        'idx_companies_ws_stage_updated_id',
        'idx_companies_archive_reason',
        'idx_companies_best_email_confidence',
        'idx_companies_revalidate',
        'idx_companies_workspace_country_nature',
        'idx_companies_signals',
    ] as $index) {
        $this->assertStringNotContainsString(
            $index,
            $plan,
            "Le plan d'une colonne SANS index cite « {$index} ». La lecture du plan est donc "
            . "fausse, et tout ce fichier avec elle.\n\n" . $plan,
        );
    }
});

// ── LES SEPT QUE L'AUDIT PROPOSAIT DE SUPPRIMER, ET QUI SERVENT ─────────────

test('G41-008 — l index de la file par etape COUVRE la requete du hub', function () {
    // 🔑 CETTE GARDE-CI N'EST PAS UN `EXPLAIN`, ET LA RAISON COMPTE.
    //
    // La requete produit est celle du hub de contacts :
    // `ContactsHubController::buildQuery()` pose `where('lifecycle_stage', …)`
    // (l. 126-128), trie par `updated_at` (l. 140, `SORTS['recent']`) et
    // pagine par CURSEUR, ce qui ajoute `id` en depart-egal. Soit exactement
    // `(workspace_id, lifecycle_stage, updated_at DESC, id DESC)
    //  WHERE deleted_at IS NULL` -- la definition de
    // `idx_companies_ws_stage_updated_id`.
    //
    // Mesure du 2026-08-20 sur `axion_crm_perf4m` (2 800 000 fiches) :
    //     Index Only Scan using idx_companies_ws_stage_updated_id
    //       Index Cond: (workspace_id = … AND lifecycle_stage = 'nouveau')
    //
    // ⚠️ Mais sur la base de TEST, a une poignee de lignes, le planificateur
    // prefere `idx_companies_ws_updated_id` (trois colonnes au lieu de quatre)
    // et filtre l'etape apres coup -- il a raison, une page suffit. Un
    // `EXPLAIN` ici ne prouverait donc rien de stable, et le rendre stable
    // demanderait un SEUIL DE VOLUME : une garde qui mesure l'histoire du banc.
    //
    // On verifie donc la CAPACITE : l'index existe, il couvre les quatre
    // colonnes dans l'ordre, et il est restreint aux fiches non supprimees.
    // C'est ce qui rougit le jour ou quelqu'un le supprime sur la foi de
    // `G41-008` -- et c'est tout ce que cette garde doit faire.
    $definition = definitionIndex('idx_companies_ws_stage_updated_id');

    expect($definition)->not->toBeNull(
        '`idx_companies_ws_stage_updated_id` a disparu. Il sert la file du hub filtree par '
        . 'etape : `ContactsHubController` filtre `lifecycle_stage`, trie par `updated_at` et '
        . 'pagine par curseur (donc `id` en depart-egal). Mesure du 2026-08-20 sur 2 800 000 '
        . 'fiches : `Index Only Scan using idx_companies_ws_stage_updated_id`. '
        . "Le constat G41-008 le range parmi les neuf « que rien ne lit » ; l'EXPLAIN dit le "
        . 'contraire.',
    );

    foreach (['workspace_id', 'lifecycle_stage', 'updated_at DESC', 'id DESC'] as $colonne) {
        $this->assertStringContainsString(
            $colonne,
            (string) $definition,
            "L'index existe mais ne couvre plus « {$colonne} ». Un index qui perd une colonne "
            . "de tete cesse de servir le tri, et le hub retombe sur un tri sur disque.\n\n"
            . $definition,
        );
    }

    $this->assertStringContainsString(
        'deleted_at IS NULL',
        (string) $definition,
        "L'index n'est plus PARTIEL. La corbeille n'a rien a faire dans une file de travail, "
        . "et l'index double de taille pour rien.\n\n" . $definition,
    );
});

test('G41-008 — TEMOIN : la lecture de definition d index ne rend pas n importe quoi', function () {
    // Sans ce temoin, la garde ci-dessus serait verte si `definitionIndex()`
    // rendait une chaine pour n'importe quel nom, ou si `pg_indexes` etait lu
    // de travers.
    expect(definitionIndex('idx_companies_ce_nom_n_existe_pas'))->toBeNull(
        'La lecture de `pg_indexes` rend une definition pour un index INEXISTANT : elle ne '
        . 'prouve rien.',
    );
    expect(definitionIndex('companies_pkey'))->not->toBeNull(
        'La lecture de `pg_indexes` ne voit meme pas la cle primaire : elle ne prouve rien.',
    );
});

test('G41-008 — la prospection internationale EMPLOIE son index', function () {
    fixtureIndexes($this->workspace->id);

    // `filter[country_code]` + `filter[entity_nature]` (CompanyQueryFilters:116-117),
    // servis par l'index PARTIEL `WHERE country_code <> 'FR'`. C'est exactement
    // le cas d'usage que le commentaire du filtre decrit : « cibler les
    // associations de Roumanie sans les melanger aux 4,29 M de fiches
    // francaises ».
    $plan = planForce(
        "SELECT id FROM companies WHERE workspace_id = ? AND country_code = 'RO' AND entity_nature = 'association' LIMIT 50",
        [$this->workspace->id],
    );

    $this->assertStringContainsString('idx_companies_workspace_country_nature', $plan, messageDeRefus('idx_companies_workspace_country_nature', $plan));
});

test('G41-008 — la reprise des fiches archivees EMPLOIE son index', function () {
    fixtureIndexes($this->workspace->id);

    // `RescrapeArchivesCommand:54` (`->where('archive_reason', $reason)`) et
    // `ObservabilityController:174-177` (le decompte par motif d'archivage).
    $plan = planForce(
        "SELECT id FROM companies WHERE workspace_id = ? AND archive_reason = 'no_email' LIMIT 50",
        [$this->workspace->id],
    );

    $this->assertStringContainsString('idx_companies_archive_reason', $plan, messageDeRefus('idx_companies_archive_reason', $plan));
});

test('G41-008 — le filtre par confiance email EMPLOIE son index', function () {
    fixtureIndexes($this->workspace->id);

    // `AllowedFilter::exact('best_email_confidence')`, CompanyQueryFilters:38 --
    // un filtre expose par l'API de liste ET par l'export.
    $plan = planForce("SELECT id FROM companies WHERE best_email_confidence = 'A' LIMIT 50");

    $this->assertStringContainsString('idx_companies_best_email_confidence', $plan, messageDeRefus('idx_companies_best_email_confidence', $plan));
});

test('G41-008 — la revalidation des sites EMPLOIE son index', function () {
    fixtureIndexes($this->workspace->id);

    // `ProspectionFindWebsites:127` : `website_status = 'found'` +
    // `whereNull('website_revalidated_at')` -- exactement le predicat de
    // l'index partiel. C'est ce qui rend la commande REPRENABLE sans balayage.
    $plan = planForce("SELECT id FROM companies WHERE website_status = 'found' AND website_revalidated_at IS NULL LIMIT 50");

    $this->assertStringContainsString('idx_companies_revalidate', $plan, messageDeRefus('idx_companies_revalidate', $plan));
});

test('G41-008 — l index GIN sur signals EST utilisable, par la forme OPERATEUR', function () {
    fixtureIndexes($this->workspace->id);

    // 🔴 CONSTAT NEUF, TROUVE EN VERIFIANT CELUI-CI, ET NON REPARE ICI.
    //
    // L'index `gin (signals)` sert `signals ? 'cle'` et `signals @> '{…}'` :
    // mesure du 2026-08-20 sur 2 800 000 fiches, `Bitmap Index Scan on
    // idx_companies_signals` dans les deux cas.
    //
    // Mais le produit ecrit la forme FONCTION -- `ProspectionEnrich.php:110`,
    // `jsonb_exists(signals, 'google_places_pending')` -- et le planificateur ne
    // rapproche PAS un appel de fonction de l'operateur correspondant : meme
    // requete, meme base, meme instant, `Seq Scan on companies`.
    //
    // C'est la famille de `G41-003` : l'index existe, il pese, et la requete ne
    // peut pas s'en servir parce qu'elle est ECRITE autrement. Le correctif
    // (`jsonb_exists(signals, 'x')` -> `signals ? 'x'`) tient en un caractere,
    // mais `ProspectionEnrich.php` est hors du perimetre de ce lot : c'est
    // SIGNALE au rapport, pas corrige au passage.
    //
    // Cette garde tient l'index en vie. Sans elle, quelqu'un le supprimerait sur
    // la foi de `G41-008` -- et le jour ou la requete serait reecrite
    // correctement, il n'y aurait plus d'index.
    // On interroge par `@>` et non par `?` : ce dernier est aussi le marqueur
    // de valeur liee de PDO, qui l'avale avant meme que Postgres le voie
    // (`SQLSTATE[25P02]`, paye le 2026-08-20). Les deux operateurs sont servis
    // par le meme index GIN `jsonb_ops` -- mesure du meme jour sur 2 800 000
    // fiches, `Bitmap Index Scan on idx_companies_signals` dans les deux cas.
    $plan = planForce('SELECT id FROM companies WHERE signals @> \'{"google_places_pending":true}\'::jsonb LIMIT 50');

    $this->assertStringContainsString('idx_companies_signals', $plan, messageDeRefus('idx_companies_signals', $plan));
});

// ── LE SEUL RETIRE, ET SON TEMOIN ───────────────────────────────────────────

test('G41-008 — idx_companies_geo est retire par la migration 2026_08_20_160000', function () {
    // LE CONSTAT D'ABORD, SUR L'ETAT REEL DE LA BASE MIGREE.
    //
    // `geo_point` est ECRITE par le waterfall (`WaterfallOrchestrator.php:371,
    // :521, :545`) et LUE NULLE PART : aucun `ST_DWithin`, `ST_Distance`,
    // `ST_Within` ni `<->` dans tout le depot. 114 Mo d'index GiST sur
    // `axion_crm_perf4m` -- ou il indexe d'ailleurs 2 800 000 valeurs NULL, un
    // GiST indexant les NULL contrairement a un btree partiel.
    //
    // Coût mesure le 2026-08-20 sur deux tables JUMELLES, meme socle de
    // 300 000 lignes, memes 50 000 insertions, ordre de passage alterne :
    // mediane 27 998 ms avec les 27 index contre 19 068 ms sans celui-ci, soit
    // de l'ordre de -25 %. C'est le seul des neuf que je retire, et le seul
    // dont je puisse dire qu'AUCUNE requete du depot ne peut le lire.
    //
    // ROUGE VU : en neutralisant la migration `2026_08_20_160000`, cette
    // assertion echoue -- « Failed asserting that true is false ».
    //
    // Le jour ou une recherche « les entreprises autour d'ici » est ecrite :
    // `php artisan migrate:rollback` repose l'index tel quel, en
    // `CONCURRENTLY`, sans verrou d'ecriture.
    expect(indexPresent('idx_companies_geo'))->toBeFalse(
        "L'index `idx_companies_geo` est present. S'il a ete repose pour servir une recherche "
        . 'geographique, tres bien -- mais alors annulez la migration `2026_08_20_160000` '
        . "plutot que de le recreer a cote, et retirez cette garde. S'il est revenu par "
        . "accident, il coute ~25 % du temps d'insertion en lot pour zero lecture.",
    );

    // TEMOIN. Sans lui, « l'index est absent » serait vrai aussi sur une base
    // sans table, sur un nom mal orthographie, ou si la lecture de `pg_indexes`
    // ne rendait jamais rien.
    //
    // Le `CREATE INDEX` est ici SANS `CONCURRENTLY` : la transaction du test
    // l'exige, et elle le defait toute seule. La migration, elle, l'emploie --
    // la production ne peut pas s'offrir un verrou d'ecriture sur `companies`.
    DB::statement('CREATE INDEX idx_companies_geo ON companies USING gist (geo_point)');
    expect(indexPresent('idx_companies_geo'))->toBeTrue(
        'Le controle ne voit pas un index qui VIENT d etre pose : il ne prouve rien.',
    );

    DB::statement('DROP INDEX idx_companies_geo');
    expect(indexPresent('idx_companies_geo'))->toBeFalse(
        'Le controle voit encore un index qui vient d etre retire : il ne prouve rien.',
    );
});

// ── Outils ──────────────────────────────────────────────────────────────────

function definitionIndex(string $nom): ?string
{
    $ligne = DB::table('pg_indexes')
        ->where('schemaname', 'public')
        ->where('tablename', 'companies')
        ->where('indexname', $nom)
        ->value('indexdef');

    return is_string($ligne) ? $ligne : null;
}

function indexPresent(string $nom): bool
{
    return DB::table('pg_indexes')
        ->where('schemaname', 'public')
        ->where('tablename', 'companies')
        ->where('indexname', $nom)
        ->exists();
}

function messageDeRefus(string $index, string $plan): string
{
    return "Le plan de la requete PRODUIT n'emploie plus « {$index} ».\n\n{$plan}\n\n"
        . 'Cet index figure dans les neuf que le constat G41-008 propose de supprimer. '
        . 'Verification du 2026-08-20 par EXPLAIN sur `axion_crm_perf4m` (2 800 000 fiches, '
        . "la base de mesure de l'audit) : SEPT des neuf sont employes par une requete reelle "
        . "du produit. L'instrument de l'audit -- `pg_stat_user_indexes.idx_scan` apres une "
        . "campagne de 32 requetes d'ECRAN -- ne pouvait pas les voir : ceux-la servent des "
        . "commandes en lot, des filtres rares et une recherche.\n"
        . "Si cet index vient d'etre supprime : le remettre. Si c'est la REQUETE qui a change, "
        . "mettre cette garde a jour avec la nouvelle -- et pas l'inverse.";
}
