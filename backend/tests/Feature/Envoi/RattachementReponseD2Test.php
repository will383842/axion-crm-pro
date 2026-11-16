<?php

/**
 * D2 — rattachement manuel d'une réponse (identifiant du registre) et compteur `reponses_7j`.
 * Chaque test est rejoué sous le rôle applicatif (RLS) ET sous le propriétaire (seul le filtre `workspace_id` protège).
 * Données fictives (example.test), aucun réseau.
 */

use App\Envoi\Reponses\ServiceReponses;
use App\Envoi\Reponses\StockerReponse;
use App\Envoi\Smtp\DepotBoites;
use App\Support\ListeSuppression;
use App\Support\WorkspaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\DoublonsFixtures as F;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

const D2_MODES = ['app', 'proprio'];

/** Exécute sous le mode : « app » = rôle applicatif non propriétaire sous RLS, « proprio » = propriétaire. */
function d2Sous(string $mode, string $ws, Closure $f): mixed
{
    WorkspaceContext::set($ws);
    if ($mode === 'app') {
        DB::statement('SET ROLE "' . str_replace('"', '""', (string) config('database.connections.pgsql_app.username')) . '"');
    }
    try {
        return $f();
    } finally {
        DB::statement('RESET ROLE'); // le rôle ne doit pas fuir vers le test suivant (même transaction)
    }
}

function d2Boite(string $ws, string $adresse): string
{
    $id = (string) Str::uuid();
    WorkspaceContext::run($ws, fn () => DB::table('envoi_boites')->insert([
        'id' => $id, 'workspace_id' => $ws, 'adresse' => $adresse, 'hote' => 'smtp.example.test', 'port' => 587,
        'chiffrement' => 'starttls', 'identifiants_chiffres' => 'zz',
    ]));

    return $id;
}

/** Une injection du registre local (faux serveur / Kumo) : l'UUID rendu est celui que la relève fournit. */
function d2Locale(string $ws): string
{
    return WorkspaceContext::run($ws, fn () => (string) DB::table('envoi_injections_locales')->insertGetId(
        ['workspace_id' => $ws, 'cle_idempotence' => 'seq-' . Str::uuid() . '-1', 'adaptateur' => 'faux'],
        'id_injection',
    ));
}

/** Événement « à rattacher » (boîte = UUID de la boîte, comme la relève) + réponse stockée ; rend l'id de la réponse. */
function d2Reponse(string $ws, string $boite): int
{
    $cle = hash('sha256', Str::random(20));

    return WorkspaceContext::run($ws, function () use ($ws, $boite, $cle): int {
        $evenement = DB::table('envoi_evenements')->insertGetId([
            'workspace_id' => $ws, 'boite' => $boite, 'uid_validity' => 1, 'uid' => random_int(1, 1_000_000),
            'empreinte_message' => $cle, 'classe' => 'reponse_humaine', 'statut' => 'a_rattacher',
            'empreinte_expediteur' => ListeSuppression::empreinte('zoe.martin@societe-a.example.test'), 'recu_le' => now(),
        ]);
        app(StockerReponse::class)->enregistrer($ws, $cle, 'Objet de test', 'Merci.', 'zoe.martin@societe-a.example.test', 'reponse_humaine');

        return (int) DB::table('envoi_reponses')->where('evenement_id', $evenement)->value('id');
    });
}

beforeEach(function () {
    $this->a = F::espace('zz-d2-a');
    $this->b = F::espace('zz-d2-b');
    $this->boiteA = d2Boite($this->a, 'envoi@societe-a.example.test');
    $this->boiteB = d2Boite($this->b, 'envoi@societe-b.example.test');
});

afterEach(function () {
    try {
        WorkspaceContext::clear();
    } catch (Throwable) {
    }
});

test('R5 rattachement manuel : l’UUID du registre local est accepté (rôle applicatif ET propriétaire)', function () {
    foreach (D2_MODES as $mode) {
        $reponse = d2Reponse($this->a, $this->boiteA);
        $injection = d2Locale($this->a);
        [$etat] = d2Sous($mode, $this->a, fn () => app(ServiceReponses::class)->rattacher($this->a, (string) Str::uuid(), $reponse, $injection));
        expect($etat)->toBe('ok', $mode);
        expect(WorkspaceContext::run($this->a, fn () => DB::table('envoi_rattachements')->where('workspace_id', $this->a)->where('id_injection', $injection)->count()))->toBe(1, $mode);
    }
});

test('R5 rattachement manuel : un UUID inconnu, ou celui d’un autre espace, est refusé (inconnue_injection)', function () {
    foreach (D2_MODES as $mode) {
        $reponse = d2Reponse($this->a, $this->boiteA);
        $deB = d2Locale($this->b);
        foreach ([(string) Str::uuid(), $deB] as $injection) {
            [$etat] = d2Sous($mode, $this->a, fn () => app(ServiceReponses::class)->rattacher($this->a, (string) Str::uuid(), $reponse, $injection));
            expect($etat)->toBe('inconnue_injection', $mode);
        }
    }
    expect(WorkspaceContext::run($this->a, fn () => DB::table('envoi_rattachements')->count()))->toBe(0);
});

test('R5 rattachement manuel : la forme historique (envoi_injections_smtp) reste acceptée', function () {
    foreach (D2_MODES as $mode) {
        $reponse = d2Reponse($this->a, $this->boiteA);
        $injection = (string) Str::uuid();
        WorkspaceContext::run($this->a, fn () => DB::table('envoi_injections_smtp')->insert(['workspace_id' => $this->a, 'boite_id' => $this->boiteA, 'id_injection' => $injection, 'cle_idempotence' => 'zz-d2-' . $injection]));
        [$etat] = d2Sous($mode, $this->a, fn () => app(ServiceReponses::class)->rattacher($this->a, (string) Str::uuid(), $reponse, $injection));
        expect($etat)->toBe('ok', $mode);
    }
});

test('R6 reponses_7j : la relève écrit l’UUID de la boîte ; deux réponses relevées donnent 2 (les autres boîtes et espaces sont exclus)', function () {
    foreach (D2_MODES as $mode) {
        $boite = d2Boite($this->a, "envoi-{$mode}@societe-a.example.test");
        d2Reponse($this->a, $boite);
        d2Reponse($this->a, $boite);
        d2Reponse($this->a, $this->boiteA);     // autre boîte du même espace
        d2Reponse($this->b, $this->boiteB);     // autre espace
        $resume = d2Sous($mode, $this->a, fn () => app(DepotBoites::class)->resumer($this->a, $boite));
        expect($resume['reponses_7j'])->toBe(2, $mode);
    }
});

test('R6 reponses_7j : une ligne de l’ancienne forme (boîte = adresse) reste comptée', function () {
    $adresse = 'ancienne@societe-a.example.test';
    $boite = d2Boite($this->a, $adresse);
    d2Reponse($this->a, $adresse);
    d2Reponse($this->a, $boite);
    expect(d2Sous('proprio', $this->a, fn () => app(DepotBoites::class)->resumer($this->a, $boite))['reponses_7j'])->toBe(2);
});
