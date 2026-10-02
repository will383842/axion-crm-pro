<?php

namespace App\Http\Middleware;

use App\Support\DelaiRequeteSql;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * Borne la durée de chaque requête SQL d'une requête HTTP de l'API — cf.
 * `App\Support\DelaiRequeteSql` pour le constat (prod, 2026-10-02 : aucune
 * limite, une liste à plus de 100 s, des requêtes empilées).
 *
 * Posé en tête du groupe `api`. Paramètre optionnel en SECONDES, pour une
 * route qui a besoin de plus (exports en flux) : `delai-sql:300`. Le second
 * passage remplace le premier.
 *
 * `jit = off` dans la foulée, mesuré en production le 2026-10-02 : la liste du
 * hub réécrite s'exécute en 2,7 ms, mais le planificateur estime mal la
 * branche des étiquettes (110 000 lignes pour 0) et déclenche la compilation
 * JIT — 331 ms de compilation pour 3 ms de travail. Un écran transactionnel
 * n'a rien à gagner au JIT.
 *
 * La limite est retirée au `terminate()` : la connexion php-fpm n'est pas
 * persistante, mais un test enchaîne requête HTTP puis commande artisan dans
 * le même processus, et rien ne doit fuir de l'une à l'autre.
 */
final class LimiteDureeRequetesSql
{
    public function handle(Request $request, Closure $next, ?string $secondes = null): Response
    {
        $ms = is_numeric($secondes) ? max(0, (int) $secondes) * 1000 : DelaiRequeteSql::delaiWebMs();

        $connexion = DB::connection();
        if ($connexion->getDriverName() === 'pgsql') {
            DelaiRequeteSql::poser($ms, $connexion);
            $connexion->statement('SET jit = off');
        }

        return $next($request);
    }

    public function terminate(Request $request, Response $response): void
    {
        $connexion = DB::connection();

        // Pas de connexion ouverte : rien à retirer, et surtout ne pas en
        // ouvrir une pour rien.
        if ($connexion->getDriverName() !== 'pgsql' || ! $connexion->getRawPdo() instanceof \PDO) {
            return;
        }

        try {
            $connexion->statement('RESET statement_timeout');
            $connexion->statement('RESET jit');
        } catch (\Throwable) {
            // Transaction avortée, connexion perdue : la connexion meurt avec
            // le processus php-fpm, rien à protéger ici.
        }
    }
}
