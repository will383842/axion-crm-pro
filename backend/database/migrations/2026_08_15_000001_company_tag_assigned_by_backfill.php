<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * `company_tag.assigned_by` accepte une quatrième valeur : `backfill-src`.
 *
 * ── Pourquoi une migration et pas un contournement ──────────────────────────
 * Le vocabulaire de cette colonne est FERMÉ par un CHECK (`auto-rule`, `llm`,
 * `user`) — c'est une bonne chose : personne ne doit pouvoir inventer une
 * provenance d'étiquetage à la volée. L'ajouter passe donc par une migration,
 * délibérément, comme n'importe quelle extension de vocabulaire gouverné.
 *
 * ── Pourquoi cette valeur est NÉCESSAIRE ────────────────────────────────────
 * Le backfill des tags `src:scraping-*` sur les 4,29 M de fiches doit rester
 * ROLLBACKABLE PAR LOT (spec d'exécution de l'audit scraping, décision 2,
 * point f). Avec `auto-rule`, un rollback effacerait aussi les étiquettes
 * posées par le funnel d'ingestion — qui utilise la même valeur. Un marqueur
 * dédié rend le rollback chirurgical :
 *
 *   DELETE FROM company_tag WHERE assigned_by = 'backfill-src';
 *
 * Constaté en production le 2026-08-15 : sans lui, le backfill échoue en
 * `SQLSTATE[23514] company_tag_assigned_by_check` (transaction annulée, aucun
 * état partiel — 0 ligne écrite).
 *
 * ── Pourquoi NOT VALID + VALIDATE ───────────────────────────────────────────
 * `company_tag` porte 3 205 326 lignes. Un `ADD CONSTRAINT` ordinaire les
 * revalide toutes sous verrou ACCESS EXCLUSIVE (toutes les écritures
 * bloquées). En deux temps, seul le changement de métadonnées prend ce verrou
 * (millisecondes) et la validation tourne en SHARE UPDATE EXCLUSIVE, qui ne
 * bloque pas les écritures. Même patron que la migration 000002 du lot L1.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE company_tag DROP CONSTRAINT IF EXISTS company_tag_assigned_by_check');
        DB::statement(
            "ALTER TABLE company_tag ADD CONSTRAINT company_tag_assigned_by_check
             CHECK (assigned_by IN ('auto-rule', 'llm', 'user', 'backfill-src')) NOT VALID",
        );
        DB::statement('ALTER TABLE company_tag VALIDATE CONSTRAINT company_tag_assigned_by_check');
    }

    public function down(): void
    {
        // Le retrait n'est possible que si plus aucune ligne ne porte la
        // valeur : sinon la contrainte d'origine serait immédiatement violée.
        DB::statement("DELETE FROM company_tag WHERE assigned_by = 'backfill-src'");
        DB::statement('ALTER TABLE company_tag DROP CONSTRAINT IF EXISTS company_tag_assigned_by_check');
        DB::statement(
            "ALTER TABLE company_tag ADD CONSTRAINT company_tag_assigned_by_check
             CHECK (assigned_by IN ('auto-rule', 'llm', 'user'))",
        );
    }
};
