<?php

namespace App\Http\Controllers\Api;

use App\Models\Media;
use App\Support\EligibiliteCampagne;
use App\Support\MasquageCoordonnees;
use App\Support\PlafondExport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * API des MÉDIAS (TV, radio, presse, portails, agences, blogs, émissions).
 * Calquée sur {@see CompaniesController} : index paginé + query filtrée partagée
 * avec l'export CSV streamé, scoping workspace explicite, défensif (jamais 500).
 */
class MediaController extends ApiController
{
    public function index(Request $r): JsonResponse
    {
        $perPage = min(100, max(1, (int) $r->query('per_page', 25)));

        if (! Schema::hasTable('media')) {
            return $this->ok([
                'data' => [],
                'meta' => ['total' => 0, 'per_page' => $perPage, 'current_page' => 1, 'last_page' => 1],
            ]);
        }

        try {
            $page = $this->buildFilteredQuery()
                ->allowedSorts(...['name', 'enriched_at', 'created_at', 'media_type'])
                ->defaultSort('name')
                ->paginate($perPage);

            return $this->ok([
                // 🔴 SITE JUMEAU de B12-002 / F36-006. `media.email` est
                // l'equivalent exact de `companies.email_generic`, qui EST
                // masque depuis le correctif : deux colonnes de meme nature,
                // une seule couverte. C'est le motif A-011 dans sa forme la
                // plus pure.
                'data' => MasquageCoordonnees::masquerSiRequis($page->items()),
                'meta' => [
                    'total' => $page->total(),
                    'per_page' => $page->perPage(),
                    'current_page' => $page->currentPage(),
                    'last_page' => $page->lastPage(),
                ],
            ]);
        } catch (\Throwable $e) {
            Log::error('media.index failed', ['exception' => $e->getMessage()]);
            report($e);

            return $this->ok([
                'data' => [],
                'meta' => ['total' => 0, 'per_page' => $perPage, 'current_page' => 1, 'last_page' => 1],
                'degraded' => true,
            ]);
        }
    }

    /**
     * Query filtrée partagée liste (index) ↔ export → l'export = la liste affichée.
     *
     * 🔴 CONSTAT G41-010 (S2), corrige le 2026-08-22. Cette requete de base ne
     * portait AUCUN predicat `workspace_id`. L'unique `where('workspace_id')` du
     * fichier vivait dans `export()` : la LISTE rendait les medias de TOUS les
     * espaces, l'export non. C'est exactement le defaut deja repare dans le
     * controleur voisin `JournalistsController` (P6-API-001) — meme patron, meme
     * fichier a cote, un seul des deux corrige. Motif A-011 dans sa forme la
     * plus pure.
     *
     * Rien ne rattrapait la liste : `App\Models\Media` n'emploie aucun trait de
     * portee par espace, et la RLS Postgres est contournee par le role de
     * connexion de l'application. Le filtre est donc pose ICI, a la source de
     * toutes les lectures du controleur.
     *
     * ⚠️ CE QUI REND CE CORRECTIF SANS PERTE DE DONNEES — mesure du 2026-08-22,
     * pas une supposition. Le risque redoute etait « des lignes creees sans
     * workspace_id disparaitront de l'ecran ». Elles ne peuvent pas exister :
     * `media.workspace_id` est declare `UUID NOT NULL REFERENCES workspaces(id)`
     * (migration `2026_07_06_000002_create_media_and_journalists.php:34`). Et les
     * index composites que le predicat rend enfin utilisables sont deja poses
     * — `media_workspace_type_idx (workspace_id, media_type)`,
     * `media_workspace_dept_idx`, `media_workspace_theme_idx` (memes lignes 66-72).
     *
     * Sans contexte d'espace : on ne rend RIEN. Meme arbitrage que
     * `RgpdRequestsController` — le doute se tranche en faveur du silence,
     * jamais du fail-open.
     *
     * ⚠️ `export()` garde son propre `where('workspace_id', $workspaceId)`. Il
     * est desormais redondant, pas faux : les deux valeurs viennent du meme
     * `app('workspace.id')`. On ne retire pas une serrure parce qu'une autre
     * vient d'etre posee.
     *
     * ⚠️ `@return QueryBuilder<Media>` est OBLIGATOIRE, pas decoratif.
     * `Spatie\QueryBuilder\QueryBuilder` est `@template TModel of Model` : sans
     * le parametre, `TModel` reste non resolu et tout ce qui recoit ensuite le
     * Builder — ici `EligibiliteCampagne::exclureOpposes()`, elle aussi
     * templatee — devient inanalysable. Mesure du 2026-08-21 : c'est la cause
     * de 5 des 38 erreurs PHPStan de la branche.
     *
     * @return QueryBuilder<Media>
     */
    private function buildFilteredQuery(): QueryBuilder
    {
        $espaceCourant = $this->espaceCourantOuNull();

        return QueryBuilder::for(
            Media::query()
                ->whereNull('deleted_at')
                ->when(
                    $espaceCourant !== null && $espaceCourant !== '',
                    fn ($q) => $q->where('workspace_id', $espaceCourant),
                    fn ($q) => $q->whereRaw('1 = 0'),
                ),
        )
            ->allowedFilters(...[
                AllowedFilter::exact('media_type'),
                AllowedFilter::exact('media_family'),
                AllowedFilter::exact('email_confidence'),
                AllowedFilter::exact('periodicity'),
                AllowedFilter::exact('editorial_theme'),
                AllowedFilter::exact('diffusion_zone'),
                AllowedFilter::exact('department_code'),
                AllowedFilter::exact('region_code'),
                AllowedFilter::exact('enrich_status'),
                AllowedFilter::exact('source'),
                AllowedFilter::partial('name'),
                AllowedFilter::callback('has_website', function ($query, $value) {
                    filter_var($value, FILTER_VALIDATE_BOOLEAN)
                        ? $query->whereNotNull('website')
                        : $query->whereNull('website');
                }),
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
        $filename = 'medias-' . now()->format('Y-m-d') . '.csv';
        $header = ['Nom', 'Type', 'Famille', 'Périodicité', 'Thème', 'Zone', 'Département', 'Région', 'Ville', 'Éditeur', 'Site web', 'Email rédaction', 'Confiance email', 'Téléphone', 'N° CPPAP', 'N° ARCOM'];

        if (! Schema::hasTable('media') || $workspaceId === null) {
            return response()->streamDownload(function () use ($header) {
                $out = fopen('php://output', 'w');
                if ($out === false) {
                    // `php://output` ne s'ouvre pas : il n'y a aucun flux ou ecrire. On LEVE
                    // plutot que de poursuivre — `fputcsv(false, ...)` est une TypeError en
                    // PHP 8, et le telechargement rendrait un fichier vide ou tronque sans
                    // que l'operateur puisse savoir que son export est incomplet. C'est le
                    // defaut meme que le plafond partage (G41-007) sert a rendre VISIBLE.
                    throw new RuntimeException("Export CSV : impossible d'ouvrir php://output.");
                }
                fwrite($out, "\xEF\xBB\xBF");
                fputcsv($out, $header);
                fclose($out);
            }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
        }

        // Scope EXPLICITE workspace (défense principale contre fuite inter-tenants).
        //
        // 🔴 Et les portes d'opposition, qui manquaient entièrement ici.
        // Cet export sort « Email rédaction » et « Téléphone » ; il ne
        // consultait NI `opt_out` NI `email_suppressions`. Une rédaction qui
        // s'est opposée y figurait quand même. Constaté le 2026-08-16 — c'est
        // le seul des trois exports qui n'avait aucun filtre d'opposition,
        // même approximatif.
        // 🔴 `getEloquentBuilder()` N'EST PAS UN DÉTAIL DE STYLE — c'est la
        // cause du 500. Constat F36-008 (S1), mesuré le 2026-08-20 :
        //
        //   TypeError: App\Support\EligibiliteCampagne::exclureOpposes():
        //   Argument #1 ($query) must be of type
        //   Illuminate\Database\Eloquent\Builder,
        //   Spatie\QueryBuilder\QueryBuilder given, called in
        //   .../MediaController.php on line 114
        //
        // `buildFilteredQuery()` rend un `Spatie\QueryBuilder\QueryBuilder`, qui
        // en v6 N'ÉTEND PLUS `Eloquent\Builder` : c'est une enveloppe qui
        // reforwarde ses appels (`__call`), et `->where(...)` lui renvoie donc
        // `$this` — l'enveloppe, pas le Builder. `GET /media/export` rendait
        // ainsi 500 à TOUS les comptes habilités (owner, admin, opérateur).
        //
        // Le défaut vivait ici depuis l'ajout de la garde d'opposition
        // (2026-08-16) et personne ne l'a vu : la seule garde posée sur cette
        // route vérifiait le REFUS opposé au `viewer`, et un 403 n'entre jamais
        // dans le contrôleur. « La garde est la seule partie qui fonctionne. »
        //
        // `getEloquentBuilder()` rend le sujet réel, celui que
        // `allowedFilters()` a DÉJÀ modifié (Spatie applique les filtres à la
        // construction, pas à l'exécution) : aucun filtre n'est perdu.
        // ⚠️ ON NE CHAINE PAS `->where(...)->getEloquentBuilder()`, ET CE N'EST
        // PAS UN GOUT. `Spatie\QueryBuilder\QueryBuilder` porte
        // `@mixin EloquentBuilder<TModel>` : pour l'analyse statique, `->where()`
        // rend un `Eloquent\Builder`, qui n'a AUCUN `getEloquentBuilder()`.
        // A l'execution le chainage marche — `__call` reforwarde puis rend
        // l'enveloppe — mais PHPStan ne peut pas le savoir, et il avait raison
        // de se plaindre : c'est le meme ecart enveloppe/Builder qui a produit
        // le 500 du constat F36-008. On garde donc l'enveloppe dans une
        // variable, on la mute, et on ne la deballe qu'une fois.
        $filtree = $this->buildFilteredQuery();
        $filtree->where('workspace_id', $workspaceId);

        $query = EligibiliteCampagne::exclureOpposes(
            $filtree->getEloquentBuilder(),
            'media.email',
        );

        return response()->streamDownload(function () use ($query, $header) {
            $out = fopen('php://output', 'w');
            if ($out === false) {
                // `php://output` ne s'ouvre pas : il n'y a aucun flux ou ecrire. On LEVE
                // plutot que de poursuivre — `fputcsv(false, ...)` est une TypeError en
                // PHP 8, et le telechargement rendrait un fichier vide ou tronque sans
                // que l'operateur puisse savoir que son export est incomplet. C'est le
                // defaut meme que le plafond partage (G41-007) sert a rendre VISIBLE.
                throw new RuntimeException("Export CSV : impossible d'ouvrir php://output.");
            }
            fwrite($out, "\xEF\xBB\xBF"); // BOM UTF-8 → Excel FR lit les accents
            fputcsv($out, $header);
            // Plafond partagé (constat G41-007) : cf. App\Support\PlafondExport.
            $tronque = PlafondExport::parcourirBorne($query, function ($m) use ($out) {
                fputcsv($out, [
                    $m->name,
                    $m->media_type,
                    $m->media_family === 'audiovisual_production' ? 'Production audiovisuelle' : 'Rédactionnel',
                    $m->periodicity,
                    $m->editorial_theme,
                    $m->diffusion_zone,
                    $m->department_code,
                    $m->region_code,
                    $m->city,
                    $m->publisher,
                    $m->website,
                    $m->email,
                    $m->email_confidence,
                    $m->phone,
                    $m->cppap_number,
                    $m->arcom_id,
                ]);
            });
            if ($tronque) {
                PlafondExport::ecrireAvertissement($out, count($header));
            }
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8'] + PlafondExport::entetes());
    }

    public function show(Media $media): JsonResponse
    {
        // Constat B12-001 / F36-005 : la resolution de route rendait
        // l'enregistrement sans aucun filtre d'espace. 404, jamais 403 :
        // « interdit » confirmerait son existence.
        $this->refuserHorsEspace($media);

        // 🔴 SITE JUMEAU de B12-002 / F36-006, et le plus grave des six : cette
        // fiche charge `journalists`. Elle livrait donc, en une requete, le
        // courriel et le telephone de TOUTE la redaction — des personnes
        // physiques nommees — a un compte en lecture seule. C'est le meme
        // mecanisme que la fiche entreprise qui livrait ses `contacts`.
        return $this->ok(MasquageCoordonnees::masquerSiRequis(
            $media->load(['journalists', 'parent', 'children', 'company']),
        ));
    }
}
