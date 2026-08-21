<?php

namespace App\Jobs\Concerns;

use App\Exceptions\MissingWorkspaceContextException;
use App\Support\WorkspaceContext;
use Closure;
use Illuminate\Support\Facades\DB;

/**
 * À utiliser par tout job / toute commande artisan qui touche des données
 * workspace-scopées.
 *
 * Le middleware SetCurrentWorkspace ne couvre QUE le HTTP : hors requête, il
 * n'existe aucun contexte. Sans ce garde-fou, un job de purge verrait zéro
 * ligne sous RLS stricte et sortirait en succès sans avoir rien purgé.
 *
 * ══════════════════════════════════════════════════════════════════════════
 * 🔴 CONSTAT B11-002 / B17-010 — POURQUOI LE TRAIT NE SUFFISAIT PAS
 * ══════════════════════════════════════════════════════════════════════════
 *
 * `Queue::looping` (AppServiceProvider) remet le contexte à ZÉRO entre deux
 * jobs : c'est son rôle, un worker Horizon garde sa connexion Postgres ouverte
 * et le contexte du job précédent fuirait sinon sur le suivant. Un job qui ne
 * RE-POSE pas le sien travaille donc sans espace.
 *
 * Mesure du 2026-08-21 sur `app/Jobs/` (six jobs) :
 *
 *   grep -l RunsInWorkspace app/Jobs/*.php   ->  EnrichCompanyJob.php seul
 *
 * et, pire, les QUATRE sites de dispatch de ce seul job appelaient
 * `EnrichCompanyJob::dispatch($company->id)` — un seul argument. Le paramètre
 * `$workspaceId` du lot L0 valait donc `null` À TOUS LES COUPS : ce n'était pas
 * 5 jobs sur 6 sans contexte, c'était 6 sur 6.
 *
 * ── Ce que ça coûte le jour où la ceinture est bouclée ─────────────────────
 *
 * La policy posée par `2026_08_14_000001_harden_workspace_isolation` est
 * STRICTE (`strictPredicate()`) :
 *
 *     workspace_id::TEXT = NULLIF(current_setting('app.current_workspace_id', true), '')
 *
 * Sans contexte, `current_setting(...)` rend NULL, la comparaison rend NULL,
 * donc FAUX : le job ne LIT aucune ligne et chacune de ses ÉCRITURES est
 * refusée par le `WITH CHECK`. Ce n'est pas « il voit tout », c'est « il ne
 * voit rien » — et un `find()` qui rend `null` est suivi, dans cinq de ces six
 * jobs, d'un `return` silencieux.
 *
 * ── Le geste ───────────────────────────────────────────────────────────────
 *
 * L'espace VOYAGE AVEC LA CHARGE du job, posé au dispatch par un appelant qui,
 * lui, a un contexte (requête HTTP, commande, job parent) :
 *
 *     EnrichCompanyJob::dispatch($company->id)->pourEspace($company->workspace_id);
 *
 * puis le job le repose pour toute sa durée via `inWorkspace()`.
 *
 * ⚠️ `$espaceCible` est une propriété DÉCLARÉE, et non un paramètre PROMU du
 * constructeur. Ce n'est pas un détail de style : mesuré le 2026-08-21,
 *
 *     unserialize('O:1:"J":1:{s:1:"a";i:7;}')  // J: ctor(int $a, ?string $w = null)
 *     -> Typed property J::$w must not be accessed before initialization
 *
 * alors que la même classe avec `public ?string $w = null;` DÉCLARÉE rend
 * `NULL`. Une charge sérialisée AVANT ce lot et encore en file au déploiement
 * exploserait donc si l'espace était un paramètre promu. C'est aussi la raison
 * pour laquelle `EnrichCompanyJob` perd ici le sien.
 *
 * Usage :
 *
 *     class PurgeCandidatsJob implements ShouldQueue
 *     {
 *         use RunsInWorkspace;
 *
 *         public function handle(): void
 *         {
 *             $this->inWorkspace($this->espaceDuJob(), function () {
 *                 // ... requêtes scopées ...
 *             });
 *         }
 *     }
 */
trait RunsInWorkspace
{
    /**
     * Espace transporté PAR LA CHARGE du job. Voir le bloc ci-dessus pour la
     * raison — mesurée — de la déclaration plutôt que de la promotion.
     */
    public ?string $espaceCible = null;

    /**
     * Pose l'espace sur le job. Chaînable derrière `dispatch()` :
     * `PendingDispatch::__call` relaie la méthode au job et se rend lui-même,
     * exactement comme `->onQueue()` ou `->delay()`.
     */
    public function pourEspace(?string $workspaceId): static
    {
        $this->espaceCible = WorkspaceContext::validIdOrNull($workspaceId);

        return $this;
    }

    /**
     * Espace sous lequel ce job doit s'exécuter. Surchargeable par un job qui
     * porte déjà l'identifiant dans son constructeur (LaunchZoneScrapingJob).
     */
    protected function espaceDuJob(): ?string
    {
        return $this->espaceCible;
    }

    /**
     * AMORÇAGE — lit l'espace de la ligne pivot quand la charge ne le porte pas
     * (job mis en file AVANT ce lot, ou point de dispatch oublié).
     *
     * ⚠️ CE CHEMIN N'EST PAS UN FILET DE SÉCURITÉ, ET IL FAUT LE DIRE.
     * `runWithoutScope()` ne lève QUE la ceinture applicative ; elle ne lève pas
     * la RLS Postgres (elle le dit elle-même). Le jour où
     * `CRM_DB_APP_ROLE_ENABLED` est armé, cette lecture-ci rend `null` comme
     * toutes les autres, et le job le DIT au lieu de travailler à l'aveugle.
     * Le seul chemin qui tienne sous RLS est l'espace porté par la charge.
     */
    protected function espaceDepuisLaLigne(string $table, int|string $id): ?string
    {
        return WorkspaceContext::runWithoutScope(
            "amorcage B11-002 : lire l'espace de {$table}#{$id} pour le reposer ensuite",
            static fn (): ?string => WorkspaceContext::validIdOrNull(
                DB::table($table)->where('id', $id)->value('workspace_id'),
            ),
        );
    }

    /**
     * @template TReturn
     *
     * @param  Closure(): TReturn  $callback
     * @return TReturn
     */
    protected function inWorkspace(?string $workspaceId, Closure $callback)
    {
        if ($workspaceId === null || $workspaceId === '') {
            throw MissingWorkspaceContextException::for(static::class);
        }

        return WorkspaceContext::run($workspaceId, $callback);
    }
}
