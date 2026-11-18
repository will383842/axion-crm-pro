<?php

namespace Tests\Feature\Envoi;

use App\Envoi\Reponses\ServiceReponses;
use App\Envoi\Reponses\StockerReponse;
use App\Support\ListeSuppression;
use App\Support\WorkspaceContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Envoi\Routes\BaseRoutesEnvoi;

/**
 * `ServiceReponses::courant()` filtre explicitement par `workspace_id` (défense en plus de la RLS).
 * Données fictives (example.test), aucun réseau, aucun envoi.
 */
uses(BaseRoutesEnvoi::class)->group('envoi');

function cr1Reponse(string $ws): int
{
    $adresse = 'zoe.martin@cr1.example.test';

    return WorkspaceContext::run($ws, function () use ($ws, $adresse): int {
        $cle = hash('sha256', Str::random(20));
        $evenement = DB::table('envoi_evenements')->insertGetId([
            'workspace_id' => $ws, 'boite' => 'b@example.test', 'uid_validity' => 1, 'uid' => random_int(1, 1_000_000),
            'empreinte_message' => $cle, 'classe' => 'reponse_humaine', 'statut' => 'a_rattacher',
            'empreinte_expediteur' => ListeSuppression::empreinte($adresse), 'recu_le' => now(),
        ]);
        app(StockerReponse::class)->enregistrer($ws, $cle, 'Objet de test', 'Extrait de la reponse.', $adresse, 'reponse_humaine');

        return (int) DB::table('envoi_reponses')->where('evenement_id', $evenement)->value('id');
    });
}

/** @return array<string, mixed>|null */
function cr1Courant(string $mode, string $ws, int $reponse): ?array
{
    WorkspaceContext::set($ws);
    if ($mode === 'app') {
        DB::statement('SET ROLE "' . str_replace('"', '""', (string) config('database.connections.pgsql_app.username')) . '"');
    }
    try {
        $m = new \ReflectionMethod(ServiceReponses::class, 'courant');

        /** @var array<string, mixed>|null */
        return $m->invoke(app(ServiceReponses::class), $ws, $reponse);
    } finally {
        DB::statement('RESET ROLE');
    }
}

test('courant() ne rend jamais la réponse d’un autre espace (app ET proprio)', function () {
    $a = $this->espace(true);
    $b = $this->espace(true);
    $rb = cr1Reponse($b->id);
    foreach (['app', 'proprio'] as $mode) {
        expect(cr1Courant($mode, $a->id, $rb))->toBeNull($mode)
            ->and(cr1Courant($mode, $b->id, $rb))->not->toBeNull($mode); // témoin : la bonne réponse est bien lue
    }
});
