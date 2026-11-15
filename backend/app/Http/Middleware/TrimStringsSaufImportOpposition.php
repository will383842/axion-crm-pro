<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Foundation\Http\Middleware\TrimStrings;

/**
 * TrimStrings de Laravel, sauf le champ `contenu` de POST /envoi/opposition/import : le fichier doit arriver
 * octet pour octet (numéros de ligne exacts, tabulation en tête refusée, plafond de 1 Mo exact). Les autres
 * champs et les autres routes gardent le rognage standard.
 */
class TrimStringsSaufImportOpposition extends TrimStrings
{
    private bool $routeImport = false;

    public function handle($request, Closure $next)
    {
        $this->routeImport = $request->is('api/v1/envoi/opposition/import');

        return parent::handle($request, $next);
    }

    /** @param  array<int, string>  $except */
    protected function shouldSkip($key, $except)
    {
        if ($this->routeImport && $key === 'contenu') {
            return true;
        }

        return parent::shouldSkip($key, $except);
    }
}
