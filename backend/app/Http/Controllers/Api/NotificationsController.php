<?php

namespace App\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * LA CLOCHE DE L'EN-TETE — constat B12-007 (S1).
 *
 * 🔴 CE QU'IL Y AVAIT AVANT :
 *
 *     public function index(Request $r): JsonResponse { return $this->ok(['data' => []]); }
 *
 * Un corps ECRIT DANS LE CODE, rendu avec un `200`. La cloche de notifications
 * est presente sur TOUS les ecrans de la console : elle annoncait « aucune
 * notification » en permanence, y compris quand la table en portait.
 *
 * ✅ La table `notifications` EXISTE (migration `2026_05_16_000006`) : UUID,
 * `workspace_id`, `user_id`, `type`, `title`, `body`, `action_url`, `read_at`,
 * et son index partiel `idx_notifications_user_unread (user_id, created_at DESC)
 * WHERE read_at IS NULL`. Cet index dit a lui seul ce que la route etait censee
 * faire : lister les non-lues d'un utilisateur, par date decroissante.
 *
 * ⚠️ CE QUE CE CORRECTIF NE FAIT PAS, ET IL FAUT LE DIRE. `markRead()` et
 * `markAllRead()` restent en `501` (Sprint 10) : la liste se lit, elle ne se
 * marque pas encore. C'est un etat boiteux mais HONNETE — chaque route dit ce
 * qu'elle fait. L'etat precedent, lui, mentait des deux cotes : `501` a
 * l'ecriture, `200 {"data":[]}` a la lecture, et l'ecran en concluait qu'il n'y
 * avait rien a marquer.
 *
 * 🔑 DOUBLE CLOISONNEMENT, ET C'EST DELIBERE. Une notification appartient a un
 * ESPACE **et** a une PERSONNE. Filtrer sur le seul espace montrerait a chaque
 * membre les notifications de ses collegues — un `title` peut nommer une fiche,
 * une campagne, un client. On filtre donc sur les deux, et la colonne
 * `user_id NOT NULL` de la migration confirme que c'etait l'intention.
 *
 * Garde : `tests/Feature/Api/CorpsCodeEnDurTest.php`.
 */
class NotificationsController extends ApiController
{
    /** Une cloche affiche ce qui tient dans un menu deroulant, pas un journal. */
    private const PLAFOND = 50;

    /**
     * @OA\Get(path="/notifications", tags={"Notifications"}, summary="Liste notifications in-app non lues + récentes",
     *     security={{"sanctumCookie":{}}},
     *
     *     @OA\Parameter(name="unread", in="query", @OA\Schema(type="boolean")),
     *
     *     @OA\Response(response=200, description="OK"))
     */
    public function index(Request $r): JsonResponse
    {
        if (! Schema::hasTable('notifications')) {
            return $this->ok(['data' => [], 'unread_count' => 0]);
        }

        $espace = $this->espaceCourantOuNull();
        $utilisateur = $r->user();

        // Fail-closed sur les DEUX dimensions : sans espace connu OU sans
        // compte identifie, on ne rend rien. Une cloche muette vaut mieux
        // qu'une cloche qui sonne pour quelqu'un d'autre.
        if ($espace === null || $utilisateur === null) {
            return $this->ok(['data' => [], 'unread_count' => 0]);
        }

        try {
            $base = fn () => DB::table('notifications')
                ->where('workspace_id', $espace)
                ->where('user_id', $utilisateur->getKey());

            $requete = $base();

            // `?unread=1` emprunte exactement l'index partiel pose par la
            // migration : `WHERE read_at IS NULL`, tri `created_at DESC`.
            if ($r->boolean('unread')) {
                $requete->whereNull('read_at');
            }

            $lignes = $requete
                ->orderByDesc('created_at')
                ->limit(self::PLAFOND)
                ->get(['id', 'type', 'title', 'body', 'action_url', 'read_at', 'created_at'])
                ->map(fn (object $l) => (array) $l)
                ->all();

            // Le compteur est calcule en base, sur la meme condition que
            // l'index partiel — pas en comptant les lignes deja rendues, qui
            // sont plafonnees a 50 et donneraient « 50 » a partir de 50.
            $nonLues = (int) $base()->whereNull('read_at')->count();

            return $this->ok(['data' => $lignes, 'unread_count' => $nonLues]);
        } catch (\Throwable $e) {
            Log::error('notifications.index failed', ['exception' => $e->getMessage()]);
            report($e);

            return $this->ok(['data' => [], 'unread_count' => 0, 'degraded' => true]);
        }
    }

    /**
     * @OA\Post(path="/notifications/{n}/read", tags={"Notifications"}, summary="Marque comme lue",
     *     security={{"sanctumCookie":{}}},
     *
     *     @OA\Parameter(name="n", in="path", required=true, @OA\Schema(type="integer")),
     *
     *     @OA\Response(response=501, description="Not implemented (Sprint 10)"))
     */
    public function markRead(int $n): JsonResponse
    {
        return $this->notImplemented('10');
    }

    /**
     * @OA\Post(path="/notifications/read-all", tags={"Notifications"}, summary="Marque toutes comme lues",
     *     security={{"sanctumCookie":{}}},
     *
     *     @OA\Response(response=501, description="Not implemented (Sprint 10)"))
     */
    public function markAllRead(): JsonResponse
    {
        return $this->notImplemented('10');
    }
}
