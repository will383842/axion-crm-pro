<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * CONVENTION COLLECTIVE (IDCC) ET OPCO DES ENTREPRISES (lot O14, chantier
 * OPCO, 05/10/2026) — alimentée par `crm:enrichir-opco`.
 *
 * PUREMENT ADDITIVE. `companies` (4,35 M de lignes, disque presque plein)
 * n'est NI modifiée NI réécrite : l'IDCC et l'OPCO vivent dans une table
 * dédiée, jointe à la lecture sur la fiche entreprise.
 *
 * ── `companies_opco` : une ligne par entreprise et par espace ─────────────
 *  - `siret`         SIRET de l'établissement rapproché (celui de la fiche,
 *                    `companies.siret`, au moment du rapprochement) ; NULL
 *                    possible pour une saisie sur une fiche sans SIRET ;
 *  - `idcc`          code IDCC à quatre chiffres (table SIRO de France
 *                    compétences, `IDCC`) ;
 *  - `opco`          OPCO PROPRIÉTAIRE (`OPCO_PROPRIETAIRE`, il fait foi) ;
 *  - `opco_gestion`  OPCO DE GESTION (`OPCO_GESTION`) ;
 *                    les deux dans une liste FERMÉE de onze valeurs ;
 *  - `source`        `siro` (table SIRET → OPCO de France compétences) ou
 *                    `saisie` (posée par une personne). Une ligne `saisie`
 *                    n'est JAMAIS écrasée par la commande ;
 *  - `releve_le`     premier jour du mois de la DSN dont la table est issue.
 *
 * ── `companies_opco_passages` : le journal des passages ───────────────────
 *  Une ligne par passage de `crm:enrichir-opco` : la ressource data.gouv lue
 *  (identifiant, URL et VERSION — somme de contrôle ou `last_modified`
 *  publiés : un fichier remplacé sous le même identifiant n'est pas repris),
 *  le mois de DSN, le CURSEUR (dernière ligne du fichier entièrement traitée,
 *  reprise après une coupure ou `--limite`) et le bilan chiffré. Patron de
 *  `insee_mises_a_jour`.
 *
 * RÈGLE ABSOLUE du propriétaire : on GARDE TOUT, on n'efface JAMAIS rien.
 *  - `company_id` et `workspace_id` en `ON DELETE RESTRICT` : supprimer une
 *    fiche (ou un espace) qui porte un IDCC est REFUSÉ par la base ;
 *  - le rôle applicatif n'a ni DELETE ni TRUNCATE sur les deux tables
 *    (REVOKE, patron de `contacts_provenances_tiers`). ⚠️ Relancer le
 *    `GRANT … ON ALL TABLES` de `2026_08_14_000001` les rétablirait : les
 *    retirer à nouveau.
 *
 * RLS : ENABLE + FORCE, politique stricte par espace (pas de repli
 * permissif) — patron de `2026_10_03_000080_provenance_tiers`. Un
 * déclencheur garantit que l'entreprise désignée est du MÊME espace que la
 * ligne (lecture sous l'identité de l'appelant, pas de SECURITY DEFINER).
 *
 * RGPD : le SIRET d'une entreprise individuelle peut être une donnée
 * personnelle (il désigne une personne physique) — voir le registre,
 * `spec/17_rgpd_aiact_owasp.md` §1.
 */
return new class extends Migration
{
    /** La liste fermée des OPCO (recopie de `App\Crm\Opco\Opco::VALEURS`, figée ici). */
    private const OPCO = [
        'opco2i', 'afdas', 'atlas', 'uniformation', 'constructys', 'opco_ep',
        'akto', 'opcommerce', 'mobilites', 'ocapiat', 'opco_sante',
    ];

    public function up(): void
    {
        DB::statement("SET LOCAL lock_timeout = '30s'");

        $liste = implode(', ', array_map(static fn (string $o): string => "'{$o}'", self::OPCO));

        DB::statement(
            <<<SQL
            CREATE TABLE IF NOT EXISTS companies_opco (
                id            BIGSERIAL PRIMARY KEY,
                workspace_id  UUID NOT NULL REFERENCES workspaces(id) ON DELETE RESTRICT,
                company_id    BIGINT NOT NULL REFERENCES companies(id) ON DELETE RESTRICT,
                siret         CHAR(14) NULL CHECK (siret IS NULL OR siret ~ '^[0-9]{14}$'),
                idcc          CHAR(4) NULL CHECK (idcc ~ '^[0-9]{4}$'),
                opco          TEXT NULL CHECK (opco IN ({$liste})),
                opco_gestion  TEXT NULL CHECK (opco_gestion IN ({$liste})),
                source        TEXT NOT NULL CHECK (source IN ('siro', 'saisie')),
                releve_le     DATE NULL,
                created_at    TIMESTAMPTZ NOT NULL DEFAULT now(),
                updated_at    TIMESTAMPTZ NOT NULL DEFAULT now(),
                CONSTRAINT companies_opco_espace_entreprise_unique UNIQUE (workspace_id, company_id)
            )
            SQL,
        );

        DB::unprepared(
            <<<'SQL'
            CREATE OR REPLACE FUNCTION public.companies_opco_meme_espace() RETURNS trigger
            LANGUAGE plpgsql
            SET search_path = public, pg_catalog
            AS $$
            BEGIN
                IF NOT EXISTS (SELECT 1 FROM companies c WHERE c.id = NEW.company_id AND c.workspace_id = NEW.workspace_id) THEN
                    RAISE EXCEPTION 'companies_opco : l entreprise % n est pas de cet espace', NEW.company_id
                        USING ERRCODE = '23514';
                END IF;
                RETURN NEW;
            END;
            $$;

            DROP TRIGGER IF EXISTS companies_opco_meme_espace ON companies_opco;
            CREATE TRIGGER companies_opco_meme_espace
                BEFORE INSERT OR UPDATE OF workspace_id, company_id ON companies_opco
                FOR EACH ROW EXECUTE FUNCTION public.companies_opco_meme_espace();
            SQL,
        );

        DB::statement(
            <<<'SQL'
            CREATE TABLE IF NOT EXISTS companies_opco_passages (
                id             BIGSERIAL PRIMARY KEY,
                workspace_id   UUID NOT NULL REFERENCES workspaces(id) ON DELETE RESTRICT,
                ressource_id   TEXT NOT NULL,
                ressource_url  TEXT NOT NULL,
                ressource_version TEXT NULL,
                releve_le      DATE NULL,
                statut         TEXT NOT NULL DEFAULT 'en_cours'
                               CHECK (statut IN ('en_cours', 'reussie', 'echouee')),
                curseur        BIGINT NOT NULL DEFAULT 0 CHECK (curseur >= 0),
                bilan          JSONB NOT NULL DEFAULT '{}'::jsonb,
                erreur         TEXT,
                demarree_le    TIMESTAMPTZ NOT NULL DEFAULT now(),
                maj_le         TIMESTAMPTZ NOT NULL DEFAULT now(),
                terminee_le    TIMESTAMPTZ
            )
            SQL,
        );
        // La FK RESTRICT depuis `companies` vérifie `companies_opco` à chaque
        // DELETE ou changement de `companies.id` : index dédié (table neuve,
        // vide — coût nul aujourd'hui).
        DB::statement('CREATE INDEX IF NOT EXISTS idx_companies_opco_company ON companies_opco (company_id)');

        DB::statement(
            'CREATE INDEX IF NOT EXISTS idx_companies_opco_passages_espace
             ON companies_opco_passages (workspace_id, statut, id DESC)',
        );

        foreach (['companies_opco', 'companies_opco_passages'] as $table) {
            DB::statement("ALTER TABLE {$table} ENABLE ROW LEVEL SECURITY");
            DB::statement("ALTER TABLE {$table} FORCE ROW LEVEL SECURITY");
            DB::statement("DROP POLICY IF EXISTS {$table}_workspace_isolation ON {$table}");
            DB::statement(
                "CREATE POLICY {$table}_workspace_isolation ON {$table} FOR ALL
                 USING (workspace_id::TEXT = NULLIF(current_setting('app.current_workspace_id', true), ''))
                 WITH CHECK (workspace_id::TEXT = NULLIF(current_setting('app.current_workspace_id', true), ''))",
            );
        }

        // Rien ne supprime un IDCC ni un passage : ni DELETE ni TRUNCATE pour
        // le rôle applicatif (les privilèges par défaut du 14/08 les lui
        // donneraient).
        $roleApplicatif = (string) config('database.connections.pgsql_app.username', 'axion_app');
        if ($roleApplicatif !== '' && DB::selectOne('SELECT 1 AS e FROM pg_roles WHERE rolname = ?', [$roleApplicatif]) !== null) {
            $role = '"' . str_replace('"', '""', $roleApplicatif) . '"';
            foreach (['companies_opco', 'companies_opco_passages'] as $table) {
                DB::statement("GRANT SELECT, INSERT, UPDATE ON public.{$table} TO " . $role);
                DB::statement("REVOKE DELETE, TRUNCATE ON public.{$table} FROM " . $role);
                DB::statement("GRANT USAGE, SELECT ON SEQUENCE public.{$table}_id_seq TO " . $role);
            }
        }

        DB::statement(
            "COMMENT ON TABLE companies_opco IS 'O14 — IDCC et OPCO d''une entreprise (table SIRET → OPCO de France compétences, ou saisie). "
            . 'Rien ne la supprime : FK en RESTRICT, rôle applicatif sans DELETE ni TRUNCATE. ATTENTION : relancer le GRANT ON ALL TABLES '
            . "de 2026_08_14_000001_harden_workspace_isolation les rétablirait — les retirer à nouveau (REVOKE DELETE, TRUNCATE).'",
        );
        DB::statement(
            "COMMENT ON COLUMN companies_opco.source IS 'siro = table SIRET → OPCO de France compétences (crm:enrichir-opco) ; saisie = posée par une personne, JAMAIS écrasée par la commande.'",
        );
        DB::statement(
            "COMMENT ON COLUMN companies_opco.releve_le IS 'Premier jour du mois de la DSN dont la table SIRO est issue.'",
        );
        DB::statement(
            "COMMENT ON TABLE companies_opco_passages IS 'O14 — journal des passages de crm:enrichir-opco (ressource, mois de DSN, curseur de reprise, bilan). Jamais purgé.'",
        );
    }

    public function down(): void
    {
        // Décomptes HORS RLS (patron de `provenance_tiers`) : sous FORCE RLS,
        // un rôle sans BYPASSRLS compterait 0 et laisserait tout jeter ; avec
        // `row_security = off`, Postgres LÈVE pour un tel rôle.
        DB::statement('SET LOCAL row_security = off');

        foreach (['companies_opco', 'companies_opco_passages'] as $table) {
            $existe = DB::selectOne("SELECT to_regclass('public.{$table}') IS NOT NULL AS existe");
            if ($existe !== null && (bool) $existe->existe) {
                $n = (int) (DB::selectOne("SELECT count(*) AS n FROM {$table}")->n ?? 0);
                if ($n > 0) {
                    // Rien n'est jamais supprimé en masse.
                    throw new RuntimeException(
                        "Retour arrière refusé : {$n} ligne(s) dans {$table}. Les exporter et décider à la main d'abord.",
                    );
                }
            }
        }

        DB::statement('DROP TABLE IF EXISTS companies_opco_passages');
        DB::statement('DROP TABLE IF EXISTS companies_opco');
        DB::statement('DROP FUNCTION IF EXISTS public.companies_opco_meme_espace()');
    }
};
