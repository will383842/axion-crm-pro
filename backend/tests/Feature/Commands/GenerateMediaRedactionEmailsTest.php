<?php

use App\Models\Workspace;
use App\Services\Email\MxEmailValidator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

function makeRedactionWorkspace(): Workspace
{
    return Workspace::create([
        'id'   => (string) Str::uuid(),
        'slug' => 'red-' . Str::random(6),
        'name' => 'WS Rédaction',
    ]);
}

function insertMedia(string $workspaceId, string $name, ?string $website, string $websiteStatus = 'found'): int
{
    return DB::table('media')->insertGetId([
        'workspace_id'   => $workspaceId,
        'name'           => $name,
        'media_type'     => 'presse_quotidien',
        'website'        => $website,
        'website_status' => $website ? $websiteStatus : 'pending',
        'email'          => null,
        'enrich_status'  => 'pending',
        'source'         => 'naf-extract',
        'created_at'     => now(),
        'updated_at'     => now(),
    ]);
}

/**
 * Mocke le MxEmailValidator : le domaine « presse-libre.test » a des MX (et
 * `redaction@` y résout), tout le reste est mort (aucun MX → tout invalid).
 */
function bindFakeValidator(): void
{
    $mock = Mockery::mock(MxEmailValidator::class);

    $mock->shouldReceive('resolveMxRecords')
        ->andReturnUsing(fn (string $domain) => $domain === 'presse-libre.test' ? ['mx1.presse-libre.test'] : []);

    $mock->shouldReceive('validate')
        ->andReturnUsing(function (string $email) {
            [$local, $domain] = array_pad(explode('@', strtolower(trim($email)), 2), 2, '');
            if ($domain !== 'presse-libre.test') {
                return ['status' => 'invalid', 'email' => $email];
            }
            // redaction@ = 1er candidat testé → verified sur ce domaine.
            return ['status' => $local === 'redaction' ? 'verified' : 'role', 'email' => $email];
        });

    test()->app->instance(MxEmailValidator::class, $mock);
}

it('écrit redaction@ sur un domaine qui a des MX et laisse null les domaines morts', function () {
    bindFakeValidator();
    $ws = makeRedactionWorkspace();

    $good = insertMedia($ws->id, 'La Presse Libre', 'https://www.presse-libre.test/actu');
    $dead = insertMedia($ws->id, 'Le Néant Quotidien', 'https://domaine-mort.test');

    test()->artisan('media:generate-redaction-emails', ['--workspace' => $ws->id])
        ->assertExitCode(0);

    expect(DB::table('media')->where('id', $good)->value('email'))->toBe('redaction@presse-libre.test')
        ->and(DB::table('media')->where('id', $dead)->value('email'))->toBeNull();
});

it('--dry-run ne persiste aucun email', function () {
    bindFakeValidator();
    $ws = makeRedactionWorkspace();

    $good = insertMedia($ws->id, 'La Presse Libre', 'https://presse-libre.test');

    test()->artisan('media:generate-redaction-emails', ['--workspace' => $ws->id, '--dry-run' => true])
        ->assertExitCode(0);

    expect(DB::table('media')->where('id', $good)->value('email'))->toBeNull();
});

it('est reprenable : ne retraite pas un média déjà pourvu d\'un email', function () {
    bindFakeValidator();
    $ws = makeRedactionWorkspace();

    $already = insertMedia($ws->id, 'Déjà Fait', 'https://presse-libre.test');
    DB::table('media')->where('id', $already)->update(['email' => 'chef@presse-libre.test']);

    test()->artisan('media:generate-redaction-emails', ['--workspace' => $ws->id])
        ->assertExitCode(0);

    // L'email existant n'est PAS écrasé.
    expect(DB::table('media')->where('id', $already)->value('email'))->toBe('chef@presse-libre.test');
});
