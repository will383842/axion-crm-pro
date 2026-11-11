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
 * États connus mais fermés (D3) : `suspendu` et `resilie` répondent 403 (codes `produit_suspendu`, `produit_resilie`,
 * texte français) : le client a le produit, l'écran doit expliquer. Aucun effet, aucune permission lue.
 *
 * 404 et non 403 pour `absent` et les valeurs inconnues : on ne divulgue pas l'existence d'une surface fermée, et le corps est le 404 JSON standard de Laravel.
 * La garde passe AVANT le contrôle de permission des routes (un `viewer` sans produit reçoit 404, pas 403).
 * Sans utilisateur ou sans espace : fermé aussi (l'authentification, elle, est refusée plus haut par `auth:sanctum`).
 */
class EnsureProduitEnvoi
{
    public function handle(Request $request, Closure $next): Response
    {
        $espace = $request->user()?->current_workspace_id;

        $etat = ProduitsEspace::etat(is_string($espace) ? $espace : null, 'envoi');
        if ($etat === 'suspendu' || $etat === 'resilie') {
            return response()->json([
                'message' => $etat === 'suspendu'
                    ? 'Le produit Envoi est suspendu pour cet espace. Contactez le responsable du projet.'
                    : 'Le produit Envoi est résilié pour cet espace. Contactez le responsable du projet.',
                'code' => 'produit_' . $etat,
            ], 403);
        }
        if ($etat !== 'actif') {
            abort(404);
        }

        return $next($request);
    }
}
