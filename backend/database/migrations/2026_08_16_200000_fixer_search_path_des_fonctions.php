<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * ⚠️ CORRECTIF CRITIQUE — la sauvegarde de production n'était pas restaurable.
 *
 * Mesuré le 2026-08-16 lors du PREMIER exercice de restauration jamais joué :
 * une restauration du dump de production rendait une base SANS `companies`
 * (4,3 M lignes) ni `contacts` (1,3 M lignes) — les deux tables centrales.
 *
 * ## La chaîne
 *
 * `pg_dump` écrit en tête de chaque dump, par sécurité :
 *
 *     SELECT pg_catalog.set_config('search_path', '', false);
 *
 * Or `normalize_name()` appelle `unaccent()` SANS qualifier son schéma. Avec un
 * `search_path` vide, la fonction devient irrésolvable :
 *
 *     ERROR: function unaccent(text) does not exist
 *     CONTEXT: SQL function "normalize_name" during inlining
 *
 * Cette fonction alimente les colonnes GENERATED de `companies` et `contacts` :
 * leur `CREATE TABLE` échoue, puis tout ce qui les référence échoue en cascade.
 *
 * ## Le correctif
 *
 * Fixer le `search_path` des fonctions du projet. `pg_dump` reporte alors la
 * clause `SET search_path` dans le `CREATE FUNCTION` du dump, et la résolution
 * ne dépend plus du contexte de l'appelant.
 *
 * Les 7 fonctions sont traitées, pas seulement la fautive : aucune n'avait de
 * `search_path` fixé, et le défaut est latent pour toutes celles qui référencent
 * un objet non qualifié. C'est aussi une bonne pratique de sécurité — un
 * `search_path` libre est un vecteur connu de détournement de résolution.
 *
 * ## Innocuité
 *
 * `ALTER FUNCTION … SET` ne change ni le corps ni le résultat de la fonction :
 * en exploitation normale, `unaccent` se résolvait déjà vers `public.unaccent`.
 * Les colonnes générées ne sont donc pas recalculées et aucune donnée ne bouge.
 */
return new class extends Migration
{
    /**
     * Signature exacte relevée en production via
     * `pg_get_function_identity_arguments()` — une signature approximative
     * ferait échouer l'ALTER en silence sur un `IF EXISTS` absent.
     *
     * @var list<string>
     */
    private const FONCTIONS = [
        'candidates_enforce_vivier_workspace()',
        'compute_size_category(effectif_min integer, effectif_max integer, is_artisan boolean)',
        'normalize_name(input text)',
        'recompute_company_quality_score(c_id bigint)',
        'trg_company_recompute_score()',
        'trg_contact_recompute_company_score()',
        'trg_set_updated_at()',
    ];

    public function up(): void
    {
        foreach (self::FONCTIONS as $signature) {
            // `public` d'abord : c'est là que vivent `unaccent`, PostGIS et les
            // tables. `pg_catalog` reste consultable de toute façon, mais on
            // l'écrit pour que l'intention soit lisible sans connaître la règle
            // implicite de PostgreSQL.
            DB::statement("ALTER FUNCTION public.{$signature} SET search_path = public, pg_catalog");
        }
    }

    public function down(): void
    {
        foreach (self::FONCTIONS as $signature) {
            DB::statement("ALTER FUNCTION public.{$signature} RESET search_path");
        }
    }
};
