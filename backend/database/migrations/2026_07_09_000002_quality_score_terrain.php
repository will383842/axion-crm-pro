<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * quality_score « terrain » — enrichit recompute_company_quality_score().
 *
 * Rappel : le corps d'origine (2026_05_16_000001_create_extensions_and_helpers)
 * comptait website(+15), phone(+15), linkedin(+15), signals.recent(+10) et un
 * bloc contacts email « valid ET email_score>=70 → +45 ». Ce dernier était en
 * pratique jamais atteint pour les emails issus des mentions légales (aucun
 * email_score posé), d'où des fiches contactables notées trop bas.
 *
 * Nouvelle grille (max = 100), orientée prospection terrain :
 *   email_generic présent .................. +15
 *   contact email contactable .............. +20   (valid|catchall|unknown, SANS exiger email_score)
 *   website ................................ +15
 *   phone .................................. +10
 *   linkedin ............................... +10
 *   adresse présente ....................... +10
 *   lat/lon présents ....................... +10
 *   enseigne présente ...................... +5
 *   signals.recent non vide ................ +5
 *   -----------------------------------------------
 *   TOTAL max .............................. 100
 *
 * Signature et nom inchangés → les appels `SELECT recompute_company_quality_score(?)`
 * restent valides.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION recompute_company_quality_score(c_id BIGINT) RETURNS INT AS $$
            DECLARE
              score INT := 0;
              row_count INT;
            BEGIN
              SELECT
                (CASE WHEN c.email_generic IS NOT NULL AND c.email_generic <> '' THEN 15 ELSE 0 END)
                + (CASE WHEN c.website IS NOT NULL THEN 15 ELSE 0 END)
                + (CASE WHEN c.phone IS NOT NULL THEN 10 ELSE 0 END)
                + (CASE WHEN c.linkedin_url IS NOT NULL THEN 10 ELSE 0 END)
                + (CASE WHEN c.address IS NOT NULL AND c.address <> '' THEN 10 ELSE 0 END)
                + (CASE WHEN c.lat IS NOT NULL AND c.lon IS NOT NULL THEN 10 ELSE 0 END)
                + (CASE WHEN c.enseigne IS NOT NULL AND c.enseigne <> '' THEN 5 ELSE 0 END)
                + (CASE WHEN c.signals IS NOT NULL AND jsonb_array_length(coalesce(c.signals->'recent', '[]'::jsonb)) > 0 THEN 5 ELSE 0 END)
              INTO score
              FROM companies c
              WHERE c.id = c_id;

              -- Contact email contactable — mentions légales n'ayant pas de score,
              -- on ne l'exige PLUS (bug historique : +45 jamais atteint).
              SELECT count(*) INTO row_count
              FROM contacts ct
              WHERE ct.company_id = c_id
                AND ct.email_status IN ('valid', 'catchall', 'unknown', 'role');
              IF row_count > 0 THEN score := score + 20; END IF;

              IF score > 100 THEN score := 100; END IF;

              UPDATE companies SET quality_score = score WHERE id = c_id;
              RETURN score;
            END;
            $$ LANGUAGE plpgsql;
        SQL);
    }

    public function down(): void
    {
        // Restaure la définition d'origine (2026_05_16_000001).
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION recompute_company_quality_score(c_id BIGINT) RETURNS INT AS $$
            DECLARE
              score INT := 0;
              row_count INT;
            BEGIN
              SELECT
                (CASE WHEN c.website IS NOT NULL THEN 15 ELSE 0 END)
                + (CASE WHEN c.phone IS NOT NULL THEN 15 ELSE 0 END)
                + (CASE WHEN c.linkedin_url IS NOT NULL THEN 15 ELSE 0 END)
                + (CASE WHEN c.signals IS NOT NULL AND jsonb_array_length(coalesce(c.signals->'recent', '[]'::jsonb)) > 0 THEN 10 ELSE 0 END)
              INTO score
              FROM companies c
              WHERE c.id = c_id;

              SELECT count(*) INTO row_count
              FROM contacts ct
              WHERE ct.company_id = c_id
                AND ct.email_status = 'valid'
                AND ct.email_score >= 70;
              IF row_count > 0 THEN score := score + 45; END IF;

              UPDATE companies SET quality_score = score WHERE id = c_id;
              RETURN score;
            END;
            $$ LANGUAGE plpgsql;
        SQL);
    }
};
