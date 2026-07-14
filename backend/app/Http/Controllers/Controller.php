<?php

namespace App\Http\Controllers;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Foundation\Validation\ValidatesRequests;

/**
 * Classe de base des contrôleurs (convention Laravel).
 *
 * Ce fichier manquait dans le dépôt alors que certains contrôleurs
 * (ex. ObservabilityController) l'étendent → fatal « Class not found » 500
 * sur leurs routes une fois authentifié. Rétabli avec les traits standard
 * (autorisation + validation) pour que tout contrôleur suivant la convention
 * fonctionne.
 */
abstract class Controller
{
    use AuthorizesRequests, ValidatesRequests;
}
