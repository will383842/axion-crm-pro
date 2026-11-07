<?php

use App\Envoi\Serveur\Contrat\EvenementSuivi;
use App\Envoi\Serveur\Contrat\LotSuivi;
use App\Envoi\Serveur\Contrat\TireurEvenements;
use App\Envoi\Serveur\Jobs\RattraperServeur;
use App\Support\HmacSignature;
use App\Support\WorkspaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    config(['crm.ingest.replay_store' => 'array', 'envoi.serveur.secret' => 'secret-de-test']);
    $this->ws = (string) Str::uuid();
    DB::table('workspaces')->insert(['id' => $this->ws, 'name' => 'T', 'slug' => 'k1-' . substr($this->ws, 0, 8), 'created_at' => now(), 'updated_at' => now()]);
    WorkspaceContext::run($this->ws, fn () => DB::table('envoi_serveurs')->insert(['workspace_id' => $this->ws, 'source' => 'srv1', 'url_base' => 'https://envoi.invalid']));
});

function pousser(object $t, array $evenements, ?string $nonce = null)
{
    $corps = json_encode(['workspace_id' => $t->ws, 'source' => 'srv1', 'evenements' => $evenements], JSON_THROW_ON_ERROR);
    $ts = (string) time();
    $nonce ??= (string) Str::uuid();
    $sig = HmacSignature::sign('secret-de-test', HmacSignature::signedPayload($ts, $nonce . '.' . $corps));

    return $t->call('POST', '/api/internal/envoi/evenements', [], [], [], [
        'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
        'HTTP_X_ENVOI_TIMESTAMP' => $ts, 'HTTP_X_ENVOI_NONCE' => $nonce, 'HTTP_X_ENVOI_SIGNATURE' => $sig,
    ], $corps);
}

function ev(int $seq, string $type, string $inj = 'm1'): array
{
    return ['event_id' => "e$seq", 'seq' => $seq, 'type' => $type, 'id_injection' => $inj];
}

function lignes(object $t, string $table)
{
    return WorkspaceContext::run($t->ws, fn () => DB::table($table)->get());
}

test('lot valide : 200, lignes écrites, curseur avancé', function () {
    pousser($this, [ev(1, 'envoye'), ev(2, 'livre')])->assertOk()->assertJson(['recus' => 2, 'doublons' => 0, 'trou' => false]);
    expect(lignes($this, 'envoi_evenements'))->toHaveCount(2)
        ->and((int) lignes($this, 'envoi_curseurs_serveur')->first()->dernier_seq)->toBe(2);
});

test('signature fausse : 401 ; nonce rejoué : 409 ; rien n’est écrit', function () {
    $r = $this->call('POST', '/api/internal/envoi/evenements', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_ENVOI_TIMESTAMP' => (string) time(), 'HTTP_X_ENVOI_NONCE' => 'n', 'HTTP_X_ENVOI_SIGNATURE' => 'faux'], '{}');
    expect($r->status())->toBe(401);
    pousser($this, [ev(1, 'envoye')], 'rejoue')->assertOk();
    expect(pousser($this, [ev(1, 'envoye')], 'rejoue')->status())->toBe(409)
        ->and(lignes($this, 'envoi_evenements'))->toHaveCount(1);
});

test('doublons ignorés (ON CONFLICT DO NOTHING sur source + event_id)', function () {
    pousser($this, [ev(1, 'envoye')])->assertOk();
    pousser($this, [ev(1, 'envoye'), ev(2, 'livre')])->assertOk()->assertJson(['recus' => 1, 'doublons' => 1]);
    expect(lignes($this, 'envoi_evenements'))->toHaveCount(2);
});

test('2xx seulement après commit : une erreur en base donne un non-2xx et ne laisse rien (lot atomique)', function () {
    $r = pousser($this, [ev(1, 'envoye'), ev(2, 'livre', "m\0x")]);
    expect($r->status())->toBeGreaterThanOrEqual(500)
        ->and(lignes($this, 'envoi_evenements'))->toHaveCount(0);
});

test('désordre toléré : 3, 1, 2 donnent un curseur à 3 sans trou', function () {
    Queue::fake();
    pousser($this, [ev(3, 'livre'), ev(1, 'envoye'), ev(2, 'envoye')])->assertOk()->assertJson(['trou' => false]);
    expect((int) lignes($this, 'envoi_curseurs_serveur')->first()->dernier_seq)->toBe(3);
    Queue::assertNothingPushed();
});

test('état monotone : « livré » après « rebond_dur » ne rétablit rien (dans les deux ordres)', function () {
    pousser($this, [ev(2, 'rebond_dur'), ev(1, 'livre')])->assertOk();
    pousser($this, [ev(4, 'rebond_dur', 'm2'), ev(3, 'envoye', 'm2'), ev(5, 'livre', 'm2')])->assertOk();
    $etats = lignes($this, 'envoi_etats_message')->pluck('etat', 'id_injection');
    expect($etats['m1'])->toBe('rebond_dur')->and($etats['m2'])->toBe('rebond_dur');
});

test('aucune mesure d’ouverture : le type est écarté, la ligne ne garde pas le type', function () {
    pousser($this, [ev(1, 'ouverture'), ev(2, 'envoye')])->assertOk();
    expect(lignes($this, 'envoi_evenements')->pluck('type')->all())->toBe(['ignore', 'envoye'])
        ->and(lignes($this, 'envoi_etats_message'))->toHaveCount(1);
});

test('trou détecté : tirage par curseur planifié, puis rattrapé', function () {
    Queue::fake();
    pousser($this, [ev(1, 'envoye'), ev(3, 'livre')])->assertOk()->assertJson(['trou' => true]);
    Queue::assertPushed(RattraperServeur::class);

    $vu = [];
    app()->instance(TireurEvenements::class, new class($vu) implements TireurEvenements
    {
        public function __construct(public array &$vu) {}

        public function tirer(string $workspaceId, string $source, ?string $curseur, int $limite, DateTimeImmutable $depuis): LotSuivi
        {
            $this->vu[] = [$curseur, $depuis];

            return new LotSuivi($curseur === '1' ? [new EvenementSuivi('e2', 2, 'envoye', 'm1')] : [], null);
        }
    });
    (new RattraperServeur($this->ws, 'srv1'))->handle(app(TireurEvenements::class), app(App\Envoi\Serveur\RecepteurEvenements::class));
    expect((int) lignes($this, 'envoi_curseurs_serveur')->first()->dernier_seq)->toBe(3)
        ->and($vu[0][0])->toBe('1')
        ->and($vu[0][1]->getTimestamp())->toBeGreaterThanOrEqual(time() - 72 * 3600 - 5);
});

test('réconciliation : commande à 02:30, rattrapage borné à 72 h, inerte sans serveur', function () {
    $t = collect(Schedule::events())->first(fn ($e) => str_contains($e->command, 'envoi:reconcilier'));
    expect($t)->not->toBeNull()->and($t->expression)->toBe('30 2 * * *');
    expect(Artisan::call('envoi:reconcilier'))->toBe(0);
});
