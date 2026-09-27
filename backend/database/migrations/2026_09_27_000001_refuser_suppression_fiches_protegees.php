<?php

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
 * PHYSIQUE d'une fiche protégée, par quelque requête `DELETE` que ce soit.
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
 *   Toujours `SET LOCAL`, jamais `SET` : sur une connexion réutilisée (worker
 *   Horizon), la levée resterait active pour les jobs suivants. Aucun chemin
 *   applicatif ne la pose ; ce verrou arrête les ACCIDENTS de code, pas une
 *   personne qui exécute du SQL à la main.
 * - Tables écrites avec `public.` : sans cela, une table temporaire du même
 *   nom masquerait le contrôle (le `search_path` suit la convention du dépôt).
 *
 * La liste des tags est FIGÉE ici, volontairement : lue dans
 * `FichesProtegees::TAGS` au moment de la migration, elle aurait suivi la
 * constante en CI (qui rejoue `migrate:fresh`) mais jamais en production,
 * où ce déclencheur n'est installé qu'une fois. Ajouter un tag à la constante
 * exige donc une nouvelle migration — et `FichesProtegeesTest` rougit tant
 * qu'elle manque (il lit le corps de la fonction installée).
 *
 * Ce que ce verrou ne voit pas : un `TRUNCATE` (pas de déclencheur ligne).
 * Il bloquerait aussi la cascade d'une suppression PHYSIQUE d'espace
 * (aujourd'hui les espaces ne vont qu'à la corbeille).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION public.refuser_suppression_fiche_protegee()
            RETURNS trigger
            LANGUAGE plpgsql
            SECURITY DEFINER
            SET search_path = public, pg_catalog
            AS $fn$
            BEGIN
                IF COALESCE(current_setting('app.autoriser_suppression_protegee', true), '') = 'on' THEN
                    RETURN OLD;
                END IF;

                IF EXISTS (
                    SELECT 1
                    FROM   public.company_tag ct
                    JOIN   public.tags t ON t.id = ct.tag_id
                    WHERE  ct.company_id = OLD.id
                    AND    t.slug IN ('src:scraping-evenements-pro')
                ) THEN
                    RAISE EXCEPTION 'fiche_protegee : suppression refusee (company_id=%)', OLD.id
                        USING HINT = 'Organisateurs d''evenements. Levee volontaire : SET LOCAL app.autoriser_suppression_protegee = ''on''.';
                END IF;

                RETURN OLD;
            END
            $fn$;

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
