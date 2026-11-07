<?php

namespace App\Http\Controllers\Internal;

use App\Envoi\Serveur\Contrat\EvenementSuivi;
use App\Envoi\Serveur\Jobs\RattraperServeur;
use App\Envoi\Serveur\RecepteurEvenements;
use App\Envoi\Serveur\VerificateurHmacEnvoi;
use App\Http\Controllers\Controller;
use App\Support\WorkspaceContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Réception des lots poussés par le serveur d'envoi. Le workspace et la source sont DANS le corps
 * signé. 2xx seulement après COMMIT (la transaction est fermée avant `response()`).
 */
final class EnvoiEvenementsController extends Controller
{
    public function store(Request $request, VerificateurHmacEnvoi $verificateur, RecepteurEvenements $recepteur): JsonResponse
    {
        $corps = $request->getContent();
        $statut = $verificateur->verifier(
            (string) config('envoi.serveur.secret', ''),
            (string) $request->input('source', ''),
            $request->header('X-Envoi-Timestamp'),
            $request->header('X-Envoi-Nonce'),
            $request->header('X-Envoi-Signature'),
            $corps,
        );
        if ($statut !== 200) {
            return response()->json(['erreur' => $statut === 409 ? 'nonce_rejoue' : ($statut === 503 ? 'indisponible' : 'non_autorise')], $statut);
        }

        $donnees = $request->validate([
            'workspace_id' => ['required', 'uuid'],
            'source' => ['required', 'regex:/^[a-z0-9_-]{1,64}$/'],
            'evenements' => ['required', 'array', 'max:500'],
            'evenements.*.event_id' => ['required', 'string', 'max:128'],
            'evenements.*.seq' => ['required', 'integer', 'min:1'],
            'evenements.*.type' => ['required', 'string', 'max:40'],
            'evenements.*.id_injection' => ['required', 'string', 'max:128'],
        ]);
        $ws = $donnees['workspace_id'];
        $source = $donnees['source'];
        $connu = WorkspaceContext::run($ws, fn () => DB::table('envoi_serveurs')->where('workspace_id', $ws)->where('source', $source)->where('actif', true)->exists());
        if (! $connu) {
            return response()->json(['erreur' => 'source_inconnue'], 404);
        }

        $evenements = array_map(fn (array $e) => new EvenementSuivi($e['event_id'], (int) $e['seq'], $e['type'], $e['id_injection']), $donnees['evenements']);
        $bilan = $recepteur->recevoir($ws, $source, $evenements); // COMMIT ici ; une erreur => 500, jamais 2xx
        if ($bilan['trou']) {
            RattraperServeur::dispatch($ws, $source);
        }

        return response()->json($bilan);
    }
}
