<?php

/**
 * B12-002 / F36-006 (S1) — LES SITES JUMEAUX QUE LE CONSTAT NE NOMMAIT PAS.
 *
 * ── Où en était le dépôt le 2026-08-21 ──────────────────────────────────────
 *
 * Le constat dit : « le masquage couvre trois listes ; la fiche détaillée de la
 * même entreprise livre tout ». C'était vrai. Ça ne l'est plus : le correctif
 * est posé et committé (0ac9578), et `MasquageFicheDetailleeTest` le garde. Ce
 * fichier-ci ne réécrit pas ce travail.
 *
 * Il ferme ce que ce travail n'avait PAS vu. En énumérant les points d'API qui
 * rendent une coordonnée, on en trouve non pas cinq mais DOUZE, et sept
 * n'étaient couverts par rien :
 *
 *   couverts avant ce lot (5) : GET /companies, GET /companies/{id},
 *       GET /contacts, GET /contacts/{id}, GET /crm/contacts-hub
 *   NON couverts (7) : GET /journalists, GET /journalists/{id}, GET /media,
 *       GET /media/{id}, GET /crm/candidates, GET /crm/persons/{k}/timeline,
 *       GET /search, GET /audiences/{id}/members, GET /crm/arbitrage
 *
 * (Neuf entrées pour sept sites : `journalists` et `media` comptent chacun pour
 * un site à deux points d'entrée.)
 *
 * Deux d'entre eux sont pires que celui du constat :
 *   - `GET /media/{id}` charge `journalists` : une requête rendait le courriel
 *     et la ligne directe de TOUTE une rédaction — des personnes physiques —
 *     à un compte en lecture seule ;
 *   - `GET /search` est la palette ⌘K, présente sur tous les écrans, et elle
 *     CHERCHE dans `contacts.email` : taper « @ » puis un domaine y donnait les
 *     adresses en clair, ligne après ligne. Un export déguisé en champ de
 *     recherche.
 *
 * ── Pourquoi ce fichier contient un BALAYAGE, et pas une liste ──────────────
 *
 * Une garde de complétude qui énumère à la main les points d'API à vérifier ne
 * verra jamais le huitième site — c'est précisément ce qui a laissé ce constat
 * ouvert alors que sa pièce de masquage existait depuis le 2026-08-15. Le
 * dernier test de ce fichier n'énumère donc rien : il parcourt la TABLE DE
 * ROUTAGE, joue chaque route GET une fois en propriétaire pour découvrir
 * lesquelles servent réellement une coordonnée, puis rejoue exactement
 * celles-là en lecture seule. Un point d'API ajouté demain entre dans le
 * balayage sans que personne ait à y penser.
 */

use App\Crm\Taxonomy;
use App\Models\Company;
use App\Models\Contact;
use App\Models\User;
use App\Models\Workspace;
use App\Support\MasquageCoordonnees;
use Database\Seeders\PermissionsAndRolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route as RoutageFacade;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

// Valeurs en clair. Aucune ne doit apparaître dans une réponse servie à un
// compte dépourvu de `contacts.view_pii`. Les domaines sont en `.test` : une
// fuite dans un journal reste inexploitable.
const JUM_MAIL_ENTREPRISE = 'accueil@jumeaux-entreprise.test';
const JUM_TEL_ENTREPRISE = '+33611111111';
const JUM_MAIL_CONTACT = 'pierre.durand@jumeaux-contact.test';
const JUM_TEL_CONTACT = '+33622222222';
const JUM_MAIL_JOURNALISTE = 'julie.martin@jumeaux-presse.test';
const JUM_TEL_JOURNALISTE = '+33633333333';
const JUM_MAIL_MEDIA = 'redaction@jumeaux-media.test';
const JUM_TEL_MEDIA = '+33644444444';
const JUM_MAIL_CANDIDAT = 'alex.candidat@jumeaux-vivier.test';
const JUM_TEL_CANDIDAT = '+33655555555';
const JUM_MAIL_ORPHELIN = 'orphelin@jumeaux-arbitrage.test';
const JUM_TEL_ORPHELIN = '+33666666666';

/** @return list<string> toutes les valeurs en clair, d'un bloc */
function jumeauxValeursEnClair(): array
{
    return [
        JUM_MAIL_ENTREPRISE, JUM_TEL_ENTREPRISE,
        JUM_MAIL_CONTACT, JUM_TEL_CONTACT,
        JUM_MAIL_JOURNALISTE, JUM_TEL_JOURNALISTE,
        JUM_MAIL_MEDIA, JUM_TEL_MEDIA,
        JUM_MAIL_CANDIDAT, JUM_TEL_CANDIDAT,
        JUM_MAIL_ORPHELIN, JUM_TEL_ORPHELIN,
    ];
}

/**
 * Joue UNE route du balayage sous point de reprise, et rend son corps.
 *
 * ── Pourquoi ce point de reprise n'est pas une précaution de confort ────────
 *
 * `RefreshDatabase` enferme le test dans UNE transaction. Sous PostgreSQL, une
 * seule requête en erreur la met en état « aborted » : TOUT ce qui suit échoue,
 * et les contrôleurs de ce dépôt attrapent `Throwable` pour rendre
 * `{"data": [], "degraded": true}` plutôt qu'un 500. Le balayage serait donc
 * devenu VERT à partir de la première route fautive — vert par silence, la
 * pire des façons de passer.
 *
 * Mesuré : sans ce point de reprise, le balayage ne voyait plus que 8 routes
 * sur 14, et toutes celles déclarées APRÈS la fautive rendaient du vide.
 *
 * `beginTransaction` sur une transaction déjà ouverte pose un SAVEPOINT ;
 * `rollBack` y revient. Une route qui casse le SQL ne contamine plus les
 * suivantes, et le balayage garde le droit de rougir.
 */
function jumeauxCorpsIsole(TestCase $test, string $url): string
{
    DB::beginTransaction();

    try {
        return (string) $test->getJson($url)->getContent();
    } catch (Throwable) {
        // Une route qui lève jusqu'ici ne sert rien : elle ne peut pas fuiter.
        return '';
    } finally {
        try {
            DB::rollBack();
        } catch (Throwable) {
            // Point de reprise déjà consommé : rien à défaire.
        }
    }
}

function jumeauxUtilisateur(string $workspaceId, ?string $vivierId, ?string $role): User
{
    $u = User::create([
        'id' => (string) Str::uuid(),
        'email' => Str::uuid() . '@example.invalid',
        'name' => 'U',
        'password_hash' => Hash::make('PasswordTest12345!'),
        'current_workspace_id' => $workspaceId,
        'first_login_completed_at' => now(),
    ]);

    if ($role !== null) {
        $u->assignRole($role);
    }

    // La console v2 exige une APPARTENANCE (`user_workspaces`), pas un rôle :
    // sans elle, `/crm/*` répond 403 et le test mesurerait le refus d'accès,
    // pas le masquage.
    foreach (array_filter([$workspaceId, $vivierId]) as $ws) {
        DB::table('user_workspaces')->insertOrIgnore([
            'user_id' => $u->id,
            'workspace_id' => $ws,
            'role_slug' => 'owner',
            'invited_at' => now(),
            'joined_at' => now(),
        ]);
    }

    return $u;
}

beforeEach(function () {
    // Sans le drapeau, tout `/crm/*` répond 404 : le balayage mesurerait
    // l'inertie de la console, pas le masquage.
    config(['crm.console_v2' => true]);

    $this->seed(PermissionsAndRolesSeeder::class);

    $this->workspace = Workspace::create([
        'id' => (string) Str::uuid(), 'slug' => 'ws-jumeaux', 'name' => 'WS', 'settings' => [],
    ]);

    app(PermissionRegistrar::class)->setPermissionsTeamId($this->workspace->id);

    $this->vivierId = DB::table('workspaces')
        ->where('slug', Taxonomy::VIVIER_WORKSPACE_SLUG)
        ->value('id');
    $this->vivierId = $this->vivierId === null ? null : (string) $this->vivierId;

    $this->personKey = hash('sha256', 'jumeaux-b12-002');

    $entreprise = Company::create([
        'workspace_id' => $this->workspace->id,
        'siren' => '987654321',
        'denomination' => 'ZZ JUMEAUX',
        'email_generic' => JUM_MAIL_ENTREPRISE,
        'phone' => JUM_TEL_ENTREPRISE,
        'signals' => [], 'metadata' => [],
    ]);
    $this->companyId = (int) $entreprise->id;

    $contact = Contact::create([
        'workspace_id' => $this->workspace->id,
        'company_id' => $this->companyId,
        'first_name' => 'Pierre',
        'last_name' => 'DURAND',
        'email' => JUM_MAIL_CONTACT,
        'phone' => JUM_TEL_CONTACT,
        'sources' => [], 'metadata' => [],
    ]);
    $this->contactId = (int) $contact->id;

    // ⚠️ `person_key` N'EST PAS dans `$fillable` de `App\Models\Contact` : passé
    // à `create()`, il est silencieusement JETÉ. Mesuré le 2026-08-21 — la
    // colonne existe (migration 2026_08_14_000002) et toute la fiche 360° du
    // chantier CRM repose dessus. On écrit donc en direct, sinon ce jeu de
    // données mentirait et la timeline verdirait sur une absence de sujet.
    DB::table('contacts')->where('id', $this->contactId)
        ->update(['person_key' => $this->personKey]);

    $this->mediaId = (int) DB::table('media')->insertGetId([
        'workspace_id' => $this->workspace->id,
        'name' => 'ZZ GAZETTE',
        'media_type' => 'presse_quotidien',
        'email' => JUM_MAIL_MEDIA,
        'phone' => JUM_TEL_MEDIA,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $this->journalistId = (int) DB::table('journalists')->insertGetId([
        'workspace_id' => $this->workspace->id,
        'media_id' => $this->mediaId,
        'first_name' => 'Julie',
        'last_name' => 'MARTIN',
        'email' => JUM_MAIL_JOURNALISTE,
        'phone' => JUM_TEL_JOURNALISTE,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    if ($this->vivierId !== null) {
        $this->candidateId = (int) DB::table('candidates')->insertGetId([
            'workspace_id' => $this->vivierId,
            'last_name' => 'CANDIDAT',
            'first_name' => 'Alex',
            'email' => JUM_MAIL_CANDIDAT,
            'phone' => JUM_TEL_CANDIDAT,
            'person_key' => $this->personKey,
            'relation_type' => 'candidat_tech',
            'lifecycle_stage' => 'nouveau',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    $this->audienceId = (int) DB::table('email_audiences')->insertGetId([
        'workspace_id' => $this->workspace->id,
        'name' => 'ZZ AUDIENCE',
        'criteria' => '{}',
    ]);

    DB::table('audience_members')->insert([
        'audience_id' => $this->audienceId,
        'company_id' => $this->companyId,
        'contact_id' => $this->contactId,
        'workspace_id' => $this->workspace->id,
    ]);

    // Activité ORPHELINE : la file d'arbitrage. La coordonnée n'est pas dans
    // une colonne, elle est dans le JSON `payload -> pending_match`.
    DB::table('activities')->insert([
        'workspace_id' => $this->workspace->id,
        'type' => 'form_submission', 'kind' => 'form_submission',
        'occurred_at' => now()->subDay(),
        'person_key' => $this->personKey,
        'external_ref' => 'site:event:' . Str::uuid(),
        'subject_type' => null, 'subject_id' => null,
        'title' => 'Formulaire — audit',
        'payload' => json_encode([
            'pending_match' => [
                'denomination' => 'ZZ JUMEAUX',
                'email' => JUM_MAIL_ORPHELIN,
                'phone' => JUM_TEL_ORPHELIN,
            ],
        ], JSON_THROW_ON_ERROR),
        'created_at' => now(),
    ]);

    $this->viewer = jumeauxUtilisateur($this->workspace->id, $this->vivierId, 'viewer');
    $this->owner = jumeauxUtilisateur($this->workspace->id, $this->vivierId, 'owner');
});

// ═══════════════════════════════════════════════════════════════════════════
// TÉMOIN NÉGATIF DE L'INSTRUMENT — avant de mesurer un masquage, on prouve que
// l'instrument sait voir une coordonnée en clair. Sans ce test, un jeu de
// données cassé (fiche vide, 404, relation non chargée) rendrait TOUS les
// masquages verts par absence de donnée.
// ═══════════════════════════════════════════════════════════════════════════

test('TEMOIN a clair : le proprietaire lit les sept sites en entier', function () {
    $this->actingAs($this->owner);

    $vus = [];

    $j = $this->getJson("/api/v1/journalists/{$this->journalistId}")->assertOk();
    expect($j->json('email'))->toBe(JUM_MAIL_JOURNALISTE);
    expect($j->json('phone'))->toBe(JUM_TEL_JOURNALISTE);
    $vus[] = 'journalists.show';

    $liste = $this->getJson('/api/v1/journalists')->assertOk();
    $this->assertStringContainsString(JUM_MAIL_JOURNALISTE, (string) $liste->getContent());
    $vus[] = 'journalists.index';

    $m = $this->getJson("/api/v1/media/{$this->mediaId}")->assertOk();
    expect($m->json('email'))->toBe(JUM_MAIL_MEDIA);
    // La fiche media charge `journalists` : elle porte donc AUSSI la
    // coordonnee nominative. C'est ce qui en fait le pire des sept.
    $this->assertStringContainsString(JUM_MAIL_JOURNALISTE, (string) $m->getContent());
    $vus[] = 'media.show';

    $this->assertStringContainsString(
        JUM_MAIL_MEDIA,
        (string) $this->getJson('/api/v1/media')->assertOk()->getContent(),
    );
    $vus[] = 'media.index';

    $recherche = $this->getJson('/api/v1/search?q=DURAND')->assertOk();
    $this->assertStringContainsString(JUM_MAIL_CONTACT, (string) $recherche->getContent());
    $vus[] = 'search';

    $membres = $this->getJson("/api/v1/audiences/{$this->audienceId}/members")->assertOk();
    $this->assertStringContainsString(JUM_MAIL_CONTACT, (string) $membres->getContent());
    $vus[] = 'audiences.members';

    $timeline = $this->getJson("/api/v1/crm/persons/{$this->personKey}/timeline")->assertOk();
    $this->assertStringContainsString(JUM_MAIL_CONTACT, (string) $timeline->getContent());
    $vus[] = 'timeline';

    $arbitrage = $this->getJson('/api/v1/crm/arbitrage')->assertOk();
    $this->assertStringContainsString(JUM_MAIL_ORPHELIN, (string) $arbitrage->getContent());
    $vus[] = 'arbitrage';

    if ($this->vivierId !== null) {
        $candidats = $this->getJson('/api/v1/crm/candidates')->assertOk();
        $this->assertStringContainsString(JUM_MAIL_CANDIDAT, (string) $candidats->getContent());
        $vus[] = 'candidates';
    }

    // Le compte des sites REELLEMENT atteints, figé. Si une route change de
    // nom ou de forme, ce test rougit au lieu de laisser les assertions de
    // masquage verdir sur du vide.
    $this->assertGreaterThanOrEqual(8, count($vus));
});

// ═══════════════════════════════════════════════════════════════════════════
// LES SEPT SITES JUMEAUX
// ═══════════════════════════════════════════════════════════════════════════

test('un viewer ne lit PAS la fiche journaliste en clair', function () {
    $this->actingAs($this->viewer);

    $r = $this->getJson("/api/v1/journalists/{$this->journalistId}")->assertOk();

    expect($r->json('email'))->toBe('j***@jumeaux-presse.test');
    expect($r->json('phone'))->toBe('+336******33');
    $this->assertStringNotContainsString(JUM_MAIL_JOURNALISTE, (string) $r->getContent());
    $this->assertStringNotContainsString(JUM_TEL_JOURNALISTE, (string) $r->getContent());
});

test('un viewer ne lit PAS la liste des journalistes en clair', function () {
    $this->actingAs($this->viewer);

    $corps = (string) $this->getJson('/api/v1/journalists')->assertOk()->getContent();

    $this->assertStringContainsString('j***@jumeaux-presse.test', $corps);
    $this->assertStringNotContainsString(JUM_MAIL_JOURNALISTE, $corps);
    $this->assertStringNotContainsString(JUM_TEL_JOURNALISTE, $corps);
});

test('un viewer ne lit PAS la fiche media — ni sa redaction entiere', function () {
    // Le pire des sept : `->load('journalists')` livrait, en un appel, le
    // courriel et la ligne directe de toute une redaction.
    $this->actingAs($this->viewer);

    $corps = (string) $this->getJson("/api/v1/media/{$this->mediaId}")->assertOk()->getContent();

    $this->assertStringNotContainsString(JUM_MAIL_MEDIA, $corps);
    $this->assertStringNotContainsString(JUM_TEL_MEDIA, $corps);
    $this->assertStringNotContainsString(JUM_MAIL_JOURNALISTE, $corps);
    $this->assertStringNotContainsString(JUM_TEL_JOURNALISTE, $corps);
});

test('un viewer ne lit PAS la liste des medias en clair', function () {
    $this->actingAs($this->viewer);

    $corps = (string) $this->getJson('/api/v1/media')->assertOk()->getContent();

    $this->assertStringContainsString('r***@jumeaux-media.test', $corps);
    $this->assertStringNotContainsString(JUM_MAIL_MEDIA, $corps);
});

test('la palette ⌘K ne rend PAS les adresses en clair a un viewer', function () {
    // `/search` cherche DANS `contacts.email` et le renvoyait : un export
    // deguise en champ de recherche, present sur tous les ecrans.
    $this->actingAs($this->viewer);

    $r = $this->getJson('/api/v1/search?q=DURAND')->assertOk();

    expect($r->json('contacts.0.email'))->toBe('p***@jumeaux-contact.test');
    $this->assertStringNotContainsString(JUM_MAIL_CONTACT, (string) $r->getContent());
});

test('les membres d une audience ne sont PAS rendus en clair a un viewer', function () {
    // 500 lignes par appel, et une audience est PRECISEMENT une selection de
    // personnes a demarcher. La ligne est un `stdClass` de `DB::table` : sans
    // la branche `stdClass` de `masquer()`, l'appel serait un no-op silencieux.
    $this->actingAs($this->viewer);

    $r = $this->getJson("/api/v1/audiences/{$this->audienceId}/members")->assertOk();

    expect($r->json('data.0.email'))->toBe('p***@jumeaux-contact.test');
    $this->assertStringNotContainsString(JUM_MAIL_CONTACT, (string) $r->getContent());
});

test('la fiche 360 ne rend PAS les coordonnees en clair a un viewer', function () {
    $this->actingAs($this->viewer);

    $r = $this->getJson("/api/v1/crm/persons/{$this->personKey}/timeline")->assertOk();

    expect($r->json('subjects.0.email'))->toBe('p***@jumeaux-contact.test');
    expect($r->json('subjects.0.phone'))->toBe('+336******22');
    $this->assertStringNotContainsString(JUM_MAIL_CONTACT, (string) $r->getContent());
    $this->assertStringNotContainsString(JUM_TEL_CONTACT, (string) $r->getContent());
});

test('la file d arbitrage ne rend PAS la coordonnee du JSON en clair', function () {
    // La coordonnee vit dans `payload -> pending_match`, pas dans une colonne :
    // aucune enumeration de colonnes ne l'aurait trouvee.
    $this->actingAs($this->viewer);

    $r = $this->getJson('/api/v1/crm/arbitrage')->assertOk();

    expect($r->json('data.0.pending_match.email'))->toBe('o***@jumeaux-arbitrage.test');
    expect($r->json('data.0.pending_match.phone'))->toBe('+336******66');
    $this->assertStringNotContainsString(JUM_MAIL_ORPHELIN, (string) $r->getContent());
});

test('le vivier ne rend PAS les coordonnees des candidats en clair a un viewer', function () {
    if ($this->vivierId === null) {
        $this->markTestSkipped('workspace vivier absent de cette base');
    }

    $this->actingAs($this->viewer);

    $r = $this->getJson('/api/v1/crm/candidates')->assertOk();

    expect($r->json('data.0.email'))->toBe('a***@jumeaux-vivier.test');
    expect($r->json('data.0.phone'))->toBe('+336******55');
    $this->assertStringNotContainsString(JUM_MAIL_CANDIDAT, (string) $r->getContent());
});

test('ANGLE MORT DECLARE : les exports en flux echappent au balayage, la porte est fermee autrement', function () {
    // `StreamedResponse::getContent()` rend `false` : les deux exports CSV
    // (`/journalists/export`, `/media/export`) sont INVISIBLES au balayage,
    // alors qu'ils sortent courriel et telephone en clair, par milliers. Un
    // angle mort qu'on ne nomme pas devient un vert mensonger — on le nomme
    // ici, et on ferme la porte par la permission, en le MESURANT.
    $this->actingAs($this->viewer);

    $this->getJson('/api/v1/journalists/export')->assertForbidden();
    $this->getJson('/api/v1/media/export')->assertForbidden();

    // TEMOIN : sans lui, une route simplement cassee (404, 500) donnerait le
    // meme vert que la permission qui refuse.
    $this->actingAs($this->owner);
    $this->get('/api/v1/journalists/export')->assertOk();
});

// ═══════════════════════════════════════════════════════════════════════════
// LE BALAYAGE — la seule partie de ce fichier qui verra le HUITIÈME site.
// ═══════════════════════════════════════════════════════════════════════════

test('BALAYAGE : aucune route GET de l API ne sert une coordonnee en clair a un viewer', function () {
    // Certaines routes n'ont de sens qu'avec une saisie. Cette table AJOUTE de
    // la couverture ; elle n'en retire jamais. Une route absente d'ici est
    // quand même jouée, simplement sans paramètre de requête.
    $requetes = [
        'api/v1/search' => '?q=DURAND',
        'api/v1/crm/contacts-hub' => '?temperature=tous',
    ];

    $substitutions = [
        'company' => $this->companyId,
        'contact' => $this->contactId,
        'journalist' => $this->journalistId,
        'media' => $this->mediaId,
        'audience' => $this->audienceId,
        'personKey' => $this->personKey,
    ];

    $cibles = [];
    $nonRemplies = [];

    foreach (RoutageFacade::getRoutes() as $route) {
        if (! in_array('GET', $route->methods(), true)) {
            continue;
        }

        $gabarit = $route->uri();
        if (! str_starts_with($gabarit, 'api/v1/')) {
            continue;
        }

        $chemin = $gabarit;
        $complete = true;
        foreach ($route->parameterNames() as $nom) {
            // Paramètre OPTIONNEL (`{any?}`) : la route existe sans lui. On la
            // visite plutôt que de la déclarer hors d'atteinte — deux routes
            // sortaient de l'angle mort rien qu'en le remarquant.
            if (str_contains($chemin, '{' . $nom . '?}')) {
                $chemin = str_replace('/{' . $nom . '?}', '', $chemin);
                $chemin = str_replace('{' . $nom . '?}', '', $chemin);

                continue;
            }

            if (! array_key_exists($nom, $substitutions)) {
                $complete = false;
                break;
            }

            $chemin = str_replace('{' . $nom . '}', (string) $substitutions[$nom], $chemin);
        }

        if (! $complete) {
            $nonRemplies[$gabarit] = true;

            continue;
        }

        $cibles[$gabarit] = '/' . $chemin . ($requetes[$gabarit] ?? '');
    }

    // ── Passe 1 : le PROPRIÉTAIRE. On ne devine pas quelles routes servent une
    // coordonnée, on le MESURE. C'est le témoin de couverture : cet ensemble se
    // remplit tout seul quand un point d'API est ajouté.
    $this->actingAs($this->owner);
    $enClair = jumeauxValeursEnClair();
    $servent = [];

    foreach ($cibles as $gabarit => $url) {
        $corps = jumeauxCorpsIsole($this, $url);
        foreach ($enClair as $valeur) {
            if (str_contains($corps, $valeur)) {
                $servent[$gabarit] = true;
                break;
            }
        }
    }

    // Si ce compte tombe, ce n'est pas que le dépôt s'est assaini : c'est que
    // le balayage ne voit plus rien (jeu de données cassé, routes renommées,
    // drapeau console retombé). Une garde qui balaie doit rougir quand le
    // balayage revient vide — sinon son vert ne signifie rien.
    $this->assertGreaterThanOrEqual(
        14,
        count($servent),
        'Le balayage ne voit plus les points d\'API qui servent une coordonnee : '
        . 'son vert serait un vert de facade. Routes vues : ' . implode(', ', array_keys($servent)),
    );

    // ── Passe 2 : le VIEWER, sur exactement les routes qui servent quelque
    // chose. Aucune valeur en clair ne doit y survivre.
    $this->actingAs($this->viewer);
    $fuites = [];

    foreach (array_keys($servent) as $gabarit) {
        $corps = jumeauxCorpsIsole($this, $cibles[$gabarit]);
        foreach ($enClair as $valeur) {
            if (str_contains($corps, $valeur)) {
                $fuites[] = $gabarit . ' → ' . $valeur;
            }
        }
    }

    $this->assertSame(
        [],
        $fuites,
        "Ces routes rendent une coordonnee EN CLAIR a un compte en lecture seule :\n"
        . implode("\n", $fuites),
    );

    // ── Ce que le balayage ne peut PAS prouver, il le COMPTE.
    //
    // Ces routes ont un paramètre que ce jeu de données ne sait pas fabriquer.
    // Relevé du 2026-08-21, SEPT, et aucune ne rend une fiche nominative :
    //   api/v1/rgpd/export/{token}          jeton à usage unique (constat C21)
    //   api/v1/coverage/cells/{cell}        maille géographique, pas de personne
    //   api/v1/scraper-runs/{run}           exécution d'un robot
    //   api/v1/llm/use-cases/{u}/prompts    gabarits de prompt
    //   api/v1/saved-views/{saved_view}     critères de filtre enregistrés
    //   api/v1/campaigns/{campaign}         campagne (l'audience, elle, EST balayée)
    //   api/v1/campaigns/{campaign}/stats   compteurs agrégés
    //
    // Le chiffre est FIGÉ : une huitième route non visitée rougit ce test et
    // oblige soit à fournir la substitution, soit à dire ici pourquoi on s'en
    // passe. Sans ce gel, l'angle mort grandirait en silence — et c'est
    // exactement comme ça qu'on hérite d'un constat rouvert six mois plus tard.
    $this->assertLessThanOrEqual(
        7,
        count($nonRemplies),
        "Routes GET non visitees faute de substitution de parametre :\n"
        . implode("\n", array_keys($nonRemplies)),
    );
});

// ═══════════════════════════════════════════════════════════════════════════
// LA PIÈCE ELLE-MÊME — les deux portes d'entrée, et le no-op qu'elles évitent.
// ═══════════════════════════════════════════════════════════════════════════

test('masquerSiRequis sur un tableau associatif serait un NO-OP : c est pourquoi la seconde porte existe', function () {
    // Ce test fige la raison d'être de `masquerTableauSiRequis`. Sans lui,
    // quelqu'un « simplifiera » un jour les quatre appels en `masquerSiRequis`,
    // et le masquage redeviendra silencieusement inopérant sur les quatre
    // points d'API qui projettent en tableau.
    $this->actingAs($this->viewer);

    $ligne = ['email' => JUM_MAIL_CONTACT, 'phone' => JUM_TEL_CONTACT];

    // `masquerSiRequis` mute des OBJETS : sur un tableau de chaînes, il rend la
    // valeur inchangée. Ce n'est pas un défaut, c'est sa nature — et c'est
    // exactement le piège.
    expect(MasquageCoordonnees::masquerSiRequis($ligne))->toBe($ligne);

    $masquee = MasquageCoordonnees::masquerTableauSiRequis($ligne);
    expect($masquee['email'])->toBe('p***@jumeaux-contact.test');
    expect($masquee['phone'])->toBe('+336******22');
});

test('la porte tableau descend dans les sous-tableaux et respecte le droit', function () {
    $charge = ['data' => [['pending_match' => ['email' => JUM_MAIL_ORPHELIN]]]];

    $this->actingAs($this->viewer);
    $masquee = MasquageCoordonnees::masquerTableauSiRequis($charge);
    expect($masquee['data'][0]['pending_match']['email'])->toBe('o***@jumeaux-arbitrage.test');

    // TÉMOIN À CLAIR sur la pièce : un droit `contacts.view_pii` doit rendre la
    // valeur intacte. Une pièce qui masque pour tout le monde ne protège rien.
    $this->actingAs($this->owner);
    expect(MasquageCoordonnees::masquerTableauSiRequis($charge))->toBe($charge);
});
