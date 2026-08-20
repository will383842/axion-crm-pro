<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * `idx_companies_geo` — 114 Mo d'index GiST que RIEN ne lit, et qui pèse sur le
 * chemin d'écriture le plus chaud du produit.
 *
 * Constat `G41-008` (S1, audit 360) : « neuf index que rien ne lit multiplient
 * par 2,7 le coût d'écriture de `companies` ».
 *
 * ⚠️ **CETTE MIGRATION N'EN RETIRE QU'UN SEUL DES NEUF, ET C'EST DÉLIBÉRÉ.**
 * Vérification faite le 2026-08-20 par `EXPLAIN` sur `axion_crm_perf4m` (la base
 * de l'audit, 2 800 000 fiches), **sept des neuf sont employés par une requête
 * réelle du produit** — le plan les cite. Le détail, index par index, est dans
 * `tests/Feature/Infra/IndexesEmployesParLeProduitTest.php`, qui rougira le jour
 * où quelqu'un les supprimera sur la foi du rapport. Le neuvième
 * (`idx_companies_workspace_dept`, 30 Mo) est bien inutilisable par la seule
 * requête produit qui touche `postcode`, mais son retrait n'a produit **aucun
 * gain mesurable** (cf. §3) : on ne retire pas un index pour rien.
 *
 * ── §1. POURQUOI CELUI-CI, ET LUI SEUL ──────────────────────────────────────
 *
 * `CREATE INDEX idx_companies_geo ON companies USING gist (geo_point)`, posé le
 * 2026-05-16 (migration `2026_05_16_000003`, l. 65).
 *
 * `geo_point` est ÉCRITE en trois endroits — `WaterfallOrchestrator.php:371`,
 * `:521`, `:545`, tous des `UPDATE … SET geo_point = ST_SetSRID(…)` — et **LUE
 * nulle part**. Aucun `ST_DWithin`, aucun `ST_Distance`, aucun `<->`, aucun
 * `ST_Within` dans tout le dépôt (`app/`, `routes/`, `database/`, `frontend/`).
 * Il n'y a donc pas d'écran, pas de commande, pas de tâche planifiée qui puisse
 * l'employer — ni en local, ni en production, puisque c'est le même code.
 *
 * C'est une raison plus forte qu'un `idx_scan = 0` : un compteur à zéro dit
 * seulement « personne ne s'en est servi pendant la fenêtre observée », et le
 * mandat rappelle à juste titre qu'un index muet sur un banc peut servir en
 * production. Ici, **aucune requête du produit ne peut le lire**, quelle que
 * soit la base.
 *
 * ── §2. CE QU'IL COÛTE, MESURÉ ──────────────────────────────────────────────
 *
 * Protocole (2026-08-20, base jetable `axion_crm_a35_idx`) : **deux tables
 * jumelles**, créées par `CREATE TABLE … (LIKE companies INCLUDING ALL)` — donc
 * schéma et 27 index identiques —, chargées du **même socle de 300 000 lignes**,
 * puis recevant les **mêmes 50 000 lignes**, au même instant, l'ordre des deux
 * tables étant ALTERNÉ à chaque tour pour annuler l'effet de chauffe.
 *
 * On ne compare donc pas « avant/après » sur une même table qui grossit — cette
 * mesure-là raconte l'histoire du banc — mais **deux objets au même instant**.
 *
 *   tour │ ordre        │ 27 index │ sans `geo` │ sans `geo`+`postcode`
 *   ─────┼──────────────┼──────────┼────────────┼──────────────────────
 *     1  │ 27,26,25     │ 37 634   │  34 914    │  28 366
 *     2  │ 25,27,26     │ 21 800   │  14 783    │  18 988
 *     3  │ 26,25,27     │ 16 786   │  14 162    │  15 300
 *   (+4 tours à deux tables : 32 914/22 378 · 23 046/19 068 · 29 068/24 301 ·
 *    27 998/14 416)          — millisecondes pour 50 000 insertions
 *
 *   Médianes : **27 998 ms avec les 27 index · 19 068 ms sans `geo`+`postcode`**
 *
 * Soit de l'ordre de **−25 % sur le temps d'insertion en lot**, et l'écart entre
 * « sans `geo` » et « sans `geo` + `postcode` » n'est pas distinguable du bruit :
 * **c'est l'index GiST qui coûte, pas le btree.** Un GiST se maintient par
 * descente et éclatement de pages ; un btree étroit de plus ne se voit pas.
 *
 * ⚠️ **La dispersion est de ±40 % d'un tour à l'autre** (14 s à 38 s) : cette
 * machine porte un seul Postgres partagé par douze chantiers. Le chiffre à
 * retenir est **un ordre de grandeur**, pas une précision. Ce qui est solide,
 * c'est le SENS : la table sans `geo` est plus rapide dans les deux ordres de
 * passage, tour après tour.
 *
 * Sur `axion_crm_perf4m` l'index pèse **114 Mo sur 2 114 Mo** de table totale —
 * et, dans ce jeu, il indexe **2 800 000 valeurs NULL** (`geo_point IS NOT NULL`
 * → 0 ligne) : un GiST indexe les NULL, contrairement à un btree partiel.
 *
 * ── §3. CE QUE JE N'AI PAS FAIT, ET POURQUOI ────────────────────────────────
 *
 * `idx_companies_workspace_dept` (30 Mo, `(workspace_id, postcode)` — le nom dit
 * « dept », la colonne dit « postcode ») : la seule requête produit qui touche
 * `postcode` est `AllowedFilter::partial('postcode')`, c'est-à-dire
 * `LOWER(postcode) LIKE '%…%'`, qu'aucun btree ne peut servir (vérifié :
 * `Seq Scan`). Il est donc bien inutilisé. Mais son retrait ne rend aucun gain
 * mesurable, et il redeviendra utile le jour où le filtre passera en `exact`.
 * **On le garde, et on le dit.**
 *
 * ── §4. RÉVERSIBLE, ET SANS VERROU ──────────────────────────────────────────
 *
 * `DROP INDEX CONCURRENTLY` : aucun verrou d'écriture sur `companies`, dont la
 * collecte se sert en permanence. `down()` le repose à l'identique, également
 * en `CONCURRENTLY` — compter quelques minutes sur les 4,29 M de la production,
 * sans blocage. Le jour où une recherche « les entreprises autour d'ici » est
 * écrite, `php artisan migrate:rollback` suffit à le rendre.
 *
 * `$withinTransaction = false` : `CONCURRENTLY` ne s'exécute pas dans une
 * transaction.
 */
return new class extends Migration
{
    public $withinTransaction = false;

    private const INDEX = 'idx_companies_geo';

    private const DEFINITION = 'CREATE INDEX CONCURRENTLY IF NOT EXISTS ' . self::INDEX . '
        ON companies USING gist (geo_point)';

    public function up(): void
    {
        // `IF EXISTS` : la base peut avoir été bâtie après ce retrait (env.
        // fraîche, `migrate:fresh`), et une migration qui échoue parce que le
        // travail est déjà fait est une migration qu'on finit par sauter.
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS ' . self::INDEX);
    }

    public function down(): void
    {
        // Un `CONCURRENTLY` interrompu laisse un index `indisvalid = false` que
        // `IF NOT EXISTS` tient pourtant pour existant : sans ce ménage, un
        // rollback rejoué « réussirait » en laissant un index inutilisable.
        // Idiome repris de `2026_08_19_000001` — on étend, on ne réinvente pas.
        $invalides = DB::select(
            'SELECT c.relname AS name
               FROM pg_class c
               JOIN pg_index i ON i.indexrelid = c.oid
              WHERE c.relname = ?
                AND NOT i.indisvalid',
            [self::INDEX],
        );

        foreach ($invalides as $ligne) {
            DB::statement('DROP INDEX CONCURRENTLY IF EXISTS ' . $ligne->name);
        }

        DB::statement(self::DEFINITION);
    }
};
