<?php

/**
 * GARDE : LES DEUX CHEMINS D'INGESTION QUE `C21-001` NOMMAIT ET QUE LA PREMIERE
 * REPARATION N'A PAS TOUCHES — plus l'INVENTAIRE de ce qui reste.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * 1. CE QUI ETAIT DEJA FAIT, ET CE QUI NE L'ETAIT PAS
 * ═══════════════════════════════════════════════════════════════════════════
 *
 * `C21-001` nomme HUIT ecritures de la forme
 *
 *     ->whereRaw('lower(email::text) = ?', [$email])
 *
 * reparties sur quatre fichiers : `SiteGdprService` (5),
 * `ScrapedRecordIngestService` (1), `ContactUpserter` (1) et
 * `SiteSyncIngestService` (1). La colonne `email` de `contacts` et de
 * `candidates` est de type **`citext`** — elle compare DEJA sans egard a la
 * casse — et chacune porte deja son index :
 *
 *     idx_contacts_email    btree (email) WHERE email IS NOT NULL
 *     idx_candidates_email  btree (email) WHERE email IS NOT NULL
 *
 * Le `lower(...::text)` ne changeait donc rien a la semantique ; il rendait
 * seulement l'index meconnaissable, et Postgres retombait sur le balayage.
 *
 * La vague du 2026-08-20 (commit `0ac9578`) a repare **SIX** de ces huit :
 * les cinq de `SiteGdprService` et celle de `ScrapedRecordIngestService`. Elle
 * a pose la garde `tests/Feature/Infra/IndexEmailRgpdServentLesRequetesTest.php`.
 *
 * **Les deux dernieres sont restees, et la garde ne pouvait pas le voir** : sa
 * parade generique inspecte une liste de fichiers ECRITE A LA MAIN, qui
 * contient exactement les deux fichiers reparés. Une garde qui n'inspecte que
 * ce qui est deja repare ne peut jamais rougir.
 *
 *     $fichiers = [
 *         base_path('app/Crm/Rgpd/SiteGdprService.php'),
 *         base_path('app/Crm/Scraping/ScrapedRecordIngestService.php'),
 *     ];
 *
 * Les deux survivantes, mesurees le 2026-08-21 sur le banc `a35r` :
 *
 *   - `app/Crm/Ingest/ContactUpserter.php:69` — la deduplication PERSONNE de
 *     l'ingestion du site, jouee A CHAQUE fiche entrante, et aussi par l'ecran
 *     d'ARBITRAGE manuel (les deux passent par ce meme service) ;
 *   - `app/Crm/Ingest/SiteSyncIngestService.php:314` — la deduplication du
 *     VIVIER (`candidates`), meme role, autre univers.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * 2. LE JUMEAU QU'ON N'ATTENDAIT PAS : LA FORME A ETE REINTRODUITE SIX FOIS
 * ═══════════════════════════════════════════════════════════════════════════
 *
 * `git log -S` sur cette branche, contre `origin/main` :
 *
 *     origin/main : 0 occurrence dans app/Services/Rgpd/
 *     HEAD        : 6
 *
 * Trois commits POSTERIEURS a la garde l'ont reintroduite, dans les chemins
 * RGPD eux-memes : `7cc855e`, `ad7ae55`, `3eebde3`. La liste ecrite a la main
 * ne les regardait pas.
 *
 * **Ces six-la ne sont PAS reparees ici, et c'est deliberé** — elles ne sont
 * pas le meme defaut. Inventaire mesure le 2026-08-21 sur `axion_crm_test_lot7`
 * (`pg_attribute` + `pg_indexes`) :
 *
 *   | site                                | table                   | type    | index servant une recherche par e-mail seul |
 *   |-------------------------------------|-------------------------|---------|---------------------------------------------|
 *   | GdprErasureService.php:55           | email_verification_logs | varchar | NON (l'unique est `(workspace_id, email, provider)`) |
 *   | GdprErasureService.php:131          | email_messages          | text    | NON (aucun index sur `from_address`)        |
 *   | GdprPortabilityService.php:71       | email_messages          | text    | NON                                         |
 *   | GdprPortabilityService.php:85       | email_verification_logs | varchar | NON                                         |
 *   | GdprPortabilityService.php:127      | unsubscribes            | citext  | NON (l'unique est `(workspace_id, email)`, colonne de tete `workspace_id`) |
 *   | GdprPortabilityService.php:133      | dnc_entries             | citext  | NON (aucun index sur `email`)               |
 *
 * Sur `varchar` et `text`, retirer `lower()` CHANGERAIT la semantique : ces
 * colonnes-la sont sensibles a la casse. Sur `unsubscribes` et `dnc_entries`,
 * la retirer est sans effet mesurable puisqu'aucun index ne peut servir la
 * recherche par e-mail seul. Dans les six cas, la reparation n'est pas
 * « retirer `lower()` » mais « poser un index d'expression, ou changer le type
 * de colonne » — un choix de schema, pas un correctif. **On les COMPTE.**
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * 3. CE QUE CETTE GARDE MESURE
 * ═══════════════════════════════════════════════════════════════════════════
 *
 * Elle ne recopie aucun SQL a la main : elle fait TOURNER les services reels,
 * intercepte par `DB::listen` le texte que Postgres recoit, et redemande a
 * Postgres — par `EXPLAIN`, sur CE texte et avec CES parametres — quel plan il
 * retient. Methode reprise telle quelle de la garde de la vague precedente ;
 * on etend, on ne reinvente pas.
 *
 * Elle a besoin d'un VOLUME : sur une table de trois lignes le planificateur
 * balaie, et il a raison. Elle peuple donc, et un temoin verifie qu'a une
 * ligne le plan est bien un balayage — sans quoi le vert ne prouverait rien.
 *
 * MESURE, banc `a35r`, `axion_crm_test_lot7`, 20 000 contacts,
 * `EXPLAIN (ANALYZE, BUFFERS)`, 2026-08-21 :
 *
 *     AVANT  lower(email::text)  Seq Scan     cout 923,00   25,257 ms   573 tampons
 *     APRES  email (citext)      Index Scan   cout   8,43    0,174 ms     4 tampons
 *
 * soit **145x** sur le temps et **143x** sur les tampons lus, a 20 000 lignes.
 * Le constat d'origine annonce 1 070 ms en production sur 1 319 567 lignes ; je
 * ne reproduis pas ce chiffre-la et je ne le recopie pas comme s'il etait
 * mesure ici. Ce qui EST mesure : le plan passe du balayage a l'index.
 *
 * ⚠️ Le volume des tests ci-dessous est plus petit (5 000) que celui de la
 * mesure ci-dessus : le peuplement de `contacts` coute ~3 ms par ligne
 * (declencheur `contacts_recompute_score`), et 20 000 lignes prenaient 58 s par
 * test. A 5 000 lignes le cout estime du balayage (~231) reste vingt-cinq fois
 * celui de l'index (8,43) : le planificateur choisit toujours l'index, et le
 * temoin de volume le prouve dans les deux sens.
 *
 * ⚠️ Toutes les assertions de texte se jouent sur des sous-chaines SANS ACCENT,
 * et `expect()->toContain()` n'est employe sur aucun texte : Pest est variadique
 * et le message d'echec y deviendrait une aiguille supplementaire.
 */

use App\Crm\Ingest\ContactUpserter;
use App\Crm\Ingest\SiteSyncEvent;
use App\Crm\Ingest\SiteSyncIngestService;
use App\Crm\Taxonomy;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/**
 * En dessous, le planificateur prefere un balayage — et il a raison.
 * Au-dessus, le peuplement coute plus que ce qu'il apporte (cf. en-tete).
 */
const C21I_VOLUME = 5000;

/** L'adresse d'une ligne qui EXISTE : chercher un absent ne mesure pas la meme chose. */
function c21iAdresseCible(): string
{
    return md5('4242') . '@exemple.fr';
}

/**
 * Peuple `contacts` (univers business) au volume voulu.
 *
 * ⚠️ UNE ENTREPRISE PAR CONTACT, ET C'EST UNE MESURE QUI L'IMPOSE : `contacts`
 * porte le declencheur `contacts_recompute_score`, qui rejoue a chaque
 * insertion un `count(*)` sur tous les contacts de l'entreprise. Tout accrocher
 * a une seule entreprise rend le peuplement quadratique.
 *
 * @return array{espace: string, entreprise: int}
 */
function c21iPeuplerContacts(): array
{
    $business = Workspace::firstOrCreate(
        ['slug' => (string) config('crm.ingest.business_workspace', 'axion-ia')],
        ['id' => (string) Str::uuid(), 'name' => 'Business'],
    );

    DB::statement("
        INSERT INTO companies (workspace_id, denomination, siren, created_at, updated_at)
        SELECT ?, 'Entreprise ' || g::text, lpad((100000000 + g)::text, 9, '0'), now(), now()
          FROM generate_series(1, ?) g
    ", [$business->id, C21I_VOLUME]);

    // `contacts.normalized_hash` est une colonne GENEREE, unique par espace :
    // le nom doit varier a chaque ligne.
    DB::statement("
        INSERT INTO contacts (workspace_id, company_id, first_name, last_name, email, person_key, created_at, updated_at)
        SELECT c.workspace_id, c.id, 'Prenom', 'Nom' || g::text,
               md5(g::text) || '@exemple.fr', 'pk-' || g::text, now(), now()
          FROM generate_series(1, ?) g
          JOIN companies c ON c.workspace_id = ? AND c.siren = lpad((100000000 + g)::text, 9, '0')
    ", [C21I_VOLUME, $business->id]);

    DB::statement('ANALYZE companies');
    DB::statement('ANALYZE contacts');

    return [
        'espace' => (string) $business->id,
        'entreprise' => (int) DB::table('companies')->where('workspace_id', $business->id)->value('id'),
    ];
}

/** Peuple `candidates` (univers vivier) au volume voulu. */
function c21iPeuplerCandidats(): string
{
    // Une contrainte de base (`candidates_enforce_vivier_workspace`) interdit
    // qu'une fiche candidate vive ailleurs que dans l'espace vivier.
    $vivier = Workspace::firstOrCreate(
        ['slug' => Taxonomy::VIVIER_WORKSPACE_SLUG],
        ['id' => (string) Str::uuid(), 'name' => 'Vivier'],
    );

    // `relation_type` est une liste FERMEE cote base
    // (`candidates_relation_type_check`).
    DB::statement("
        INSERT INTO candidates (workspace_id, last_name, email, person_key, relation_type, created_at, updated_at)
        SELECT ?, 'Nom' || g::text, md5(g::text) || '@exemple.fr', 'pk-' || g::text, 'candidat_commercial', now(), now()
          FROM generate_series(1, ?) g
    ", [$vivier->id, C21I_VOLUME]);

    DB::statement('ANALYZE candidates');

    return (string) $vivier->id;
}

/**
 * Fait tourner `$travail` en interceptant le SQL que Postgres recoit.
 *
 * @return list<array{sql: string, bindings: list<mixed>}>
 */
function c21iSqlEmisPar(callable $travail): array
{
    $vus = [];
    DB::listen(function ($requete) use (&$vus): void {
        $vus[] = ['sql' => $requete->sql, 'bindings' => $requete->bindings];
    });

    $travail();

    // Laravel n'expose pas de retrait d'ecouteur : on neutralise en remettant
    // un dispatcher neuf pour la suite du test.
    DB::connection()->unsetEventDispatcher();
    DB::connection()->setEventDispatcher(app('events'));

    return $vus;
}

/**
 * Retrouve, parmi le SQL intercepte, LA requete qui cherche par e-mail sur une
 * table donnee — et ECHOUE bruyamment si elle n'y est pas.
 *
 * C'est le TEMOIN DE COUVERTURE de cette garde : sans lui, un service qui
 * n'emettrait plus aucune requete (renommage, court-circuit, drapeau a OFF)
 * ferait passer la mesure au vert sur du neant.
 *
 * @param  list<array{sql: string, bindings: list<mixed>}>  $emis
 * @return array{sql: string, bindings: list<mixed>}
 */
function c21iRequeteEmail(array $emis, string $table): array
{
    foreach ($emis as $requete) {
        $sql = strtolower(ltrim($requete['sql']));
        if (! str_starts_with($sql, 'select')) {
            continue;
        }
        if (! str_contains($sql, '"' . $table . '"')) {
            continue;
        }
        if (! in_array(c21iAdresseCible(), array_map(fn ($b) => is_string($b) ? $b : '', $requete['bindings']), true)) {
            continue;
        }

        return $requete;
    }

    throw new RuntimeException(
        "Aucune requete `select` sur « {$table} » portant l'adresse cible n'a ete emise : "
        . "la garde mesurerait le neant. SQL intercepte :\n  - "
        . implode("\n  - ", array_map(fn ($r) => $r['sql'], $emis)),
    );
}

/**
 * Le plan que Postgres retient pour CE texte SQL et CES parametres.
 *
 * @param  array{sql: string, bindings: list<mixed>}  $requete
 */
function c21iPlanDe(array $requete): string
{
    return collect(DB::select('EXPLAIN ' . $requete['sql'], $requete['bindings']))
        ->map(fn ($l) => (array) $l)
        ->flatten()
        ->implode("\n");
}

/**
 * Le CODE d'un fichier PHP, commentaires retires.
 *
 * ⚠️ On ne peut pas faire un `str_contains` sur le fichier entier : les
 * fichiers repares EXPLIQUENT le defaut dans leur en-tete, en citant la forme
 * fautive. Un grep naif les accuse tous. `token_get_all()` sait separer les
 * commentaires du reste ; aucune expression reguliere ne le sait sur du PHP.
 */
function c21iCodeSeul(string $php): string
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

/**
 * Balaye TOUT `app/` — pas une liste ecrite a la main — et rend, par fichier
 * relatif, le nombre d'occurrences de `lower(<colonne>::text)` dans le CODE.
 *
 * @return array{occurrences: array<string, int>, fichiers_lus: int}
 */
function c21iBalayage(): array
{
    $racine = base_path('app');
    $iterateur = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($racine, FilesystemIterator::SKIP_DOTS),
    );

    $occurrences = [];
    $lus = 0;

    foreach ($iterateur as $entree) {
        /** @var SplFileInfo $entree */
        if (! $entree->isFile() || $entree->getExtension() !== 'php') {
            continue;
        }

        $lus++;
        $code = c21iCodeSeul((string) file_get_contents($entree->getPathname()));
        $n = preg_match_all('/lower\(\s*[a-z_]+\s*::\s*text\s*\)/i', $code);

        if ($n > 0) {
            $relatif = str_replace('\\', '/', substr($entree->getPathname(), strlen(base_path()) + 1));
            $occurrences[$relatif] = $n;
        }
    }

    ksort($occurrences);

    return ['occurrences' => $occurrences, 'fichiers_lus' => $lus];
}

// ── TEMOINS DE L'INSTRUMENT ──────────────────────────────────────────────────

test('C21-001/twins — TEMOIN : l instrument SAIT rendre autre chose qu un index', function () {
    // ── CE TEMOIN A CHANGE DE FORME, ET LA RAISON COMPTE ────────────────────
    //
    // Il affirmait : « sur UNE ligne, Postgres balaie, et il a raison ». Cette
    // premisse est FAUSSE des qu'un test voisin a brasse `candidates`, et il a
    // rougi pour cela dans la suite complete du 2026-08-21.
    //
    // Mesure, MEME ligne unique, MEME requete :
    //
    //   table propre ...... Seq Scan                        (cost=0.00..1.01)
    //   table ballonnee ... Index Scan using idx_candidates_email (cost=0.25..8.27)
    //
    // `RefreshDatabase` annule les DONNEES entre deux tests, pas l'ETAT
    // PHYSIQUE : les tuples morts et les pages restent. Un balayage qui doit
    // lire 200 pages perd alors contre un index qui en lit deux — et le
    // planificateur a raison. Le temoin ne mesurait donc pas l'instrument, il
    // mesurait ce que ses voisins avaient laisse dans la table.
    //
    // ── CE QU'IL MESURE MAINTENANT ─────────────────────────────────────────
    //
    // La question utile est : « cet instrument sait-il rendre autre chose que
    // "index" ? ». Une colonne qui ne porte AUCUN index y repond, et sa reponse
    // ne depend d'aucun etat : aucun index ne peut la servir, donc le balayage
    // est le seul plan possible.
    //
    // `candidates.last_name` n'est indexee nulle part (catalogue verifie le
    // 2026-08-21 : sept index, aucun sur `last_name`). Mesure : `Seq Scan` sur
    // table propre ET sur table ballonnee, et jusque `enable_seqscan` decourage.
    //
    // Le vrai pouvoir discriminant du fichier est ailleurs, et il est intact :
    // le temoin suivant montre qu'AU MEME VOLUME, la forme `lower(email::text)`
    // reste un balayage la ou la forme correcte prend l'index. Deux plans
    // opposes dans les memes conditions — c'est cela qui prouve la mesure.
    $vivier = Workspace::firstOrCreate(
        ['slug' => Taxonomy::VIVIER_WORKSPACE_SLUG],
        ['id' => (string) Str::uuid(), 'name' => 'Vivier'],
    );

    DB::table('candidates')->insert([
        'workspace_id' => $vivier->id,
        'last_name' => 'Seule',
        'email' => c21iAdresseCible(),
        'person_key' => 'pk-seule',
        'relation_type' => 'candidat_commercial',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    // Pas d'`ANALYZE` ici, et c'est delibere : le verdict de ce temoin ne doit
    // dependre d'AUCUNE statistique. S'il en dependait, il retomberait dans le
    // defaut qu'on vient de lui retirer.
    $plan = c21iPlanDe([
        'sql' => 'select * from "candidates" where "last_name" = ? limit 1',
        'bindings' => ['Seule'],
    ]);

    $this->assertStringContainsString(
        'Seq Scan',
        $plan,
        "`candidates.last_name` ne porte aucun index : le plan DOIT etre un balayage.\n"
        . "S'il n'en est plus un, c'est qu'un index a ete ajoute sur cette colonne — et\n"
        . "ce temoin ne discrimine plus rien. Choisir alors une autre colonne nue.\n" . $plan,
    );

    // Et la ligne est bien la : un temoin qui balaie une table VIDE ne prouve
    // rien non plus.
    expect(DB::table('candidates')->where('last_name', 'Seule')->count())->toBe(1);
});

test('C21-001/twins — TEMOIN : la forme fautive reste un balayage AU VOLUME', function () {
    // Le pendant du temoin precedent : au volume, la forme `lower(email::text)`
    // DOIT rester un balayage. Si Postgres l'indexait tout seul, le constat
    // C21-001 serait faux et il faudrait le dire, pas le reparer.
    $peuple = c21iPeuplerContacts();

    $plan = c21iPlanDe([
        'sql' => 'select * from "contacts" where "workspace_id" = ? and lower(email::text) = ? limit 1',
        'bindings' => [$peuple['espace'], c21iAdresseCible()],
    ]);

    $this->assertStringContainsString(
        'Seq Scan',
        $plan,
        "La forme `lower(email::text)` n'est plus un balayage : le constat C21-001 "
        . "serait a rouvrir, pas a fermer.\n" . $plan,
    );

    // Et la ligne cherchee EXISTE : chercher un absent mesurerait autre chose.
    expect(DB::table('contacts')->where('email', c21iAdresseCible())->count())->toBe(1)
        ->and(DB::table('contacts')->count())->toBe(C21I_VOLUME);
});

// ── LES DEUX SITES JUMEAUX, MESURES SUR LE SQL REELLEMENT EMIS ───────────────

test('C21-001/twins — la deduplication PERSONNE de l ingestion EMPLOIE son index', function () {
    $peuple = c21iPeuplerContacts();

    // On appelle le service REEL. `externalRef` a null et un `personKey` qui
    // n'existe pas : les deux premieres recherches de la chaine echouent, et
    // c'est bien la recherche PAR E-MAIL qui decide.
    $emis = c21iSqlEmisPar(function () use ($peuple) {
        app(ContactUpserter::class)->upsert(
            workspaceId: $peuple['espace'],
            companyId: $peuple['entreprise'],
            personKey: 'pk-inexistante-' . Str::random(8),
            externalRef: null,
            email: c21iAdresseCible(),
            firstName: 'Prenom',
            lastName: 'Nom4242',
            phone: null,
            // Liste FERMEE cote base (`contacts_legal_basis_check`, batie sur
            // `Taxonomy::LEGAL_BASES`) : une valeur inventee passerait la
            // validation applicative pour mourir en base.
            legalBasis: 'legitimate_interest_b2b',
        );
    });

    $plan = c21iPlanDe(c21iRequeteEmail($emis, 'contacts'));

    $this->assertStringContainsString(
        'idx_contacts_email',
        $plan,
        '`ContactUpserter` cherche encore la personne par `lower(email::text)`. La colonne '
        . '`contacts.email` est de type `citext` : elle compare DEJA sans egard a la casse, et '
        . "`idx_contacts_email` porte sur la COLONNE, pas sur l'expression. Cette recherche est "
        . "jouee A CHAQUE fiche entrante, et aussi par l'ecran d'arbitrage manuel.\n\nPlan :\n" . $plan,
    );
    $this->assertStringNotContainsString(
        'Seq Scan on contacts',
        $plan,
        "Balayage sequentiel de `contacts` sur le chemin d'ingestion.\n" . $plan,
    );
});

test('C21-001/twins — la deduplication du VIVIER EMPLOIE son index', function () {
    $vivier = c21iPeuplerCandidats();
    config([
        'crm.ingest.enabled' => true,
        'crm.ingest.candidates_enabled' => true,
    ]);

    // Evenement candidat : `application_submitted` bascule l'univers sur
    // « vivier », et la version de consentement doit etre une v2 fermee, sans
    // quoi le service rejette avant d'avoir cherche quoi que ce soit.
    $evenement = SiteSyncEvent::fromArray([
        'schema_version' => 1,
        'event_id' => (string) Str::uuid(),
        'event_type' => 'application_submitted',
        'occurred_at' => '2026-08-21T09:30:00+02:00',
        'subject_ref' => 'site:submission:' . Str::uuid(),
        'person' => [
            // Une cle de personne ABSENTE du peuplement : sans cela la
            // recherche par `person_key` aboutirait et la recherche par e-mail
            // — celle qu'on mesure — ne serait jamais emise. Le contrat d'entree
            // exige un sha256 hexadecimal de 64 caracteres ; le peuplement, lui,
            // porte des `pk-<n>` : aucune collision possible.
            'person_key' => hash('sha256', 'absente-' . Str::random(12)),
            'email' => c21iAdresseCible(),
            'first_name' => 'Prenom',
            'last_name' => 'Nom4242',
        ],
        'company' => [],
        'consent' => [
            'version' => 'careers-v2-2026-08-13',
            'at' => '2026-08-21T09:29:00+02:00',
            'vivier_at' => '2026-08-21T09:29:00+02:00',
        ],
        'candidate' => ['family' => 'candidat_commercial'],
        'tags' => [],
        'payload' => [],
    ]);

    $emis = c21iSqlEmisPar(function () use ($evenement) {
        app(SiteSyncIngestService::class)->ingest($evenement);
    });

    $plan = c21iPlanDe(c21iRequeteEmail($emis, 'candidates'));

    $this->assertStringContainsString(
        'idx_candidates_email',
        $plan,
        '`SiteSyncIngestService::upsertCandidate()` cherche encore par `lower(email::text)`. '
        . '`candidates.email` est de type `citext` et porte `idx_candidates_email` : la forme '
        . "actuelle rend cet index inutilisable.\n\nPlan :\n" . $plan,
    );
    $this->assertStringNotContainsString(
        'Seq Scan on candidates',
        $plan,
        "Balayage sequentiel de `candidates` sur le chemin d'ingestion du vivier.\n" . $plan,
    );

    // Et la deduplication a bien FAIT SON TRAVAIL : elle a retrouve la fiche
    // existante au lieu d'en creer une seconde. Une garde de performance qui
    // laisserait passer une regression de comportement ne vaudrait rien.
    expect(DB::table('candidates')->count())->toBe(C21I_VOLUME);
});

// ── L'INVENTAIRE DE CE QUI RESTE, ET LE BALAYAGE QUI LE TIENT ────────────────

test('C21-001/twins — inventaire fige de lower(colonne::text) dans tout app/', function () {
    // ⚠️ CE TEST REMPLACE LA LISTE ECRITE A LA MAIN DE LA GARDE PRECEDENTE.
    // Elle n'inspectait que les deux fichiers deja repares ; trois commits
    // ulterieurs ont reintroduit la forme SIX fois ailleurs sans jamais la
    // faire rougir. Ici on balaie l'arbre entier et on FIGE le residu.
    //
    // Ces six-la ne sont pas reparables mecaniquement (cf. l'en-tete : deux
    // colonnes `varchar`/`text` ou `lower()` porte du SENS, deux colonnes
    // `citext` qu'aucun index ne sert). Elles sont donc COMPTEES.
    //
    // Ce tableau doit BAISSER, jamais monter. S'il monte, quelqu'un vient de
    // reintroduire le defaut ; s'il baisse, mettez-le a jour DELIBEREMENT.
    $attendu = [
        'app/Services/Rgpd/GdprErasureService.php' => 2,
        'app/Services/Rgpd/GdprPortabilityService.php' => 4,
    ];

    $balayage = c21iBalayage();

    // TEMOIN DE COUVERTURE, et il n'est pas decoratif : si le chemin etait
    // faux, l'iterateur ne verrait AUCUN fichier, le tableau des occurrences
    // serait vide, et la garde passerait au vert en n'ayant rien regarde.
    // 293 fichiers PHP dans `app/` au 2026-08-21 ; on exige un ordre de
    // grandeur, pas le compte exact, pour ne pas rougir a chaque fichier neuf.
    $this->assertGreaterThan(
        250,
        $balayage['fichiers_lus'],
        'Le balayage n a lu que ' . $balayage['fichiers_lus'] . ' fichiers PHP : le chemin est faux '
        . 'et cette garde ne regarde rien. Un vert obtenu ainsi est un faux vert.',
    );

    $this->assertSame(
        $attendu,
        $balayage['occurrences'],
        "L'inventaire de `lower(<colonne>::text)` a bouge.\n\n"
        . "S'il a AUGMENTE : la colonne visee est-elle `citext` ? Si oui, `lower()` est redondant "
        . "et tue l'index — ecrivez `->where('email', \$email)`. Si elle est `varchar`/`text`, "
        . "`lower()` porte du sens : il faut alors un index d'expression, et ca se decide, ca ne "
        . "se glisse pas dans un correctif.\n\n"
        . "S'il a DIMINUE : tant mieux, mettez ce tableau a jour.\n\n"
        . 'Trouve : ' . json_encode($balayage['occurrences'], JSON_PRETTY_PRINT),
    );
});

test('C21-001/twins — TEMOIN : le detecteur sait discriminer code et commentaire', function () {
    // Sans ces deux temoins, le tableau vide ci-dessus pourrait venir d'un
    // detecteur casse plutot que d'un code propre.
    $this->assertStringContainsString(
        'lower(email::text)',
        c21iCodeSeul("<?php \$q->whereRaw('lower(email::text) = ?', [\$e]);"),
        'Le detecteur ne voit plus la forme fautive dans du CODE : l inventaire ne prouve rien.',
    );
    $this->assertStringNotContainsString(
        'lower(email::text)',
        c21iCodeSeul("<?php // on explique lower(email::text) ici\n\$x = 1;"),
        'Le detecteur compte les COMMENTAIRES : il accuserait les fichiers qui documentent le '
        . 'defaut au lieu de ceux qui le portent.',
    );

    // Et le motif est bien celui qu'on croit : il attrape aussi `from_address`,
    // pas seulement `email` — c'est ainsi qu'on a trouve les six residus.
    expect(preg_match('/lower\(\s*[a-z_]+\s*::\s*text\s*\)/i', 'lower(from_address::text)'))->toBe(1)
        ->and(preg_match('/lower\(\s*[a-z_]+\s*::\s*text\s*\)/i', 'lower(email)'))->toBe(0);
});
