<?php

/**
 * GARDE : LA RECHERCHE PAR E-MAIL DES CHEMINS RGPD ET DE DEDUPLICATION DOIT
 * EMPLOYER UN INDEX — constat `C21-001` (S1).
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * LE DEFAUT, ET IL EST DE LA MEME FAMILLE QUE `G41-003`
 * ═══════════════════════════════════════════════════════════════════════════
 *
 * `contacts.email` et `candidates.email` sont de type **`citext`**, et chacune
 * portait deja un index :
 *
 *     idx_contacts_email    btree (email) WHERE email IS NOT NULL
 *     idx_candidates_email  btree (email) WHERE email IS NOT NULL
 *
 * Mais les chemins RGPD et la deduplication du scraping n'interrogeaient pas
 * `email` : ils interrogeaient une EXPRESSION.
 *
 *     ->whereRaw('lower(email::text) = ?', [$email])
 *
 * Deux operations d'affilee — transtypage `citext → text`, puis `lower()` — et
 * l'index n'est plus reconnaissable : il porte sur la COLONNE. Postgres n'a
 * plus que le balayage sequentiel. L'index existe, il pese, `\d` le montre, et
 * il ne sert pas : `G41-003` mot pour mot.
 *
 * Le transtypage etait INUTILE. `citext` compare deja sans egard a la casse :
 * `email = 'x@y.fr'` trouve `X@Y.FR`. Le `lower()` ne changeait rien a la
 * SEMANTIQUE — les appelants passent d'ailleurs tous une adresse deja mise en
 * minuscules par `mb_strtolower()` — il ne faisait que tuer l'index.
 *
 * Sept requetes touchees, sur trois tables :
 *   - `SiteGdprService` : 5 (export contacts, export candidates, effacement
 *     contacts, effacement health_practitioners, effacement candidates) ;
 *   - `ScrapedRecordIngestService::upsertContact()` : 1, jouee A CHAQUE fiche
 *     ingeree par le scraping ;
 *   - `health_practitioners` n'avait EN OUTRE aucun index sur `email` :
 *     migration `2026_08_20_150000_index_email_praticiens_sante.php`.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * CE QUE CETTE GARDE MESURE, ET POURQUOI ELLE N'EST NI UN `grep` NI UN SQL
 * ECRIT A LA MAIN
 * ═══════════════════════════════════════════════════════════════════════════
 *
 * Une garde qui EXPLAIN un SQL recopie a la main ne prouve rien : elle mesure
 * ce que le testeur a tape, pas ce que le service emet. Celle-ci fait donc
 * TOURNER les services reels, INTERCEPTE le SQL que Postgres recoit
 * (`DB::listen`), et redemande a Postgres — par `EXPLAIN`, sur CE texte-la et
 * avec CES parametres-la — s'il emploie un index.
 *
 * ⚠️ Elle a besoin d'un VOLUME. Sur une table de trois lignes le planificateur
 * balaie, et il a raison. Elle peuple donc ce qu'elle mesure et ECHOUE si le
 * volume n'est pas atteint : une mesure de performance sur une table vide ne
 * mesure rien. Methode reprise de `IndexServentLesRequetesTest.php`.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * MESURE, 20 000 LIGNES PAR TABLE, BANC DU 2026-08-20 (conteneur `a35r`)
 * ═══════════════════════════════════════════════════════════════════════════
 *
 * `EXPLAIN (ANALYZE, BUFFERS)`, cache chaud, troisieme passe. A gauche la forme
 * `lower(email::text)`, a droite `email` (citext) :
 *
 *   dedup du scraping (contacts, e-mail seul)
 *       AVANT  Seq Scan                        11,216 ms   589 tampons
 *       APRES  Index Scan idx_contacts_email    0,046 ms     4 tampons   x 244
 *
 *   art. 15 / art. 17 sur `contacts` (OU person_key + e-mail)
 *       AVANT  Seq Scan                        13,172 ms   589 tampons
 *       APRES  BitmapOr(idx_contacts_workspace_person_key,
 *                       idx_contacts_email)     0,094 ms     6 tampons   x 140
 *
 *   art. 17 sur `health_practitioners` (SANTE, art. 9)
 *       AVANT  Seq Scan                        11,764 ms   409 tampons
 *       APRES  Index Scan idx_health_practitioners_email
 *                                               0,034 ms     4 tampons   x 346
 *
 *   art. 15 / art. 17 sur `candidates` (vivier)
 *       AVANT  Seq Scan                         9,500 ms   487 tampons
 *       APRES  BitmapOr(idx_candidates_workspace_person_key,
 *                       idx_candidates_email)   0,049 ms     6 tampons   x 194
 *
 * ⚠️ LE CONSTAT ANNONCE « 1 070 ms de balayage sequentiel par requete ». Je ne
 * reproduis pas ce chiffre : sur 20 000 lignes je mesure 9 a 13 ms. Il n'est
 * pas incoherent pour autant — le cout d'un balayage est LINEAIRE, et 13 ms
 * pour 20 000 lignes donne ~1 070 ms vers 1,6 million. Je ne peux pas le
 * verifier au volume de la production, et je ne le recopie donc pas comme s'il
 * etait mesure ici. Ce qui EST mesure : le plan passe du balayage a l'index, et
 * les tampons lus tombent d'un facteur 100.
 */

use App\Crm\Rgpd\SiteGdprService;
use App\Crm\Scraping\ScrapedRecord;
use App\Crm\Scraping\ScrapedRecordIngestService;
use App\Crm\Taxonomy;
use App\Models\Workspace;
use Database\Seeders\ScrapingSourcesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/** En dessous, le planificateur prefere un Seq Scan, et il a raison. */
const VOLUME_EMAIL = 20000;

/** L'adresse d'une ligne qui EXISTE : chercher un absent ne mesure pas la meme chose. */
function adresseCible(): string
{
    return md5('4242') . '@exemple.fr';
}

function cleSujet(): string
{
    return 'pk-4242';
}

/**
 * Peuple les trois tables visees dans les DEUX univers que `SiteGdprService`
 * connait (business + vivier). `generate_series` en SQL, jamais une boucle
 * PHP : 20 000 inserts PHP prendraient des dizaines de secondes, et c'est la
 * difference entre une garde qu'on joue et une garde qu'on desactive.
 *
 * @return array{business: string, vivier: string, entreprise: int}
 */
function peuplerEmails(): array
{
    // ⚠️ L'espace vivier est DEJA pose par les migrations de ce depot, et une
    // contrainte de base (`candidates_enforce_vivier_workspace`) interdit
    // qu'une fiche candidate vive ailleurs que dans un espace « vivier* ».
    // `firstOrCreate` : on ne recree pas ce qui existe.
    $business = Workspace::firstOrCreate(
        ['slug' => (string) config('crm.ingest.business_workspace', 'axion-ia')],
        ['id' => (string) Str::uuid(), 'name' => 'Business'],
    );
    $vivier = Workspace::firstOrCreate(
        ['slug' => Taxonomy::VIVIER_WORKSPACE_SLUG],
        ['id' => (string) Str::uuid(), 'name' => 'Vivier'],
    );

    // ⚠️ UNE ENTREPRISE PAR CONTACT, ET C'EST UNE MESURE QUI L'IMPOSE.
    // `contacts` porte le declencheur `contacts_recompute_score`, qui rejoue a
    // CHAQUE insertion un `count(*)` sur tous les contacts de l'entreprise.
    // 20 000 contacts accroches a UNE SEULE entreprise, c'est donc un cout
    // quadratique : mesure du 2026-08-20, le peuplement mettait 233 s. Reparti
    // sur 20 000 entreprises, chaque `count(*)` ne voit qu'une ligne.
    DB::statement("
        INSERT INTO companies (workspace_id, denomination, siren, created_at, updated_at)
        SELECT ?, 'Entreprise ' || g::text, lpad((100000000 + g)::text, 9, '0'), now(), now()
          FROM generate_series(1, ?) g
    ", [$business->id, VOLUME_EMAIL]);
    $entreprise = (int) DB::table('companies')->where('workspace_id', $business->id)->value('id');

    // `contacts.normalized_hash` est une colonne generee, unique par espace :
    // le nom doit donc varier a chaque ligne.
    DB::statement("
        INSERT INTO contacts (workspace_id, company_id, first_name, last_name, email, person_key, created_at, updated_at)
        SELECT c.workspace_id, c.id, 'Prenom', 'Nom' || g::text,
               md5(g::text) || '@exemple.fr', 'pk-' || g::text, now(), now()
          FROM generate_series(1, ?) g
          JOIN companies c ON c.workspace_id = ? AND c.siren = lpad((100000000 + g)::text, 9, '0')
    ", [VOLUME_EMAIL, $business->id]);

    DB::statement("
        INSERT INTO health_practitioners (workspace_id, nom, prenom, specialite, email, created_at, updated_at)
        SELECT ?, 'Nom' || g::text, 'Prenom', 'generaliste', md5(g::text) || '@exemple.fr', now(), now()
          FROM generate_series(1, ?) g
    ", [$business->id, VOLUME_EMAIL]);

    // `relation_type` est une liste FERMEE cote base
    // (`candidates_relation_type_check`) : une valeur inventee passerait la
    // validation applicative pour mourir en base.
    DB::statement("
        INSERT INTO candidates (workspace_id, last_name, email, person_key, relation_type, created_at, updated_at)
        SELECT ?, 'Nom' || g::text, md5(g::text) || '@exemple.fr', 'pk-' || g::text, 'candidat_commercial', now(), now()
          FROM generate_series(1, ?) g
    ", [$vivier->id, VOLUME_EMAIL]);

    DB::statement('ANALYZE companies');
    DB::statement('ANALYZE contacts');
    DB::statement('ANALYZE candidates');
    DB::statement('ANALYZE health_practitioners');

    return ['business' => (string) $business->id, 'vivier' => (string) $vivier->id, 'entreprise' => $entreprise];
}

/**
 * Fait tourner `$travail` en interceptant le SQL que Postgres recoit.
 *
 * @return list<array{sql: string, bindings: list<mixed>}>
 */
function sqlEmisPar(callable $travail): array
{
    $vus = [];
    DB::listen(function ($requete) use (&$vus): void {
        $vus[] = ['sql' => $requete->sql, 'bindings' => $requete->bindings];
    });

    $travail();

    // Laravel n'expose pas de retrait d'ecouteur : on neutralise en vidant le
    // dispatcher des requetes pour la suite du test.
    DB::connection()->unsetEventDispatcher();
    DB::connection()->setEventDispatcher(app('events'));

    return $vus;
}

/**
 * Retrouve, parmi le SQL intercepte, LA requete qui cherche par e-mail sur une
 * table donnee — et ECHOUE bruyamment si elle n'y est pas. Sans ce garde-fou,
 * une garde qui ne trouve aucune requete passerait au vert sur du neant.
 *
 * @param  list<array{sql: string, bindings: list<mixed>}>  $emis
 * @return array{sql: string, bindings: list<mixed>}
 */
function requeteEmail(array $emis, string $table, string $verbe = 'select'): array
{
    foreach ($emis as $requete) {
        $sql = strtolower($requete['sql']);
        if (! str_starts_with(ltrim($sql), $verbe)) {
            continue;
        }
        if (! str_contains($sql, '"' . $table . '"')) {
            continue;
        }
        if (! in_array(adresseCible(), array_map(fn ($b) => is_string($b) ? $b : '', $requete['bindings']), true)) {
            continue;
        }

        return $requete;
    }

    throw new RuntimeException(
        "Aucune requete `{$verbe}` sur « {$table} » portant l'adresse cible n'a ete emise. "
        . "La garde mesurerait le neant. SQL intercepte :\n  - "
        . implode("\n  - ", array_map(fn ($r) => $r['sql'], $emis)),
    );
}

/**
 * Le plan que Postgres retient pour CE texte SQL et CES parametres.
 *
 * @param  array{sql: string, bindings: list<mixed>}  $requete
 */
function planDe(array $requete): string
{
    return collect(DB::select('EXPLAIN ' . $requete['sql'], $requete['bindings']))
        ->map(fn ($l) => (array) $l)
        ->flatten()
        ->implode("\n");
}

/**
 * Le CODE d'un fichier PHP, commentaires retires.
 *
 * ⚠️ ON NE PEUT PAS FAIRE UN `str_contains` SUR LE FICHIER ENTIER, et c'est une
 * mesure qui l'a montre : les deux fichiers repares EXPLIQUENT le defaut dans
 * leur en-tete, en citant la forme fautive. Un grep naif les accusait donc tous
 * les deux -- et une garde qui rougit sur son propre commentaire est une garde
 * qu'on finit par retirer. `token_get_all()` sait separer les commentaires du
 * reste, ce qu'aucune expression reguliere ne sait faire correctement sur du PHP.
 */
function codeSansCommentaires(string $php): string
{
    $code = '';
    foreach (token_get_all($php) as $jeton) {
        if (is_array($jeton)) {
            if (in_array($jeton[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            $code .= $jeton[1];

            continue;
        }
        $code .= $jeton;
    }

    return $code;
}

// ── TEMOINS : la garde sait-elle ce qu'elle fait ? ───────────────────────────

test('C21-001 — TEMOIN : sans volume, la mesure ne mesure rien', function () {
    // Ce test ne verifie pas le produit : il verifie que les gardes ci-dessous
    // savent ce qu'elles font. Sur une ligne, Postgres balaie -- et c'est le bon
    // choix. Une garde de performance qui l'ignore rend un verdict faux.
    $espace = Workspace::create([
        'id' => (string) Str::uuid(), 'slug' => 'vide-' . Str::random(6), 'name' => 'Vide',
    ]);
    // `health_practitioners` : la seule des trois tables sans contrainte
    // d'espace ni colonne generee -- une ligne suffit a poser le temoin.
    DB::table('health_practitioners')->insert([
        'workspace_id' => $espace->id, 'nom' => 'Seule', 'prenom' => 'Une',
        'specialite' => 'generaliste', 'email' => 'seule@exemple.fr',
        'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::statement('ANALYZE health_practitioners');

    $plan = planDe([
        'sql' => 'select id from "health_practitioners" where email = ?',
        'bindings' => ['seule@exemple.fr'],
    ]);

    $this->assertStringContainsString(
        'Seq Scan',
        $plan,
        'Sur une table minuscule, le planificateur DOIT balayer. Si ce test rougit, le seuil '
        . "a change et les gardes ci-dessous mesurent autre chose.\n\n{$plan}",
    );
});

test('C21-001 — TEMOIN : le volume est en place et la ligne cherchee EXISTE', function () {
    peuplerEmails();

    foreach (['contacts', 'candidates', 'health_practitioners'] as $table) {
        expect((int) DB::table($table)->count())->toBeGreaterThanOrEqual(
            VOLUME_EMAIL,
            "La table « {$table} » n'atteint pas le volume attendu : les mesures ci-dessous "
            . 'ne prouveraient rien.',
        );
        // Une garde qui interroge une adresse ABSENTE passerait au vert sur une
        // reponse vide, index ou pas.
        expect((int) DB::table($table)->where('email', adresseCible())->count())->toBe(
            1,
            "L'adresse cherchee n'existe pas dans « {$table} » : la mesure porterait sur du vide.",
        );
    }
});

// ── La deduplication du scraping : jouee a CHAQUE fiche ingeree ──────────────

test('C21-001 — la deduplication par e-mail du scraping EMPLOIE son index', function () {
    $ctx = peuplerEmails();

    // On ne recopie PAS le SQL : on fait tourner le funnel d'ingestion REEL et
    // on intercepte ce que Postgres recoit. Un SQL recopie a la main ne
    // mesurerait que ce que le testeur a tape.
    config([
        'crm.scrape_funnel.enabled' => true,
        'crm.scrape_funnel.validate_mx' => false,   // jamais de DNS reel sur le banc
        'crm.ingest.business_workspace' => 'axion-ia',
    ]);
    $this->seed(ScrapingSourcesSeeder::class);

    $emis = sqlEmisPar(function (): void {
        app(ScrapedRecordIngestService::class)->ingest(ScrapedRecord::fromArray([
            'schema_version' => 1,
            'source' => 'mentions-legales',
            'run_id' => (string) Str::uuid(),
            'fetched_at' => '2026-08-20T11:00:00+02:00',
            'status' => 'success',
            'company' => ['siren' => '900000202', 'fields' => ['denomination' => 'ZZ INDEX SARL']],
            'persons' => [[
                'first_name' => 'Paul', 'last_name' => 'ZZ INDEX', 'kind' => 'person',
                'email' => adresseCible(),
            ]],
            'evidence' => ['url' => 'https://zz-index.example.invalid/mentions-legales'],
            'confidence' => 90,
        ]));
    });

    $plan = planDe(requeteEmail($emis, 'contacts'));

    $this->assertStringContainsString(
        'idx_contacts_email',
        $plan,
        'La deduplication est jouee A CHAQUE fiche ingeree par le scraping. Son plan '
        . "n'emploie pas `idx_contacts_email` :\n\n{$plan}\n\n"
        . 'C\'est le constat C21-001 : `contacts.email` est de type `citext` et porte deja '
        . 'un index, mais la requete l\'interrogeait par `lower(email::text)` -- un '
        . 'transtypage puis une fonction, donc une EXPRESSION que l\'index ne couvre pas.',
    );
});

// ── Les chemins RGPD : art. 15 (acces) et art. 17 (effacement) ───────────────

test('C21-001 — les chemins RGPD (art. 15 et art. 17) EMPLOIENT tous un index', function () {
    // Les quatre requetes par e-mail de `SiteGdprService` sont mesurees dans UN
    // SEUL test, et c'est delibere : chaque peuplement coute 20 000 lignes sur
    // quatre tables. Elles restent quatre assertions distinctes, avec chacune
    // son message -- une seule d'entre elles peut rougir sans masquer les autres.
    peuplerEmails();

    // ── Art. 15, acces ──────────────────────────────────────────────────────
    $emisExport = sqlEmisPar(function (): void {
        app(SiteGdprService::class)->export(cleSujet(), adresseCible());
    });

    $planContacts = planDe(requeteEmail($emisExport, 'contacts'));
    $this->assertStringContainsString(
        'idx_contacts_email',
        $planContacts,
        "Une demande d'acces ne doit pas balayer la base de contacts. Plan obtenu :

{$planContacts}",
    );

    // La requete reelle est un OU entre `person_key` et l'e-mail : les DEUX
    // branches doivent etre servies, sans quoi le OU degenere en balayage
    // complet (defaut `G41-002`, mesure sur `companies`).
    $this->assertStringContainsString(
        'idx_contacts_workspace_person_key',
        $planContacts,
        'La branche `person_key` du OU doit elle aussi etre servie par son index -- sinon '
        . "Postgres retombe sur un balayage pour la TOTALITE du OU. Plan obtenu :

{$planContacts}",
    );

    $planCandidats = planDe(requeteEmail($emisExport, 'candidates'));
    $this->assertStringContainsString(
        'idx_candidates_email',
        $planCandidats,
        "Une demande d'acces ne doit pas balayer le vivier. Plan obtenu :

{$planCandidats}",
    );

    // ── Art. 17, effacement, sur les DEUX univers ───────────────────────────
    $emisErase = sqlEmisPar(function (): void {
        app(SiteGdprService::class)->erase(cleSujet(), adresseCible(), 'both');
    });

    // `health_practitioners` -- ARTICLE 9, categorie particuliere. Cette table
    // n'avait AUCUN index sur `email` avant la migration
    // `2026_08_20_150000_index_email_praticiens_sante.php`.
    $planSante = planDe(requeteEmail($emisErase, 'health_practitioners', verbe: 'delete'));
    $this->assertStringContainsString(
        'idx_health_practitioners_email',
        $planSante,
        "L'effacement d'une donnee de SANTE ne doit pas balayer la table. Plan obtenu :

"
        . "{$planSante}

Si l'index n'apparait pas, verifier que la migration "
        . '`2026_08_20_150000_index_email_praticiens_sante.php` est bien passee, et surtout '
        . "que son nom n'etait pas DEJA pris par un index sur une AUTRE colonne : un "
        . '`CREATE INDEX IF NOT EXISTS` sur un nom deja pris est un SILENCE, pas une '
        . 'idempotence (cf. G41-003).',
    );

    $planEffacementVivier = planDe(requeteEmail($emisErase, 'candidates', verbe: 'delete'));
    $this->assertStringContainsString(
        'idx_candidates_email',
        $planEffacementVivier,
        "Une demande d'effacement ne doit pas balayer le vivier. Plan obtenu :

{$planEffacementVivier}",
    );

    $planEffacementContacts = planDe(requeteEmail($emisErase, 'contacts', verbe: 'delete'));
    $this->assertStringContainsString(
        'idx_contacts_email',
        $planEffacementContacts,
        "Une demande d'effacement ne doit pas balayer la base de contacts. Plan obtenu :

"
        . $planEffacementContacts,
    );
});

/**
 * LA PARADE GENERIQUE : plus aucun `lower(email::text)` dans les chemins repares.
 *
 * Ce test-ci EST un `grep`, et il l'assume : il ne remplace pas les mesures
 * ci-dessus, il empeche la REGRESSION silencieuse. Un jour quelqu'un
 * reintroduira la forme par habitude ; les plans, eux, ne rougiront que sur une
 * base de volume, jamais sur le banc d'un contributeur presse.
 */
test('C21-001 — les chemins repares n emploient plus lower(email::text)', function () {
    $fichiers = [
        base_path('app/Crm/Rgpd/SiteGdprService.php'),
        base_path('app/Crm/Scraping/ScrapedRecordIngestService.php'),
    ];

    $coupables = [];
    foreach ($fichiers as $fichier) {
        expect(file_exists($fichier))->toBeTrue("Fichier introuvable : {$fichier}");

        // ⚠️ ON NE PEUT PAS FAIRE UN `str_contains` SUR LE FICHIER ENTIER, et
        // c'est une mesure qui l'a montre : les deux fichiers repares
        // EXPLIQUENT le defaut dans leur en-tete, en citant la forme fautive.
        // Un grep naif les accusait donc tous les deux -- une garde qui rougit
        // sur son propre commentaire est une garde qu'on finit par retirer.
        // On ne lit que le CODE : `token_get_all()` sait separer les
        // commentaires du reste, ce qu'aucune expression reguliere ne sait
        // faire correctement sur du PHP.
        // Motif ecrit SANS lettre accentuee, et cherche litteralement.
        if (str_contains(codeSansCommentaires((string) file_get_contents($fichier)), 'lower(email::text)')) {
            $coupables[] = $fichier;
        }
    }

    // TEMOIN DU DETECTEUR, et il n'est pas decoratif : si `token_get_all()`
    // rendait n'importe quoi (fichier illisible, extension PHP absente), la
    // boucle ci-dessus ne trouverait jamais rien et la garde serait MUETTE.
    $this->assertStringContainsString(
        'lower(email::text)',
        codeSansCommentaires("<?php \$q->whereRaw('lower(email::text) = ?', [\$e]);"),
        'Le detecteur ne voit plus la forme fautive : la garde ci-dessus ne prouve rien.',
    );
    $this->assertStringNotContainsString(
        'lower(email::text)',
        codeSansCommentaires('<?php // on explique lower(email::text) ici
$x = 1;'),
        'Le detecteur compte les COMMENTAIRES : il accuserait les fichiers qui documentent '
        . 'le defaut au lieu de ceux qui le portent.',
    );

    $this->assertSame(
        [],
        $coupables,
        '`lower(email::text)` est de retour. La colonne `email` est de type `citext` : elle '
        . 'compare DEJA sans egard a la casse, et l\'index porte sur la COLONNE, pas sur '
        . "l'expression. Ecrire `->where('email', \$email)` suffit et reste indexe.\n\n"
        . 'Fichiers : ' . implode(', ', $coupables),
    );
});
