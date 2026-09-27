<?php

use App\Crm\FichesProtegees;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * FICHES PROTÉGÉES — le dernier verrou est dans la BASE.
 *
 * `FichesProtegees` exclut ces fiches des deux purges et de la corbeille de la
 * console. Mais une purge écrite demain en `DB::table('companies')->delete()`
 * ne saurait rien de ce prédicat : c'est exactement ainsi que
 * `purge-non-commercial` s'est retrouvée à viser tous les organisateurs
 * d'événements (`legal_form IS NULL`). Ce déclencheur refuse la suppression
 * PHYSIQUE d'une fiche protégée, quel que soit le chemin.
 *
 * - `SECURITY DEFINER` : sans contexte d'espace, la RLS du rôle applicatif
 *   rendrait `company_tag` vide et le déclencheur laisserait tout passer.
 *   La fonction ne fait que LIRE.
 * - `BEFORE DELETE` : le tag est encore là (la cascade vers `company_tag`
 *   n'a pas eu lieu).
 * - La corbeille (`deleted_at`) est un UPDATE : elle est gardée côté
 *   contrôleur, pas ici.
 * - Levée volontaire, pour l'annulation d'un import décidée par Will, dans la
 *   même transaction : `SET LOCAL app.autoriser_suppression_protegee = 'on'`.
 *
 * La liste des tags est recopiée de `FichesProtegees::TAGS` à la migration ;
 * un test vérifie que chaque tag de la constante est bien refusé ici.
 */
return new class extends Migration
{
    public function up(): void
    {
        $slugs = implode(', ', array_map(
            static fn (string $slug): string => "'" . str_replace("'", "''", $slug) . "'",
            FichesProtegees::TAGS,
        ));

        DB::unprepared(<<<SQL
            CREATE OR REPLACE FUNCTION public.refuser_suppression_fiche_protegee()
            RETURNS trigger
            LANGUAGE plpgsql
            SECURITY DEFINER
            SET search_path = public, pg_catalog
            AS \$fn\$
            BEGIN
                IF COALESCE(current_setting('app.autoriser_suppression_protegee', true), '') = 'on' THEN
                    RETURN OLD;
                END IF;

                IF EXISTS (
                    SELECT 1
                    FROM   company_tag ct
                    JOIN   tags t ON t.id = ct.tag_id
                    WHERE  ct.company_id = OLD.id
                    AND    t.slug IN ({$slugs})
                ) THEN
                    RAISE EXCEPTION 'fiche_protegee : suppression refusee (company_id=%)', OLD.id
                        USING HINT = 'Organisateurs d''evenements. Levee volontaire : SET LOCAL app.autoriser_suppression_protegee = ''on''.';
                END IF;

                RETURN OLD;
            END
            \$fn\$;

            DROP TRIGGER IF EXISTS companies_refuser_suppression_protegee ON public.companies;

            CREATE TRIGGER companies_refuser_suppression_protegee
                BEFORE DELETE ON public.companies
                FOR EACH ROW EXECUTE FUNCTION public.refuser_suppression_fiche_protegee();
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS companies_refuser_suppression_protegee ON public.companies;
            DROP FUNCTION IF EXISTS public.refuser_suppression_fiche_protegee();
        SQL);
    }
};
