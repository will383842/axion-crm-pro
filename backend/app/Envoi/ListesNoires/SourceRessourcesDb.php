<?php

namespace App\Envoi\ListesNoires;

use App\Support\WorkspaceContext;
use Illuminate\Support\Facades\DB;

/** Domaines : table envoi_domaines. IP : config `envoi.listes_noires.ips`, « ip@uuid-workspace ». */
final class SourceRessourcesDb implements SourceRessources
{
    public function toutes(): array
    {
        $out = [];
        foreach (DB::table('workspaces')->pluck('id') as $ws) {
            $ws = (string) $ws;
            foreach (WorkspaceContext::run($ws, fn () => DB::table('envoi_domaines')->where('workspace_id', $ws)->pluck('nom')) as $nom) {
                $out[] = new Ressource($ws, Ressource::DOMAINE, (string) $nom);
            }
        }
        foreach ((array) config('envoi.listes_noires.ips', []) as $entree) {
            [$ip, $ws] = array_pad(explode('@', (string) $entree, 2), 2, '');
            if ($ip !== '' && $ws !== '') {
                $out[] = new Ressource($ws, Ressource::IP, $ip);
            }
        }

        return $out;
    }
}
