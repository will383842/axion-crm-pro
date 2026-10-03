<?php

namespace App\Http\Controllers\Api;

use App\Crm\Console\ScoresPerimes;
use App\Crm\Taxonomy;
use App\Exceptions\TableauDeBordIncomplet;
use App\Support\DelaiRequeteSql;
use App\Support\WorkspaceContext;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * L'ÉCRAN D'ACCUEIL DE LA CONSOLE — constat P6-UI-001 (S0).
 *
 * 🔴 CE QU'IL Y AVAIT AVANT, ET CE QUE ÇA FAISAIT.
 *
 * `GET /dashboard/stats` n'était pas un contrôleur : c'était une **closure dans
 * `routes/api.php` qui renvoyait des zéros écrits en dur**. Aucun
 * `DashboardController` n'existait dans ce dépôt.
 *
 * Or `DashboardPage.tsx` teste `companies_total === 0` pour décider d'afficher
 * son état vide. **L'écran d'accueil du CRM annonçait donc en permanence
 * « Lance ton premier scrape — aucune entreprise collectée »**, sur une base qui
 * en porte 4 295 349. Les quatre vignettes, les trois graphiques et les deux
 * cartes latérales étaient du code injoignable.
 *
 * 🔑 POURQUOI PERSONNE NE L'AVAIT VU. Le mandat de l'audit 360° exige que chaque
 * écran soit ouvert à la main dans un vrai navigateur (§12, point 3). *La console
 * ne tourne pas.* Un défaut qui saute aux yeux en trois secondes d'usage a donc
 * survécu à un audit de 46 agents, et n'a été trouvé qu'à la passe P6, par un
 * regard neuf, en lisant le code.
 *
 * ── DEUX PRINCIPES QUI VIENNENT DES CONSTATS DU JOUR ────────────────────────
 *
 * 1. **Cloisonné, et fail-closed.** Un compteur est plus discret qu'une liste :
 *    il ne montre aucune fiche, mais il en **révèle le nombre**. Sans contexte
 *    d'espace, on ne compte donc RIEN — jamais le total de tous les clients.
 *    Depuis le lot 1 de l'audit UX (2026-10-03, P0-1), on le DIT : HTTP 409
 *    `no_workspace`. Les zéros qu'on rendait avant faisaient croire à une base
 *    vide (« Votre base est vide » sur 4,3 M de fiches).
 *
 * 2. **Chaque compteur se défend seul.** Une requête en erreur ne doit pas
 *    emporter l'écran entier : c'est ce qui a produit `A-015`, où l'accueil
 *    s'effaçait dès qu'`audit_logs` portait une ligne. On rend `null` pour CE
 *    compteur-là (l'écran écrit « — », jamais 0), on le journalise, et le
 *    résultat partiel n'entre PAS dans le cache (`TableauDeBordIncomplet`).
 */
class DashboardController extends ApiController
{
    /**
     * ── 2026-10-02 : « le tableau de bord met ~10 s » ──────────────────────
     *
     * Mesuré en production (EXPLAIN) : `companies_total` parcourt un index de
     * 4,3 M d'entrées, `contacts_qualified` balaie `contacts` (1,3 M) en
     * séquentiel, `size_distribution` balaie le tas de `companies` (4 Go).
     * Recalculés à CHAQUE ouverture de l'accueil, par chaque session.
     *
     * Ce sont des ordres de grandeur, pas une comptabilité : on les sert
     * depuis un cache court, par espace — même mécanique que
     * `App\Crm\Console\CompteursHub` (`Cache::flexible` : la valeur périmée
     * est servie tout de suite, UN seul recalcul part après la réponse, sous
     * verrou borné).
     */
    public const FRAIS_SECONDES = 120;

    public const PERIME_SECONDES = 1800;

    /**
     * Vrai dès qu'un compteur du calcul en cours est tombé dans son filet.
     * Remis à faux au début de chaque calcul (le recalcul différé réutilise
     * cette instance).
     */
    private bool $incomplet = false;

    /** Vrai pendant le calcul fait DANS la requête (pas le recalcul différé). */
    private bool $dansLaRequete = false;

    /**
     * `v4` (2026-10-03) : un compteur peut désormais valoir `null`
     * (« indisponible »). Une charge utile ne contient jamais de `null` en
     * cache — un résultat partiel n'y entre pas — mais la forme du contrat a
     * changé : la version change avec elle.
     *
     * `v5` (2026-10-03, nouvel accueil en blocs) : la charge utile gagne
     * `companies_enriched`, `prospects_joignables` et
     * `prospects_joignables_idf` (et leurs `_raison`).
     */
    public static function cle(string $espace): string
    {
        return 'crm:dashboard:stats:v5:' . $espace;
    }

    /**
     * Les audiences SYSTÈME dont l'accueil lit le nombre de membres
     * (`DefaultAudiencesSeeder`). Le nombre est recalculé chaque nuit par le
     * rafraîchissement des audiences : l'accueil le lit, il ne le recompte
     * pas (le recompter, c'est rejouer les critères sur 4,35 M de fiches).
     */
    public const AUDIENCE_JOIGNABLES = 'Prospects contactables';

    public const AUDIENCE_JOIGNABLES_IDF = 'Prospects contactables — Île-de-France';

    public function stats(Request $r): JsonResponse
    {
        $espace = $this->espaceCourantOuNull();

        // Sans contexte d'espace : on ne compte rien — jamais le total de tout
        // le monde — et on le DIT. Des zéros faisaient croire à une base vide
        // (audit UX du 2026-10-02, P0-1).
        if ($espace === null) {
            return $this->reponseSansEspace($r);
        }

        $this->dansLaRequete = true;
        try {
            /** @var mixed $charge */
            $charge = Cache::flexible(
                self::cle($espace),
                [self::FRAIS_SECONDES, self::PERIME_SECONDES],
                fn (): array => $this->calculerPourLeCache($espace),
                lock: ['seconds' => 60],
            );
        } catch (TableauDeBordIncomplet $e) {
            // Servi à l'écran, jamais gardé : la requête suivante retentera.
            $charge = $e->chiffres;
        } finally {
            $this->dansLaRequete = false;
        }

        if (! is_array($charge)) {
            $charge = $this->calculer($espace);
        }

        return response()->json(array_merge($this->gabaritVide(), $charge, [
            'period_label' => $this->libellePeriode($r->query('period')),
            // UNE seule valeur pour l'accueil et la carte de France (relecture
            // A09 de #291) : deux échantillons distincts, dans deux caches,
            // pouvaient dire « calcul en attente » ici et afficher le score
            // là-bas. On lit le cache partagé, jamais une copie figée ici.
            'quality_a_recalculer_pct' => ScoresPerimes::enCache($espace),
        ]));
    }

    /**
     * HTTP 409, dans la forme d'erreur de l'API (`error` + `message`). Deux
     * situations, deux gestes différents pour l'administrateur, donc deux
     * codes :
     *
     *  - `no_workspace` : le compte n'est membre d'AUCUN espace (aucune ligne
     *    non révoquée dans `user_workspaces`). Il faut l'y rattacher.
     *  - `workspace_not_selected` : le compte est membre d'au moins un espace,
     *    mais `users.current_workspace_id` est vide. Il suffit d'en
     *    sélectionner un. La console n'a pas (encore) de sélecteur d'espace
     *    (P0-2 : un seul espace, rien à choisir) : le message renvoie donc
     *    vers l'administrateur plutôt que vers un écran qui n'existe pas.
     *
     * `user_workspaces` n'est pas sous RLS (c'est la table qui DIT à quel
     * espace on appartient, cf. `harden_workspace_isolation`) : la lecture
     * est possible sans contexte d'espace. Une panne de cette lecture retombe
     * sur `no_workspace`, journalisée.
     */
    private function reponseSansEspace(Request $r): JsonResponse
    {
        $membre = false;
        $compte = $r->user();

        if ($compte !== null) {
            try {
                $membre = DB::table('user_workspaces')
                    ->where('user_id', $compte->getAuthIdentifier())
                    ->whereNull('revoked_at')
                    ->exists();
            } catch (\Throwable $e) {
                Log::warning('dashboard: appartenance indisponible', self::panneSansSql($e));
            }
        }

        if ($membre) {
            return response()->json([
                'error' => 'workspace_not_selected',
                'message' => "Aucun espace de travail n'est sélectionné sur votre compte. Contactez l'administrateur pour qu'il en sélectionne un.",
            ], 409);
        }

        return response()->json([
            'error' => 'no_workspace',
            'message' => "Aucun espace de travail n'est rattaché à votre compte.",
        ], 409);
    }

    /**
     * Le calcul confié à `Cache::flexible`. Un résultat partiel ne doit jamais
     * être écrit : la seule façon d'empêcher `flexible` d'écrire ce que rend
     * le calcul est de lever une exception (même patron que
     * `ObservabilityController::calculerPourLeCache`, F39-007).
     *
     * - Dans la requête : rattrapée par `stats()`, les chiffres sont servis.
     * - Dans le recalcul différé : journalisée ICI en `warning` — l'espace,
     *   jamais le SQL ni les valeurs — puis levée vers `rescue()`, qui ne la
     *   signale pas (`ShouldntReport`). La valeur en cache reste l'ancienne.
     *
     * @return array<string, mixed>
     */
    private function calculerPourLeCache(string $espace): array
    {
        $chiffres = $this->calculer($espace);
        if (! $this->incomplet) {
            return $chiffres;
        }

        if (! $this->dansLaRequete) {
            Log::warning('dashboard: recalcul différé incomplet, valeur en cache conservée', [
                'workspace_id' => $espace,
            ]);
        }

        throw new TableauDeBordIncomplet($chiffres);
    }

    /**
     * Le calcul, sans cache.
     *
     * - `WorkspaceContext::run` : le recalcul différé de `Cache::flexible`
     *   tourne APRÈS la réponse, quand `SetCurrentWorkspace` a retiré la
     *   variable de session de la RLS — sans contexte, il compterait zéro et
     *   le mettrait en cache (cf. `CompteursHub::calculer`).
     * - `DelaiRequeteSql::etendu` : ces balayages dépassent les 15 s accordés
     *   aux écrans ; ils ne bloquent plus l'écran, ils ont droit à plus.
     *
     * @return array<string, mixed>
     */
    private function calculer(string $espace): array
    {
        $this->incomplet = false;

        return DelaiRequeteSql::etendu(120, fn (): array => WorkspaceContext::run($espace, fn (): array => [
            'companies_total' => $this->compter('companies', $espace),
            // 🔴 2026-10-02 : « Enrichies 24h » = 1 671 720. On comptait
            // `updated_at` : TOUTE modification d'une fiche (ici un recalcul
            // massif du score qualité) passait pour un enrichissement. Seul
            // `enriched_at` dit qu'une fiche a été enrichie ; l'index partiel
            // `idx_companies_ws_enriched_at` (2026_10_02_000020) sert ce
            // comptage sans relire les 4,35 M de fiches.
            'companies_enriched_24h' => $this->compter('companies', $espace, function ($q) {
                $q->whereNotNull('enriched_at')->where('enriched_at', '>=', now()->subDay());
            }),
            // « Fiches enrichies » de l'accueil (part du total) : toutes les
            // fiches vivantes qui portent un `enriched_at`. Même index partiel
            // `idx_companies_ws_enriched_at` que le compteur sur 24 h — son
            // prédicat est exactement celui-ci.
            'companies_enriched' => $this->compter('companies', $espace, function ($q) {
                $q->whereNotNull('enriched_at');
            }),
            // « Prospects joignables » : le nombre de membres de l'audience
            // système « Prospects contactables » (e-mail présent, prêt au
            // démarchage, relations établies exclues), et celui de sa
            // déclinaison Île-de-France. Lus, pas recomptés.
            ...$this->membresAudience($espace, self::AUDIENCE_JOIGNABLES, 'prospects_joignables'),
            ...$this->membresAudience($espace, self::AUDIENCE_JOIGNABLES_IDF, 'prospects_joignables_idf'),
            'contacts_qualified' => $this->compter('contacts', $espace, function ($q) {
                // « Qualifiée » = joignable. C'est la définition que le hub
                // emploie déjà ; on ne réinvente pas un second sens ici.
                $q->where(function ($sq) {
                    $sq->whereNotNull('email')->orWhereNotNull('phone');
                });
            }),
            'scraper_runs_24h' => $this->compter('scraper_runs', $espace, function ($q) {
                $q->where('created_at', '>=', now()->subDay());
            }),
            // 🔴 2026-10-02 : « Qualité moyenne 0/100 » sur 4,3 M de fiches.
            // La répartition lisait la colonne `quality_tier`, qui N'EXISTE
            // PAS (la colonne générée s'appelle `quality_badge`) : le garde
            // `hasColumn` rendait le gabarit à zéro, et l'écran en tirait une
            // moyenne de 0. On lit désormais `quality_score` lui-même.
            ...$this->qualite($espace),
            // Les tailles du référentiel unique (`Taxonomy::TAILLES`), plus
            // aucune liste recopiée ici.
            'size_distribution' => $this->repartition('companies', $espace, 'size_category', self::taillesAZero()),
            'computed_at' => now()->utc()->toIso8601ZuluString(),
        ]));
    }

    /**
     * La qualité des fiches : répartition, moyenne RÉELLE, et part estimée
     * des scores PÉRIMÉS.
     *
     * Les seuils sont ceux de la colonne générée `quality_badge` (≥ 90
     * complète, ≥ 50 partielle, sinon basique). Un seul passage, servi par
     * l'index `idx_companies_workspace_score` (≈ 4 s sur la production,
     * dans le calcul différé de `stats()`).
     *
     * `quality_a_recalculer_pct` : sur un échantillon de 0,1 % des fiches,
     * la part dont le score stocké diffère du barème
     * (`company_quality_score_calcul`). Elle n'est PAS calculée ici : `stats()`
     * la lit dans le cache partagé `App\Crm\Console\ScoresPerimes::enCache`.
     * Mesure du 2026-10-02 matin : ≈ 79 % ; reprise terminée le soir → 0. Tant que
     * cette part est forte, l'écran DIT « calcul en attente » au lieu d'une
     * moyenne fausse. `null` = estimation impossible (fonction absente).
     *
     * @return array<string, mixed>
     */
    private function qualite(string $espace): array
    {
        $resultat = [
            'quality_distribution' => ['complete' => 0, 'partielle' => 0, 'basique' => 0],
            'quality_avg' => null,
            'quality_scored' => null,
            'quality_a_recalculer_pct' => null,
        ];

        try {
            $l = DB::selectOne(
                'SELECT count(*) FILTER (WHERE quality_score >= 90) AS complete,
                        count(*) FILTER (WHERE quality_score >= 50 AND quality_score < 90) AS partielle,
                        count(*) FILTER (WHERE quality_score < 50) AS basique,
                        count(*) FILTER (WHERE quality_score > 0) AS notees,
                        round(avg(quality_score)) AS moyenne
                   FROM companies
                  WHERE workspace_id = ? AND deleted_at IS NULL',
                [$espace],
            );
            $resultat['quality_distribution'] = [
                'complete' => (int) $l->complete, 'partielle' => (int) $l->partielle, 'basique' => (int) $l->basique,
            ];
            $resultat['quality_avg'] = $l->moyenne === null ? null : (int) $l->moyenne;
            $resultat['quality_scored'] = (int) $l->notees;
        } catch (\Throwable $e) {
            $this->incomplet = true;
            Log::warning('dashboard: qualite indisponible', self::panneSansSql($e));

            // Pas des zéros : une répartition à 0 / 0 / 0 se lirait « aucune
            // fiche ». `null` = « chiffre indisponible », l'écran écrit « — ».
            $resultat['quality_distribution'] = null;

            return $resultat;
        }

        return $resultat;
    }

    /**
     * Le gabarit complet, à zéro.
     *
     * `DashboardPage.tsx` lit toutes ces clés. Une clé absente rend `undefined`,
     * que l'écran affiche en `NaN` ou qui le fait planter au calcul de moyenne.
     * On part donc TOUJOURS du gabarit complet, et on l'enrichit.
     *
     * @return array<string, mixed>
     */
    private function gabaritVide(): array
    {
        return [
            'companies_total' => 0,
            'companies_enriched_24h' => 0,
            'contacts_qualified' => 0,
            'scraper_runs_24h' => 0,
            'llm_cost_eur_month' => 0,
            'quality_distribution' => ['complete' => 0, 'partielle' => 0, 'basique' => 0],
            // `null` = pas de moyenne connue : l'écran écrit « — », jamais 0.
            'quality_avg' => null,
            'quality_scored' => null,
            'quality_a_recalculer_pct' => null,
            'size_distribution' => self::taillesAZero(),
            // `null` = inconnu : l'écran écrit « — », jamais 0.
            'companies_enriched' => null,
            'prospects_joignables' => null,
            'prospects_joignables_raison' => null,
            'prospects_joignables_idf' => null,
            'prospects_joignables_idf_raison' => null,
        ];
    }

    /** L'audience système n'existe pas dans l'espace (ou a été supprimée). */
    public const RAISON_INTROUVABLE = 'audience_introuvable';

    /** Elle existe, mais est désactivée ou n'est plus rafraîchie chaque nuit : son chiffre est figé. */
    public const RAISON_INACTIVE = 'audience_inactive';

    /** Elle n'a jamais été calculée (`member_count` vaut 0 par défaut). */
    public const RAISON_NON_CALCULEE = 'audience_non_calculee';

    /**
     * Le nombre de membres d'une audience système, tel que le dernier
     * rafraîchissement l'a écrit — sous `{cle}` — et, quand il n'est pas
     * fiable, la RAISON sous `{cle}_raison`.
     *
     * Le chiffre vaut `null` — jamais 0, jamais un chiffre figé — dans quatre
     * cas :
     *  - l'audience n'existe pas (ou plus) : raison `audience_introuvable` ;
     *  - elle est désactivée (`is_active`) ou n'est plus rafraîchie chaque nuit
     *    (`auto_refresh`) : son `member_count` est figé à la dernière passe,
     *    raison `audience_inactive` (relecture exactitude de #302) ;
     *  - elle n'a jamais été rafraîchie : raison `audience_non_calculee` ;
     *  - la lecture échoue : panne, journalisée, raison `null` (« indisponible
     *    pour le moment »), résultat PAS mis en cache.
     * Les trois premiers cas ne sont pas des pannes : ils entrent en cache.
     *
     * Le nom est la seule clé des audiences système (`updateOrCreate` sur
     * `workspace_id, name` dans le seeder). Si plusieurs portent ce nom, la
     * plus ancienne — celle du seeder — fait foi.
     *
     * @return array<string, int|string|null>
     */
    private function membresAudience(string $espace, string $nom, string $cle): array
    {
        $resultat = static fn (?int $n, ?string $raison): array => [$cle => $n, $cle . '_raison' => $raison];

        if (! Schema::hasTable('email_audiences')) {
            return $resultat(null, self::RAISON_INTROUVABLE);
        }

        try {
            $ligne = DB::table('email_audiences')
                ->where('workspace_id', $espace)
                ->where('name', $nom)
                ->whereNull('deleted_at')
                ->orderBy('id')
                ->first(['member_count', 'refreshed_at', 'is_active', 'auto_refresh']);
        } catch (\Throwable $e) {
            $this->incomplet = true;
            Log::warning('dashboard: audience indisponible', self::panneSansSql($e));

            return $resultat(null, null);
        }

        if ($ligne === null) {
            return $resultat(null, self::RAISON_INTROUVABLE);
        }
        if (! (bool) $ligne->is_active || ! (bool) $ligne->auto_refresh) {
            return $resultat(null, self::RAISON_INACTIVE);
        }
        if ($ligne->refreshed_at === null) {
            return $resultat(null, self::RAISON_NON_CALCULEE);
        }

        return $resultat((int) $ligne->member_count, null);
    }

    /** @return array<string, int> */
    private static function taillesAZero(): array
    {
        return array_fill_keys(array_keys(Taxonomy::TAILLES), 0);
    }

    /**
     * Un compteur qui ne peut pas emporter l'écran avec lui.
     *
     * `null` = « je n'ai pas pu compter » (requête en erreur, délai dépassé) :
     * l'écran écrit « — », jamais 0. Une table ABSENTE reste à 0 : avant la
     * migration, il n'y a vraiment rien à compter.
     */
    private function compter(string $table, string $espace, ?callable $affiner = null): ?int
    {
        if (! Schema::hasTable($table)) {
            return 0;
        }

        try {
            $q = DB::table($table)->where('workspace_id', $espace);

            if (Schema::hasColumn($table, 'deleted_at')) {
                $q->whereNull('deleted_at');
            }
            if ($affiner !== null) {
                $affiner($q);
            }

            return (int) $q->count();
        } catch (\Throwable $e) {
            // Constat A-015 : l'accueil s'effaçait entièrement dès qu'une seule
            // requête échouait. Un compteur en panne vaut mieux qu'un écran
            // blanc — mais il ne doit pas se taire, ni se faire passer pour un
            // zéro (audit UX du 2026-10-02, P0-1) : `null`, et pas de cache.
            $this->incomplet = true;
            Log::warning('dashboard: compteur indisponible', [
                'table' => $table, ...self::panneSansSql($e),
            ]);

            return null;
        }
    }

    /**
     * `null` quand la requête échoue : un gabarit à zéros se lirait « aucune
     * fiche classée » ; l'écran écrit « Chiffre indisponible pour le moment ».
     *
     * @param  array<string, int>  $gabarit
     * @return array<string, int>|null
     */
    private function repartition(string $table, string $espace, string $colonne, array $gabarit): ?array
    {
        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $colonne)) {
            return $gabarit;
        }

        try {
            $lignes = DB::table($table)
                ->where('workspace_id', $espace)
                ->whereNotNull($colonne)
                ->select($colonne, DB::raw('count(*) as n'))
                ->groupBy($colonne)
                ->get();

            foreach ($lignes as $ligne) {
                $cle = (string) $ligne->{$colonne};
                // On n'invente pas de catégorie : une valeur hors gabarit est
                // ajoutée telle quelle, l'écran la rendra sous son nom brut
                // plutôt que de la perdre en silence.
                $gabarit[$cle] = (int) $ligne->n;
            }
        } catch (\Throwable $e) {
            $this->incomplet = true;
            Log::warning('dashboard: repartition indisponible', [
                'table' => $table, 'colonne' => $colonne, ...self::panneSansSql($e),
            ]);

            return null;
        }

        return $gabarit;
    }

    /**
     * Ce qu'on journalise d'une panne : sa nature, JAMAIS le texte de la
     * requête. Le message d'une `QueryException` recopie le SQL et ses valeurs
     * liées (identifiant d'espace compris) ; la classe et le code SQLSTATE
     * suffisent à reconnaître un délai dépassé (57014) d'une colonne absente.
     *
     * @return array{exception: string, sqlstate: string|null}
     */
    private static function panneSansSql(\Throwable $e): array
    {
        return [
            'exception' => $e::class,
            'sqlstate' => $e instanceof QueryException ? (string) $e->getCode() : null,
        ];
    }

    /**
     * ⚠️ Le sélecteur 7j / 30j / 90j de l'écran n'est PAS dans sa `queryKey`
     * React Query : changer de période ne relance aucune requête, seul le
     * sous-titre change. C'est un défaut du frontend, relevé par la passe P6, et
     * il n'est pas corrigé ici — mais l'API accepte et renvoie le libellé, pour
     * que le jour où le frontend sera réparé, le contrat existe déjà.
     */
    private function libellePeriode(mixed $periode): string
    {
        return match ((string) $periode) {
            '7d' => '7 derniers jours',
            '90d' => '90 derniers jours',
            default => '30 derniers jours',
        };
    }
}
