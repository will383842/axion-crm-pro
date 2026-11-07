<?php

namespace App\Envoi\Desinscription;

use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Pages publiques de désinscription. Le jeton signé EST l'autorisation.
 *
 *  - GET  : une page, UN bouton, AUCUNE écriture (un robot qui précharge le
 *           lien ne crée aucune opposition).
 *  - POST : l'opposition (bouton), ou « one-click » RFC 8058 (corps
 *           `List-Unsubscribe=One-Click`, réponse vide).
 *  - Tout jeton refusé (forme, signature, échéance, inconnu) reçoit LA MÊME
 *    réponse 404 : rien ne permet de distinguer les causes.
 *
 * Page sans suivi : pas de cookie, pas de script, pas d'image, pas de
 * ressource distante, rien qui mesure une ouverture.
 */
final class DesinscriptionController
{
    public function page(string $jeton): Response
    {
        if (JetonDesinscription::verifier($jeton) === null) {
            return $this->refus();
        }
        $action = e('/desinscription/' . $jeton);

        return $this->html(200, $this->gabarit(
            'Se désinscrire',
            '<h1>Se désinscrire</h1>'
            . '<p>Vous ne recevrez plus de message de notre part.</p>'
            . '<form method="post" action="' . $action . '"><button type="submit">Confirmer la désinscription</button></form>',
        ));
    }

    public function confirmer(Request $request, string $jeton, EnregistrerOpposition $opposition): Response
    {
        $verifie = JetonDesinscription::verifier($jeton);
        if ($verifie === null) {
            return $this->refus();
        }
        $unClic = $request->input('List-Unsubscribe') === 'One-Click';
        $connu = $opposition->executer(
            $verifie['workspace_id'],
            $verifie['reference'],
            $unClic ? EnregistrerOpposition::CANAL_ONE_CLICK : EnregistrerOpposition::CANAL_PAGE,
        );
        if (! $connu) {
            return $this->refus();
        }
        if ($unClic) {
            return $this->entetes(response('', 200));
        }

        return $this->html(200, $this->gabarit(
            'Désinscription confirmée',
            '<h1>Désinscription confirmée</h1><p>Votre demande est enregistrée. Vous ne recevrez plus de message de notre part.</p>',
        ));
    }

    private function refus(): Response
    {
        return $this->html(404, $this->gabarit(
            'Lien non valide',
            '<h1>Lien non valide</h1><p>Ce lien n’est pas valide ou a expiré.</p>',
        ));
    }

    private function gabarit(string $titre, string $corps): string
    {
        return '<!DOCTYPE html><html lang="fr"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<meta name="robots" content="noindex, nofollow"><title>' . e($titre) . '</title>'
            . '<style>body{font-family:sans-serif;max-width:32rem;margin:3rem auto;padding:0 1rem;line-height:1.5;color:#111;background:#fff}'
            . 'button{font:inherit;padding:.75rem 1.25rem;border:2px solid #111;background:#fff;color:#111;cursor:pointer}'
            . 'button:focus{outline:3px solid #1a56db;outline-offset:2px}</style></head>'
            . '<body><main>' . $corps . '</main></body></html>';
    }

    private function html(int $statut, string $contenu): Response
    {
        return $this->entetes(response($contenu, $statut)->header('Content-Type', 'text/html; charset=UTF-8'));
    }

    private function entetes(Response $r): Response
    {
        return $r->withHeaders([
            'Cache-Control' => 'no-store, private',
            'Referrer-Policy' => 'no-referrer',
            'X-Robots-Tag' => 'noindex, nofollow',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; style-src 'unsafe-inline'; form-action 'self'; base-uri 'none'; frame-ancestors 'none'",
        ]);
    }
}
