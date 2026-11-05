<?php

namespace App\Http\Controllers\Api\Crm\Concerns;

use App\Crm\Console\ConsoleAccess;
use App\Socle\Espace\FonctionsEspace;
use Illuminate\Http\Request;

/**
 * Garde unique des lectures d'origines (L6) : interrupteur général éteint → 404 ;
 * espace non business → 403 ; compte non membre de l'espace → 403 ; fonction
 * d'espace `origines.lecture` absente ou fermée → 404 (on ne confirme pas son existence).
 */
trait GardeOrigines
{
    protected function gardeOrigines(Request $request): string
    {
        abort_unless((bool) config('origines.lecture_enabled', false), 404);

        $espace = $this->businessWorkspace($request);
        abort_unless(ConsoleAccess::isMemberOf($this->currentUser($request), $espace), 403);
        abort_unless(FonctionsEspace::ouverte($espace, FonctionsEspace::ORIGINES_LECTURE), 404);

        return $espace;
    }
}
