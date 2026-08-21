<?php

namespace App\Policies;

use App\Models\User;

/**
 * Politique commune : owners + admins ont accès complet,
 * operators ont CRUD sauf delete, viewers ont lecture seule.
 * Les policies spécifiques peuvent override ce comportement.
 */
abstract class BasePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasAnyRole(['owner', 'admin', 'operator', 'viewer']);
    }

    public function view(User $user, $model): bool
    {
        return $this->sameWorkspace($user, $model);
    }

    public function create(User $user): bool
    {
        return $user->hasAnyRole(['owner', 'admin', 'operator']);
    }

    public function update(User $user, $model): bool
    {
        return $this->sameWorkspace($user, $model) && $user->hasAnyRole(['owner', 'admin', 'operator']);
    }

    public function delete(User $user, $model): bool
    {
        return $this->sameWorkspace($user, $model) && $user->hasAnyRole(['owner', 'admin']);
    }

    /**
     * Le modèle appartient-il à l'espace de travail courant de l'utilisateur ?
     *
     * ─────────────────────────────────────────────────────────────────────────
     * 🔴 LE CAST `(int)` SUR UN UUID AUTORISAIT TOUT LE MONDE (B12-012, corrigé
     *    ici le 2026-08-20 — le même défaut avait déjà été corrigé dans
     *    `routes/channels.php` le 2026-08-16 et n'avait jamais été porté ici)
     * ─────────────────────────────────────────────────────────────────────────
     *
     * Cette méthode comparait :
     *
     *     return (int) $user->current_workspace_id === (int) $model->workspace_id;
     *
     * Or `users.current_workspace_id` et `<table>.workspace_id` sont des colonnes
     * UUID (migration 2026_05_16_000002 :
     * `current_workspace_id UUID REFERENCES workspaces(id) ON DELETE SET NULL`).
     * Le cast en entier ne conserve que les chiffres de tête. MESURÉ en PHP 8 :
     *
     *     (int) '1db106f5-4b47-4c8a-9b3e-0000000000aa'  vaut  1
     *     (int) '1c9f0000-0000-4000-8000-000000000001'  vaut  1   ← étrangers, ÉGAUX
     *     (int) 'a1b2c3d4-1111-4000-8000-000000000001'  vaut  0
     *     (int) 'f9e8d7c6-2222-4000-8000-000000000002'  vaut  0   ← étrangers, ÉGAUX
     *
     * Un UUID v4 commence par une lettre (a–f) dans 6 cas sur 16 : la majorité
     * des paires tombait donc sur `0 === 0`, et deux UUID partageant leur chiffre
     * de tête tombaient sur le même entier. La comparaison était VRAIE pour
     * l'écrasante majorité des couples — y compris entre deux espaces de travail
     * sans le moindre rapport.
     *
     * Les DIX policies qui héritent d'ici (AuditLog, Company, Contact,
     * LlmUseCase, ProxyProvider, RgpdRequest, ScraperRun, Tag, User, Workspace)
     * laissaient donc passer `view()`, `update()` et `delete()` sur un modèle
     * d'un AUTRE espace.
     *
     * ⚠️ NE PAS RECASTER EN `int`. Ce sont des UUID, ils se comparent en CHAÎNES.
     * `hash_equals` fait une comparaison stricte, à temps constant — c'est
     * exactement la forme retenue dans `routes/channels.php`.
     *
     * Garde : `tests/Unit/Policies/CloisonnementBasePolicyTest.php`.
     *
     * ⚠️ CE QUI N'EST **PAS** CORRIGÉ ICI (constaté le 2026-08-20, hors lot) :
     * le repli `isset($model->workspace_id) === false ⇒ true` reste en place. Il
     * ouvre `UserPolicy` et `WorkspacePolicy` en grand, car ni `User` ni
     * `Workspace` ne portent d'attribut `workspace_id` (leur clé est `id` /
     * `current_workspace_id`) : pour ces deux modèles, `sameWorkspace()` rend
     * toujours `true`. Il rend aussi permissive toute ligne dont le
     * `workspace_id` est NULL — ce qui est LÉGITIME pour `llm_use_cases`
     * (lignes globales, cf. `Tests\Support\EtancheiteWorkspace::LIGNES_GLOBALES`)
     * et ne l'est pour aucune autre table. Ce point est signalé au rapport
     * d'audit, il n'est pas dans le périmètre de B12-012.
     */
    protected function sameWorkspace(User $user, $model): bool
    {
        if (! isset($model->workspace_id)) {
            return true;
        }

        return hash_equals((string) $user->current_workspace_id, (string) $model->workspace_id);
    }
}
