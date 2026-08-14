<?php

namespace App\Support;

use App\Exceptions\MissingWorkspaceContextException;
use Closure;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;

/**
 * Porte le workspace courant, à la fois côté PHP et côté Postgres
 * (`app.current_workspace_id`, lu par les policies RLS).
 *
 * Trois usages :
 *   - HTTP    : posé par le middleware SetCurrentWorkspace ;
 *   - jobs    : WorkspaceContext::run($id, fn () => ...) — OBLIGATOIRE ;
 *   - système : WorkspaceContext::runWithoutScope(fn () => ...) — échappatoire
 *               EXPLICITE et rare (migrations de données, tâches réellement
 *               cross-workspace). Ce n'est jamais un défaut par omission.
 *
 * ⚠️ Portée de la variable Postgres. L'implémentation précédente utilisait
 * `set_config(..., true)` (is_local) HORS transaction : la portée est alors le
 * statement courant, pas la requête. Autrement dit le contexte disparaissait
 * juste après avoir été posé. On pose donc la variable au niveau SESSION
 * (`is_local = false`), et on la NETTOIE :
 *   - en fin de requête HTTP (terminate du middleware) ;
 *   - avant chaque job (Queue::looping dans AppServiceProvider),
 * car un worker Horizon garde sa connexion ouverte entre deux jobs : sans
 * nettoyage, le contexte d'un job fuirait sur le suivant.
 */
final class WorkspaceContext
{
    public const PG_SETTING = 'app.current_workspace_id';

    private static ?string $current = null;

    private static bool $unscoped = false;

    /**
     * Pose le contexte (PHP + session Postgres). `null` le retire.
     */
    public static function set(?string $workspaceId, ?ConnectionInterface $connection = null): void
    {
        $workspaceId = self::validIdOrNull($workspaceId);
        self::$current = $workspaceId;

        if ($workspaceId === null) {
            app()->forgetInstance('workspace.id');
        } else {
            app()->instance('workspace.id', $workspaceId);
        }

        $connection ??= DB::connection();
        // set_config() est paramétrable (contrairement à SET LOCAL) → pas d'injection.
        $connection->select('SELECT set_config(?, ?, false)', [self::PG_SETTING, $workspaceId ?? '']);
    }

    public static function clear(?ConnectionInterface $connection = null): void
    {
        self::set(null, $connection);
    }

    public static function current(): ?string
    {
        return self::$current;
    }

    /**
     * Contexte courant ou échec bruyant.
     */
    public static function require(string $what): string
    {
        $id = self::$current;
        if ($id === null || $id === '') {
            throw MissingWorkspaceContextException::for($what);
        }

        return $id;
    }

    /**
     * Exécute $callback avec le contexte $workspaceId, puis restaure l'état
     * précédent — même en cas d'exception.
     *
     * C'est la seule façon correcte d'écrire un job/une commande qui touche des
     * données scopées.
     *
     * @template TReturn
     *
     * @param  Closure(): TReturn  $callback
     * @return TReturn
     */
    public static function run(string $workspaceId, Closure $callback, ?ConnectionInterface $connection = null)
    {
        $valid = self::validIdOrNull($workspaceId);
        if ($valid === null) {
            throw MissingWorkspaceContextException::for("identifiant de workspace invalide : « {$workspaceId} »");
        }

        $previous = self::$current;
        self::set($valid, $connection);

        try {
            return $callback();
        } finally {
            self::set($previous, $connection);
        }
    }

    /**
     * Échappatoire EXPLICITE pour les rares traitements réellement
     * cross-workspace (backfills, réconciliation, supervision).
     *
     * Volontairement verbeuse : elle doit sauter aux yeux en revue de code.
     * Elle ne lève pas la RLS Postgres — un rôle applicatif non-propriétaire
     * reste soumis aux policies ; elle ne fait que désactiver la ceinture
     * applicative et l'échec bruyant.
     *
     * @template TReturn
     *
     * @param  Closure(): TReturn  $callback
     * @return TReturn
     */
    public static function runWithoutScope(string $justification, Closure $callback)
    {
        if (trim($justification) === '') {
            throw new \InvalidArgumentException('runWithoutScope() exige une justification écrite.');
        }

        $previous = self::$current;
        $previousUnscoped = self::$unscoped;
        self::$unscoped = true;

        try {
            return $callback();
        } finally {
            self::$unscoped = $previousUnscoped;
            self::$current = $previous;
        }
    }

    public static function isUnscoped(): bool
    {
        return self::$unscoped;
    }

    /**
     * Le durcissement applicatif (global scope + échec bruyant) est-il actif ?
     * Drapeau `crm.strict_workspace_scope`, défaut false → comportement
     * identique à l'existant.
     */
    public static function strictModeEnabled(): bool
    {
        return filter_var(config('crm.strict_workspace_scope', false), FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * Accepte UUID v1-v8, ULID Crockford base32 (26 caractères) ou bigint
     * legacy (> 0). Sinon null. Garde-fou anti-corruption.
     */
    public static function validIdOrNull(mixed $v): ?string
    {
        if ($v === null || $v === '') {
            return null;
        }
        $s = (string) $v;
        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $s)) {
            return $s;
        }
        if (preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/i', $s)) {
            return $s;
        }
        if (preg_match('/^[1-9][0-9]*$/', $s)) {
            return $s;
        }

        return null;
    }
}
