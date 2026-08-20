<?php

/**
 * GARDE : LA CONSOLE NE DOIT PAS GELER AU VOLUME DE PRODUCTION — `G41-001`,
 * `G41-002`, `G41-003` / `G41-004`.
 *
 * ── CE QUE CETTE GARDE MESURE, ET POURQUOI ELLE PASSE PAR LE VRAI ENDPOINT ──
 *
 * `tests/Feature/Infra/IndexServentLesRequetesTest.php` a ouvert la voie :
 * demander à **Postgres lui-même**, par `EXPLAIN`, si le plan emploie un index.
 * Cette garde-ci va un cran plus loin, et le cran est la leçon de `G41-003`.
 *
 * 🔴 La garde de référence écrit la requête À LA MAIN :
 *
 *       EXPLAIN SELECT id FROM companies WHERE denomination_normalized ILIKE …
 *
 * Elle a donc mesuré la BONNE colonne pendant que le produit interrogeait la
 * MAUVAISE. Elle était verte, et `CompanyQueryFilters` faisait toujours un
 * balayage séquentiel. **Une garde qui réécrit la requête du produit ne garde
 * pas le produit, elle garde sa propre réécriture.**
 *
 * Celle-ci appelle donc l'endpoint HTTP réel, INTERCEPTE le SQL effectivement
 * émis (`DB::listen`), et fait expliquer CE SQL-LÀ. Si demain quelqu'un change
 * la colonne interrogée dans le contrôleur, la garde le voit.
 *
 * ── LES TROIS DÉFAUTS, MESURÉS AVANT CORRECTIF (150 000 fiches) ─────────────
 *
 *   `G41-001` écran d'accueil (`temperature=actifs`, LE DÉFAUT) :
 *        le `EXISTS` joint `tags` ligne à ligne. Postgres le « hashe » et
 *        BALAYE `company_tag` EN ENTIER : Seq Scan de 300 300 lignes, 67 ms.
 *        En production `company_tag` porte plusieurs étiquettes par fiche
 *        au-dessus de 4,29 M de fiches — et le hash finit par déborder
 *        `work_mem`, ce qui fait retomber Postgres en ré-exécution PAR LIGNE.
 *        C'est ça, les 3 minutes : pas une pente, une falaise.
 *
 *   `G41-002` recherche du hub : `denomination ILIKE 'x%'` → Parallel Seq Scan,
 *        103 ms. Le commentaire qui la justifiait invoquait un index B-tree sur
 *        `denomination` : **cet index n'existe pas**, et n'a jamais existé.
 *
 *   `G41-003`/`G41-004` filtre `filter[denomination]` → Seq Scan, 145 ms.
 *
 * ── POURQUOI CETTE GARDE PEUPLE, ET POURQUOI ELLE ÉCHOUE SI ELLE NE PEUT PAS ─
 *
 * Sur une table de trois lignes, le planificateur balaie — et il a RAISON. Une
 * mesure de performance faite sur une table vide ne mesure rien. La garde
 * peuple donc ce qu'elle mesure, par `generate_series` (une boucle PHP mettrait
 * des minutes), fait `ANALYZE`, et **vérifie le volume atteint** avant de
 * conclure quoi que ce soit.
 *
 * 20 000 : seuil VÉRIFIÉ À LA MESURE, pas choisi au jugé. À ce volume, et sur
 * le code d'avant correctif, les trois défauts se reproduisent tous les trois
 * (Seq Scan sur `company_tag`, Seq Scan sur `companies` × 2).
 */

use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/**
 * En dessous, le planificateur préfère un balayage — et il a raison. Vérifié à
 * la mesure : c'est le volume auquel les trois défauts se reproduisent.
 */
const VOLUME_HUB = 20000;

beforeEach(function () {
    config(['crm.console_v2' => true]);

    $this->espace = Workspace::create([
        'id' => (string) Str::uuid(),
        'slug' => 'ws-volume-' . Str::random(6),
        'name' => 'Volume',
        'settings' => [],
    ]);

    $utilisateur = User::create([
        'id' => (string) Str::uuid(),
        'email' => 'volume@example.invalid',
        'name' => 'Operateur volume',
        'password_hash' => Hash::make('PasswordTest12345!'),
        'current_workspace_id' => $this->espace->id,
        'first_login_completed_at' => now(),
    ]);

    DB::table('user_workspaces')->insertOrIgnore([
        'user_id' => $utilisateur->id,
        'workspace_id' => $this->espace->id,
        'role_slug' => 'owner',
        'invited_at' => now(),
        'joined_at' => now(),
    ]);

    $this->actingAs($utilisateur);
});

/**
 * ⚠️ CE FICHIER GROSSIT `companies` POUR LE RESTE DU PROCESSUS — ET C'EST
 * POURQUOI IL S'APPELLE `Volume…`, DONC PASSE APRÈS `IndexServentLesRequetes…`.
 *
 * Une garde de volume laisse une trace qu'AUCUN nettoyage en base ne peut
 * effacer depuis l'intérieur d'un test :
 *
 *   - `RefreshDatabase` annule les LIGNES par un `ROLLBACK`, mais un rollback
 *     **ne rend pas les pages au fichier**. `companies` reste physiquement
 *     grosse jusqu'à un `VACUUM` — lequel est interdit dans une transaction,
 *     et `RefreshDatabase` en tient une ouverte en permanence ;
 *   - le planificateur lit la TAILLE RÉELLE du fichier (`RelationGetNumberOfBlocks`),
 *     pas seulement `reltuples`. Un `DELETE` + `ANALYZE` ne le trompe donc pas :
 *     essayé, sans effet.
 *
 * Conséquence MESURÉE : `IndexServentLesRequetesTest`, dont le témoin vérifie
 * qu'une table minuscule se BALAIE, passe au rouge dès qu'un fichier de volume
 * tourne avant lui — Postgres voit une table de 20 000 lignes et choisit un
 * `Bitmap Heap Scan`, ce qui est le bon choix pour ce qu'on lui a laissé.
 *
 * Le nom de ce fichier est donc porteur : `V` passe après `I`, le témoin
 * « table minuscule » de l'autre garde s'exécute sur une base encore propre.
 * **Ne renommer ce fichier qu'en vérifiant cet ordre.**
 *
 * (Aucun témoin « sans volume » ici, pour la même raison : il serait le premier
 * à rougir sur le volume laissé par le fichier précédent. La discrimination de
 * cette garde est prouvée autrement — cf. « la garde DETECTE le defaut ».)
 */

// ── Outillage ────────────────────────────────────────────────────────────────

/**
 * Peuple `companies` + `company_tag` au volume, par `generate_series`.
 *
 * La forme des données REPRODUIT la production, et chaque détail compte :
 *
 *   - 95 % des fiches à l'étape « nouveau » : c'est la masse froide (4,29 M),
 *     celle que la vue par défaut doit écarter — donc celle que le `EXISTS`
 *     doit évaluer, une par une, dans le code d'avant correctif ;
 *   - CHAQUE fiche porte une étiquette `src:scraping-*`, une poignée seulement
 *     porte une provenance humaine. `company_tag` est donc PLUS GROSSE que
 *     `companies`, exactement comme en production. C'est ce qui rend son
 *     balayage ruineux, et c'est invisible si on ne peuple qu'un tag par fiche.
 */
function hubPeupler(string $espaceId): void
{
    DB::statement("
        INSERT INTO companies (workspace_id, denomination, siren, relation_type,
                               lifecycle_stage, legal_basis, discovery_source,
                               quality_score, signals, metadata, field_origins,
                               created_at, updated_at)
        SELECT ?, 'Entreprise ' || md5(g::text), lpad((100000000 + g)::text, 9, '0'),
               'prospect',
               CASE WHEN g % 20 = 0 THEN 'qualifie' ELSE 'nouveau' END,
               'legitimate_interest_b2b', 'site', 0, '{}', '{}', '{}', now(), now()
          FROM generate_series(1, ?) g
    ", [$espaceId, VOLUME_HUB]);

    // Une fiche ACCENTUEE et ARTICULEE, pour la garde de justesse plus bas.
    DB::table('companies')->insert([
        'workspace_id' => $espaceId,
        'denomination' => 'La Boulangerie Crème Brûlée',
        'siren' => '999999999',
        'relation_type' => 'prospect',
        'lifecycle_stage' => 'nouveau',
        'legal_basis' => 'legitimate_interest_b2b',
        'discovery_source' => 'site',
        'quality_score' => 0,
        'signals' => '{}', 'metadata' => '{}', 'field_origins' => '{}',
        'created_at' => now(), 'updated_at' => now(),
    ]);

    foreach (['src:scraping-pages-jaunes', 'src:site-formulaire'] as $slug) {
        DB::table('tags')->insert([
            'workspace_id' => $espaceId,
            'slug' => $slug, 'name' => $slug,
            'category' => 'custom', 'kind' => 'auto',
            'rules' => '{}', 'is_locked' => false,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    // Toutes scrapees…
    DB::statement("
        INSERT INTO company_tag (company_id, tag_id, workspace_id, assigned_at, assigned_by)
        SELECT c.id, t.id, c.workspace_id, now(), 'backfill-src'
          FROM companies c
          JOIN tags t ON t.workspace_id = c.workspace_id AND t.slug = 'src:scraping-pages-jaunes'
         WHERE c.workspace_id = ?
    ", [$espaceId]);

    // …une sur 500 porte EN PLUS une provenance humaine.
    DB::statement("
        INSERT INTO company_tag (company_id, tag_id, workspace_id, assigned_at, assigned_by)
        SELECT c.id, t.id, c.workspace_id, now(), 'user'
          FROM companies c
          JOIN tags t ON t.workspace_id = c.workspace_id AND t.slug = 'src:site-formulaire'
         WHERE c.workspace_id = ? AND c.id % 500 = 0
    ", [$espaceId]);

    DB::statement('ANALYZE companies');
    DB::statement('ANALYZE company_tag');
    DB::statement('ANALYZE tags');
}

/**
 * Appelle l'endpoint et rend le plan Postgres du SELECT réellement émis sur
 * `companies`.
 *
 * C'est le cœur de la méthode : on n'invente pas la requête, on INTERCEPTE
 * celle du produit. `substituteBindingsIntoRawSql` réinjecte les valeurs pour
 * que `EXPLAIN` planifie sur les vraies constantes — un plan générique sur des
 * paramètres liés ne dirait pas la même chose.
 */
function hubPlanDeLEndpoint(object $test, string $url): string
{
    $sql = hubSqlDeLEndpoint($test, $url);

    return collect(DB::select('EXPLAIN ' . $sql))
        ->map(fn ($ligne) => (array) $ligne)
        ->flatten()
        ->implode("\n");
}

/**
 * Le SQL littéral réellement émis sur `companies` par l'endpoint.
 *
 * On n'invente pas la requête, on INTERCEPTE celle du produit :
 * `IndexServentLesRequetesTest` a été verte pendant que le produit interrogeait
 * la mauvaise colonne, précisément parce qu'elle réécrivait la requête à la
 * main. `substituteBindingsIntoRawSql` réinjecte les valeurs, pour qu'`EXPLAIN`
 * planifie sur les vraies constantes.
 */
function hubSqlDeLEndpoint(object $test, string $url): string
{
    /** @var list<array{sql: string, bindings: array<int, mixed>}> $requetes */
    $requetes = [];

    DB::listen(function ($requete) use (&$requetes): void {
        $requetes[] = ['sql' => $requete->sql, 'bindings' => $requete->bindings];
    });

    $test->getJson($url)->assertOk();

    $grammaire = DB::connection()->getQueryGrammar();

    // La requête de liste : un SELECT sur `companies`. Les chargements liés
    // (`contacts`, `tags`) partent sur d'AUTRES tables et ne matchent pas.
    $candidates = array_values(array_filter(
        $requetes,
        static fn (array $r): bool => str_starts_with(strtolower(ltrim($r['sql'])), 'select')
            && str_contains($r['sql'], 'from "companies"'),
    ));

    if ($candidates === []) {
        $vues = implode("\n  - ", array_map(static fn (array $r): string => $r['sql'], $requetes));

        throw new RuntimeException(
            "Aucun SELECT sur `companies` intercepte pour {$url}. La garde ne mesure donc RIEN.\n"
            . "Requetes vues :\n  - {$vues}",
        );
    }

    // La dernière : les précédentes sont les vérifications d'accès.
    $derniere = end($candidates);

    return $grammaire->substituteBindingsIntoRawSql($derniere['sql'], $derniere['bindings']);
}

// ── TÉMOINS : la garde sait-elle ce qu'elle fait ? ───────────────────────────

test('TEMOIN — le volume est REELLEMENT atteint', function () {
    hubPeupler($this->espace->id);

    // Une garde de performance sur une table vide est verte et ne garde rien.
    // On refuse de conclure si le peuplement n'a pas eu lieu.
    expect((int) DB::table('companies')->count())->toBeGreaterThanOrEqual(VOLUME_HUB);
    expect((int) DB::table('company_tag')->count())->toBeGreaterThan(VOLUME_HUB);
});

test('TEMOIN — la garde DETECTE le defaut : la forme d avant correctif balaie bien', function () {
    hubPeupler($this->espace->id);

    // C'est la requete que `applyTemperature` emettait AVANT correctif, mot
    // pour mot. Si elle cessait de balayer `company_tag`, l'assertion des
    // gardes ci-dessous deviendrait increvable -- donc muette.
    $plan = collect(DB::select("
        EXPLAIN SELECT c.id FROM companies c
         WHERE c.deleted_at IS NULL AND c.workspace_id = ?
           AND ( c.lifecycle_stage <> 'nouveau'
              OR EXISTS (SELECT 1 FROM company_tag ct JOIN tags t ON t.id = ct.tag_id
                          WHERE ct.company_id = c.id
                            AND t.slug NOT LIKE 'src:scraping-%' AND t.slug LIKE 'src:%') )
         ORDER BY c.updated_at DESC, c.id DESC LIMIT 25
    ", [$this->espace->id]))->map(fn ($l) => (array) $l)->flatten()->implode("\n");

    $this->assertStringContainsString(
        'Seq Scan on company_tag',
        $plan,
        "La forme d'avant correctif ne balaie plus `company_tag` a ce volume. L'assertion "
        . 'de la garde G41-001 ne discrimine donc plus rien : il faut remonter VOLUME_HUB '
        . "jusqu'a ce que le defaut se reproduise.\n\n{$plan}",
    );
});

// ── G41-001 : l'écran d'accueil ──────────────────────────────────────────────

test('G41-001 — l ecran d accueil ne balaie pas la table des etiquettes', function () {
    hubPeupler($this->espace->id);

    // AUCUN parametre : c'est LA requete de l'ecran d'accueil, celle qui met
    // 3 minutes en production. `temperature` vaut `actifs` par defaut.
    $plan = hubPlanDeLEndpoint($this, '/api/v1/crm/contacts-hub');

    $this->assertStringNotContainsString(
        'Seq Scan on company_tag',
        $plan,
        "C'est le constat G41-001. Le plan de l'ecran d'accueil BALAIE `company_tag` en "
        . "entier.\n\nEn production cette table porte plusieurs etiquettes par fiche "
        . 'au-dessus de 4,29 M de fiches : le hash deborde `work_mem` et Postgres retombe '
        . "en re-execution PAR LIGNE. C'est la falaise des 3 minutes.\n\n"
        . "Le sous-select ne doit PAS joindre `tags` : les identifiants d'etiquettes se "
        . "resolvent EN AMONT, en une requete, et sont passes en valeurs litterales.\n\n{$plan}",
    );

    // La moitie positive : sans l'index, la liste litterale seule retombe en
    // Parallel Seq Scan (mesure : 80,3 ms contre 0,90 ms). Les deux moities du
    // correctif sont necessaires, et cette assertion garde la seconde.
    $this->assertStringContainsString(
        'idx_company_tag_tag',
        $plan,
        "Le plan n'emploie pas l'index `(tag_id, company_id)` de `company_tag`.\n\n"
        . "Mesure sur 150 000 fiches / 300 300 etiquettes :\n"
        . "  liste litterale SANS index ... Parallel Seq Scan   80,3 ms\n"
        . "  liste litterale AVEC index ... Index Only Scan      0,90 ms\n\n"
        . "L'index SEUL ne suffit pas non plus (73,8 ms tant que le sous-select joint "
        . "`tags`) : il faut les DEUX moities.\n\n{$plan}",
    );
});

// ── G41-002 : la recherche du hub ────────────────────────────────────────────

test('G41-002 — la recherche du hub interroge la colonne INDEXEE', function () {
    hubPeupler($this->espace->id);

    $sql = hubSqlDeLEndpoint($this, '/api/v1/crm/contacts-hub?q=c3a4');

    // La moitie du constat qui est FERMEE : la colonne interrogee. Le
    // commentaire qui justifiait `denomination` invoquait « un index B-tree
    // pose par la migration 2026_07_09_000004 » -- index qui n'existe pas sur
    // cette colonne (verifie a `\d companies` le 2026-08-20 : `denomination` ne
    // porte AUCUN index ; la migration citee cree `idx_companies_denom_btree`
    // sur `denomination_normalized`, une AUTRE colonne).
    $this->assertStringContainsString(
        'denomination_normalized',
        $sql,
        "La recherche du hub n'interroge pas `denomination_normalized`, la seule des deux "
        . "colonnes que couvre un index.\n\n{$sql}",
    );

    // Et le TEMOIN de cette assertion : l'ancienne forme ne doit plus etre la.
    // Sans ce controle, ajouter la colonne normalisee SANS retirer l'ancienne
    // condition passerait au vert en ne changeant rien.
    $this->assertStringNotContainsString(
        'lower("denomination")',
        $sql,
        "La condition d'origine sur `denomination` est TOUJOURS emise. Elle balaie "
        . '(Seq Scan, 145,7 ms sur 150 000 lignes) et annule le benefice de la '
        . "colonne normalisee.\n\n{$sql}",
    );
});

test('G41-002 — RESTE OUVERT : le OU multi-champs annule l index trigrammes', function () {
    hubPeupler($this->espace->id);

    $plan = hubPlanDeLEndpoint($this, '/api/v1/crm/contacts-hub?q=c3a4');

    // ⚠️ CE TEST EST DELIBEREMENT « INCOMPLET », PAS VERT ET PAS ROUGE.
    //
    // Il documente un defaut MESURE et NON REPARE. Le declarer vert serait un
    // mensonge ; le laisser rouge ferait de la suite un feu permanent que
    // quelqu'un finirait par desactiver -- et le defaut deviendrait invisible.
    //
    // CE QUI A ETE REPARE (constat G41-002, et c'est prouve par les deux
    // gardes voisines) : la colonne interrogee, et le commentaire qui invoquait
    // un index inexistant.
    //
    // CE QUI RESTE : `applySearch` cherche sur TROIS champs en OU --
    // denomination, SIREN, et un `EXISTS` correle sur `contacts`. Postgres ne
    // sait pas combiner un index trigrammes avec un sous-select correle dans un
    // `BitmapOr` : il retombe sur un parcours de `idx_companies_ws_updated_id`
    // avec filtre. Mesure sur 150 000 fiches, MEME plan avant et apres :
    //
    //     avant (denomination brute + OU contacts) ..... 354,3 ms
    //     apres (colonne normalisee + OU contacts) ..... 157,7 ms
    //     branche denomination SEULE ...................   2,0 ms
    //     terme ne matchant RIEN, avec le OU ........... 531,7 ms
    //     terme ne matchant RIEN, sans le OU ...........   2,7 ms
    //
    // Le gain 354 → 158 ms vient de ce qu'un `%x%` matche PLUS TOT qu'un
    // prefixe, pas d'un index : le plan est structurellement identique.
    //
    // Ce qui le debloquerait, mesure : resoudre la branche `contacts` EN AMONT
    // en une liste d'identifiants LITTERAUX, comme l'a fait `applyTemperature`.
    // Postgres rend alors un vrai `BitmapOr` (trigrammes + pkey) -- 8,9 ms.
    // Mais cela suppose soit un PLAFOND sur cette liste (changement de
    // semantique : une fiche trouvable seulement par un contact au-dela du
    // plafond disparaitrait), soit un repli sur la forme actuelle. Et la
    // branche `email` reste de toute facon sans index utilisable :
    // `idx_contacts_email` porte sur `email` brut, pas sur `lower(email)`.
    //
    // Aucun constat de ce lot ne nomme `contacts` : l'arbitrage revient a Will.
    $this->assertStringNotContainsString(
        'idx_companies_denomination_trgm',
        $plan,
        "BONNE NOUVELLE, et il faut la traiter : le plan emploie DESORMAIS l'index "
        . 'trigrammes. Le defaut documente ici est donc referme -- retirer le '
        . '`markTestIncomplete` ci-dessous et transformer cette garde en assertion '
        . "positive.\n\n{$plan}",
    );

    $this->markTestIncomplete(
        "G41-002 n'est ferme qu'a moitie. La colonne interrogee est corrigee (gardes "
        . 'voisines, vertes), mais le OU multi-champs de `applySearch` empeche toujours '
        . "l'emploi de l'index trigrammes : 157,7 ms sur 150 000 fiches, 531,7 ms pour un "
        . 'terme sans resultat, contre 2,0 ms pour la branche denomination seule. '
        . 'Voir le commentaire ci-dessus pour la piste mesuree et son arbitrage.',
    );
});

// ── G41-003 / G41-004 : le filtre par dénomination ───────────────────────────

test('G41-003 — le filtre par denomination EMPLOIE l index trigrammes', function () {
    hubPeupler($this->espace->id);

    $plan = hubPlanDeLEndpoint($this, '/api/v1/crm/contacts-hub?' . http_build_query([
        'filter' => ['denomination' => 'c3a4'],
    ]));

    $this->assertStringContainsString(
        'idx_companies_denomination_trgm',
        $plan,
        "C'est le constat G41-003/G41-004 : « le filtre par denomination coute 65 s au volume "
        . "de production, et l'index de 110 Mo cense le servir porte sur une AUTRE "
        . "colonne ».\n\n"
        . "`AllowedFilter::partial('denomination')` emet `denomination ILIKE '%x%'`, qui ne "
        . 'peut employer aucun index existant. Mesure sur 150 000 lignes : Seq Scan '
        . "145,7 ms contre Bitmap Index Scan 2,7 ms sur `denomination_normalized`.\n\n{$plan}",
    );
});

test('G41-004 — la liste entreprises partage le correctif du filtre', function () {
    hubPeupler($this->espace->id);

    // MEME liste de filtres (`CompanyQueryFilters`), donc meme defaut et meme
    // correctif. Le patron A-011 de ce depot est « le correctif existe deja
    // quelque part et n'a pas ete porte ailleurs » : on garde les DEUX sites.
    $plan = hubPlanDeLEndpoint($this, '/api/v1/companies?' . http_build_query([
        'filter' => ['denomination' => 'c3a4'],
    ]));

    $this->assertStringContainsString(
        'idx_companies_denomination_trgm',
        $plan,
        'La liste entreprises (`CompaniesController`) partage `CompanyQueryFilters` avec le '
        . "hub. Si cette garde rougit seule, c'est que le correctif n'a ete porte que sur un "
        . "des deux sites.\n\n{$plan}",
    );
});

// ── JUSTESSE : un correctif de vitesse ne doit rien casser ───────────────────

test('G41-002 — chercher sur la colonne normalisee TROUVE accents et articles', function () {
    hubPeupler($this->espace->id);

    // ⚠️ CETTE GARDE EST LE GARDE-FOU DU CORRECTIF LUI-MEME.
    //
    // `denomination_normalized` est GENERATED ALWAYS AS
    // (normalize_name(denomination)) : minuscules, SANS ACCENTS, et les
    // articles (de|du|la|le|les|d|l) SONT RETIRES. « La Boulangerie Creme
    // Brulee » y est stockee « boulangerie creme brulee ».
    //
    // Basculer la recherche sur cette colonne SANS normaliser le terme rend
    // donc la recherche MUETTE sur ce que les gens tapent. Mesure faite ce
    // jour, sur la fiche « La Boulangerie Creme Brulee » :
    //
    //     denomination_normalized ILIKE '%Creme%'  (accent) ....... 0 resultat
    //     denomination_normalized ILIKE '%La Boulangerie%' ........ 0 resultat
    //     denomination_normalized ILIKE '%'||normalize_name(…)||'%' 1 resultat
    //
    // On echangerait un defaut de lenteur contre un defaut de justesse, qui est
    // PIRE : une recherche lente se voit, une recherche muette se croit vide.
    foreach (['Creme', 'Crème', 'creme brulee', 'La Boulangerie', 'BOULANGERIE'] as $saisie) {
        $reponse = $this->getJson('/api/v1/crm/contacts-hub?temperature=tous&' . http_build_query([
            'q' => $saisie,
        ]))->assertOk();

        $denominations = array_column($reponse->json('data') ?? [], 'denomination');

        $this->assertContains(
            'La Boulangerie Crème Brûlée',
            $denominations,
            "La saisie « {$saisie} » ne retrouve pas « La Boulangerie Creme Brulee ».\n\n"
            . 'La colonne interrogee est normalisee (sans accent, articles retires) : le '
            . "TERME doit l'etre par la MEME fonction SQL `normalize_name()`, sans quoi la "
            . 'recherche devient muette sur les accents et sur tout nom commencant par un '
            . 'article.',
        );
    }
});

test('G41-003 — le filtre par denomination TROUVE lui aussi accents et articles', function () {
    hubPeupler($this->espace->id);

    foreach (['Creme', 'Crème', 'La Boulangerie'] as $saisie) {
        $reponse = $this->getJson('/api/v1/crm/contacts-hub?temperature=tous&' . http_build_query([
            'filter' => ['denomination' => $saisie],
        ]))->assertOk();

        $denominations = array_column($reponse->json('data') ?? [], 'denomination');

        $this->assertContains(
            'La Boulangerie Crème Brûlée',
            $denominations,
            "Le filtre `filter[denomination]={$saisie}` ne retrouve pas la fiche accentuee. "
            . 'Meme cause, meme remede que la recherche du hub.',
        );
    }
});

test('G41-001 — la vue par defaut garde EXACTEMENT le meme decoupage chaud/froid', function () {
    // Le correctif de `applyTemperature` change la FORME de la requete, pas sa
    // definition. Cette garde fixe la definition (audit d'harmonisation §B.2) :
    //   ACTIF = etape au-dela de « nouveau », OU une provenance non-scraping ;
    //   FROID = etape « nouveau » ET uniquement des `src:scraping-*`.
    $tags = [];
    foreach (['src:scraping-pages-jaunes', 'src:site-formulaire'] as $slug) {
        $tags[$slug] = (int) DB::table('tags')->insertGetId([
            'workspace_id' => $this->espace->id,
            'slug' => $slug, 'name' => $slug,
            'category' => 'custom', 'kind' => 'auto',
            'rules' => '{}', 'is_locked' => false,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    $fiche = function (string $siren, string $etape, ?string $tag): int {
        $id = (int) DB::table('companies')->insertGetId([
            'workspace_id' => $this->espace->id,
            'siren' => $siren, 'denomination' => 'ZZ ' . $siren,
            'relation_type' => 'prospect', 'lifecycle_stage' => $etape,
            'legal_basis' => 'legitimate_interest_b2b', 'discovery_source' => 'site',
            'quality_score' => 0, 'signals' => '{}', 'metadata' => '{}',
            'field_origins' => '{}', 'created_at' => now(), 'updated_at' => now(),
        ]);

        if ($tag !== null) {
            DB::table('company_tag')->insert([
                'company_id' => $id, 'tag_id' => $tag,
                'workspace_id' => $this->espace->id,
                'assigned_at' => now(), 'assigned_by' => 'user',
            ]);
        }

        return $id;
    };

    // Froide : « nouveau » + scraping seul.
    $fiche('900000301', 'nouveau', $tags['src:scraping-pages-jaunes']);
    // Chaude par l'ETAPE.
    $fiche('900000302', 'qualifie', $tags['src:scraping-pages-jaunes']);
    // Chaude par la PROVENANCE, bien qu'a l'etape « nouveau ».
    $fiche('900000303', 'nouveau', $tags['src:site-formulaire']);
    // « nouveau » SANS AUCUNE etiquette : froide (pas de provenance humaine).
    $fiche('900000304', 'nouveau', null);

    $sirens = function (string $url): array {
        $data = $this->getJson($url)->assertOk()->json('data') ?? [];

        return array_values(array_filter(
            array_map(static fn (array $l): string => (string) ($l['siren'] ?? ''), $data),
            static fn (string $s): bool => str_starts_with($s, '9000003'),
        ));
    };

    $actifs = $sirens('/api/v1/crm/contacts-hub');
    sort($actifs);
    expect($actifs)->toBe(['900000302', '900000303']);

    $froids = $sirens('/api/v1/crm/contacts-hub?temperature=froids');
    sort($froids);
    expect($froids)->toBe(['900000301', '900000304']);

    $tous = $sirens('/api/v1/crm/contacts-hub?temperature=tous');
    expect($tous)->toHaveCount(4);
});
