<?php

namespace App\Envoi\Reputation;

use App\Support\WorkspaceContext;
use Closure;
use Illuminate\Support\Facades\DB;

/**
 * Constats de réputation (ajout seul). Le rejeu est tranché sous un verrou consultatif de l'espace, dans la même
 * transaction que l'écriture : deux requêtes identiques simultanées n'écrivent (et ne freinent) qu'une fois.
 */
class DepotReputation
{
    public const MAX_LECTURE = 100;

    /**
     * @param  Closure(): bool  $freiner  appelé UNE fois, seulement pour un constat neuf ; rend vrai si une pause a été ouverte
     * @return array{ligne: array<string, mixed>, rejeu: bool}|null null = même clé de rejeu, contenu différent (conflit)
     */
    public function enregistrer(string $ws, ConstatReputation $c, string $cle, string $empreinte, Closure $freiner): ?array
    {
        return WorkspaceContext::run($ws, fn () => DB::transaction(function () use ($ws, $c, $cle, $empreinte, $freiner): ?array {
            DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', ['envoi_reputation|' . $ws]);
            $existant = DB::table('envoi_reputation_constats')->where('workspace_id', $ws)->where('cle_rejeu', $cle)->first();
            if ($existant !== null) {
                return $existant->empreinte === $empreinte ? ['ligne' => (array) $existant, 'rejeu' => true] : null;
            }
            $pause = $freiner();
            $id = DB::table('envoi_reputation_constats')->insertGetId([
                'workspace_id' => $ws,
                'source' => $c->source,
                'ressource_type' => $c->ressourceType,
                'ressource' => $c->ressource,
                'jour' => $c->jour,
                'reputation' => $c->reputation,
                'taux_plaintes' => $c->tauxPlaintes,
                'erreurs_auth' => $c->erreursAuth,
                'pieges' => $c->pieges,
                'saisie' => $c->saisie,
                'preuve' => $c->preuve,
                'auteur' => $c->auteur,
                'cle_rejeu' => $cle,
                'empreinte' => $empreinte,
                'pause_ouverte' => $pause,
            ]);

            return ['ligne' => (array) DB::table('envoi_reputation_constats')->where('id', $id)->first(), 'rejeu' => false];
        }));
    }

    /** @return list<array<string, mixed>> les plus récents d'abord */
    public function derniers(string $ws): array
    {
        return WorkspaceContext::run($ws, fn () => DB::table('envoi_reputation_constats')
            ->where('workspace_id', $ws)->orderByDesc('jour')->orderByDesc('id')->limit(self::MAX_LECTURE)->get()
            ->map(fn ($l) => (array) $l)->all());
    }
}
