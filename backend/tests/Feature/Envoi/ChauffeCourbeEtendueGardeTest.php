<?php

use App\Envoi\Chauffe\DepotChauffe;
use App\Envoi\Chauffe\GardeChauffe;
use App\Envoi\Chauffe\ResolveurEnMemoire;
use App\Envoi\Chauffe\ResolveurFournisseur;
use App\Envoi\Chauffe\Seuils;
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
 * X11-A : la garde lit la table étendue P1-P8 (avec les reculs du domaine), plafonnée à Seuils::PALIER_MAX (3) :
 * aucune montée par défaut. Chaque scénario tourne en propriétaire PUIS sous le rôle applicatif (RLS armée).
 */

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-11-10 11:00:00', 'UTC'));
    config(['envoi.cle_empreinte' => 'cle-de-test-seulement']);
    app()->instance(ResolveurFournisseur::class, new ResolveurEnMemoire(['gmail.com' => 'gmail']));
});

afterEach(fn () => Carbon::setTestNow());

function xaJour(string $j = '2026-11-10'): DateTimeImmutable
{
    return new DateTimeImmutable($j, new DateTimeZone('Europe/Paris'));
}

function xaEspace(): string
{
    $id = (string) Str::uuid();
    DB::table('workspaces')->insert(['id' => $id, 'name' => 'Xa', 'slug' => 'xa-' . substr($id, 0, 8), 'created_at' => now(), 'updated_at' => now()]);
    Artisan::call('envoi:temoins-off', ['workspace' => $id, '--auteur' => 'test']);
    WorkspaceContext::run($id, fn () => DB::table('envoi_journal_commandes')->insert([
        'workspace_id' => $id, 'commande' => 'tableau_valide', 'auteur' => 'test', 'details' => '{}',
        'jour' => '2026-11-10', 'cree_le' => '2026-11-10 08:00:00+01',
    ]));

    return $id;
}

/** Une boîte avec $jours jours d'historique consécutifs (la veille et avant) à $parJour envois. */
function xaBoite(string $ws, string $adresse, int $jours, int $parJour = 30): string
{
    $id = (string) Str::uuid();
    WorkspaceContext::run($ws, fn () => DB::table('envoi_boites')->insert([
        'id' => $id, 'workspace_id' => $ws, 'adresse' => $adresse, 'hote' => 'smtp.example.test', 'port' => 587,
        'identifiants_chiffres' => 'chiffre-de-test',
    ]));
    for ($i = 1; $i <= $jours; $i++) {
        app(DepotChauffe::class)->incrementer($ws, $id, xaJour()->modify("-{$i} days"), 'autre', 'envoyes', $parJour);
    }

    return $id;
}

function xaEnvoyes(string $ws, string $boite, int $n): void
{
    app(DepotChauffe::class)->incrementer($ws, $boite, xaJour(), 'autre', 'envoyes', $n);
}

function xaRecul(string $ws, string $domaine, string $jour): void
{
    WorkspaceContext::run($ws, fn () => DB::table('envoi_chauffe_reculs')->insert([
        'workspace_id' => $ws, 'ressource_type' => 'domaine', 'ressource' => $domaine, 'motif' => 'test', 'auteur' => 'test', 'date_recul' => $jour,
    ]));
}

function xaPlainte(string $ws, string $boite, string $jour): void
{
    app(DepotChauffe::class)->incrementer($ws, $boite, xaJour($jour), 'autre', 'plaintes');
}

function xaRefus(string $ws, string $adresse, ?int $palierMax, bool $rls): ?string
{
    $maintenant = new DateTimeImmutable('2026-11-10 12:00:00', new DateTimeZone('Europe/Paris'));
    $garde = new GardeChauffe(app(DepotChauffe::class), app(DepotSocle::class), app(ResolveurFournisseur::class), null, palierMax: $palierMax);
    $garde->battre($maintenant);
    $m = new MessageAEnvoyer($ws, 'x@gmail.com', $adresse, 'S', 'C', (string) Str::uuid());
    if (! $rls) {
        return $garde->refus($m, xaJour(), $maintenant, 'gmail');
    }
    DB::statement('SET ROLE axion_app');
    try {
        return WorkspaceContext::run($ws, fn () => $garde->refus($m, xaJour(), $maintenant, 'gmail'));
    } finally {
        DB::statement('RESET ROLE');
    }
}

/** Quota de la boîte = plus petit n tel que n envoyés déjà => refus ; on le lit en posant n-1 puis 1. */
function xaQuotaEstDe(string $ws, string $boite, string $adresse, int $quota, ?int $palierMax, bool $rls): void
{
    xaEnvoyes($ws, $boite, $quota - 1);
    expect(xaRefus($ws, $adresse, $palierMax, $rls))->toBeNull();
    xaEnvoyes($ws, $boite, 1);
    expect(xaRefus($ws, $adresse, $palierMax, $rls))->toBe(GardeChauffe::QUOTA_ATTEINT);
}

const XA_ADR = 'a@dom.example.test';

test('X11-A le palier maximal par défaut est 3', function () {
    expect(Seuils::PALIER_MAX)->toBe(3);
});

dataset('xa_roles', [false, true]);

test('X11-A jour 40 sans recul : quota 25 par défaut (aucune montée)', function (bool $rls) {
    $ws = xaEspace();
    $b = xaBoite($ws, XA_ADR, 40);
    xaQuotaEstDe($ws, $b, XA_ADR, 25, null, $rls);
})->with('xa_roles');

test('X11-A la garde lit la table étendue : palier max 8, jour 18 => quota 50', function (bool $rls) {
    $ws = xaEspace();
    $b = xaBoite($ws, XA_ADR, 17);
    xaQuotaEstDe($ws, $b, XA_ADR, 50, 8, $rls);
})->with('xa_roles');

test('X11-A jour 18 avec un recul du domaine la veille : quota du palier inférieur (25)', function (bool $rls) {
    $ws = xaEspace();
    $b = xaBoite($ws, XA_ADR, 17);
    xaRecul($ws, 'dom.example.test', '2026-11-09');
    xaQuotaEstDe($ws, $b, XA_ADR, 25, 8, $rls);
})->with('xa_roles');

test('X11-A plainte récente : palier tenu (25, pas 50)', function (bool $rls) {
    $ws = xaEspace();
    $b = xaBoite($ws, XA_ADR, 17);
    xaPlainte($ws, $b, '2026-11-07');
    xaQuotaEstDe($ws, $b, XA_ADR, 25, 8, $rls);
})->with('xa_roles');

test('X11-A le recul d\'un autre espace sur le même nom de domaine est sans effet', function (bool $rls) {
    $ws = xaEspace();
    $autre = xaEspace();
    $b = xaBoite($ws, XA_ADR, 17);
    xaRecul($autre, 'dom.example.test', '2026-11-09');
    xaQuotaEstDe($ws, $b, XA_ADR, 50, 8, $rls);
})->with('xa_roles');
