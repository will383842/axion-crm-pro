<?php

namespace App\Http\Controllers\Api;

use App\Envoi\Chauffe\DepotChauffe;
use App\Envoi\Sante\DepotSante;
use App\Envoi\Sante\SuperviseurSante;
use App\Support\WorkspaceContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

/** Écran « Santé des envois » (K15) : dernier relevé, constats ouverts et pauses de l'espace courant. Lecture seule. */
class EnvoiSanteController extends ApiController
{
    public function index(DepotSante $depot, DepotChauffe $chauffe): JsonResponse
    {
        $ws = (string) WorkspaceContext::current();
        $sources = WorkspaceContext::run($ws, fn () => DB::table('envoi_serveurs')->where('workspace_id', $ws)->orderBy('source')->get(['source', 'actif']));
        $serveurs = [];
        foreach ($sources as $s) {
            $source = (string) $s->source;
            $constats = [];
            foreach ($depot->constatsOuverts($ws, $source) as $code => $c) {
                $constats[] = ['code' => $code, 'gravite' => $c['gravite'], 'detail' => $c['detail'], 'depuis' => $c['depuis']];
            }
            $serveurs[] = ['source' => $source, 'actif' => (bool) $s->actif, 'dernier_releve' => $depot->dernierReleve($ws, $source), 'constats' => $constats];
        }
        $espace = [];
        foreach ($depot->constatsOuverts($ws, SuperviseurSante::ESPACE) as $code => $c) {
            $espace[] = ['code' => $code, 'gravite' => $c['gravite'], 'detail' => $c['detail'], 'depuis' => $c['depuis']];
        }
        $pauses = array_map(
            static fn ($p): array => ['motif' => (string) $p->motif, 'portee' => (string) $p->portee, 'fournisseur' => $p->fournisseur],
            $chauffe->pausesActives($ws, now()->toDateTimeImmutable()),
        );

        return response()->json(['serveurs' => $serveurs, 'constats_espace' => $espace, 'pauses' => $pauses, 'seuils' => (array) config('envoi.sante')]);
    }
}
