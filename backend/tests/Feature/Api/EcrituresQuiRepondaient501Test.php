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
function compteE501(string $nom): array
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
    $compte->assignRole('admin');

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
