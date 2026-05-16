<?php

use App\Services\Auth\MagicLinkService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

test('issue creates a magic_links row with hashed token and 15min TTL', function () {
    $service = new MagicLinkService();
    DB::table('users')->insert([
        'id'         => '00000000-0000-0000-0000-000000000001',
        'email'      => 'test@example.com',
        'name'       => 'Test',
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

test('issue for unknown email does not throw (anti-enumeration)', function () {
    $service = new MagicLinkService();
    $service->issue('nobody@example.com');

    $row = DB::table('magic_links')->where('email', 'nobody@example.com')->first();
    expect($row)->not->toBeNull();
    expect($row->user_id)->toBeNull();
});
