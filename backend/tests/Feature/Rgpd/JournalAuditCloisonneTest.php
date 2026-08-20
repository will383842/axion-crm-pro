<?php

/**
 * GARDE DU CLOISONNEMENT DU JOURNAL D'AUDIT — constats B16-004 et P5-35-007.
 *
 * CE QUE LA PREMIÈRE CORRECTION A MANQUÉ, ET POURQUOI C'EST INSTRUCTIF.
 *
 * `GET /api/v1/audit-logs` rendait le journal de **tous les espaces de travail**
 * à **tout compte authentifié**. Un premier correctif (commit `46848d4`) a posé
 * la permission `audit.view` sur la route. Le `viewer` a bien été repoussé.
 * Mais la fuite entre espaces, elle, est restée entière : l'administrateur de
 * l'espace A continue de lire le journal de l'espace B.
 *
 * 🔑 Et la garde écrite pour prouver ce correctif **certifiait la fuite** :
 *
 *     expect($reponse->status())->not->toBe(403);
 *
 * Cette assertion ne demande qu'une chose — « est-ce autre chose qu'un 403 ? ».
 * Elle passe donc sur un **200 qui rend le journal d'un autre espace**. La
 * garde n'a pas échoué à voir le défaut : elle l'a inscrit en assertion.
 *
 * La contre-vérification adversariale de l'audit a relevé ce cas comme « le
 * plus retors de tous », parce que le motif — une garde qui mesure le mauvais
 * objet — s'est reproduit **dans le correctif d'un défaut dont ce motif était
 * la cause**. C'est le constat P5-35-007.
 *
 * CE QUE CETTE GARDE MESURE, ELLE.
 *
 * Non pas « ai-je le droit d'entrer », mais **« que contient ce qu'on me
 * rend »**. Une garde d'autorisation qui aboutit à un 200 doit toujours
 * inspecter le corps : le statut seul ne dit rien du cloisonnement.
 */

use App\Models\AuditLog;
use App\Models\User;
use App\Models\Workspace;
use Database\Seeders\PermissionsAndRolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    $this->seed(PermissionsAndRolesSeeder::class);
});

/** Un espace de travail, et un compte qui y porte le rôle demandé. */
function espaceEtCompte(string $etiquette, string $role = 'admin'): array
{
    $espace = Workspace::create([
        'id' => (string) Str::uuid(),
        'slug' => 'journal-' . $etiquette . '-' . Str::random(6),
        'name' => 'Espace ' . $etiquette,
    ]);

    $compte = User::create([
        'id' => (string) Str::uuid(),
        'email' => $etiquette . '-' . Str::random(6) . '@journal.test',
        'name' => 'Compte ' . $etiquette,
        'password_hash' => password_hash('MotDePasseDeTest2026!', PASSWORD_BCRYPT, ['cost' => 4]),
        'current_workspace_id' => $espace->id,
        'first_login_completed_at' => now(),
    ]);

    setPermissionsTeamId($espace->id);
    $compte->assignRole($role);

    return [$espace, $compte];
}

/** Une entrée de journal reconnaissable, rattachée à un espace. */
function entreeDeJournal(string $espaceId, string $chemin): AuditLog
{
    return AuditLog::create([
        'workspace_id' => $espaceId,
        'user_id' => null,
        'event_type' => 'http',
        'path' => $chemin,
        'status_code' => 200,
        'payload_hash' => hash('sha256', $chemin),
        'prev_hash' => str_repeat('0', 64),
        'current_hash' => hash('sha256', $chemin . $espaceId),
        'created_at' => now(),
    ]);
}

test('P5-35-007 — l administrateur d un espace ne lit QUE le journal de son espace', function () {
    [$espaceA, $adminA] = espaceEtCompte('a');
    [$espaceB] = espaceEtCompte('b');

    entreeDeJournal($espaceA->id, '/api/v1/chez-moi');
    entreeDeJournal($espaceB->id, '/api/v1/chez-le-voisin');

    $reponse = $this->actingAs($adminA)->getJson('/api/v1/audit-logs');

    // Le statut EXACT, pas « autre chose qu'un 403 » : c'est cette nuance qui a
    // laissé passer la fuite la première fois.
    $reponse->assertOk();

    $chemins = collect($reponse->json('data'))->pluck('path')->all();

    // ATTENTION : `expect()->toContain()` de Pest prend des VALEURS, pas un
    // message. Un second argument y est asserte comme une DEUXIEME valeur a
    // trouver. Ecrite ainsi, la garde cherchait sa propre phrase d'explication
    // dans la reponse : elle rougissait toujours, y compris une fois le
    // cloisonnement pose. On passe par assertContains(), qui porte un vrai
    // message d'echec.
    //
    // Le motif est celui-la meme que ce fichier denonce : une garde qui mesure
    // autre chose que ce qu'elle annonce. Releve ici sur elle-meme.

    // TEMOIN INTEGRE : sans lui, une reponse vide satisferait la garde, et on
    // ne saurait pas si le cloisonnement marche ou si la route est cassee.
    $this->assertContains(
        '/api/v1/chez-moi',
        $chemins,
        'La route ne rend rien du tout : ce vert ne prouverait pas le cloisonnement, '
        . 'seulement que la route est muette.'
    );

    $this->assertNotContains(
        '/api/v1/chez-le-voisin',
        $chemins,
        'Le journal d\'un AUTRE espace de travail est rendu. La permission `audit.view` '
        . 'a bien ete posee, mais elle ne dit rien de l\'espace : elle separe les roles, '
        . 'pas les clients.'
    );
});

test('P5-35-007 — le total annonce ne compte pas les lignes des autres espaces', function () {
    [$espaceA, $adminA] = espaceEtCompte('a');
    [$espaceB] = espaceEtCompte('b');

    entreeDeJournal($espaceA->id, '/api/v1/mien-1');
    entreeDeJournal($espaceA->id, '/api/v1/mien-2');
    entreeDeJournal($espaceB->id, '/api/v1/autre-1');
    entreeDeJournal($espaceB->id, '/api/v1/autre-2');
    entreeDeJournal($espaceB->id, '/api/v1/autre-3');

    $reponse = $this->actingAs($adminA)->getJson('/api/v1/audit-logs');
    $reponse->assertOk();

    // Un total qui compte les lignes du voisin est une fuite en soi : il révèle
    // le volume d'activité d'un autre client, même sans en montrer le détail.
    expect($reponse->json('meta.total'))->toBe(2);
});

test('P5-35-007 — TEMOIN : chaque espace voit bien SON journal, pas un journal vide', function () {
    [$espaceA, $adminA] = espaceEtCompte('a');
    [$espaceB, $adminB] = espaceEtCompte('b');

    entreeDeJournal($espaceA->id, '/api/v1/propre-a-a');
    entreeDeJournal($espaceB->id, '/api/v1/propre-a-b');

    $vuDeA = collect($this->actingAs($adminA)->getJson('/api/v1/audit-logs')->json('data'))->pluck('path')->all();
    $vuDeB = collect($this->actingAs($adminB)->getJson('/api/v1/audit-logs')->json('data'))->pluck('path')->all();

    // La garde doit distinguer « cloisonné » de « cassé pour tout le monde ».
    // Un correctif qui rendrait la liste vide passerait les deux tests
    // précédents ; il échoue ici.
    expect($vuDeA)->toBe(['/api/v1/propre-a-a']);
    expect($vuDeB)->toBe(['/api/v1/propre-a-b']);
});

test('B16-004 — un compte en LECTURE SEULE reste refuse, meme sur son propre espace', function () {
    [$espace, $viewer] = espaceEtCompte('lecture', 'viewer');
    entreeDeJournal($espace->id, '/api/v1/peu-importe');

    // Non-régression du premier correctif : le cloisonnement ne doit pas
    // remplacer la permission, il s'y ajoute.
    $this->actingAs($viewer)->getJson('/api/v1/audit-logs')->assertForbidden();
});
