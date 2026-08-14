<?php

namespace App\Crm\Console;

use App\Crm\Taxonomy;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * RÔLES PAR UNIVERS (plan §2.10) — l'étanchéité vaut aussi pour les humains.
 *
 * La RLS (lot L0) et les policies Postgres restent l'autorité : elles empêchent
 * un contrôleur oublieux de fuiter. Cette classe est la ceinture applicative
 * qui, en plus, refuse EXPLICITEMENT (403) l'accès à l'univers vivier à qui
 * n'en est pas membre — un refus lisible vaut mieux qu'une liste vide, qui se
 * confond avec « il n'y a rien ».
 *
 * ── La règle, en une phrase ────────────────────────────────────────────────
 * L'accès au VIVIER exige une ligne `user_workspaces` NON RÉVOQUÉE vers le
 * workspace `vivier-candidats`. Point final.
 *
 * Elle est délibérément plus stricte que « le workspace courant de
 * l'utilisateur » : `users.current_workspace_id` est un simple pointeur
 * d'affichage, modifiable par l'utilisateur lui-même via le sélecteur de
 * workspace. Faire reposer une frontière RGPD sur un pointeur d'affichage,
 * c'est n'avoir aucune frontière. L'appartenance, elle, est posée par un admin.
 */
final class ConsoleAccess
{
    /**
     * Identifiant du workspace vivier, ou null s'il n'existe pas encore en base
     * (environnements où la migration L1 n'a pas été jouée).
     */
    public static function vivierWorkspaceId(): ?string
    {
        $id = DB::table('workspaces')
            ->where('slug', Taxonomy::VIVIER_WORKSPACE_SLUG)
            ->value('id');

        return $id === null ? null : (string) $id;
    }

    /**
     * L'utilisateur est-il membre NON RÉVOQUÉ de ce workspace ?
     */
    public static function isMemberOf(User $user, string $workspaceId): bool
    {
        return DB::table('user_workspaces')
            ->where('user_id', $user->getKey())
            ->where('workspace_id', $workspaceId)
            ->whereNull('revoked_at')
            ->exists();
    }

    /**
     * Accès à l'univers vivier — la seule question qui compte pour `/candidates`
     * et pour le volet vivier de la fiche 360°.
     */
    public static function canAccessVivier(User $user): bool
    {
        $vivier = self::vivierWorkspaceId();

        return $vivier !== null && self::isMemberOf($user, $vivier);
    }

    /**
     * Workspace BUSINESS de travail : le workspace courant de l'utilisateur,
     * sauf s'il s'agit du vivier (auquel cas il n'y a pas d'univers business
     * courant — les deux navigations sont distinctes, cf. conception §2.2).
     */
    public static function businessWorkspaceId(User $user): ?string
    {
        $current = $user->current_workspace_id;
        if ($current === null || $current === '') {
            return null;
        }

        $vivier = self::vivierWorkspaceId();

        return $vivier !== null && (string) $current === $vivier ? null : (string) $current;
    }

    /**
     * Le workspace courant EST-il le vivier ? Sert aux surfaces qui n'existent
     * que côté business (arbitrage des rapprochements d'entreprises : un
     * candidat n'a pas de SIREN à rapprocher — plan §2.1).
     */
    public static function currentIsVivier(User $user): bool
    {
        $vivier = self::vivierWorkspaceId();

        return $vivier !== null && (string) $user->current_workspace_id === $vivier;
    }
}
