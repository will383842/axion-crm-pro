<?php

use App\Contracts\LLMClient;
use App\Data\LLM\LLMRequestData;
use App\Data\LLM\LLMResponseData;
use App\Models\Journalist;
use App\Models\Media;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/**
 * `journalists:scrape-ours` — extraction LLM (Mistral) qui remplace la regex.
 * Le LLM est mocké (JSON déterministe) ; le fetch HTTP des pages ours est faké.
 */

function makeJournoWorkspace(): Workspace
{
    return Workspace::create([
        'id'   => (string) Str::uuid(),
        'slug' => 'jl-' . Str::random(6),
        'name' => 'WS Journalists',
    ]);
}

function makeJournoMedia(Workspace $ws): Media
{
    return Media::create([
        'workspace_id' => $ws->id,
        'name'         => 'Le Média Test',
        'media_type'   => 'presse_quotidien',
        'website'      => 'https://media-test.example',
    ]);
}

/** Bind un LLMClient qui renvoie toujours le même texte JSON. */
function fakeLlmReturning(string $jsonText): void
{
    test()->app->instance(LLMClient::class, new class($jsonText) implements LLMClient {
        public function __construct(private string $jsonText) {}

        public function complete(LLMRequestData $request): LLMResponseData
        {
            return new LLMResponseData(
                text: $this->jsonText,
                providerUsed: 'mock',
                modelUsed: 'mistral-small-latest',
                promptTemplateSlug: $request->useCaseSlug,
            );
        }
    });
}

beforeEach(function () {
    config()->set('services.media.journalists_enabled', true);
    // Page ours fakee : >= 500 caracteres de texte pour passer MIN_BODY_LENGTH.
    Http::fake([
        '*' => Http::response(
            '<html><body><h1>Ours</h1><p>' . str_repeat('Rédaction du Média Test. ', 40) . '</p></body></html>',
            200,
            ['Content-Type' => 'text/html'],
        ),
    ]);
});

it('upsert 2 journalistes quand le LLM en renvoie 2', function () {
    fakeLlmReturning(json_encode(['journalists' => [
        ['first_name' => 'Claire', 'last_name' => 'Durand', 'role' => 'Directrice de la publication', 'beat' => ''],
        ['first_name' => 'Marc', 'last_name' => 'Petit', 'role' => 'Rédacteur en chef', 'beat' => 'politique'],
    ]]));

    $ws = makeJournoWorkspace();
    $media = makeJournoMedia($ws);

    $this->artisan('journalists:scrape-ours', ['--limit' => 10])->assertExitCode(0);

    expect(Journalist::where('media_id', $media->id)->count())->toBe(2);

    $claire = Journalist::where('last_name', 'Durand')->first();
    expect($claire)->not->toBeNull()
        ->and($claire->first_name)->toBe('Claire')
        ->and($claire->role)->toBe('Directrice de la publication')
        ->and($claire->source)->toBe('ours-llm')
        ->and($claire->source_url)->toBe('https://media-test.example')
        ->and($claire->opt_out)->toBeFalse();

    $marc = Journalist::where('last_name', 'Petit')->first();
    expect($marc->beat)->toBe('politique');
});

it('ne crée aucun journaliste quand le LLM renvoie une liste vide', function () {
    fakeLlmReturning(json_encode(['journalists' => []]));

    $ws = makeJournoWorkspace();
    $media = makeJournoMedia($ws);

    $this->artisan('journalists:scrape-ours', ['--limit' => 10])->assertExitCode(0);

    expect(Journalist::where('media_id', $media->id)->count())->toBe(0);
});

it('refuse si MEDIA_JOURNALISTS_ENABLED est false', function () {
    config()->set('services.media.journalists_enabled', false);
    fakeLlmReturning(json_encode(['journalists' => [
        ['first_name' => 'X', 'last_name' => 'Y', 'role' => 'Journaliste', 'beat' => ''],
    ]]));

    $ws = makeJournoWorkspace();
    makeJournoMedia($ws);

    $this->artisan('journalists:scrape-ours')->assertExitCode(1);
    expect(Journalist::count())->toBe(0);
});
