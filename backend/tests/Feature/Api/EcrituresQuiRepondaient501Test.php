<?php

/**
 * LES ECRITURES QUI REPONDAIENT 501 — exigence n. 10 du §12 du mandat.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * CE QUE CETTE GARDE COUVRE, ET POURQUOI ELLE EXISTE
 * ─────────────────────────────────────────────────────────────────────────────
 *
 * Le mandat exige « aucune route 501 ». Le recensement du 2026-08-23 en trouve
 * VINGT, dans onze controleurs. La vague 2 avait deja repare toutes les
 * LECTURES (`CorpsCodeEnDurTest`) : ces routes-la interrogent desormais
 * vraiment leur table. Ce qui restait en 501 est le cote ECRITURE — et les
 * controleurs le disaient eux-memes en commentaire, honnetement.
 *
 * Cette garde ferme le cote ecriture, lot par lot.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * 🔑 CE QUE CHAQUE CAS MESURE — trois exigences dans le meme appel
 * ─────────────────────────────────────────────────────────────────────────────
 *
 *  1. **L'ecriture a lieu.** On relit la BASE apres l'appel, jamais le corps de
 *     la reponse : une methode peut rendre `{"ok":true}` sans avoir rien ecrit,
 *     et c'est exactement le motif `B12-007` qu'on vient de fermer a cote.
 *
 *  2. **Elle ne deborde pas sur le voisin.** Une notification appartient a un
 *     ESPACE **et** a une PERSONNE — la colonne `user_id NOT NULL` de la
 *     migration `2026_05_16_000006` le dit. Chaque cas seme donc une ligne pour
 *     un collegue du MEME espace et une ligne d'un AUTRE espace, et exige que
 *     ni l'une ni l'autre ne bouge. Brancher une ecriture sur la base sans
 *     filtrer sur les deux dimensions rouvrirait `P6-API-001` le jour meme ou
 *     l'on ferme le 501.
 *
 *  3. **Le refus est un 404, pas un 403.** Repondre 403 sur la ligne d'un autre
 *     espace confirme son existence a qui n'a pas le droit de la voir. Le
 *     depot tranche deja ainsi (`ApiController::refuserHorsEspace`), on suit.
 *
 * ⚠️ `assertContains()` / `assertNotContains()`, JAMAIS `expect()->toContain()`
 * avec un message : ce dernier est VARIADIQUE et le message y devient une
 * seconde valeur cherchee. Piege referme six fois dans ce depot.
 *
 * ⚠️ Les helpers sont prefixes `e501` : Pest declare ces fonctions au niveau
 * GLOBAL, et deux fichiers qui nomment leur helper pareil finissent en
 * « Cannot redeclare function » — une fatale, pas un echec lisible.
 */

use App\Models\User;
use App\Models\Workspace;
use Database\Seeders\PermissionsAndRolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    $this->seed(PermissionsAndRolesSeeder::class);
});

/**
 * Un compte admin dans un espace de travail neuf.
 *
 * @return array{0: User, 1: string}
 */
function compteE501(string $nom, string $role = 'admin'): array
{
    $espace = Workspace::create([
        'id' => (string) Str::uuid(),
        'slug' => strtolower($nom) . '-e501-' . Str::random(8),
        'name' => $nom,
    ]);

    $compte = User::create([
        'id' => (string) Str::uuid(),
        'email' => strtolower($nom) . '-' . Str::random(6) . '@ecritures-501.test',
        'name' => $nom,
        'password_hash' => password_hash('MotDePasseDeTest2026!', PASSWORD_BCRYPT, ['cost' => 4]),
        'current_workspace_id' => $espace->id,
        'first_login_completed_at' => now(),
    ]);

    setPermissionsTeamId($espace->id);
    $compte->assignRole($role);

    return [$compte, (string) $espace->id];
}

/** Un second compte DANS LE MEME espace — le collegue dont il ne faut rien toucher. */
function collegueE501(string $espace, string $nom): User
{
    $compte = User::create([
        'id' => (string) Str::uuid(),
        'email' => strtolower($nom) . '-' . Str::random(6) . '@ecritures-501.test',
        'name' => $nom,
        'password_hash' => password_hash('MotDePasseDeTest2026!', PASSWORD_BCRYPT, ['cost' => 4]),
        'current_workspace_id' => $espace,
        'first_login_completed_at' => now(),
    ]);

    setPermissionsTeamId($espace);
    $compte->assignRole('admin');

    return $compte;
}

/** Seme une notification non lue et rend son identifiant (UUID). */
function notificationE501(string $espace, string $utilisateur, string $titre): string
{
    $id = (string) Str::uuid();

    DB::table('notifications')->insert([
        'id' => $id,
        'workspace_id' => $espace,
        'user_id' => $utilisateur,
        'type' => 'test',
        'title' => $titre,
        'body' => null,
        'action_url' => null,
        'read_at' => null,
        'created_at' => now(),
    ]);

    return $id;
}

/** `read_at` de la ligne, ou `null`. Relu en BASE, jamais dans la reponse. */
function lueE501(string $id): mixed
{
    return DB::table('notifications')->where('id', $id)->value('read_at');
}

// ═══════════════════════════════════════════════════════════════════════════
// LOT 1 — LA CLOCHE : `POST /notifications/{n}/read` et `/read-all`
// ═══════════════════════════════════════════════════════════════════════════

it('la table des notifications existe sur le banc, sans quoi tout ce lot est un vert deguise', function () {
    expect(Schema::hasTable('notifications'))->toBeTrue();
});

it('marque une notification comme lue, et l ecrit vraiment en base', function () {
    [$moi, $espace] = compteE501('Marqueur');
    $mienne = notificationE501($espace, (string) $moi->id, 'a-moi');

    expect(lueE501($mienne))->toBeNull();

    $this->actingAs($moi)
        ->postJson("/api/v1/notifications/{$mienne}/read")
        ->assertOk();

    expect(lueE501($mienne))->not->toBeNull();
});

it('ne marque pas la notification d un COLLEGUE du meme espace', function () {
    [$moi, $espace] = compteE501('Curieux');
    $collegue = collegueE501($espace, 'Collegue');
    $sienne = notificationE501($espace, (string) $collegue->id, 'a-lui');

    $this->actingAs($moi)
        ->postJson("/api/v1/notifications/{$sienne}/read")
        ->assertNotFound();

    expect(lueE501($sienne))->toBeNull();
});

it('ne marque pas la notification d un AUTRE espace, et repond 404 et non 403', function () {
    [$moi] = compteE501('Interne');
    [$autre, $autreEspace] = compteE501('Externe');
    $sienne = notificationE501($autreEspace, (string) $autre->id, 'ailleurs');

    $this->actingAs($moi)
        ->postJson("/api/v1/notifications/{$sienne}/read")
        ->assertNotFound();

    expect(lueE501($sienne))->toBeNull();
});

it('un identifiant qui n est pas un UUID rend 404 et non une erreur serveur', function () {
    [$moi] = compteE501('Malforme');

    // 🔴 La signature d'origine etait `markRead(int $n)` alors que la colonne
    // `id` est un UUID : le 501 masquait un typage qui aurait casse le jour de
    // son implementation. Cette ligne est la pour que ce piege ne revienne pas.
    $this->actingAs($moi)
        ->postJson('/api/v1/notifications/pas-un-uuid/read')
        ->assertNotFound();
});

it('marque TOUTES mes notifications, et aucune de celles des autres', function () {
    [$moi, $espace] = compteE501('Balayeur');
    $collegue = collegueE501($espace, 'Voisin');
    [$autre, $autreEspace] = compteE501('Lointain');

    $mienneA = notificationE501($espace, (string) $moi->id, 'a-moi-1');
    $mienneB = notificationE501($espace, (string) $moi->id, 'a-moi-2');
    $duCollegue = notificationE501($espace, (string) $collegue->id, 'au-voisin');
    $dAilleurs = notificationE501($autreEspace, (string) $autre->id, 'au-loin');

    $this->actingAs($moi)
        ->postJson('/api/v1/notifications/read-all')
        ->assertOk();

    expect(lueE501($mienneA))->not->toBeNull();
    expect(lueE501($mienneB))->not->toBeNull();
    expect(lueE501($duCollegue))->toBeNull();
    expect(lueE501($dAilleurs))->toBeNull();
});

it('ne rouvre pas la date de lecture d une notification deja lue', function () {
    [$moi, $espace] = compteE501('Idempotent');
    $mienne = notificationE501($espace, (string) $moi->id, 'deja-lue');

    $this->actingAs($moi)->postJson("/api/v1/notifications/{$mienne}/read")->assertOk();
    $premiere = lueE501($mienne);

    $this->actingAs($moi)->postJson("/api/v1/notifications/{$mienne}/read")->assertOk();

    // Relire ne doit pas deplacer l'horodatage : « lu a 14h02 » est une mesure,
    // pas un compteur de clics.
    expect((string) lueE501($mienne))->toBe((string) $premiere);
});

// ═══════════════════════════════════════════════════════════════════════════
// LOT 2 — LES VUES SAUVEGARDEES : `POST`, `GET {id}`, `PUT`, `DELETE`
// ═══════════════════════════════════════════════════════════════════════════
//
// `saved_views.id` est un BIGSERIAL, pas un UUID : ici le typage `int` des
// stubs etait JUSTE. On ne corrige donc pas ce qui n'est pas casse — mais on
// mesure quand meme la valeur absurde, parce qu'un identifiant d'un autre
// espace est un entier parfaitement valide.
//
// 🔑 `UNIQUE (user_id, entity, name)` est dans la migration. Une contrainte
// violee doit rendre 422, pas 500 : « ce nom existe deja » est une reponse,
// « erreur serveur » est un aveu.

/** Seme une vue sauvegardee et rend son identifiant. */
function vueE501(string $espace, string $utilisateur, string $nom, string $entite = 'companies'): int
{
    return (int) DB::table('saved_views')->insertGetId([
        'workspace_id' => $espace,
        'user_id' => $utilisateur,
        'entity' => $entite,
        'name' => $nom,
        'filters' => json_encode(['pays' => 'FR']),
        'is_default' => false,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

it('la table des vues sauvegardees existe sur le banc', function () {
    expect(Schema::hasTable('saved_views'))->toBeTrue();
});

it('cree une vue sauvegardee, et l ecrit vraiment en base avec mon espace et mon compte', function () {
    [$moi, $espace] = compteE501('Createur');

    $reponse = $this->actingAs($moi)->postJson('/api/v1/saved-views', [
        'entity' => 'companies',
        'name' => 'Mes cibles FR',
        'filters' => ['pays' => 'FR', 'taille' => 'PME'],
    ])->assertCreated();

    $id = (int) $reponse->json('data.id');

    $ligne = DB::table('saved_views')->where('id', $id)->first();
    expect($ligne)->not->toBeNull();
    expect((string) $ligne->workspace_id)->toBe($espace);
    expect((string) $ligne->user_id)->toBe((string) $moi->id);
    expect($ligne->name)->toBe('Mes cibles FR');

    // Les filtres doivent revenir en OBJET, pas en chaine JSON : c'est ce que
    // la lecture fait deja, les deux cotes doivent s'accorder.
    expect(json_decode((string) $ligne->filters, true))->toBe(['pays' => 'FR', 'taille' => 'PME']);
});

it('refuse une creation sans entite ni nom, en 422 et non en 500', function () {
    [$moi] = compteE501('Bavard');

    $this->actingAs($moi)
        ->postJson('/api/v1/saved-views', ['filters' => []])
        ->assertStatus(422);
});

it('rend 422 et non 500 quand le nom existe deja pour la meme entite', function () {
    [$moi, $espace] = compteE501('Doubleur');
    vueE501($espace, (string) $moi->id, 'Doublon');

    $this->actingAs($moi)
        ->postJson('/api/v1/saved-views', [
            'entity' => 'companies',
            'name' => 'Doublon',
            'filters' => [],
        ])
        ->assertStatus(422);
});

it('lit une de mes vues, et jamais celle d un collegue ni d un autre espace', function () {
    [$moi, $espace] = compteE501('Lecteur');
    $collegue = collegueE501($espace, 'Autre');
    [$lointain, $autreEspace] = compteE501('Lointain');

    $mienne = vueE501($espace, (string) $moi->id, 'a-moi');
    $duCollegue = vueE501($espace, (string) $collegue->id, 'au-voisin');
    $dAilleurs = vueE501($autreEspace, (string) $lointain->id, 'au-loin');

    $this->actingAs($moi)->getJson("/api/v1/saved-views/{$mienne}")->assertOk();
    $this->actingAs($moi)->getJson("/api/v1/saved-views/{$duCollegue}")->assertNotFound();
    $this->actingAs($moi)->getJson("/api/v1/saved-views/{$dAilleurs}")->assertNotFound();
});

it('modifie une de mes vues, et refuse celle d un autre sans la toucher', function () {
    [$moi, $espace] = compteE501('Modifieur');
    $collegue = collegueE501($espace, 'Intouchable');

    $mienne = vueE501($espace, (string) $moi->id, 'avant');
    $sienne = vueE501($espace, (string) $collegue->id, 'a-lui');

    $this->actingAs($moi)
        ->putJson("/api/v1/saved-views/{$mienne}", ['name' => 'apres'])
        ->assertOk();
    expect(DB::table('saved_views')->where('id', $mienne)->value('name'))->toBe('apres');

    $this->actingAs($moi)
        ->putJson("/api/v1/saved-views/{$sienne}", ['name' => 'vole'])
        ->assertNotFound();
    expect(DB::table('saved_views')->where('id', $sienne)->value('name'))->toBe('a-lui');
});

it('supprime une de mes vues, et refuse celle d un autre sans la supprimer', function () {
    [$moi, $espace] = compteE501('Suppresseur');
    $collegue = collegueE501($espace, 'Preserve');

    $mienne = vueE501($espace, (string) $moi->id, 'jetable');
    $sienne = vueE501($espace, (string) $collegue->id, 'gardee');

    $this->actingAs($moi)->deleteJson("/api/v1/saved-views/{$mienne}")->assertOk();
    expect(DB::table('saved_views')->where('id', $mienne)->exists())->toBeFalse();

    $this->actingAs($moi)->deleteJson("/api/v1/saved-views/{$sienne}")->assertNotFound();
    expect(DB::table('saved_views')->where('id', $sienne)->exists())->toBeTrue();
});

it('une seule vue par defaut et par entite : poser la nouvelle retire l ancienne', function () {
    [$moi, $espace] = compteE501('Defaut');

    $premiere = vueE501($espace, (string) $moi->id, 'premiere');
    DB::table('saved_views')->where('id', $premiere)->update(['is_default' => true]);

    $reponse = $this->actingAs($moi)->postJson('/api/v1/saved-views', [
        'entity' => 'companies',
        'name' => 'seconde',
        'filters' => [],
        'is_default' => true,
    ])->assertCreated();

    $seconde = (int) $reponse->json('data.id');

    // « Par defaut » au pluriel ne veut rien dire : l'ecran doit pouvoir en
    // ouvrir UNE sans choisir.
    expect((bool) DB::table('saved_views')->where('id', $seconde)->value('is_default'))->toBeTrue();
    expect((bool) DB::table('saved_views')->where('id', $premiere)->value('is_default'))->toBeFalse();
});

// ═══════════════════════════════════════════════════════════════════════════
// LOT 3 — LE REGLAGE DE L'ESPACE : `PUT /workspace`
// ═══════════════════════════════════════════════════════════════════════════
//
// La route porte deja `permission:workspaces.manage` : l'autorisation n'est pas
// l'objet de ce lot. Ce qui est en jeu ici, c'est QUELS CHAMPS une console a le
// droit de changer sur son propre espace — et deux d'entre eux ne doivent PAS
// l'etre depuis l'interieur :
//
//   `slug`      — il est UNIQUE et sert d'adresse ; le changer depuis un ecran
//                 casserait silencieusement toute reference exterieure.
//   `is_active` — un espace se desactive depuis l'ADMINISTRATION, pas depuis
//                 lui-meme : se couper le courant de l'interieur enferme tous
//                 ses membres dehors, sans recours par le produit.
//
// Une route qui accepte tout ce qu'on lui envoie n'est pas « souple », elle est
// non specifiee. On mesure donc aussi ce qu'elle REFUSE de faire.
//
// ⚠️ CES CAS EXIGENT `owner`, ET C'EST LA GARDE QUI ME L'A APPRIS. Ecrits avec
// un compte `admin`, ils rendaient 403 : dans `PermissionsAndRolesSeeder`, le
// role `admin` porte `users.manage` mais PAS `workspaces.manage`, que seul
// `owner` detient. Le libelle « Administrateur workspace » le laisse mal
// deviner. Ce n'etait donc pas la route qui avait tort, c'etait mon compte de
// test — et on le note ici pour que le prochain ne repaie pas la surprise.

it('modifie le nom et le plafond de mon espace, et l ecrit vraiment en base', function () {
    [$moi, $espace] = compteE501('Reglant', 'owner');

    $this->actingAs($moi)
        ->putJson('/api/v1/workspace', ['name' => 'Nouveau nom', 'cost_cap_eur' => 1234.50])
        ->assertOk();

    $ligne = DB::table('workspaces')->where('id', $espace)->first();
    expect($ligne->name)->toBe('Nouveau nom');
    expect((float) $ligne->cost_cap_eur)->toBe(1234.50);
});

it('remplace les reglages libres de mon espace', function () {
    [$moi, $espace] = compteE501('Reglages', 'owner');

    $this->actingAs($moi)
        ->putJson('/api/v1/workspace', ['settings' => ['fuseau' => 'Europe/Paris']])
        ->assertOk();

    expect(json_decode((string) DB::table('workspaces')->where('id', $espace)->value('settings'), true))
        ->toBe(['fuseau' => 'Europe/Paris']);
});

it('ne touche JAMAIS l espace d un autre, meme si le corps le nomme', function () {
    [$moi, $espace] = compteE501('Chezmoi', 'owner');
    [, $autreEspace] = compteE501('Chezlautre', 'owner');

    $avant = DB::table('workspaces')->where('id', $autreEspace)->value('name');

    $this->actingAs($moi)
        ->putJson('/api/v1/workspace', ['name' => 'Detourne', 'id' => $autreEspace, 'workspace_id' => $autreEspace])
        ->assertOk();

    // L'espace vise est TOUJOURS le mien : il vient du contexte, jamais du corps.
    expect(DB::table('workspaces')->where('id', $autreEspace)->value('name'))->toBe($avant);
    expect(DB::table('workspaces')->where('id', $espace)->value('name'))->toBe('Detourne');
});

it('refuse un plafond negatif, en 422 et non en 500', function () {
    [$moi] = compteE501('Negatif', 'owner');

    $this->actingAs($moi)
        ->putJson('/api/v1/workspace', ['cost_cap_eur' => -1])
        ->assertStatus(422);
});

it('ne laisse changer ni l adresse ni l etat actif de l espace depuis l interieur', function () {
    [$moi, $espace] = compteE501('Immuable', 'owner');
    $slugAvant = DB::table('workspaces')->where('id', $espace)->value('slug');

    $this->actingAs($moi)
        ->putJson('/api/v1/workspace', ['slug' => 'vole', 'is_active' => false, 'name' => 'ok'])
        ->assertOk();

    expect(DB::table('workspaces')->where('id', $espace)->value('slug'))->toBe($slugAvant);
    expect((bool) DB::table('workspaces')->where('id', $espace)->value('is_active'))->toBeTrue();
    // Le champ legitime du meme appel doit, lui, avoir ete pris en compte :
    // sans quoi la garde passerait au vert sur une route qui refuse TOUT.
    expect(DB::table('workspaces')->where('id', $espace)->value('name'))->toBe('ok');
});

// ═══════════════════════════════════════════════════════════════════════════
// LOT 4 — LES COMPTES : `POST`, `PUT`, `DELETE /users/{user}`
// ═══════════════════════════════════════════════════════════════════════════
//
// 🔑 CE QUE LE SCHEMA DIT DU GESTE, ET QU'IL FALLAIT LIRE AVANT D'INVENTER.
// `user_workspaces` porte `role_slug`, `invited_at`, `joined_at`, `revoked_at`
// (migration 2026_05_16_000002). Ce n'est pas une simple table de liaison :
// c'est une table d'INVITATION. Le produit avait deja pense le geste ; la route
// ne le posait simplement pas.
//
// 🔴 ET ON N'INSCRIT AUCUN MOT DE PASSE. `users.password_hash` est NULLABLE, et
// le constat f35-008 de ce meme audit s'appelle « mot de passe proprietaire en
// clair ». Un ecran d'administration qui choisit le mot de passe d'autrui le
// connait — donc le compte n'est plus a personne. On cree un compte SANS secret
// et la personne s'en donne un par le parcours existant.
//
// ⚠️ CE QUE CE LOT NE FAIT PAS, et il faut le dire : AUCUN COURRIEL N'EST
// ENVOYE. `MAIL_MAILER` etait l'un des quatre verrous du rapport final, et une
// route qui pretend inviter alors que rien ne part serait une facade de plus.
// La ligne d'invitation est ECRITE et datee ; sa remise est un autre chantier.

/** Le role porte par un compte dans `user_workspaces`. */
function roleE501(string $utilisateur, string $espace): ?string
{
    return DB::table('user_workspaces')
        ->where('user_id', $utilisateur)
        ->where('workspace_id', $espace)
        ->value('role_slug');
}

it('cree un compte invite, sans mot de passe, avec sa ligne d invitation datee', function () {
    [$moi, $espace] = compteE501('Inviteur');

    $reponse = $this->actingAs($moi)->postJson('/api/v1/users', [
        'email' => 'nouvelle-recrue@ecritures-501.test',
        'name' => 'Nouvelle Recrue',
        'role' => 'operator',
    ])->assertCreated();

    $id = (string) $reponse->json('data.id');
    $ligne = DB::table('users')->where('id', $id)->first();

    expect($ligne)->not->toBeNull();
    expect((string) $ligne->current_workspace_id)->toBe($espace);

    // 🔴 Le coeur de ce cas : aucun secret n'a ete choisi par l'administrateur.
    expect($ligne->password_hash)->toBeNull();

    // Et l'invitation existe, datee, pas encore acceptee.
    $invitation = DB::table('user_workspaces')
        ->where('user_id', $id)->where('workspace_id', $espace)->first();
    expect($invitation)->not->toBeNull();
    expect($invitation->role_slug)->toBe('operator');
    expect($invitation->invited_at)->not->toBeNull();
    expect($invitation->joined_at)->toBeNull();
});

it('refuse une adresse deja prise, en 422 et non en 500', function () {
    [$moi] = compteE501('Redondant');

    $this->actingAs($moi)->postJson('/api/v1/users', [
        'email' => 'doublon@ecritures-501.test', 'name' => 'Un', 'role' => 'viewer',
    ])->assertCreated();

    // `users.email` est CITEXT NOT NULL UNIQUE : sans controle prealable,
    // Postgres leve et la reponse serait un 500. CITEXT, donc la casse ne
    // sauve pas non plus.
    $this->actingAs($moi)->postJson('/api/v1/users', [
        'email' => 'DOUBLON@ecritures-501.test', 'name' => 'Deux', 'role' => 'viewer',
    ])->assertStatus(422);
});

it('refuse un role qui n existe pas, en 422', function () {
    [$moi] = compteE501('Inventeur');

    // La colonne porte un CHECK (owner, admin, operator, viewer) : une valeur
    // inventee doit tomber ICI, pas dans Postgres.
    $this->actingAs($moi)->postJson('/api/v1/users', [
        'email' => 'chimere@ecritures-501.test', 'name' => 'Chimere', 'role' => 'sorcier',
    ])->assertStatus(422);
});

it('modifie un compte de mon espace, et jamais un compte d un autre espace', function () {
    [$moi, $espace] = compteE501('Modifieur2');
    $collegue = collegueE501($espace, 'Modifiable');
    [$lointain] = compteE501('Lointain2');

    $this->actingAs($moi)
        ->putJson("/api/v1/users/{$collegue->id}", ['name' => 'Renomme'])
        ->assertOk();
    expect(DB::table('users')->where('id', $collegue->id)->value('name'))->toBe('Renomme');

    $avant = DB::table('users')->where('id', $lointain->id)->value('name');
    $this->actingAs($moi)
        ->putJson("/api/v1/users/{$lointain->id}", ['name' => 'Detourne'])
        ->assertNotFound();
    expect(DB::table('users')->where('id', $lointain->id)->value('name'))->toBe($avant);
});

it('change le role d un compte, et l ecrit dans la table d invitation', function () {
    [$moi, $espace] = compteE501('Promoteur');
    $collegue = collegueE501($espace, 'Promu');

    DB::table('user_workspaces')->insert([
        'user_id' => $collegue->id, 'workspace_id' => $espace,
        'role_slug' => 'viewer', 'invited_at' => now(), 'joined_at' => now(),
    ]);

    $this->actingAs($moi)
        ->putJson("/api/v1/users/{$collegue->id}", ['role' => 'admin'])
        ->assertOk();

    expect(roleE501((string) $collegue->id, $espace))->toBe('admin');
});

it('ne laisse pas modifier l adresse d un compte : c est son identite', function () {
    [$moi, $espace] = compteE501('Usurpateur');
    $collegue = collegueE501($espace, 'Cible');
    $avant = DB::table('users')->where('id', $collegue->id)->value('email');

    $this->actingAs($moi)
        ->putJson("/api/v1/users/{$collegue->id}", [
            'email' => 'vole@ecritures-501.test', 'name' => 'ok',
        ])
        ->assertOk();

    // L'adresse est l'identifiant de connexion : la changer par un ecran
    // d'administration, sans verification, revient a prendre le compte.
    expect(DB::table('users')->where('id', $collegue->id)->value('email'))->toBe($avant);
    expect(DB::table('users')->where('id', $collegue->id)->value('name'))->toBe('ok');
});

it('ferme un compte en douceur et revoque son invitation, sans effacer la ligne', function () {
    [$moi, $espace] = compteE501('Fermeur');
    $collegue = collegueE501($espace, 'Ferme');

    DB::table('user_workspaces')->insert([
        'user_id' => $collegue->id, 'workspace_id' => $espace,
        'role_slug' => 'viewer', 'invited_at' => now(), 'joined_at' => now(),
    ]);

    $this->actingAs($moi)->deleteJson("/api/v1/users/{$collegue->id}")->assertOk();

    // `users.deleted_at` existe et le modele porte `SoftDeletes` : un compte se
    // ferme, il ne s'efface pas. Le journal d'audit reference son auteur.
    $ligne = DB::table('users')->where('id', $collegue->id)->first();
    expect($ligne)->not->toBeNull();
    expect($ligne->deleted_at)->not->toBeNull();

    expect(DB::table('user_workspaces')->where('user_id', $collegue->id)->value('revoked_at'))
        ->not->toBeNull();
});

it('refuse de me fermer moi-meme', function () {
    [$moi] = compteE501('Suicidaire');

    // Sans ce refus, le dernier administrateur connecte peut se verrouiller
    // dehors, et plus personne ne peut rouvrir la porte par le produit.
    $this->actingAs($moi)->deleteJson("/api/v1/users/{$moi->id}")->assertStatus(422);
    expect(DB::table('users')->where('id', $moi->id)->value('deleted_at'))->toBeNull();
});

it('ne ferme pas un compte d un autre espace', function () {
    [$moi] = compteE501('Interne2');
    [$lointain] = compteE501('Externe2');

    $this->actingAs($moi)->deleteJson("/api/v1/users/{$lointain->id}")->assertNotFound();
    expect(DB::table('users')->where('id', $lointain->id)->value('deleted_at'))->toBeNull();
});
