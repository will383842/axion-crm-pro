<?php

namespace Tests\Feature\Envoi;

use App\Envoi\Reponses\ServiceReponses;
use App\Envoi\Reponses\StockerReponse;
use App\Models\User;
use App\Support\ListeSuppression;
use App\Support\WorkspaceContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Envoi\Routes\BaseRoutesEnvoi;

/**
 * RR-5B — avis « réponse intéressée » (cloche seulement) aux propriétaires et administrateurs, sauf l'auteur.
 * Routes réelles ; données fictives (example.test), aucun réseau, aucun envoi.
 */
uses(BaseRoutesEnvoi::class)->group('envoi');

function av5Sous(string $mode, string $ws, \Closure $f): mixed
{
    WorkspaceContext::set($ws);
    if ($mode === 'app') {
        DB::statement('SET ROLE "' . str_replace('"', '""', (string) config('database.connections.pgsql_app.username')) . '"');
    }
    try {
        return $f();
    } finally {
        DB::statement('RESET ROLE');
    }
}

/** Compte de l'espace, membre (user_workspaces) avec le rôle donné. */
function av5Membre(User $u, object $ws, string $role, bool $rejoint = true): User
{
    DB::table('user_workspaces')->insert(['user_id' => $u->id, 'workspace_id' => $ws->id, 'role_slug' => $role, 'joined_at' => $rejoint ? now() : null]);

    return $u;
}

/** Réponse « à rattacher » minimale ; rend son id. */
function av5Reponse(string $ws): int
{
    $adresse = 'zoe.martin@societe-a.example.test';

    return WorkspaceContext::run($ws, function () use ($ws, $adresse): int {
        $cle = hash('sha256', Str::random(20));
        $evenement = DB::table('envoi_evenements')->insertGetId([
            'workspace_id' => $ws, 'boite' => 'b@example.test', 'uid_validity' => 1, 'uid' => random_int(1, 1_000_000),
            'empreinte_message' => $cle, 'classe' => 'reponse_humaine', 'statut' => 'a_rattacher',
            'empreinte_expediteur' => ListeSuppression::empreinte($adresse), 'recu_le' => now(),
        ]);
        app(StockerReponse::class)->enregistrer($ws, $cle, 'Objet de test', 'Extrait secret de la reponse.', $adresse, 'reponse_humaine');

        return (int) DB::table('envoi_reponses')->where('evenement_id', $evenement)->value('id');
    });
}

/** @return list<object> */
function av5Avis(string $ws): array
{
    return WorkspaceContext::run($ws, fn () => DB::table('notifications')->where('workspace_id', $ws)->where('type', 'envoi.reponse_interessee')->orderBy('created_at')->orderBy('user_id')->get()->all());
}

beforeEach(function () {
    $this->ws = $this->espace(true);
    $this->p1 = av5Membre($this->compte($this->ws, 'owner'), $this->ws, 'owner');
    $this->p2 = av5Membre($this->compte($this->ws, 'owner'), $this->ws, 'owner');
    $this->adm = av5Membre($this->compte($this->ws, 'admin'), $this->ws, 'admin');
    $this->lecteur = av5Membre($this->compte($this->ws, 'viewer'), $this->ws, 'viewer');
    $this->invite = av5Membre($this->compte($this->ws, 'admin'), $this->ws, 'admin', false);
    $this->revoque = av5Membre($this->compte($this->ws, 'admin'), $this->ws, 'admin');
    DB::table('user_workspaces')->where('user_id', $this->revoque->id)->where('workspace_id', $this->ws->id)->update(['revoked_at' => now()]);
    $this->autre = $this->espace(true);
    $this->pb = av5Membre($this->compte($this->autre, 'owner'), $this->autre, 'owner');
    $this->rep = av5Reponse($this->ws->id);
    $this->url = "/api/v1/envoi/reponses/{$this->rep}/classer";
});

test('V1 « intéressé » par P1 : avis pour P2 et l’administrateur, aucun pour P1, le lecteur, l’invité, l’administrateur révoqué, ni l’autre espace', function () {
    $this->actingAs($this->p1)->postJson($this->url, ['classement' => 'interesse', 'creer_tache' => true])->assertOk();
    $avis = av5Avis($this->ws->id);
    $dest = array_map(fn ($a) => $a->user_id, $avis);
    sort($dest);
    $attendu = [$this->p2->id, $this->adm->id];
    sort($attendu);
    expect($dest)->toBe($attendu)
        ->and($avis[0]->action_url)->toBe('/envoi/reponses/' . $this->rep)
        ->and($avis[0]->produit)->toBe('envoi')->and($avis[0]->gravite)->toBe('info')
        ->and($avis[0]->title)->toBe('Réponse intéressée');
    expect(av5Avis($this->autre->id))->toBeEmpty();
});

test('V2 rejouer, corriger puis reclasser « intéressé » : un seul avis par destinataire', function () {
    $this->actingAs($this->p1)->postJson($this->url, ['classement' => 'interesse'])->assertOk();
    $this->actingAs($this->p1)->postJson($this->url, ['classement' => 'interesse'])->assertOk();
    $this->actingAs($this->p1)->postJson($this->url, ['classement' => 'refus'])->assertOk();
    $this->actingAs($this->p2)->postJson($this->url, ['classement' => 'interesse'])->assertOk();
    expect(av5Avis($this->ws->id))->toHaveCount(2);
});

test('V3 « pas maintenant » et « question » : aucun avis', function () {
    $this->actingAs($this->p1)->postJson($this->url, ['classement' => 'pas_maintenant'])->assertOk();
    $this->actingAs($this->p1)->postJson($this->url, ['classement' => 'question', 'creer_tache' => true])->assertOk();
    expect(av5Avis($this->ws->id))->toBeEmpty();
});

test('V4 aucune notification dans l’Espace B (app ET proprio)', function () {
    foreach (['app', 'proprio'] as $mode) {
        $rep = av5Reponse($this->ws->id);
        av5Sous($mode, $this->ws->id, fn () => app(ServiceReponses::class)->classer($this->ws->id, (string) $this->p1->id, $rep, 'interesse'));
        expect(av5Avis($this->autre->id))->toBeEmpty($mode)
            ->and(WorkspaceContext::run($this->ws->id, fn () => DB::table('notifications')->where('action_url', '/envoi/reponses/' . $rep)->pluck('user_id')->map(fn ($v) => (string) $v)->sort()->values()->all()))
            ->not->toContain((string) $this->pb->id)->not->toContain((string) $this->p1->id);
    }
});

test('V5 ni arobase, ni extrait, ni nom dans le titre et le corps', function () {
    $this->actingAs($this->p1)->postJson($this->url, ['classement' => 'interesse'])->assertOk();
    foreach (av5Avis($this->ws->id) as $a) {
        expect($a->title . $a->body)->not->toContain('@')->not->toContain('Extrait secret')->not->toContain('Zoe')->not->toContain('Martin');
    }
});
