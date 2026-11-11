<?php

namespace App\Http\Middleware;

use App\Socle\Produits\ProduitsEspace;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Garde « produit » côté serveur (lot R00) : 404 si l'espace courant n'a pas le produit Envoi actif.
 *
 * Même source que le drapeau `produits.envoi` du contrat : `ProduitsEspace`. Modèle : EnsureCrmConsoleV2.
 *
 * 404 et non 403 : on ne divulgue pas l'existence d'une surface fermée, et le corps est le 404 JSON standard de Laravel.
 * La garde passe AVANT le contrôle de permission des routes (un `viewer` sans produit reçoit 404, pas 403).
 * Sans utilisateur ou sans espace : fermé aussi (l'authentification, elle, est refusée plus haut par `auth:sanctum`).
 */
class EnsureProduitEnvoi
{
    public function handle(Request $request, Closure $next): Response
    {
        $espace = $request->user()?->current_workspace_id;

        if (! ProduitsEspace::actif(is_string($espace) ? $espace : null, 'envoi')) {
            abort(404);
        }

        return $next($request);
    }
}
