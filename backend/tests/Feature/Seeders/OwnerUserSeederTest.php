<?php

use Database\Seeders\OwnerUserSeeder;
use Database\Seeders\PermissionsAndRolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    // Seed les rôles d'abord (dépendance owner role)
    $this->seed(PermissionsAndRolesSeeder::class);
});

test('OwnerUserSeeder crée workspace + owner avec password depuis env', function () {
    // Set env var
    putenv('OWNER_INITIAL_EMAIL=owner@test.local');
    putenv('OWNER_INITIAL_NAME=Owner Test');
    putenv('OWNER_INITIAL_PASSWORD=ExplicitPassword!9876');

    try {
        $this->seed(OwnerUserSeeder::class);

        $workspace = DB::table('workspaces')->where('slug', 'axion-ia')->first();
        expect($workspace)->not->toBeNull();

        $user = DB::table('users')->where('email', 'owner@test.local')->first();
        expect($user)->not->toBeNull();
        expect($user->password_hash)->not->toBeNull();
        expect($user->password_hash)->not->toBe('');
        expect(Hash::check('ExplicitPassword!9876', $user->password_hash))->toBeTrue();
        expect($user->current_workspace_id)->toBe($workspace->id);
    } finally {
        putenv('OWNER_INITIAL_EMAIL');
        putenv('OWNER_INITIAL_NAME');
        putenv('OWNER_INITIAL_PASSWORD');
    }
});

test('OwnerUserSeeder génère password sécurisé si OWNER_INITIAL_PASSWORD vide', function () {
    Storage::fake('local');

    putenv('OWNER_INITIAL_EMAIL=owner2@test.local');
    putenv('OWNER_INITIAL_PASSWORD');  // unset

    try {
        $this->seed(OwnerUserSeeder::class);

        $user = DB::table('users')->where('email', 'owner2@test.local')->first();
        expect($user)->not->toBeNull();
        expect($user->password_hash)->not->toBeNull();
        // Hash bcrypt commence par $2y$
        expect($user->password_hash)->toStartWith('$2y$');

        // Fichier persisté
        Storage::disk('local')->assertExists('seeders/owner-initial-password.txt');
    } finally {
        putenv('OWNER_INITIAL_EMAIL');
    }
});

test('OwnerUserSeeder est idempotent (re-run ne casse pas)', function () {
    putenv('OWNER_INITIAL_EMAIL=owner3@test.local');
    putenv('OWNER_INITIAL_PASSWORD=FirstPassword!2345');

    try {
        $this->seed(OwnerUserSeeder::class);
        $userBefore = DB::table('users')->where('email', 'owner3@test.local')->first();

        // Re-run
        $this->seed(OwnerUserSeeder::class);
        $userAfter = DB::table('users')->where('email', 'owner3@test.local')->first();

        expect($userAfter->id)->toBe($userBefore->id);
        expect($userAfter->password_hash)->toBe($userBefore->password_hash);

        // Un seul workspace axion-ia
        expect(DB::table('workspaces')->where('slug', 'axion-ia')->count())->toBe(1);
    } finally {
        putenv('OWNER_INITIAL_EMAIL');
        putenv('OWNER_INITIAL_PASSWORD');
    }
});

test('OwnerUserSeeder pose pivot user_workspaces avec role owner', function () {
    putenv('OWNER_INITIAL_EMAIL=owner4@test.local');
    putenv('OWNER_INITIAL_PASSWORD=PivotTest!8821');

    try {
        $this->seed(OwnerUserSeeder::class);

        $user = DB::table('users')->where('email', 'owner4@test.local')->first();
        $workspace = DB::table('workspaces')->where('slug', 'axion-ia')->first();

        $pivot = DB::table('user_workspaces')
            ->where('user_id', $user->id)
            ->where('workspace_id', $workspace->id)
            ->first();

        expect($pivot)->not->toBeNull();
        expect($pivot->role_slug)->toBe('owner');
    } finally {
        putenv('OWNER_INITIAL_EMAIL');
        putenv('OWNER_INITIAL_PASSWORD');
    }
});
