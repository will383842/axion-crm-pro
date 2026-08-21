<?php

namespace App\Support;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Cache;

/**
 * LE TOTAL D'UNE LISTE PAGINÉE, SORTI DU CHEMIN D'AFFICHAGE.
 *
 * 🔴 Constat `G41-006` (S1, audit 360) : « le `count(*)` de la pagination coûte
 * 2,2 s à chaque affichage de la liste entreprises ».
 *
 * ── CE QUE J'AI MESURÉ, LE 2026-08-20 ───────────────────────────────────────
 *
 * Sur `axion_crm_perf4m` (2 800 000 fiches, un seul workspace — la production
 * en porte 4 295 349, soit 1,53 ×), les deux requêtes du MÊME affichage, jouées
 * au MÊME instant sur la MÊME table :
 *
 *     -- le total (ce que `paginate()` émet AVANT la page)
 *     select count(*) from companies where deleted_at is null and workspace_id = ?
 *       Parallel Index Only Scan using idx_companies_ws_counts
 *       Heap Fetches: 0        Buffers: shared read=2472
 *       Execution Time: 1 818,064 ms (froid) · 490,431 ms (chaud)
 *
 *     -- la page
 *     select * from companies where … order by quality_score desc limit 25
 *       Index Scan using idx_companies_workspace_score
 *       Buffers: shared read=4
 *       Execution Time: 3,310 ms
 *
 * **148 fois le prix de la page à chaud, 550 fois à froid.** L'audit annonçait
 * 2 193 ms ; je mesure 1 818 ms sur le même jeu — même ordre de grandeur.
 *
 * ── LA PISTE « POSER UN INDEX » EST RÉFUTÉE PAR LA MESURE ────────────────────
 *
 * C'est le point qui commande tout le reste, et il ne se devine pas : ce
 * `count(*)` est **déjà** servi par un `Index Only Scan` sur le meilleur index
 * possible — `idx_companies_ws_counts` (20 Mo, `(workspace_id, relation_type,
 * lifecycle_stage) WHERE deleted_at IS NULL`), posé le 2026-08-19 pour les
 * pastilles du hub. `Heap Fetches: 0` : Postgres ne touche pas le tas du tout.
 * Et il coûte quand même une demi-seconde à chaud, parce que compter 2,8 M de
 * lignes exige de visiter 2,8 M d'entrées d'index. **Aucun index ne réparera
 * ça.** Le seul remède est de ne pas recompter à chaque affichage.
 *
 * ── POURQUOI UN CACHE, ET PAS LES DEUX AUTRES PISTES ─────────────────────────
 *
 * L'audit proposait `simplePaginate()` ou « un total approché ». Les deux
 * changent la SÉMANTIQUE de ce que l'API rend, et ce n'est pas au correctif de
 * trancher ça :
 *
 *   - `simplePaginate()` **supprime** `meta.total` et `meta.last_page`.
 *     `CompaniesListPage.tsx:751` passe `lastPage` au composant `Pagination` et
 *     `total` à la tuile de KPI (l. 391, 425) : la liste perdrait sa pagination
 *     par numéro de page ET son compteur. C'est une refonte d'écran, pas un
 *     correctif de latence.
 *   - un total **borné** (`count(*)` sur un `LIMIT 10000`) rendrait « 10 000 »
 *     là où il y a 2,8 M : le nombre affiché deviendrait faux, et `last_page`
 *     avec lui.
 *
 * Le cache, lui, garde le champ, son type, et sa valeur : c'est toujours un
 * comptage EXACT de l'état réel de la table — simplement pas à la seconde près.
 * C'est le compromis que le dépôt a déjà pris, argumenté et gardé pour le même
 * genre de nombre sur la MÊME table (`App\Crm\Console\CompteursHub`, constat
 * `G41-001`) : on étend le patron, on n'en invente pas un second.
 *
 * ── DEUX PIÈGES PAYÉS AILLEURS, PORTÉS ICI ──────────────────────────────────
 *
 * 1. **Le contexte RLS.** `Cache::flexible` rafraîchit en DIFFÉRÉ, après la
 *    réponse — donc après le `terminate()` de `SetCurrentWorkspace`, qui retire
 *    `app.current_workspace_id`. `companies` porte une policy RLS en FORCE :
 *    sans contexte, le rôle applicatif voit ZÉRO ligne et le total se figerait
 *    à 0 pour toute la fenêtre de péremption — un écran vide, sans erreur nulle
 *    part. Le calcul pose donc lui-même son contexte (`WorkspaceContext::run`).
 * 2. **Le verrou borné.** Par défaut `flexible` prend un verrou SANS
 *    expiration : un processus tué entre la prise et le relâchement le
 *    laisserait posé pour toujours, et ce total ne se rafraîchirait plus
 *    JAMAIS.
 *
 * ── LA CLÉ : ESPACE + REQUÊTE, JAMAIS L'ÉCRAN ───────────────────────────────
 *
 * Un cache de total mal indexé est PIRE que pas de cache : l'écran annoncerait
 * « 3 fiches » en en affichant deux. La clé porte donc l'empreinte de la
 * requête RÉELLE — SQL compilé + valeurs liées, tri et bornes de page retirés
 * (ils ne changent pas un comptage) — et l'identifiant d'espace de travail.
 * Deux gardes le tiennent (`TotalListeEntreprisesTest` n°3 et n°4).
 */
final class TotalListe
{
    /**
     * Fenêtre FRAÎCHE : en deçà, le total en cache est servi tel quel.
     *
     * 60 s, et non les 300 s de `CompteursHub`, à dessein : une pastille de
     * navigation est décorative, le total d'une liste est lu juste à côté des
     * fiches qu'il compte. Une minute borne l'écart visible à quelque chose
     * qu'un opérateur ne peut pas distinguer d'un rafraîchissement d'écran,
     * tout en ramenant le coût du comptage de « une fois par affichage » à
     * « une fois par minute et par liste ».
     */
    public const FRAIS_SECONDES = 60;

    /**
     * Fenêtre PÉRIMÉE : entre `FRAIS_SECONDES` et celle-ci, la valeur est
     * encore servie IMMÉDIATEMENT et le recalcul part en différé, sous verrou.
     * Au-delà, plus rien en cache : le comptage redevient synchrone.
     *
     * Avec `Cache::remember`, l'expiration provoquerait une ruée — dix sessions
     * simultanées lanceraient dix comptages de 490 ms à la même seconde. Le
     * verrou de `flexible` fait qu'un seul recalcule.
     */
    public const PERIME_SECONDES = 900;

    /** Verrou de rafraîchissement, BORNÉ (cf. piège n°2 en tête de classe). */
    private const VERROU_SECONDES = 30;

    /**
     * Le total de la liste, depuis le cache quand c'est possible.
     *
     * Le comptage lui-même reste `getCountForPagination()` — celui de Laravel,
     * mot pour mot : il faut que le nombre servi soit EXACTEMENT celui que
     * `paginate()` aurait calculé, sans quoi `last_page` et la page affichée
     * pourraient diverger.
     *
     * On reçoit la requête de BASE (`Query\Builder`), pas la requête Eloquent :
     * un comptage n'hydrate aucun modèle, et c'est aussi ce que l'appelant peut
     * fournir sans que l'analyse statique perde le fil — `CompaniesController`
     * manipule une enveloppe `Spatie\QueryBuilder\QueryBuilder` dont les appels
     * sont reforwardés par `__call`, et dont PHPStan ne suit plus le type après
     * un `->where()`. `toBase()`, lui, est compris des deux côtés.
     */
    public static function pour(Builder $requete, string $workspaceId): int
    {
        // On travaille sur un CLONE : `pour()` est appelé juste avant
        // `paginate()` sur la même instance, et consommer la requête d'appel
        // ferait disparaître la page.
        $base = clone $requete;

        // `@var mixed` : le cache peut rendre TOUT ce qu'un déploiement
        // antérieur y a laissé. Sans cette annotation, l'analyse statique
        // déduit le type de la fermeture et déclare la défense ci-dessous
        // « toujours vraie » — elle raisonnerait sur le code, pas sur le
        // contenu du magasin. Idiome repris de `CompteursHub::pour()`.
        /** @var mixed $charge */
        $charge = Cache::flexible(
            self::cle($workspaceId, $base),
            [self::FRAIS_SECONDES, self::PERIME_SECONDES],
            static fn (): int => WorkspaceContext::run(
                $workspaceId,
                static fn (): int => (clone $base)->getCountForPagination(),
            ),
            lock: ['seconds' => self::VERROU_SECONDES],
        );

        // Défense contre une charge utile d'une forme antérieure restée en
        // cache (clé recyclée, déploiement) : on recompte plutôt que de servir
        // un `last_page` calculé sur n'importe quoi. `is_numeric` et pas
        // `is_int` : selon le pilote, un entier peut revenir en chaîne.
        return is_numeric($charge)
            ? (int) $charge
            : WorkspaceContext::run($workspaceId, static fn (): int => (clone $base)->getCountForPagination());
    }

    /**
     * Oublie TOUS les totaux d'un espace de travail.
     *
     * Appelé depuis les écritures qui changent le NOMBRE de fiches (création,
     * suppression). Volontairement PAS branché sur la collecte, qui insère par
     * centaines de milliers : invalider à chaque insertion ferait recompter en
     * permanence ce que ce cache est là pour éviter — et c'est déjà le choix
     * assumé de `CompteursHub`.
     *
     * ⚠️ On ne peut pas énumérer les clés : il y en a une par combinaison de
     * filtres, et le magasin de cache n'offre pas de balayage portable
     * (`Cache::tags()` n'existe pas sur le magasin `file`, ni sur `array` en
     * test). On incrémente donc un NUMÉRO DE GÉNÉRATION par espace, présent
     * dans chaque clé : l'ancienne génération devient inatteignable et expire
     * toute seule. Aucun balayage, aucune dépendance au pilote.
     */
    public static function oublier(string $workspaceId): void
    {
        $cle = self::cleGeneration($workspaceId);

        // 🔴 PIÈGE PAYÉ LE 2026-08-20, ET C'EST MA PROPRE GARDE QUI L'A VU.
        //
        // Première version : « si la clé manque, poser 1 ». Or la génération
        // par défaut valait AUSSI 1 — le tout PREMIER oubli ne changeait donc
        // aucune clé, et le total restait figé pendant une minute après la
        // toute première création. Rouge de `TotalListeEntreprisesTest` n°6 :
        // « Failed asserting that 1 is identical to 2 ».
        //
        // La génération par défaut est maintenant **0**, et le premier oubli
        // pose **1** : la première invalidation compte comme les suivantes.
        // C'est exactement le genre de no-op silencieux qui fait croire un
        // correctif posé — et la raison pour laquelle une garde doit rougir
        // AVANT d'être verte.
        //
        // `Cache::increment` ne crée pas la clé sur tous les pilotes : on pose
        // donc la valeur d'abord quand elle manque, on incrémente ensuite.
        if (Cache::get($cle) === null) {
            Cache::forever($cle, 1);

            return;
        }

        Cache::increment($cle);
    }

    /** Le numéro de génération courant de l'espace (0 tant qu'aucun oubli). */
    private static function generation(string $workspaceId): int
    {
        $valeur = Cache::get(self::cleGeneration($workspaceId), 0);

        return is_numeric($valeur) ? (int) $valeur : 0;
    }

    private static function cleGeneration(string $workspaceId): string
    {
        return 'crm:liste:total:gen:' . $workspaceId;
    }

    /**
     * L'empreinte de la requête, pour la clé de cache.
     *
     * `cloneWithout` / `cloneWithoutBindings` : le même idiome que
     * `Query\Builder::runPaginationCountQuery()`. On retire colonnes, tri et
     * bornes de page — aucun d'eux ne change un comptage, et les garder ferait
     * manquer le cache dès que l'opérateur change l'ordre d'affichage.
     *
     * `serialize()` sur les valeurs liées et pas `implode()` : `['1','2']` et
     * `['12']` ne doivent pas produire la même empreinte. `xxh128` plutôt que
     * `md5` : quatre fois plus rapide, et il ne s'agit ici de rien de
     * cryptographique — juste de distinguer deux requêtes.
     */
    private static function cle(string $workspaceId, Builder $base): string
    {
        $pourCompter = $base
            ->cloneWithout($base->unions ? [] : ['columns', 'orders', 'limit', 'offset'])
            ->cloneWithoutBindings($base->unions ? [] : ['select', 'order']);

        $empreinte = hash(
            'xxh128',
            $pourCompter->toSql() . '|' . serialize($pourCompter->getBindings()),
        );

        // `v1` : le jour où la forme de la valeur mise en cache change, la
        // version change avec elle. Sans cela, un déploiement sert pendant un
        // quart d'heure des charges utiles de l'ancienne forme à du code neuf.
        return 'crm:liste:total:v1:' . $workspaceId . ':' . self::generation($workspaceId) . ':' . $empreinte;
    }
}
