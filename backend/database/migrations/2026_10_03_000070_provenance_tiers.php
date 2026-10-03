<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * PROVENANCE DES INFORMATIONS VENUES DE TIERS (N12) ET DERNIER ÉCHANGE À
 * L'INITIATIVE DE LA PERSONNE (N14 réduit) — 03/10/2026.
 *
 * Préparation du futur canal Axion Partners : RIEN n'est branché, aucune
 * ligne n'est écrite. Migration ADDITIVE seulement (une table, trois
 * colonnes nullables, un déclencheur) : rien n'est réécrit, rien n'est
 * supprimé.
 *
 * ── `contacts_provenances_tiers` ───────────────────────────────────────────
 *
 * D'où vient une personne apportée par un tiers : son `origine` — vocabulaire
 * EXACT du contrat Partners (`Taxonomy::FIELD_ORIGINS_TIERS` : `apporteur`,
 * `commercial`, `societe`) —, la `reference_externe` OPAQUE que le tiers lui
 * donne (jamais une coordonnée : on ne la lit pas, on la recopie), et
 * `information_tiers_version`, la version du texte d'information que la
 * personne a reçu du tiers (art. 14 RGPD). Une version inconnue ou < 5
 * l'exclut de toute campagne (`EligibiliteAdresse`, motif
 * `information_tiers_insuffisante`).
 *
 * VIDE à la livraison. Lecture réservée au rôle owner
 * (`ProvenancesTiersController`) ; aucune autre route ne la lit.
 *
 * Les personnes apportées sont ACQUISES par Axion-IA : aucune échéance,
 * aucune mise à l'écart, aucun archivage à 3 ans (décision de Will). Une
 * provenance ne s'efface que si la personne elle-même l'est (art. 17,
 * `contact_id` en CASCADE : un effacement légal n'est jamais bloqué).
 *
 * ── `opt_out.phone_hash` ───────────────────────────────────────────────────
 *
 * Empreinte HMAC-SHA256 À CLÉ du téléphone normalisé
 * (`App\Crm\ProvenanceTiers\EmpreinteTelephone`). Pas un SHA nu : l'espace
 * des numéros de téléphone français tient en quelques centaines de millions
 * de valeurs, un SHA nu s'inverse par force brute en minutes. La clé vit dans
 * l'environnement (`CRM_OPT_OUT_PHONE_HMAC_KEY`), jamais dans le dépôt.
 * Colonne vide à la livraison : la colonne `phone` existante n'est pas
 * touchée.
 *
 * ── `contacts.dernier_echange_initiative_at` (N14 réduit) ──────────────────
 *
 * Le dernier échange à l'initiative de la personne (formulaire, demande du
 * guide, rendez-vous, réponse). Alimentée par un déclencheur sur
 * `activities`, là où ces événements sont DÉJÀ consignés, quelle que soit la
 * route qui les écrit. La date n'avance que vers le plus récent ; elle ne
 * recule ni ne s'efface jamais. Pas de rattrapage de l'historique ici : un
 * `UPDATE` de 1,3 M de fiches n'a rien à faire dans une migration.
 *
 * RLS : ENABLE + FORCE, politique stricte (pas de repli permissif) — patron
 * de `2026_10_01_000040_listes_manuelles`. Les droits du rôle `axion_app`
 * viennent des privilèges par défaut posés par
 * `2026_08_14_000001_harden_workspace_isolation`.
 */
return new class extends Migration
{
    /** Recopie de `Taxonomy::ACTIVITY_KINDS_INITIATIVE_PERSONNE` (figée ici). */
    private const KINDS_INITIATIVE = ['form_submission', 'lead_magnet_requested', 'calendly_booked', 'press_reply'];

    public function up(): void
    {
        DB::statement("SET LOCAL lock_timeout = '30s'");

        DB::statement(
            <<<'SQL'
            CREATE TABLE IF NOT EXISTS contacts_provenances_tiers (
                id                         BIGSERIAL PRIMARY KEY,
                workspace_id               UUID NOT NULL REFERENCES workspaces(id) ON DELETE CASCADE,
                contact_id                 BIGINT NOT NULL REFERENCES contacts(id) ON DELETE CASCADE,
                origine                    TEXT NOT NULL,
                reference_externe          TEXT NOT NULL CHECK (btrim(reference_externe) <> '' AND length(reference_externe) <= 200),
                information_tiers_version  INTEGER CHECK (information_tiers_version IS NULL OR information_tiers_version >= 1),
                information_tiers_at       TIMESTAMPTZ,
                recu_le                    TIMESTAMPTZ NOT NULL DEFAULT now(),
                created_at                 TIMESTAMPTZ NOT NULL DEFAULT now(),
                updated_at                 TIMESTAMPTZ NOT NULL DEFAULT now(),
                CONSTRAINT contacts_provenances_tiers_origine_check CHECK (origine IN ('apporteur', 'commercial', 'societe'))
            )
            SQL,
        );
        // Une même référence d'un même tiers ne désigne qu'une provenance.
        DB::statement(
            'CREATE UNIQUE INDEX IF NOT EXISTS uq_contacts_provenances_tiers_ref
             ON contacts_provenances_tiers (workspace_id, origine, reference_externe)',
        );
        // Lu par contact (motif d'exclusion, lecture owner).
        DB::statement(
            'CREATE INDEX IF NOT EXISTS idx_contacts_provenances_tiers_contact
             ON contacts_provenances_tiers (contact_id)',
        );

        // La provenance désigne une personne du MÊME espace. La RLS garantit
        // l'espace de la LIGNE ; ce déclencheur, celui de la personne qu'elle
        // désigne (lecture sous l'identité de l'appelant, pas de SECURITY
        // DEFINER) — patron de `listes_membres_meme_espace`.
        DB::unprepared(
            <<<'SQL'
            CREATE OR REPLACE FUNCTION public.provenances_tiers_meme_espace() RETURNS trigger
            LANGUAGE plpgsql
            SET search_path = public, pg_catalog
            AS $$
            BEGIN
                IF NOT EXISTS (SELECT 1 FROM contacts ct WHERE ct.id = NEW.contact_id AND ct.workspace_id = NEW.workspace_id) THEN
                    RAISE EXCEPTION 'contacts_provenances_tiers : la personne % n est pas de cet espace', NEW.contact_id
                        USING ERRCODE = '23514';
                END IF;
                RETURN NEW;
            END;
            $$;

            DROP TRIGGER IF EXISTS provenances_tiers_meme_espace ON contacts_provenances_tiers;
            CREATE TRIGGER provenances_tiers_meme_espace
                BEFORE INSERT OR UPDATE OF workspace_id, contact_id ON contacts_provenances_tiers
                FOR EACH ROW EXECUTE FUNCTION public.provenances_tiers_meme_espace();
            SQL,
        );

        DB::statement('ALTER TABLE contacts_provenances_tiers ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE contacts_provenances_tiers FORCE ROW LEVEL SECURITY');
        DB::statement('DROP POLICY IF EXISTS contacts_provenances_tiers_workspace_isolation ON contacts_provenances_tiers');
        DB::statement(
            "CREATE POLICY contacts_provenances_tiers_workspace_isolation ON contacts_provenances_tiers FOR ALL
             USING (workspace_id::TEXT = NULLIF(current_setting('app.current_workspace_id', true), ''))
             WITH CHECK (workspace_id::TEXT = NULLIF(current_setting('app.current_workspace_id', true), ''))",
        );
        DB::statement(
            "COMMENT ON TABLE contacts_provenances_tiers IS 'N12 — provenance d''une personne apportée par un tiers (contrat Axion Partners). Lecture réservée au rôle owner. Vide tant que le canal n''est pas branché.'",
        );
        DB::statement(
            "COMMENT ON COLUMN contacts_provenances_tiers.information_tiers_version IS 'Version du texte d''information (art. 14) reçu du tiers. Inconnue ou < 5 : la personne ne part dans aucune campagne.'",
        );

        // ── opt_out.phone_hash ─────────────────────────────────────────────
        DB::statement('ALTER TABLE opt_out ADD COLUMN IF NOT EXISTS phone_hash TEXT');
        DB::statement(
            'CREATE INDEX IF NOT EXISTS idx_opt_out_scope_phone_hash ON opt_out (scope, phone_hash) WHERE phone_hash IS NOT NULL',
        );
        DB::statement(
            "COMMENT ON COLUMN opt_out.phone_hash IS 'HMAC-SHA256 À CLÉ du téléphone normalisé (clé : CRM_OPT_OUT_PHONE_HMAC_KEY, jamais dans le dépôt). Jamais un SHA nu.'",
        );

        // ── contacts.dernier_echange_initiative_at (N14 réduit) ────────────
        // Nullable, sans défaut : ajout instantané (catalogue seulement).
        DB::statement('ALTER TABLE contacts ADD COLUMN IF NOT EXISTS dernier_echange_initiative_at TIMESTAMPTZ');
        DB::statement(
            "COMMENT ON COLUMN contacts.dernier_echange_initiative_at IS 'Dernier échange à l''initiative de la personne (formulaire, guide, rendez-vous, réponse), posé par le déclencheur activites_echange_initiative. N''avance que vers le plus récent.'",
        );

        $kinds = implode(', ', array_map(static fn (string $k): string => "'{$k}'", self::KINDS_INITIATIVE));
        DB::unprepared(
            <<<SQL
            CREATE OR REPLACE FUNCTION public.activites_echange_initiative() RETURNS trigger
            LANGUAGE plpgsql
            SET search_path = public, pg_catalog
            AS \$\$
            DECLARE
                quand TIMESTAMPTZ := COALESCE(NEW.occurred_at, NEW.created_at);
            BEGIN
                IF NEW.contact_id IS NULL OR quand IS NULL OR NEW.kind NOT IN ({$kinds}) THEN
                    RETURN NULL;
                END IF;
                UPDATE contacts
                   SET dernier_echange_initiative_at = quand
                 WHERE id = NEW.contact_id
                   AND workspace_id = NEW.workspace_id
                   AND (dernier_echange_initiative_at IS NULL OR dernier_echange_initiative_at < quand);
                RETURN NULL;
            END;
            \$\$;

            DROP TRIGGER IF EXISTS activites_echange_initiative ON activities;
            CREATE TRIGGER activites_echange_initiative
                AFTER INSERT OR UPDATE OF contact_id, kind, occurred_at ON activities
                FOR EACH ROW EXECUTE FUNCTION public.activites_echange_initiative();
            SQL,
        );
    }

    public function down(): void
    {
        $existe = DB::selectOne("SELECT to_regclass('public.contacts_provenances_tiers') IS NOT NULL AS existe");
        if ($existe !== null && (bool) $existe->existe) {
            $n = (int) (DB::selectOne('SELECT count(*) AS n FROM contacts_provenances_tiers')->n ?? 0);
            if ($n > 0) {
                // Rien n'est jamais supprimé en masse : on refuse de jeter des
                // provenances reçues d'un tiers par un retour arrière.
                throw new RuntimeException(
                    "Retour arrière refusé : {$n} provenance(s) tiers existent. Les exporter et décider à la main d'abord.",
                );
            }
        }
        $empreintes = (int) (DB::selectOne(
            "SELECT CASE WHEN EXISTS (SELECT 1 FROM information_schema.columns WHERE table_name = 'opt_out' AND column_name = 'phone_hash')
                    THEN (SELECT count(*) FROM opt_out WHERE phone_hash IS NOT NULL) ELSE 0 END AS n",
        )->n ?? 0);
        if ($empreintes > 0) {
            // Une opposition est une volonté exprimée : jamais jetée.
            throw new RuntimeException("Retour arrière refusé : {$empreintes} opposition(s) portent une empreinte de téléphone.");
        }

        DB::statement('DROP TRIGGER IF EXISTS activites_echange_initiative ON activities');
        DB::statement('DROP FUNCTION IF EXISTS public.activites_echange_initiative()');
        DB::statement('ALTER TABLE contacts DROP COLUMN IF EXISTS dernier_echange_initiative_at');
        DB::statement('DROP INDEX IF EXISTS idx_opt_out_scope_phone_hash');
        DB::statement('ALTER TABLE opt_out DROP COLUMN IF EXISTS phone_hash');
        DB::statement('DROP TABLE IF EXISTS contacts_provenances_tiers');
        DB::statement('DROP FUNCTION IF EXISTS public.provenances_tiers_meme_espace()');
    }
};
