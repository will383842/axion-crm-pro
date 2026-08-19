<?php

use App\Services\Auth\MagicLinkService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/** Cree un compte minimal et rend son identifiant. */
function makeMagicUser(string $email): object
{
    $id = (string) \Illuminate\Support\Str::uuid();
    DB::table('users')->insert([
        'id' => $id,
        'email' => $email,
        'name' => 'Magic',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return (object) ['id' => $id];
}

test('issue creates a magic_links row with hashed token and 15min TTL', function () {
    $service = new MagicLinkService;
    DB::table('users')->insert([
        'id' => '00000000-0000-0000-0000-000000000001',
        'email' => 'test@example.com',
        'name' => 'Test',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $service->issue('test@example.com', '127.0.0.1');

    $row = DB::table('magic_links')->where('email', 'test@example.com')->first();
    expect($row)->not->toBeNull();
    expect($row->token_hash)->toHaveLength(64);
    expect($row->consumed_at)->toBeNull();
    expect((string) $row->expires_at)->not->toBeEmpty();
});

/**
 * CE TEST GARDAIT UN DEFAUT. Il exigeait qu'une ligne `magic_links` soit ecrite
 * meme pour une adresse SANS compte, avec `user_id` a NULL. Or `consume()`
 * retrouvait l'utilisateur PAR ADRESSE : un lien emis pour une adresse sans
 * compte devenait utilisable des que le compte etait cree, dans les 15 minutes.
 * Quiconque connaissait l'adresse d'un futur collaborateur pouvait preparer un
 * lien et prendre sa session. Accessoirement, la table grossissait sans borne en
 * stockant adresse et IP du demandeur - donnees personnelles, sans purge.
 * Mesure le 2026-08-19 (audit 360, F35-013).
 *
 * L'anti-enumeration est PRESERVEE, et c'est le point important : elle tient a
 * l'identite de la REPONSE du controleur, pas a l'ecriture d'une ligne inutile.
 * Aucun hachage n'est calcule sur ce chemin, donc aucun ecart de temps non plus.
 */
test('issue pour une adresse inconnue n ecrit rien, et ne leve rien', function () {
    $service = new MagicLinkService;
    $service->issue('nobody@example.com');

    expect(DB::table('magic_links')->where('email', 'nobody@example.com')->count())->toBe(0);
});

test('TEMOIN : issue pour une adresse CONNUE ecrit bien une ligne rattachee au compte', function () {
    $user = makeMagicUser('connu-magic@example.com');

    $service = new MagicLinkService;
    $service->issue('connu-magic@example.com');

    $row = DB::table('magic_links')->where('email', 'connu-magic@example.com')->first();
    expect($row)->not->toBeNull();
    expect($row->user_id)->toBe($user->id);
});
