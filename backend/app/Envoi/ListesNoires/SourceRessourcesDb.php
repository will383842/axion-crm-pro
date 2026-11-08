<?php

namespace App\Envoi\ListesNoires;

use App\Support\WorkspaceContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Domaines : table envoi_domaines. IP : config `envoi.listes_noires.ips`, « ip@uuid-workspace »
 * (une IP d'envoi n'est pas connue du CRM en base : seule cette configuration la déclare).
 * UUID invalide => entrée rejetée (listée par `rejets()`). IP invalide => gardée, le contrôle la
 * marque « non fiable » (jamais un vert).
 */
final class SourceRessourcesDb implements SourceRessources
{
    /** @var list<string> */
    private array $rejets = [];

    public function toutes(): array
    {
        $this->rejets = [];
        $out = [];
        foreach (DB::table('workspaces')->pluck('id') as $ws) {
            $ws = (string) $ws;
            foreach (WorkspaceContext::run($ws, fn () => DB::table('envoi_domaines')->where('workspace_id', $ws)->pluck('nom')) as $nom) {
                $out[] = new Ressource($ws, Ressource::DOMAINE, (string) $nom);
            }
        }
        foreach ((array) config('envoi.listes_noires.ips', []) as $entree) {
            [$ip, $ws] = array_pad(explode('@', (string) $entree, 2), 2, '');
            $ip = trim($ip);
            $ws = trim($ws);
            if ($ip === '' && $ws === '') {
                continue;
            }
            if (! Str::isUuid($ws)) {
                $this->rejets[] = 'ip@espace invalide : ' . $ip;
                Log::critical('[ENVOI] entrée envoi.listes_noires.ips rejetée (identifiant d\'espace invalide) : ' . $ip);

                continue;
            }
            $out[] = new Ressource($ws, Ressource::IP, $ip);
        }

        return $out;
    }

    public function rejets(): array
    {
        return $this->rejets;
    }
}
