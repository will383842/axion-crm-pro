<?php

/**
 * GARDE B10-016 (S1) — « le code lit en soft-delete, Eloquent ecrit en dur ».
 * Audit 360, agent 35, lot 6 (2026-08-20).
 *
 * LE DEFAUT, EN UNE PHRASE
 * -----------------------
 * Les tables `companies`, `contacts`, `users` et `workspaces` portent toutes une
 * colonne `deleted_at` (migrations 2026_05_16_000002 lignes 36 et 65,
 * 2026_05_16_000003 lignes 57 et 95). TOUT le code de lecture s'appuie dessus.
 * Mais les quatre modeles Eloquent n'ont jamais declare `SoftDeletes` : un
 * `->delete()` emet un `DELETE FROM` sec. La ligne que tout le reste du code
 * croit seulement MASQUEE est en realite DETRUITE.
 *
 * CE QUI EST MESURE DANS CE DEPOT (grep joue le 2026-08-20)
 * --------------------------------------------------------
 * Cote LECTURE, `whereNull('deleted_at')` sur ces quatre tables :
 *   - companies : CompaniesController.php:136 (liste ET export partagent la
 *     requete), CompanyTagsBulkController.php:90, ArbitrageController.php:118,
 *     CompteursHub.php:151, EligibiliteCampagne.php:76/99/250, GlobalSearch,
 *     ExtractMediaFromCompanies.php:58, MediaLinkToCompanies (6 occurrences SQL).
 *   - contacts  : ContactsHubController.php:115, ContactsController.php:50,
 *     PersonTimelineController.php:188/233, BulkController.php:171/322.
 *   - users     : AuthService.php:78, MagicLinkService.php:22 et :96,
 *     PasswordResetController.php:110 — soit LES QUATRE portes d'entree de
 *     l'authentification.
 *   - workspaces: index partiel `idx_workspaces_slug_active` (migration 000002:38).
 * Plus SIX index partiels bati sur `WHERE deleted_at IS NULL` (migrations
 * 2026_08_15_000002, 2026_08_15_000003, 2026_08_15_000006, 2026_08_17_000001,
 * 2026_08_19_000001) : la moitie du plan de requetes de la console suppose une
 * corbeille qui n'existe pas.
 *
 * Cote ECRITURE, AUCUN de ces sites ne pose jamais `deleted_at`. La seule
 * suppression Eloquent du perimetre est `CompaniesController.php:442`
 * (`$company->delete()`), dont la documentation OpenAPI, DOUZE LIGNES PLUS HAUT
 * (ligne 427), promet noir sur blanc : « Soft-delete une entreprise
 * (deleted_at pose) ». Le fichier se contredit lui-meme.
 *
 * L'AGGRAVATION MESUREE : LA CASCADE
 * ----------------------------------
 * `contacts.company_id ... REFERENCES companies(id) ON DELETE CASCADE`
 * (migration 000003 ligne 74). Un `DELETE` dur sur une entreprise emporte donc
 * AUSSI, definitivement, tous ses contacts — alors que la liste des contacts se
 * contente de filtrer `deleted_at IS NULL` et croit les masquer.
 *
 * POURQUOI CES GARDES SONT ECRITES AU NIVEAU DU MODELE
 * ---------------------------------------------------
 * Le defaut est dans `app/Models/` : c'est la, et nulle part ailleurs, que la
 * semantique de `->delete()` se decide. Une garde HTTP mesurerait en plus le
 * routage, l'authentification et le cloisonnement d'espace — trois choses qui
 * ont leurs propres gardes, et qui rendraient celle-ci fragile pour rien.
 *
 * ⚠️ Les purges RGPD ne sont PAS concernees et doivent rester des suppressions
 * DURES : `GdprErasureService` (lignes 38, 39, 56, 59), `RetentionPurge`,
 * `RgpdPurgeBusinessProspects`, `ProspectionPurgeNonDiffusible` passent tous par
 * `DB::table(...)` — le constructeur de requetes ignore les traits Eloquent.
 * Verifie fichier par fichier : le droit a l'effacement reste un effacement.
 */

use App\Models\Company;
use App\Models\Contact;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/**
 * Origin de domaine stateful + jeton CSRF fourni.
 *
 * Le fichier de configuration pose `APP_ENV=testing` SANS `force="true"` : la
 * variable d'environnement du conteneur (`APP_ENV=local`) l'emporte donc, et
 * `ValidateCsrfToken` ne s'auto-desactive pas. On FOURNIT le jeton plutot que
 * de neutraliser le middleware — sans quoi la garde de connexion partirait en
 * 419 et l'on croirait mesurer un compte refuse alors qu'on mesure son propre
 * banc. Meme piege, meme parade que `GardesAuthentificationAgent35Test.php`.
 */
beforeEach(function () {
    $domaine = trim((string) (array_values(array_filter((array) config('sanctum.stateful')))[0] ?? 'localhost'));
    $this->withHeader('Origin', 'https://' . $domaine);
    $this->withSession(['_token' => 'b10-016-csrf'])->withHeader('X-CSRF-TOKEN', 'b10-016-csrf');
});

/** Un espace de travail jetable. Suffixe aleatoire : `slug` est UNIQUE (CITEXT). */
function espaceB10(): Workspace
{
    return Workspace::create([
        'id' => (string) Str::uuid(),
        'slug' => 'b10-' . Str::random(10),
        'name' => 'B10-016',
    ]);
}

/**
 * Une entreprise jetable. `siren` est CHAR(9) NOT NULL et la table porte
 * UNIQUE (workspace_id, siren) : on tire 9 chiffres au hasard.
 */
function entrepriseB10(Workspace $espace): Company
{
    return Company::create([
        'workspace_id' => $espace->id,
        'siren' => str_pad((string) random_int(0, 999999999), 9, '0', STR_PAD_LEFT),
        'denomination' => 'Entreprise B10-016',
    ]);
}

/** Lecture BRUTE, hors Eloquent : aucun scope global ne peut la maquiller. */
function ligneBruteB10(string $table, int|string $id): ?object
{
    return DB::table($table)->where('id', $id)->first();
}

// ───────────────────────────────────────────────── TEMOINS D'INSTRUMENTATION

/**
 * TEMOIN. Sans lui, toutes les gardes ci-dessous pourraient verdir sur une
 * colonne absente : `deleted_at` inexistant => `->delete()` reste dur => la
 * garde « la ligne survit » echouerait, mais la garde « deleted_at est pose »
 * pourrait etre lue comme « rien a garder ». On exige que les QUATRE colonnes
 * existent AVANT de conclure quoi que ce soit.
 */
test('B10-016 TEMOIN — les quatre tables du lot portent reellement une colonne deleted_at', function () {
    $attendues = ['companies', 'contacts', 'users', 'workspaces'];
    $portantes = array_values(array_filter(
        $attendues,
        fn (string $t): bool => Schema::hasTable($t) && Schema::hasColumn($t, 'deleted_at')
    ));

    // Egalite stricte, pas `toContain` : si une table disparait du schema, la
    // garde doit ROUGIR en le disant, pas se taire (regle « un test ignore est
    // un vert deguise »).
    expect($portantes)->toBe($attendues);
});

/**
 * TEMOIN. La garde « la ligne survit a `->delete()` » n'a de valeur que si la
 * ligne EXISTAIT. On verifie ici que le banc sait creer puis relire par SQL
 * brut les quatre sortes d'enregistrements — sinon un `->count() === 0` ferait
 * passer n'importe quoi.
 */
test('B10-016 TEMOIN — le banc cree bien les quatre sortes de lignes et sait les relire en SQL brut', function () {
    $espace = espaceB10();
    $entreprise = entrepriseB10($espace);
    $contact = Contact::create([
        'workspace_id' => $espace->id,
        'company_id' => $entreprise->id,
        'last_name' => 'Temoin',
    ]);
    $utilisateur = User::create([
        'id' => (string) Str::uuid(),
        'email' => 'temoin-' . Str::random(8) . '@b10.test',
        'name' => 'Temoin B10',
    ]);

    expect(ligneBruteB10('workspaces', $espace->id))->not->toBeNull();
    expect(ligneBruteB10('companies', $entreprise->id))->not->toBeNull();
    expect(ligneBruteB10('contacts', $contact->id))->not->toBeNull();
    expect(ligneBruteB10('users', $utilisateur->id))->not->toBeNull();

    // Et elles naissent VIVANTES : `deleted_at` nul. Si la colonne naissait
    // deja remplie, la garde principale verdirait pour la mauvaise raison.
    expect(ligneBruteB10('companies', $entreprise->id)->deleted_at)->toBeNull();
    expect(ligneBruteB10('contacts', $contact->id)->deleted_at)->toBeNull();
    expect(ligneBruteB10('users', $utilisateur->id)->deleted_at)->toBeNull();
    expect(ligneBruteB10('workspaces', $espace->id)->deleted_at)->toBeNull();
});

// ───────────────────────────────────────────────── LE CONSTAT, MODELE PAR MODELE

test('B10-016 — supprimer une entreprise la MASQUE et ne la detruit pas', function () {
    $entreprise = entrepriseB10(espaceB10());
    $id = $entreprise->id;

    $entreprise->delete();

    // 1. La ligne survit. C'est CE point que `CompaniesController.php:427`
    //    promet a qui lit la documentation OpenAPI de l'API publique.
    $brute = ligneBruteB10('companies', $id);
    expect($brute)->not->toBeNull();

    // 2. Et elle est marquee. Une ligne survivante SANS `deleted_at` serait pire
    //    que tout : elle continuerait d'apparaitre dans la liste.
    expect($brute->deleted_at)->not->toBeNull();

    // 3. Vue d'Eloquent, elle a bien disparu : c'est ce que la liste
    //    (`CompaniesController.php:136`) montre a l'operateur.
    expect(Company::find($id))->toBeNull();

    // 4. Et on sait la retrouver : sans ceci, « masque » ne vaudrait pas mieux
    //    que « detruit » pour l'operateur qui s'est trompe de bouton.
    expect(Company::withTrashed()->find($id))->not->toBeNull();
});

test('B10-016 — supprimer un contact le MASQUE et ne le detruit pas', function () {
    $espace = espaceB10();
    $contact = Contact::create([
        'workspace_id' => $espace->id,
        'company_id' => entrepriseB10($espace)->id,
        'last_name' => 'Masque',
    ]);
    $id = $contact->id;

    $contact->delete();

    $brute = ligneBruteB10('contacts', $id);
    expect($brute)->not->toBeNull();
    expect($brute->deleted_at)->not->toBeNull();
    expect(Contact::find($id))->toBeNull();
    expect(Contact::withTrashed()->find($id))->not->toBeNull();
});

test('B10-016 — supprimer un compte le MASQUE et ne le detruit pas', function () {
    $utilisateur = User::create([
        'id' => (string) Str::uuid(),
        'email' => 'masque-' . Str::random(8) . '@b10.test',
        'name' => 'Compte masque',
    ]);
    $id = $utilisateur->id;

    $utilisateur->delete();

    $brute = ligneBruteB10('users', $id);
    expect($brute)->not->toBeNull();
    expect($brute->deleted_at)->not->toBeNull();
    expect(User::find($id))->toBeNull();
    expect(User::withTrashed()->find($id))->not->toBeNull();
});

test('B10-016 — supprimer un espace de travail le MASQUE et ne le detruit pas', function () {
    $espace = espaceB10();
    $id = $espace->id;

    $espace->delete();

    $brute = ligneBruteB10('workspaces', $id);
    expect($brute)->not->toBeNull();
    expect($brute->deleted_at)->not->toBeNull();
    expect(Workspace::find($id))->toBeNull();
    expect(Workspace::withTrashed()->find($id))->not->toBeNull();
});

// ───────────────────────────────────────────────── L'AGGRAVATION : LA CASCADE

/**
 * Le point le plus couteux du constat, et celui qu'on ne voit pas en lisant les
 * modeles : `contacts.company_id ... ON DELETE CASCADE` (migration 000003:74).
 *
 * Avec un `DELETE` dur sur `companies`, Postgres emporte les contacts — sans
 * jamais passer par Eloquent, donc sans le moindre evenement, journal ou trace.
 * UN clic sur « supprimer » d'une entreprise de 40 contacts detruit 41 lignes.
 * Avec `SoftDeletes`, aucun `DELETE` n'est emis : la cascade ne se declenche
 * pas et les contacts survivent.
 */
test('B10-016 — supprimer une entreprise n emporte pas ses contacts par cascade SQL', function () {
    $espace = espaceB10();
    $entreprise = entrepriseB10($espace);

    $ids = [];
    foreach (['Alpha', 'Bravo', 'Charlie'] as $nom) {
        $ids[] = Contact::create([
            'workspace_id' => $espace->id,
            'company_id' => $entreprise->id,
            'last_name' => $nom,
        ])->id;
    }

    // TEMOIN : les trois contacts sont bien la AVANT. Sans ce controle, un
    // `count() === 3` en fin de test pourrait porter sur trois lignes d'un
    // autre test, et un `count() === 0` passerait pour « rien a proteger ».
    expect(DB::table('contacts')->whereIn('id', $ids)->count())->toBe(3);

    $entreprise->delete();

    // Les trois lignes survivent, en SQL brut : la cascade ne s'est pas armee.
    expect(DB::table('contacts')->whereIn('id', $ids)->count())->toBe(3);

    // Et elles restent VIVANTES : masquer l'entreprise ne masque pas ses
    // contacts. C'est une decision, pas un oubli — l'effacement en cascade des
    // contacts serait une SEMANTIQUE nouvelle, qui ne se decide pas dans un
    // correctif d'audit.
    expect(DB::table('contacts')->whereIn('id', $ids)->whereNull('deleted_at')->count())->toBe(3);
});

// ───────────────────────────────────────────────── LA CONSEQUENCE POUR L'AUTH

/**
 * Les quatre portes d'entree de l'authentification filtrent deja
 * `whereNull('deleted_at')` (AuthService:78, MagicLinkService:22 et :96,
 * PasswordResetController:110). Ce filtre ne protege RIEN tant que rien ne pose
 * jamais `deleted_at` : l'etat « compte desactive » est inatteignable.
 *
 * Cette garde verifie qu'il devient atteignable ET honore.
 */
test('B10-016 — un compte supprime ne peut plus ouvrir de session', function () {
    $motDePasse = 'MotDePasseTresLong2026!';
    $espace = espaceB10();
    $utilisateur = User::create([
        'id' => (string) Str::uuid(),
        'email' => 'ferme-' . Str::random(8) . '@b10.test',
        'name' => 'Compte ferme',
        'password_hash' => password_hash($motDePasse, PASSWORD_BCRYPT, ['cost' => 4]),
        'current_workspace_id' => $espace->id,
        'first_login_completed_at' => now(),
    ]);

    // TEMOIN : le compte marche AVANT la suppression. Sans lui, un refus final
    // prouverait seulement qu'on s'est trompe de mot de passe.
    $this->withServerVariables(['REMOTE_ADDR' => '10.60.16.1'])
        ->postJson('/api/v1/auth/login', ['email' => $utilisateur->email, 'password' => $motDePasse])
        ->assertOk();

    // ⚠️ Toute connexion reussie appelle `session()->regenerate()` — c'est la
    // protection contre la FIXATION de session, et elle est inconditionnelle.
    // Le jeton CSRF change donc, et l'en-tete pose par `beforeEach` devient
    // perime : SANS cette resynchronisation la requete suivante part en 419 et
    // l'on croit avoir mesure un compte refuse alors qu'on a mesure son propre
    // banc. Vu pour de vrai le 2026-08-20 : premiere execution de cette garde,
    // « Expected 422 but received 419 ».
    $this->withHeader('X-CSRF-TOKEN', app('session.store')->token());

    app('auth')->forgetGuards();
    $utilisateur->delete();

    $this->withServerVariables(['REMOTE_ADDR' => '10.60.16.2'])
        ->postJson('/api/v1/auth/login', ['email' => $utilisateur->email, 'password' => $motDePasse])
        ->assertStatus(422);
});

/**
 * Le vrai gain de securite : `config/auth.php` declare le fournisseur
 * `eloquent` sur `App\Models\User`. `EloquentUserProvider::retrieveById()`
 * construit donc une requete du MODELE — le scope global de `SoftDeletes`
 * s'y applique. Sans le trait, un compte « supprime » gardait ses jetons
 * d'API valides, car la resolution du porteur ne filtre nulle part
 * `deleted_at` (ce filtre n'existe QUE sur le chemin mot-de-passe).
 */
test('B10-016 — supprimer un compte invalide ses jetons d API deja emis', function () {
    $utilisateur = User::create([
        'id' => (string) Str::uuid(),
        'email' => 'jeton-' . Str::random(8) . '@b10.test',
        'name' => 'Porteur de jeton',
        'current_workspace_id' => espaceB10()->id,
        'first_login_completed_at' => now(),
    ]);
    $entetes = ['Authorization' => 'Bearer ' . $utilisateur->createToken('b10')->plainTextToken];

    // TEMOIN : le jeton marche AVANT.
    $this->withHeaders($entetes)->getJson('/api/v1/auth/me')->assertOk();

    $utilisateur->delete();
    app('auth')->forgetGuards();

    $this->withHeaders($entetes)->getJson('/api/v1/auth/me')->assertUnauthorized();
});

// ───────────────────────────────────────────────── LA GARDE ANTI-RECHUTE

/**
 * Le defaut caracteristique de ce depot n'est pas d'ignorer `SoftDeletes` :
 * SIX modeles le declarent deja (Media, Journalist, Candidate, EmailAudience,
 * HealthPractitioner, ScrapingCampaign). Le defaut est qu'on l'a pose ICI et
 * pas LA. Cette garde ne nomme donc aucun modele : elle balaye `app/Models/`,
 * et exige le trait partout ou la table porte la colonne.
 *
 * C'est elle qui attrapera le PROCHAIN modele ajoute sur une table a
 * `deleted_at` — sans qu'un auditeur ait a repasser derriere.
 */
test('B10-016 — tout modele assis sur une table a deleted_at declare SoftDeletes', function () {
    $manquants = [];
    $balayes = 0;

    foreach (glob(app_path('Models') . '/*.php') ?: [] as $fichier) {
        $classe = 'App\\Models\\' . basename($fichier, '.php');
        if (! class_exists($classe) || ! is_subclass_of($classe, \Illuminate\Database\Eloquent\Model::class)) {
            continue;
        }

        $table = (new $classe())->getTable();
        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'deleted_at')) {
            continue;
        }

        $balayes++;
        if (! in_array(SoftDeletes::class, class_uses_recursive($classe), true)) {
            $manquants[] = basename($fichier, '.php') . ' (' . $table . ')';
        }
    }

    // TEMOIN D'INSTRUMENTATION, indispensable : si le balayage ne trouvait
    // AUCUN modele — repertoire deplace, autoload casse, migrations non
    // jouees — `$manquants` serait vide et la garde verdirait sur du neant.
    // On a compte 10 modeles concernes le 2026-08-20 (les 6 deja pourvus + les
    // 4 du lot) ; on exige au moins ces 10.
    expect($balayes)->toBeGreaterThanOrEqual(10);

    expect($manquants)->toBe([]);
});
