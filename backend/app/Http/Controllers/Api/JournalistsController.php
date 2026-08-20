<?php

namespace App\Http\Controllers\Api;

use App\Crm\Outbound\ConsentOutboundRecorder;
use App\Models\Journalist;
use App\Support\EligibiliteCampagne;
use App\Support\PlafondExport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * API des JOURNALISTES (contacts rédaction). ⚠️ Données personnelles (RGPD).
 * Le champ `opt_out` reste visible (transparence) ; l'effacement = soft-delete.
 */
class JournalistsController extends ApiController
{
    public function index(Request $r): JsonResponse
    {
        $perPage = min(100, max(1, (int) $r->query('per_page', 25)));

        if (! Schema::hasTable('journalists')) {
            return $this->ok([
                'data' => [],
                'meta' => ['total' => 0, 'per_page' => $perPage, 'current_page' => 1, 'last_page' => 1],
            ]);
        }

        try {
            $page = $this->buildFilteredQuery()
                ->allowedIncludes(...['media'])
                ->allowedSorts(...['last_name', 'created_at'])
                ->defaultSort('last_name')
                ->paginate($perPage);

            return $this->ok([
                'data' => $page->items(),
                'meta' => [
                    'total' => $page->total(),
                    'per_page' => $page->perPage(),
                    'current_page' => $page->currentPage(),
                    'last_page' => $page->lastPage(),
                ],
            ]);
        } catch (\Throwable $e) {
            Log::error('journalists.index failed', ['exception' => $e->getMessage()]);
            report($e);

            return $this->ok([
                'data' => [],
                'meta' => ['total' => 0, 'per_page' => $perPage, 'current_page' => 1, 'last_page' => 1],
                'degraded' => true,
            ]);
        }
    }

    /**
     * 🔴 CONSTAT P6-API-001 (S0). Cette requete de base ne portait AUCUN filtre
     * d'espace. L'unique `where('workspace_id')` du fichier vivait dans
     * `export()` : la LISTE fuyait, l'export ne fuyait pas. Un compte en
     * lecture seule lisait donc nom, adresse et telephone des journalistes de
     * tous les clients.
     *
     * `Journalist` ne porte pas le trait `BelongsToWorkspace`, le scope global
     * est inerte par defaut (`CRM_STRICT_WORKSPACE_SCOPE=false`), et la RLS est
     * contournee par le role de connexion. Le filtre est donc pose ICI, a la
     * source de toutes les lectures du controleur.
     *
     * Sans contexte d'espace : on ne rend RIEN.
     */
    private function buildFilteredQuery(): QueryBuilder
    {
        $espaceCourant = $this->espaceCourantOuNull();

        return QueryBuilder::for(
            Journalist::query()
                ->whereNull('deleted_at')
                ->when(
                    $espaceCourant !== null,
                    fn ($q) => $q->where('workspace_id', $espaceCourant),
                    fn ($q) => $q->whereRaw('1 = 0'),
                )
        )
            ->allowedFilters(...[
                AllowedFilter::exact('media_id'),
                AllowedFilter::exact('beat'),
                AllowedFilter::exact('opt_out'),
                AllowedFilter::partial('last_name'),
                AllowedFilter::callback('has_email', function ($query, $value) {
                    filter_var($value, FILTER_VALIDATE_BOOLEAN)
                        ? $query->whereNotNull('email')
                        : $query->whereNull('email');
                }),
            ]);
    }

    public function export(Request $r): StreamedResponse
    {
        $workspaceId = app()->bound('workspace.id') ? app('workspace.id') : null;
        $filename = 'journalistes-' . now()->format('Y-m-d') . '.csv';
        $header = ['Prénom', 'Nom', 'Rôle', 'Rubrique', 'Email', 'Téléphone', 'Média', 'Opt-out', 'Source'];

        if (! Schema::hasTable('journalists') || $workspaceId === null) {
            return response()->streamDownload(function () use ($header) {
                $out = fopen('php://output', 'w');
                fwrite($out, "\xEF\xBB\xBF");
                fputcsv($out, $header);
                fclose($out);
            }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
        }

        // 🔴 `opt_out` EST UNE COLONNE LOCALE, PAS LES TABLES D'OPPOSITION.
        //
        // `journalists.opt_out` n'enregistre qu'une opposition signalée sur la
        // fiche elle-même. Elle ignore `opt_out` et `email_suppressions` — les
        // tables où atterrissent les oppositions venues du site et les
        // effacements RGPD. Une personne qui s'est opposée par le site, et qui
        // est aussi journaliste, SORTAIT dans ce CSV avec nom, email et
        // téléphone. Constaté le 2026-08-16.
        //
        // On garde le filtre local (il dit quelque chose de vrai) et on ajoute
        // les deux portes partagées.
        // 🔴 `getEloquentBuilder()` — MÊME DÉFAUT QU'EN `MediaController`, et
        // c'est le patron A-011 du dépôt : le même geste faux recopié à deux
        // endroits. Constat F36-008 (S1), mesuré le 2026-08-20 :
        //
        //   TypeError: App\Support\EligibiliteCampagne::exclureOpposes():
        //   Argument #1 ($query) must be of type
        //   Illuminate\Database\Eloquent\Builder,
        //   Spatie\QueryBuilder\QueryBuilder given
        //
        // `Spatie\QueryBuilder\QueryBuilder` v6 n'étend plus `Eloquent\Builder`
        // (enveloppe à `__call`) : `->where(...)` rend l'enveloppe.
        // `GET /journalists/export` rendait donc 500 à tous les ayants droit.
        // `getEloquentBuilder()` rend le sujet réel, filtres déjà appliqués.
        $query = EligibiliteCampagne::exclureOpposes(
            $this->buildFilteredQuery()
                ->where('workspace_id', $workspaceId)
                ->where('opt_out', false)
                ->getEloquentBuilder(),
            'journalists.email',
        )->with('media');

        return response()->streamDownload(function () use ($query, $header) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, $header);
            // Plafond partagé (constat G41-007) : cf. App\Support\PlafondExport.
            $tronque = PlafondExport::parcourirBorne($query, function ($j) use ($out) {
                fputcsv($out, [
                    $j->first_name,
                    $j->last_name,
                    $j->role,
                    $j->beat,
                    $j->email,
                    $j->phone,
                    $j->media?->name,
                    $j->opt_out ? 'oui' : 'non',
                    $j->source,
                ]);
            });
            if ($tronque) {
                PlafondExport::ecrireAvertissement($out, count($header));
            }
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8'] + PlafondExport::entetes());
    }

    public function show(Journalist $journalist): JsonResponse
    {
        // Constat B12-001 / F36-005 : la resolution de route rendait
        // l'enregistrement sans aucun filtre d'espace. 404, jamais 403 :
        // « interdit » confirmerait son existence.
        $this->refuserHorsEspace($journalist);

        return $this->ok($journalist->load('media'));
    }

    /**
     * Droit d'opposition RGPD : bascule opt_out (le contact reste en base mais
     * exclu des exports/campagnes).
     */
    public function optOut(Journalist $journalist): JsonResponse
    {
        // Constat B12-001 / F36-005 : la resolution de route rendait
        // l'enregistrement sans aucun filtre d'espace. 404, jamais 403 :
        // « interdit » confirmerait son existence.
        $this->refuserHorsEspace($journalist);

        $email = $journalist->email;

        $journalist->update(['opt_out' => true]);

        // Lot L5 — l'opposition décidée DANS la console doit converger vers le
        // site : sans cela le site continuerait d'adresser une personne que le
        // CRM a opposée, et la prochaine synchro site → CRM la « rouvrirait ».
        // Sans email, il n'y a pas de hash — donc rien que le site puisse
        // rapprocher : on ne met rien en file plutôt qu'un message inexploitable.
        if (is_string($email) && trim($email) !== '') {
            try {
                app(ConsentOutboundRecorder::class)->recordForEmail(
                    'consent_optout',
                    $email,
                    'business',
                    payload: ['surface' => 'console:journalists', 'journalist_id' => $journalist->id],
                );
            } catch (\Throwable $e) {
                // Une panne de la mini-outbox ne fait pas échouer un droit
                // d'opposition déjà acté en base. Journalisé, jamais avalé.
                Log::error('crm.outbound.record_failed', [
                    'event_type' => 'consent_optout',
                    'journalist_id' => $journalist->id,
                    'exception' => $e->getMessage(),
                ]);
                report($e);
            }
        }

        return $this->ok($journalist);
    }

    /** Droit à l'effacement RGPD : soft-delete. */
    public function destroy(Journalist $journalist): JsonResponse
    {
        // Constat B12-001 / F36-005 : la resolution de route rendait
        // l'enregistrement sans aucun filtre d'espace. 404, jamais 403 :
        // « interdit » confirmerait son existence.
        $this->refuserHorsEspace($journalist);

        $journalist->delete();

        return response()->json(null, 204);
    }
}
