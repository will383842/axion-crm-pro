<?php

use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

function syncParentWorkspace(): Workspace
{
    return Workspace::create([
        'id' => (string) Str::uuid(),
        'slug' => 'sp-' . Str::random(6),
        'name' => 'WS Sync Parent',
    ]);
}

function insertMedia(string $ws, array $attrs): int
{
    return DB::table('media')->insertGetId(array_merge([
        'workspace_id' => $ws,
        'name' => 'Média',
        'media_type' => 'tv',
        'created_at' => now(),
        'updated_at' => now(),
    ], $attrs));
}

it('fait hériter l\'émission sans email/site/tél de sa chaîne parente', function () {
    $ws = syncParentWorkspace();

    $channel = insertMedia($ws->id, [
        'name' => 'Canal+',
        'media_type' => 'tv',
        'email' => 'contact@canalplus.fr',
        'website' => 'https://www.canalplus.fr',
        'phone' => '0180001000',
    ]);

    $emission = insertMedia($ws->id, [
        'name' => 'Le Grand Journal',
        'media_type' => 'tv_emission',
        'parent_media_id' => $channel,
    ]);

    $this->artisan('media:sync-emissions-from-parent')->assertExitCode(0);

    $row = DB::table('media')->where('id', $emission)->first();
    expect($row->email)->toBe('contact@canalplus.fr')
        ->and($row->website)->toBe('https://www.canalplus.fr')
        ->and($row->phone)->toBe('0180001000');
});

it('N\'ÉCRASE PAS un champ déjà rempli sur l\'émission', function () {
    $ws = syncParentWorkspace();

    $channel = insertMedia($ws->id, [
        'name' => 'France 2',
        'email' => 'redaction@france2.fr',
        'website' => 'https://www.france2.fr',
        'phone' => '0140002000',
    ]);

    $emission = insertMedia($ws->id, [
        'name' => 'Télématin',
        'media_type' => 'tv_emission',
        'parent_media_id' => $channel,
        'email' => 'telematin@perso.fr',
        'website' => 'https://telematin.example',
        'phone' => '0999999999',
    ]);

    $this->artisan('media:sync-emissions-from-parent')->assertExitCode(0);

    $row = DB::table('media')->where('id', $emission)->first();
    // Rien n'a bougé : les valeurs propres de l'émission sont préservées.
    expect($row->email)->toBe('telematin@perso.fr')
        ->and($row->website)->toBe('https://telematin.example')
        ->and($row->phone)->toBe('0999999999');
});

it('est idempotent : un 2e run n\'affecte aucune ligne', function () {
    $ws = syncParentWorkspace();
    $channel = insertMedia($ws->id, ['name' => 'RTL', 'media_type' => 'radio', 'email' => 'contact@rtl.fr']);
    insertMedia($ws->id, ['name' => 'On refait le monde', 'media_type' => 'tv_emission', 'parent_media_id' => $channel]);

    $this->artisan('media:sync-emissions-from-parent')->assertExitCode(0);
    // 2e run : plus rien à hériter (IS DISTINCT FROM), aucune erreur.
    $this->artisan('media:sync-emissions-from-parent')->assertExitCode(0);

    expect(DB::table('media')->where('media_type', 'tv_emission')->where('email', 'contact@rtl.fr')->count())->toBe(1);
});

it('--dry-run ne persiste rien', function () {
    $ws = syncParentWorkspace();
    $channel = insertMedia($ws->id, ['name' => 'Europe 1', 'media_type' => 'radio', 'email' => 'contact@europe1.fr']);
    $emission = insertMedia($ws->id, ['name' => 'Matinale', 'media_type' => 'tv_emission', 'parent_media_id' => $channel]);

    $this->artisan('media:sync-emissions-from-parent', ['--dry-run' => true])->assertExitCode(0);

    expect(DB::table('media')->where('id', $emission)->value('email'))->toBeNull();
});
