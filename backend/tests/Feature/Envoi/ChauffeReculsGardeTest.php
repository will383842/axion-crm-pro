<?php

use App\Envoi\Chauffe\DepotChauffe;
use App\Envoi\Chauffe\GardeChauffe;
use App\Envoi\Chauffe\ResolveurEnMemoire;
use App\Envoi\Chauffe\ResolveurFournisseur;
use App\Envoi\Contrat\MessageAEnvoyer;
use App\Envoi\Depot\DepotSocle;
use App\Support\WorkspaceContext;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/*
 * B9 (T-CHAUFFE F07b) : un recul posé à l'écran sur un DOMAINE doit réellement abaisser le quota appliqué par la garde,
 * et ne jamais le faire monter. Sans recul, le comportement est strictement celui d'avant.
 */

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-11-10 11:00:00', 'UTC')); // 12:00 Paris, dans la fenêtre d'envoi
    config(['envoi.cle_empreinte' => 'cle-de-test-seulement']);
    app()->instance(ResolveurFournisseur::class, new ResolveurEnMemoire(['gmail.com' => 'gmail']));
});

afterEach(fn () => Carbon::setTestNow());

function rgJour(string $j = '2026-11-10'): DateTimeImmutable
{
    return new DateTimeImmutable($j, new DateTimeZone('Europe/Paris'));
}

function rgEspace(): string
{
    $id = (string) Str::uuid();
    DB::table('workspaces')->insert(['id' => $id, 'name' => 'Rg', 'slug' => 'rg-' . substr($id, 0, 8), 'created_at' => now(), 'updated_at' => now()]);
    Artisan::call('envoi:temoins-off', ['workspace' => $id, '--auteur' => 'test']);
    WorkspaceContext::run($id, fn () => DB::table('envoi_journal_commandes')->insert([
        'workspace_id' => $id, 'commande' => 'tableau_valide', 'auteur' => 'test', 'details' => '{}',
        'jour' => '2026-11-10', 'cree_le' => '2026-11-10 08:00:00+01',
    ]));

    return $id;
}

function rgBoite(string $ws, string $adresse): string
{
    $id = (string) Str::uuid();
    WorkspaceContext::run($ws, fn () => DB::table('envoi_boites')->insert([
        'id' => $id, 'workspace_id' => $ws, 'adresse' => $adresse, 'hote' => 'smtp.example.test', 'port' => 587,
        'identifiants_chiffres' => 'chiffre-de-test',
    ]));

    return $id;
}

/** Trois boîtes d'un même domaine, `$jours` jours effectifs chacune : par défaut 5 jours à 10 = boîtes et domaine en P2 (quota de boîte 18). */
function rgDomaineEnP2(string $ws, string $domaine, int $jours = 5, int $parJour = 10): array
{
    $d = app(DepotChauffe::class);
    $ids = [rgBoite($ws, "a@{$domaine}"), rgBoite($ws, "b@{$domaine}"), rgBoite($ws, "c@{$domaine}")];
    for ($i = 1; $i <= $jours; $i++) {
        $d->incrementer($ws, $ids[0], rgJour()->modify("-{$i} days"), 'autre', 'envoyes', $parJour);
        $d->incrementer($ws, $ids[1], rgJour()->modify("-{$i} days"), 'autre', 'envoyes', $parJour);
        $d->incrementer($ws, $ids[2], rgJour()->modify("-{$i} days"), 'autre', 'envoyes', $parJour);
    }

    return $ids;
}

function rgEnvoyes(string $ws, string $boiteId, int $n): void
{
    app(DepotChauffe::class)->incrementer($ws, $boiteId, rgJour(), 'autre', 'envoyes', $n);
}

function rgRecul(string $ws, string $type, string $ressource, string $jour = '2026-11-10'): void
{
    WorkspaceContext::run($ws, fn () => DB::table('envoi_chauffe_reculs')->insert([
        'workspace_id' => $ws, 'ressource_type' => $type, 'ressource' => $ressource, 'motif' => 'test', 'auteur' => 'test', 'date_recul' => $jour,
    ]));
}

/** Refus de la garde pour un message de la boîte `c@` du domaine. */
function rgRefus(string $ws, string $domaine): ?string
{
    $maintenant = new DateTimeImmutable('2026-11-10 12:00:00', new DateTimeZone('Europe/Paris'));
    $garde = new GardeChauffe(app(DepotChauffe::class), app(DepotSocle::class), app(ResolveurFournisseur::class), null);
    $garde->battre($maintenant);

    return $garde->refus(new MessageAEnvoyer($ws, 'x@gmail.com', "c@{$domaine}", 'S', 'C', (string) Str::uuid()), rgJour(), $maintenant, 'gmail');
}

test('B9 domaine en P2 puis un recul : quota_du_domaine_atteint dès 20 envois du domaine', function () {
    $ws = rgEspace();
    [$a, $b] = rgDomaineEnP2($ws, 'dom.example.test');
    rgEnvoyes($ws, $a, 10);
    rgEnvoyes($ws, $b, 9);
    rgRecul($ws, 'domaine', 'dom.example.test');
    expect(rgRefus($ws, 'dom.example.test'))->toBeNull();      // 19 < 20
    rgEnvoyes($ws, $b, 1);
    expect(rgRefus($ws, 'dom.example.test'))->toBe(GardeChauffe::QUOTA_DOMAINE_ATTEINT); // 20 : recul P2 -> P1 (budget 20)
});

test('B9 le même domaine SANS recul : mêmes seuils qu\'avant (3 x quota de boîte = 54)', function () {
    $ws = rgEspace();
    [$a, $b] = rgDomaineEnP2($ws, 'dom.example.test');
    rgEnvoyes($ws, $a, 18);
    rgEnvoyes($ws, $b, 18);
    expect(rgRefus($ws, 'dom.example.test'))->toBeNull();      // 36 < 54
    $c = app(DepotChauffe::class)->boiteId($ws, 'c@dom.example.test');
    rgEnvoyes($ws, $c, 17);
    expect(rgRefus($ws, 'dom.example.test'))->toBeNull();      // 53 < 54
    rgEnvoyes($ws, $c, 1);
    expect(rgRefus($ws, 'dom.example.test'))->toBe(GardeChauffe::QUOTA_ATTEINT); // la boîte c a atteint son quota de 18
});

test('B9 un recul daté de demain est sans effet', function () {
    $ws = rgEspace();
    [$a, $b] = rgDomaineEnP2($ws, 'dom.example.test');
    rgEnvoyes($ws, $a, 10);
    rgEnvoyes($ws, $b, 10);
    rgRecul($ws, 'domaine', 'dom.example.test', '2026-11-11');
    expect(rgRefus($ws, 'dom.example.test'))->toBeNull();
});

test('B9 le recul d\'un autre domaine, d\'une IP du même nom ou d\'un autre espace est sans effet', function () {
    $ws = rgEspace();
    $autre = rgEspace();
    [$a, $b] = rgDomaineEnP2($ws, 'dom.example.test');
    rgEnvoyes($ws, $a, 10);
    rgEnvoyes($ws, $b, 10);
    rgRecul($ws, 'domaine', 'autre.example.test');
    rgRecul($ws, 'ip', 'dom.example.test');
    rgRecul($autre, 'domaine', 'dom.example.test');
    expect(rgRefus($ws, 'dom.example.test'))->toBeNull();
});

test('B9 en P1 le quota ne monte jamais : un recul ne relève ni la boîte (10) ni le domaine (20, pas 30)', function () {
    $ws = rgEspace();
    $ids = [rgBoite($ws, 'a@dom.example.test'), rgBoite($ws, 'b@dom.example.test'), rgBoite($ws, 'c@dom.example.test')];
    rgRecul($ws, 'domaine', 'dom.example.test');
    rgEnvoyes($ws, $ids[0], 10);
    rgEnvoyes($ws, $ids[1], 9);
    expect(rgRefus($ws, 'dom.example.test'))->toBeNull();      // 19 < 20
    rgEnvoyes($ws, $ids[1], 1);
    expect(rgRefus($ws, 'dom.example.test'))->toBe(GardeChauffe::QUOTA_DOMAINE_ATTEINT); // 20, jamais 30
    rgEnvoyes($ws, $ids[2], 10);
    expect(rgRefus($ws, 'dom.example.test'))->toBe(GardeChauffe::QUOTA_ATTEINT);          // boîte à 10
});

test('B9 en P3 sans plafond de fin, historique long et sans recul : le quota reste à 25 (pas de passage en P4)', function () {
    $ws = rgEspace();
    $ids = rgDomaineEnP2($ws, 'dom.example.test', 40, 14);     // 40 jours d'historique à 14/jour (>= 50 % du quota P3) : boîtes en P3
    rgEnvoyes($ws, $ids[2], 24);
    expect(rgRefus($ws, 'dom.example.test'))->toBeNull();       // 24 < 25
    rgEnvoyes($ws, $ids[2], 1);
    expect(rgRefus($ws, 'dom.example.test'))->toBe(GardeChauffe::QUOTA_ATTEINT); // 25, pas 50
});
