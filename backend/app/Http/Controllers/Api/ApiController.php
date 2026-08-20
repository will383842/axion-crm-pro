<?php

namespace App\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;

/**
 * @OA\Info(
 *     version="1.0.0",
 *     title="Axion CRM Pro API",
 *     description="Plateforme B2B de prospection automatisée — API v1 Sanctum SPA cookie.",
 *
 *     @OA\Contact(email="contact@axion-ia.com")
 * )
 *
 * @OA\Server(url="https://api.localhost/api/v1", description="Local dev")
 * @OA\Server(url="https://api.axion-crm-pro.com/api/v1", description="Production")
 *
 * @OA\SecurityScheme(
 *     securityScheme="sanctumCookie",
 *     type="apiKey",
 *     in="cookie",
 *     name="axion_crm_session"
 * )
 *
 * @OA\Tag(name="Auth", description="Login + 2FA + magic-link + password reset")
 * @OA\Tag(name="Companies", description="Entreprises scrapées et enrichies")
 * @OA\Tag(name="Contacts", description="Décideurs / personnes")
 * @OA\Tag(name="Coverage", description="Carte de couverture France + sélection zones")
 * @OA\Tag(name="Scraping", description="Scraper runs Horizon/BullMQ")
 * @OA\Tag(name="LLM", description="LLM Router 9 use cases + 5 providers")
 * @OA\Tag(name="RGPD", description="Requêtes RGPD art. 15-22 + AI Act register")
 * @OA\Tag(name="Phase 2", description="Cold email / LinkedIn (501). Campaigns est réel
 *     depuis le sprint 19.7 ; CRM et Analytics ont été retirés le 2026-08-18 —
 *     leurs noms entraient en collision avec le chantier CRM cible, et un
 *     `/crm` qui répond « Not Implemented » à côté de la console v2 réelle est
 *     un piège de nommage, pas une promesse.")
 * @OA\Tag(name="Notifications", description="Notifications in-app + WebSocket Reverb")
 * @OA\Tag(name="Tags", description="Tags utilisateurs + auto-tags")
 * @OA\Tag(name="Users", description="Users du workspace + invitations")
 * @OA\Tag(name="Workspace", description="Workspace courant + recherche globale ⌘K")
 * @OA\Tag(name="AuditLogs", description="Audit logs hash-chained SHA-256")
 * @OA\Tag(name="SavedViews", description="Vues sauvegardées (filtres)")
 * @OA\Tag(name="Rotations", description="Rotation LLM use cases")
 */
abstract class ApiController extends Controller
{
    /**
     * Refuse un enregistrement qui appartient a un AUTRE espace de travail.
     *
     * 🔴 POURQUOI CETTE METHODE EXISTE (audit 360, F36-005 / B12-001, S0).
     *
     * `GET /contacts/{id}` et `GET /companies/{id}` rendaient `$this->ok($modele)`
     * sur ce que la resolution de route avait trouve, SANS AUCUN filtre d'espace.
     * Un compte de l'espace BETA lisait donc la fiche d'une personne de l'espace
     * ALPHA en devinant un identifiant - et les identifiants sont des entiers
     * consecutifs. Mesure le 2026-08-19.
     *
     * La ceinture applicative existait pourtant : `WorkspaceScope`, pose par le
     * trait `BelongsToWorkspace`. Elle est INERTE tant que
     * `CRM_STRICT_WORKSPACE_SCOPE` est a false, et c'est le defaut. On ne bascule
     * PAS ce drapeau ici : il ferait echouer les 26 taches planifiees qui
     * s'executent sans contexte d'espace (B11-001). On ferme donc la fuite la ou
     * elle est, au point d'entree, sans toucher a ce qui tourne en arriere-plan.
     *
     * 404 et non 403 : repondre « interdit » confirmerait l'existence de la fiche,
     * donc renseignerait un curieux qui balaie les identifiants. « Introuvable »
     * ne renseigne personne.
     */
    protected function refuserHorsEspace(mixed $modele, string $colonne = 'workspace_id'): void
    {
        if ($modele === null) {
            return;
        }

        $espaceDuModele = $modele->{$colonne} ?? null;

        // Un enregistrement sans espace n'appartient a personne en particulier
        // (referentiels globaux) : ce n'est pas a cette garde de trancher.
        if ($espaceDuModele === null || $espaceDuModele === '') {
            return;
        }

        $espaceCourant = $this->espaceCourantOuNull();

        if ($espaceCourant === null || (string) $espaceDuModele !== (string) $espaceCourant) {
            abort(404);
        }
    }

    /**
     * L'espace de travail courant, ou `null`.
     *
     * 🔑 DEUX SOURCES, ET C'EST DELIBERE. Le conteneur d'abord : c'est
     * `SetCurrentWorkspace` qui l'y pose, et c'est la meme frontiere que la RLS
     * Postgres. Le compte authentifie ensuite, en repli, pour le cas ou le
     * middleware n'aurait pas tourne.
     *
     * Ce repli existait deja -- ecrit deux fois, a l'identique, dans
     * `ScraperRunsController` et `ScrapingCampaignsController`. Il manquait a la
     * garde durcie. Dix-neuvieme fois que ce depot porte le meme code a deux
     * endroits et le correctif a un seul : on le remonte ici, une fois.
     */
    protected function espaceCourantOuNull(): ?string
    {
        if (app()->bound('workspace.id')) {
            $id = app('workspace.id');
            if ($id !== null && $id !== '') {
                return (string) $id;
            }
        }

        $user = request()->user();
        if ($user && ($user->current_workspace_id ?? null)) {
            return (string) $user->current_workspace_id;
        }

        return null;
    }

    /**
     * L'enregistrement appartient-il a l'espace courant ?
     *
     * 🔴 CONSTAT B12-001 / F36-005, DEUXIEME TOUR. Trois controleurs portaient
     * chacun leur propre version de ce controle, toutes ecrites ainsi :
     *
     *     if ($workspaceId === null) { return true; }   // « tolerant en test/dev »
     *
     * C'est un contournement FAIL-OPEN : quand le contexte d'espace manque, la
     * garde laisse tout passer. Le commentaire d'origine l'assumait -- « Tolerant
     * si workspace.id n'est pas bound (tests/dev) : ne bloque pas » -- mais rien
     * dans le code ne distingue un test d'une production : une commande, un job,
     * un appel arrive avant le middleware, et la tolerance devient la regle.
     *
     * C'est le meme motif que `F37-001`, un etage plus haut : un controle qui,
     * faute de savoir, repond « oui ». Ici la reponse est « non ».
     */
    protected function estDeMonEspace(mixed $modele, string $colonne = 'workspace_id'): bool
    {
        if ($modele === null) {
            return true;
        }

        $espaceDuModele = $modele->{$colonne} ?? null;
        if ($espaceDuModele === null || $espaceDuModele === '') {
            return true;
        }

        $espaceCourant = $this->espaceCourantOuNull();

        return $espaceCourant !== null && (string) $espaceDuModele === (string) $espaceCourant;
    }

    protected function ok(mixed $data = null, int $status = 200): JsonResponse
    {
        return response()->json($data ?? ['ok' => true], $status);
    }

    protected function notImplemented(string $sprint): JsonResponse
    {
        return response()->json([
            'error' => 'not_implemented',
            'message' => "Endpoint à implémenter en Sprint $sprint.",
            'sprint' => $sprint,
        ], 501);
    }
}
