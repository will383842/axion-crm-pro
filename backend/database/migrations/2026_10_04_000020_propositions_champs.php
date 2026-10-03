<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * FILE DE PROPOSITIONS (N13, 03/10/2026).
 *
 * Quand une information venue d'un tiers (`apporteur`, `commercial`,
 * `societe` — vocabulaire du contrat Axion Partners,
 * `Taxonomy::FIELD_ORIGINS_TIERS`) DIFFÈRE d'une valeur déjà présente sur une
 * fiche entreprise (`companies`) ou personne (`contacts`), elle n'écrase
 * JAMAIS la valeur existante : elle devient une ligne de cette table, que le
 * propriétaire (rôle owner) accepte ou refuse. La règle est écrite UNE fois,
 * dans `App\Crm\Propositions\Propositions`.
 *
 * Préparation du futur canal Partners : rien n'est branché, aucune route
 * publique n'écrit ici. VIDE à la livraison.
 *
 * Migration ADDITIVE : une table, deux déclencheurs. Rien n'est réécrit.
 *
 * ── Colonnes ────────────────────────────────────────────────────────────────
 *  - `entite` + `entite_id` : la fiche visée (`entreprise` → `companies`,
 *    `personne` → `contacts`). Pas de clé étrangère possible sur deux
 *    tables : le déclencheur `propositions_champs_meme_espace` vérifie que la
 *    fiche existe dans le MÊME espace (lecture sous l'identité de
 *    l'appelant, patron de `provenances_tiers_meme_espace`).
 *  - `valeur_actuelle` : la valeur de la fiche AU MOMENT de la proposition
 *    (NULL si vide) ; `valeur_proposee` : ce que dit le tiers.
 *  - `reference_externe` : la référence OPAQUE du tiers (jamais lue, recopiée).
 *  - `statut` : `en_attente` → `acceptee` | `refusee`, une seule fois.
 *    `decidee_par` (compte) et `decidee_le` sont posés avec la décision, et
 *    seulement avec elle (CHECK). `decidee_par` n'a pas de clé étrangère : la
 *    trace d'une décision survit au compte qui l'a prise.
 *
 * Une même valeur proposée pour un même champ d'une même fiche n'ouvre
 * qu'UNE proposition en attente (index unique partiel) : un message rejoué
 * ne remplit pas la file.
 *
 * ── On ne supprime rien ─────────────────────────────────────────────────────
 * RÈGLE ABSOLUE du propriétaire (03/10/2026) : on GARDE TOUT.
 *  - `workspace_id` en `ON DELETE RESTRICT` ;
 *  - une proposition décidée est FIGÉE (déclencheur
 *    `propositions_champs_figee`) : sa décision ne se reprend pas ;
 *  - le rôle applicatif n'a ni DELETE ni TRUNCATE (REVOKE, patron de
 *    `contacts_provenances_tiers`). ⚠️ Relancer le `GRANT … ON ALL TABLES` de
 *    `2026_08_14_000001` les rétablirait : les retirer à nouveau.
 *
 * RLS : ENABLE + FORCE, politique stricte (pas de repli permissif) — patron
 * de `2026_10_03_000080_provenance_tiers`.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("SET LOCAL lock_timeout = '30s'");

        DB::statement(
            <<<'SQL'
            CREATE TABLE IF NOT EXISTS propositions_champs (
                id                 BIGSERIAL PRIMARY KEY,
                workspace_id       UUID NOT NULL REFERENCES workspaces(id) ON DELETE RESTRICT,
                entite             TEXT NOT NULL,
                entite_id          BIGINT NOT NULL,
                champ              TEXT NOT NULL CHECK (champ ~ '^[a-z_]{1,64}$'),
                valeur_actuelle    TEXT CHECK (valeur_actuelle IS NULL OR length(valeur_actuelle) <= 2000),
                valeur_proposee    TEXT NOT NULL CHECK (btrim(valeur_proposee) <> '' AND length(valeur_proposee) <= 2000),
                origine            TEXT NOT NULL,
                reference_externe  TEXT CHECK (reference_externe IS NULL OR (btrim(reference_externe) <> '' AND length(reference_externe) <= 200)),
                statut             TEXT NOT NULL DEFAULT 'en_attente',
                decidee_par        UUID,
                decidee_le         TIMESTAMPTZ,
                created_at         TIMESTAMPTZ NOT NULL DEFAULT now(),
                updated_at         TIMESTAMPTZ NOT NULL DEFAULT now(),
                CONSTRAINT propositions_champs_entite_check CHECK (entite IN ('entreprise', 'personne')),
                CONSTRAINT propositions_champs_origine_check CHECK (origine IN ('apporteur', 'commercial', 'societe')),
                CONSTRAINT propositions_champs_statut_check CHECK (statut IN ('en_attente', 'acceptee', 'refusee')),
                CONSTRAINT propositions_champs_decision_check CHECK (
                    (statut = 'en_attente' AND decidee_par IS NULL AND decidee_le IS NULL)
                    OR (statut <> 'en_attente' AND decidee_par IS NOT NULL AND decidee_le IS NOT NULL)
                )
            )
            SQL,
        );

        // La file « à valider » : par espace, les plus anciennes d'abord.
        DB::statement(
            "CREATE INDEX IF NOT EXISTS idx_propositions_champs_en_attente
             ON propositions_champs (workspace_id, id) WHERE statut = 'en_attente'",
        );
        // Un message rejoué n'ouvre pas une seconde proposition identique.
        DB::statement(
            "CREATE UNIQUE INDEX IF NOT EXISTS uq_propositions_champs_en_attente
             ON propositions_champs (workspace_id, entite, entite_id, champ, md5(valeur_proposee))
             WHERE statut = 'en_attente'",
        );

        DB::unprepared(
            <<<'SQL'
            CREATE OR REPLACE FUNCTION public.propositions_champs_meme_espace() RETURNS trigger
            LANGUAGE plpgsql
            SET search_path = public, pg_catalog
            AS $$
            BEGIN
                IF NEW.entite = 'entreprise' THEN
                    IF NOT EXISTS (SELECT 1 FROM companies c WHERE c.id = NEW.entite_id AND c.workspace_id = NEW.workspace_id) THEN
                        RAISE EXCEPTION 'propositions_champs : l entreprise % n est pas de cet espace', NEW.entite_id
                            USING ERRCODE = '23514';
                    END IF;
                ELSIF NOT EXISTS (SELECT 1 FROM contacts ct WHERE ct.id = NEW.entite_id AND ct.workspace_id = NEW.workspace_id) THEN
                    RAISE EXCEPTION 'propositions_champs : la personne % n est pas de cet espace', NEW.entite_id
                        USING ERRCODE = '23514';
                END IF;
                RETURN NEW;
            END;
            $$;

            DROP TRIGGER IF EXISTS propositions_champs_meme_espace ON propositions_champs;
            CREATE TRIGGER propositions_champs_meme_espace
                BEFORE INSERT OR UPDATE OF workspace_id, entite, entite_id ON propositions_champs
                FOR EACH ROW EXECUTE FUNCTION public.propositions_champs_meme_espace();

            -- Une décision prise ne se reprend pas, et une proposition ne change
            -- pas de sens en route : seuls le statut et la décision bougent, une
            -- fois.
            CREATE OR REPLACE FUNCTION public.propositions_champs_figee() RETURNS trigger
            LANGUAGE plpgsql
            SET search_path = public, pg_catalog
            AS $$
            BEGIN
                IF OLD.statut <> 'en_attente' THEN
                    RAISE EXCEPTION 'propositions_champs : la proposition % est déjà décidée', OLD.id
                        USING ERRCODE = '23514';
                END IF;
                IF NEW.workspace_id IS DISTINCT FROM OLD.workspace_id
                   OR NEW.entite IS DISTINCT FROM OLD.entite
                   OR NEW.entite_id IS DISTINCT FROM OLD.entite_id
                   OR NEW.champ IS DISTINCT FROM OLD.champ
                   OR NEW.valeur_actuelle IS DISTINCT FROM OLD.valeur_actuelle
                   OR NEW.valeur_proposee IS DISTINCT FROM OLD.valeur_proposee
                   OR NEW.origine IS DISTINCT FROM OLD.origine
                   OR NEW.reference_externe IS DISTINCT FROM OLD.reference_externe
                   OR NEW.created_at IS DISTINCT FROM OLD.created_at THEN
                    RAISE EXCEPTION 'propositions_champs : seule la décision d une proposition se modifie'
                        USING ERRCODE = '23514';
                END IF;
                RETURN NEW;
            END;
            $$;

            DROP TRIGGER IF EXISTS propositions_champs_figee ON propositions_champs;
            CREATE TRIGGER propositions_champs_figee
                BEFORE UPDATE ON propositions_champs
                FOR EACH ROW EXECUTE FUNCTION public.propositions_champs_figee();
            SQL,
        );

        DB::statement('ALTER TABLE propositions_champs ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE propositions_champs FORCE ROW LEVEL SECURITY');
        DB::statement('DROP POLICY IF EXISTS propositions_champs_workspace_isolation ON propositions_champs');
        DB::statement(
            "CREATE POLICY propositions_champs_workspace_isolation ON propositions_champs FOR ALL
             USING (workspace_id::TEXT = NULLIF(current_setting('app.current_workspace_id', true), ''))
             WITH CHECK (workspace_id::TEXT = NULLIF(current_setting('app.current_workspace_id', true), ''))",
        );

        $roleApplicatif = (string) config('database.connections.pgsql_app.username', 'axion_app');
        if ($roleApplicatif !== '' && DB::selectOne('SELECT 1 AS e FROM pg_roles WHERE rolname = ?', [$roleApplicatif]) !== null) {
            $role = '"' . str_replace('"', '""', $roleApplicatif) . '"';
            DB::statement('GRANT SELECT, INSERT, UPDATE ON public.propositions_champs TO ' . $role);
            DB::statement('REVOKE DELETE, TRUNCATE ON public.propositions_champs FROM ' . $role);
            DB::statement('GRANT USAGE, SELECT ON SEQUENCE public.propositions_champs_id_seq TO ' . $role);
        }
        DB::statement(
            "COMMENT ON TABLE propositions_champs IS 'N13 — propositions de valeurs venues d''un tiers (apporteur, commercial, societe), en attente de la décision du propriétaire. "
            . 'Rien ne la supprime : rôle applicatif sans DELETE ni TRUNCATE, décision figée. ATTENTION : relancer le GRANT ON ALL TABLES '
            . "de 2026_08_14_000001_harden_workspace_isolation les rétablirait — les retirer à nouveau (REVOKE DELETE, TRUNCATE).'",
        );
    }

    public function down(): void
    {
        // Décompte HORS RLS (patron de `2026_10_03_000080`) : sous FORCE RLS, un
        // rôle sans BYPASSRLS compterait 0 sans rien dire.
        DB::statement('SET LOCAL row_security = off');

        $existe = DB::selectOne("SELECT to_regclass('public.propositions_champs') IS NOT NULL AS existe");
        if ($existe !== null && (bool) $existe->existe) {
            $n = (int) (DB::selectOne('SELECT count(*) AS n FROM propositions_champs')->n ?? 0);
            if ($n > 0) {
                throw new RuntimeException(
                    "Retour arrière refusé : {$n} proposition(s) existent. Les exporter et décider à la main d'abord.",
                );
            }
        }

        DB::statement('DROP TABLE IF EXISTS propositions_champs');
        DB::statement('DROP FUNCTION IF EXISTS public.propositions_champs_figee()');
        DB::statement('DROP FUNCTION IF EXISTS public.propositions_champs_meme_espace()');
    }
};
