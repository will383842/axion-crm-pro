<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * LE REGISTRE DES RETRAITS, POUR LES ORGANISMES SANS SIREN (2026-09-30).
 *
 * `crm:import-federations` accepte désormais les organismes SANS SIREN
 * (unions départementales, conseils départementaux d'ordres, antennes de
 * confédérations…) : leur fiche s'ancre sur (`country_code`, `foreign_id`).
 * Le registre `contacts_retires` (migration `2026_09_29_000001`) ne
 * connaissait que le SIREN : une personne supprimée ou effacée d'une telle
 * fiche y était inscrite avec un SIREN NULL — et `contacts_retires_contient`
 * (`r.siren = p_siren`) ne la retrouvait jamais. Au ré-import, elle revenait.
 *
 * Ce que fait cette migration :
 *
 *  - le registre gagne l'ancre des fiches sans SIREN : `country_code` et
 *    `foreign_id`, recopiés de la fiche par les deux déclencheurs (suppression
 *    d'une personne ; suppression d'une fiche, avant la cascade) ;
 *  - une clé unique PARTIELLE (`siren IS NULL`) dédoublonne ces lignes-là
 *    comme `contacts_retires_cle_key` dédoublonne celles à SIREN (dont les
 *    NULL, distincts entre eux, n'étaient jamais dédoublonnés) ;
 *  - `contacts_retires_contient_ancre()` : la même question oui/non que
 *    `contacts_retires_contient()`, posée par l'ancre (pays, `foreign_id`),
 *    dans l'espace du contexte seulement. `SECURITY DEFINER`,
 *    `search_path` fixé, EXECUTE retiré à PUBLIC et donné au seul rôle
 *    applicatif (relecture S-a : il n'exécute toujours pas
 *    `contacts_retires_empreinte`).
 *
 * Une ligne à SIREN s'écrit et se lit exactement comme avant : les
 * déclencheurs posent en plus l'ancre de la fiche, sans rien retirer.
 *
 * Aucune reprise : avant cette migration, aucune fiche sans SIREN n'a pu être
 * importée par `crm:import-federations` (elle exigeait un SIREN), donc aucune
 * ligne du registre ne manque d'ancre pour cette source.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("SET LOCAL lock_timeout = '30s'");

        DB::statement('ALTER TABLE contacts_retires ADD COLUMN IF NOT EXISTS country_code CHAR(2)');
        DB::statement('ALTER TABLE contacts_retires ADD COLUMN IF NOT EXISTS foreign_id TEXT');
        // Nom VÉRIFIÉ LIBRE le 2026-09-30.
        DB::statement(
            'CREATE UNIQUE INDEX IF NOT EXISTS contacts_retires_cle_ancre_key
             ON contacts_retires (workspace_id, country_code, foreign_id, cle_nom)
             WHERE siren IS NULL',
        );
        DB::statement("COMMENT ON TABLE contacts_retires IS 'Personnes retirees (suppression, effacement) : empreinte du nom normalise + SIREN, ou ancre (country_code, foreign_id) d un organisme sans SIREN. Jamais le nom en clair. Lue par crm:import-federations.'");

        DB::unprepared(<<<'SQL'
            -- La question de l'import pour un organisme SANS SIREN : « cette
            -- personne de cet organisme a-t-elle été retirée ? », par l'ancre
            -- (pays, foreign_id) — dans l'espace du contexte, jamais un autre.
            CREATE OR REPLACE FUNCTION public.contacts_retires_contient_ancre(p_workspace UUID, p_pays TEXT, p_foreign_id TEXT, p_prenom TEXT, p_nom TEXT)
            RETURNS BOOLEAN
            LANGUAGE sql
            STABLE
            SECURITY DEFINER
            SET search_path = public, pg_catalog
            AS $fn$
                SELECT EXISTS (
                    SELECT 1
                    FROM   public.contacts_retires r
                    WHERE  r.workspace_id = p_workspace
                    AND    p_workspace::TEXT = NULLIF(current_setting('app.current_workspace_id', true), '')
                    AND    r.country_code = p_pays::CHAR(2)
                    AND    r.foreign_id = p_foreign_id
                    AND    r.cle_nom = public.contacts_retires_empreinte(p_prenom, p_nom)
                )
            $fn$;
            REVOKE EXECUTE ON FUNCTION public.contacts_retires_contient_ancre(UUID, TEXT, TEXT, TEXT, TEXT) FROM PUBLIC;
        SQL);
        $roleApplicatif = (string) config('database.connections.pgsql_app.username', 'axion_app');
        if ($roleApplicatif !== '' && DB::selectOne('SELECT 1 AS e FROM pg_roles WHERE rolname = ?', [$roleApplicatif]) !== null) {
            $role = '"' . str_replace('"', '""', $roleApplicatif) . '"';
            DB::statement('GRANT EXECUTE ON FUNCTION public.contacts_retires_contient_ancre(UUID, TEXT, TEXT, TEXT, TEXT) TO ' . $role);
        }

        DB::unprepared(<<<'SQL'
            -- Même corps qu'en 2026_09_29_000001, plus l'ancre de la fiche.
            CREATE OR REPLACE FUNCTION public.contacts_memoriser_retrait()
            RETURNS trigger
            LANGUAGE plpgsql
            SECURITY DEFINER
            SET search_path = public, pg_catalog
            AS $fn$
            DECLARE
                v_siren      CHAR(9);
                v_pays       CHAR(2);
                v_foreign_id TEXT;
            BEGIN
                IF NOT EXISTS (SELECT 1 FROM public.workspaces w WHERE w.id = OLD.workspace_id) THEN
                    RETURN OLD;
                END IF;

                IF COALESCE(OLD.sources, '[]'::jsonb) @> '["federations-2026"]'::jsonb THEN
                    SELECT c.siren, c.country_code, c.foreign_id INTO v_siren, v_pays, v_foreign_id
                    FROM public.companies c WHERE c.id = OLD.company_id;
                    IF NOT FOUND THEN
                        -- Suppression EN CASCADE de la fiche : le déclencheur
                        -- de `companies` (BEFORE DELETE) a déjà inscrit ses
                        -- personnes, avec son SIREN ou son ancre.
                        RETURN OLD;
                    END IF;

                    INSERT INTO public.contacts_retires (workspace_id, company_id, siren, country_code, foreign_id, cle_nom)
                    VALUES (OLD.workspace_id, OLD.company_id, v_siren, v_pays, v_foreign_id,
                            public.contacts_retires_empreinte(OLD.first_name, OLD.last_name))
                    ON CONFLICT DO NOTHING;
                END IF;

                RETURN OLD;
            END
            $fn$;

            CREATE OR REPLACE FUNCTION public.companies_memoriser_retraits()
            RETURNS trigger
            LANGUAGE plpgsql
            SECURITY DEFINER
            SET search_path = public, pg_catalog
            AS $fn$
            BEGIN
                IF NOT EXISTS (SELECT 1 FROM public.workspaces w WHERE w.id = OLD.workspace_id) THEN
                    RETURN OLD;
                END IF;

                INSERT INTO public.contacts_retires (workspace_id, company_id, siren, country_code, foreign_id, cle_nom)
                SELECT ct.workspace_id, ct.company_id, OLD.siren, OLD.country_code, OLD.foreign_id,
                       public.contacts_retires_empreinte(ct.first_name, ct.last_name)
                FROM   public.contacts ct
                WHERE  ct.company_id = OLD.id
                AND    COALESCE(ct.sources, '[]'::jsonb) @> '["federations-2026"]'::jsonb
                ON CONFLICT DO NOTHING;

                RETURN OLD;
            END
            $fn$;
        SQL);
    }

    /**
     * Les déclencheurs reprennent leur corps de `2026_09_29_000001`, la
     * fonction de lecture par l'ancre part. Les COLONNES et leurs valeurs
     * RESTENT, comme le registre lui-même au retour arrière de la migration
     * des fédérations (relecture B1) : les retirer effacerait la mémoire des
     * personnes retirées des fiches sans SIREN, qui reviendraient au prochain
     * import une fois la migration rejouée.
     */
    public function down(): void
    {
        DB::statement('DROP FUNCTION IF EXISTS public.contacts_retires_contient_ancre(UUID, TEXT, TEXT, TEXT, TEXT)');
        DB::statement('DROP INDEX IF EXISTS contacts_retires_cle_ancre_key');

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION public.contacts_memoriser_retrait()
            RETURNS trigger
            LANGUAGE plpgsql
            SECURITY DEFINER
            SET search_path = public, pg_catalog
            AS $fn$
            DECLARE
                v_siren CHAR(9);
            BEGIN
                IF NOT EXISTS (SELECT 1 FROM public.workspaces w WHERE w.id = OLD.workspace_id) THEN
                    RETURN OLD;
                END IF;

                IF COALESCE(OLD.sources, '[]'::jsonb) @> '["federations-2026"]'::jsonb THEN
                    SELECT c.siren INTO v_siren FROM public.companies c WHERE c.id = OLD.company_id;
                    IF NOT FOUND THEN
                        RETURN OLD;
                    END IF;

                    INSERT INTO public.contacts_retires (workspace_id, company_id, siren, cle_nom)
                    VALUES (OLD.workspace_id, OLD.company_id, v_siren, public.contacts_retires_empreinte(OLD.first_name, OLD.last_name))
                    ON CONFLICT DO NOTHING;
                END IF;

                RETURN OLD;
            END
            $fn$;

            CREATE OR REPLACE FUNCTION public.companies_memoriser_retraits()
            RETURNS trigger
            LANGUAGE plpgsql
            SECURITY DEFINER
            SET search_path = public, pg_catalog
            AS $fn$
            BEGIN
                IF NOT EXISTS (SELECT 1 FROM public.workspaces w WHERE w.id = OLD.workspace_id) THEN
                    RETURN OLD;
                END IF;

                INSERT INTO public.contacts_retires (workspace_id, company_id, siren, cle_nom)
                SELECT ct.workspace_id, ct.company_id, OLD.siren, public.contacts_retires_empreinte(ct.first_name, ct.last_name)
                FROM   public.contacts ct
                WHERE  ct.company_id = OLD.id
                AND    COALESCE(ct.sources, '[]'::jsonb) @> '["federations-2026"]'::jsonb
                ON CONFLICT DO NOTHING;

                RETURN OLD;
            END
            $fn$;
        SQL);
    }
};
