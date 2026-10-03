<?php

namespace App\Http\Controllers\Api\Crm;

use App\Crm\Console\ConsoleAccess;
use App\Crm\Console\FilesATraiter;
use App\Support\WorkspaceContext;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * COMPTEURS « À TRAITER » DU MENU — audit UX du 02/10/2026, lot 8.
 *
 * `GET /v1/crm/a-traiter/compteurs` → `{ doublons: int|null, a_rattacher: int|null }`.
 *
 * ── TROIS RÈGLES ────────────────────────────────────────────────────────────
 *
 * 1. **Le chiffre du menu = le total de l'écran.** Chaque compteur compte la
 *    file que l'écran affiche, construite par le MÊME code
 *    (`App\Crm\Console\FilesATraiter`) : « Doublons à vérifier » =
 *    `meta.total` de `/doublons` sans filtre de motif ; « Personnes à
 *    rattacher » = `meta.total` de `/crm/arbitrage`.
 *
 * 2. **Un compteur en échec vaut `null`, jamais 0.** Zéro dirait « rien à
 *    traiter » — c'est-à-dire exactement le contraire de « on ne sait pas ».
 *    Le menu n'affiche alors pas de pastille. Chaque compteur se défend seul :
 *    une file en panne n'emporte pas l'autre (patron du `DashboardController`).
 *    Il en va de même d'une file qui n'existe pas dans l'univers courant : le
 *    vivier n'a pas d'arbitrage (même règle que `ArbitrageController`), son
 *    compteur est `null`, pas 0.
 *
 * 3. **Court et borné.** La route porte `delai-sql:3` : une pastille qui
 *    attendrait plus de trois secondes ne sert à rien, et un menu affiché sur
 *    tous les écrans ne doit jamais voler le processeur aux écrans eux-mêmes.
 *    La réponse est mise en cache 60 s PAR ESPACE (la clé porte son
 *    identifiant : deux espaces ne partagent jamais un compteur) — le front
 *    redemande toutes les 60 s, onglet visible seulement. Un échec est mis en
 *    cache comme le reste : une requête qui vient de dépasser son délai ne
 *    doit pas être relancée à chaque écran. Les gestes qui vident une file
 *    (fusionner, écarter une paire, rattacher, écarter un événement) oublient
 *    ce cache (`oublier()`), pour que la pastille suive l'écran. Ce n'est
 *    pas une garantie : un calcul lancé par un autre écran JUSTE AVANT le
 *    geste peut réécrire l'ancien chiffre après l'oubli — la pastille reste
 *    alors au plus 60 s sur l'ancien total (durée du cache), jamais plus.
 *
 * Les journaux ne portent que la CLASSE de l'exception et son code SQLSTATE :
 * le message d'une `QueryException` recopie le SQL avec ses valeurs
 * (identifiants d'espace et de fiches), qui n'ont rien à faire dans un
 * journal.
 *
 * Chaque compteur tourne dans un point de sauvegarde (`DB::transaction`) :
 * hors transaction (la production), c'est une transaction de lecture sans
 * effet ; dans une transaction englobante, l'échec d'un compteur n'avorte pas
 * la transaction entière — le compteur suivant peut encore répondre.
 */
class ATraiterController extends ConsoleController
{
    public const CACHE_SECONDES = 60;

    public static function cle(string $espace): string
    {
        return 'crm:a-traiter:compteurs:v1:' . $espace;
    }

    /**
     * Oublie les compteurs de l'espace — appelé après un geste qui vide une
     * file (fusionner, écarter, rattacher) : sans lui, la pastille resterait
     * jusqu'à 60 s sur l'ancien chiffre alors que l'écran vient de changer.
     * Un cache indisponible n'empêche jamais le geste lui-même.
     */
    public static function oublier(string $espace): void
    {
        try {
            Cache::forget(self::cle($espace));
        } catch (\Throwable $e) {
            Log::warning('a-traiter: cache non vidé', self::contexteErreur($e));
        }
    }

    public function compteurs(Request $request): JsonResponse
    {
        $user = $this->currentUser($request);
        $espace = $this->espaceCourantOuNull();

        // Sans espace : on ne sait rien, et on ne compte surtout pas « tout ».
        if ($espace === null) {
            return $this->ok(self::inconnus());
        }

        $calcul = fn (): array => $this->calculer($espace, ConsoleAccess::currentIsVivier($user));

        try {
            /** @var mixed $charge */
            $charge = Cache::remember(self::cle($espace), self::CACHE_SECONDES, $calcul);
        } catch (\Throwable $e) {
            // Cache indisponible : on calcule sans lui plutôt que de priver le
            // menu de ses pastilles.
            Log::warning('a-traiter: cache indisponible', self::contexteErreur($e));
            $charge = $calcul();
        }

        return $this->ok(is_array($charge) ? array_merge(self::inconnus(), $charge) : self::inconnus());
    }

    /** @return array{doublons: int|null, a_rattacher: int|null} */
    private function calculer(string $espace, bool $vivier): array
    {
        return WorkspaceContext::run($espace, fn (): array => [
            'doublons' => $this->compter('doublons', FilesATraiter::doublons(...), $espace),
            'a_rattacher' => $vivier ? null : $this->compter('a_rattacher', FilesATraiter::aRattacher(...), $espace),
        ]);
    }

    /** @param  callable(string): Builder  $file */
    private function compter(string $nom, callable $file, string $espace): ?int
    {
        try {
            return DB::transaction(static fn (): int => $file($espace)->count());
        } catch (\Throwable $e) {
            Log::warning('a-traiter: compteur indisponible', ['compteur' => $nom] + self::contexteErreur($e));

            return null;
        }
    }

    /**
     * Ce qu'on journalise d'un échec : la classe et le SQLSTATE, JAMAIS le
     * message (il contient le SQL et ses valeurs).
     *
     * @return array{exception: class-string, sqlstate: string|null}
     */
    private static function contexteErreur(\Throwable $e): array
    {
        $sqlstate = null;
        if ($e instanceof QueryException) {
            $etat = $e->errorInfo[0] ?? $e->getCode();
            $sqlstate = is_scalar($etat) && (string) $etat !== '' ? (string) $etat : null;
        }

        return ['exception' => $e::class, 'sqlstate' => $sqlstate];
    }

    /** @return array{doublons: null, a_rattacher: null} */
    private static function inconnus(): array
    {
        return ['doublons' => null, 'a_rattacher' => null];
    }
}
