<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * PROVENANCE DES INFORMATIONS VENUES DE TIERS (N12) ET DERNIER ÉCHANGE À
 * L'INITIATIVE DE LA PERSONNE (N14 réduit) — 03/10/2026.
 *
 * Préparation du futur canal Axion Partners : RIEN n'est branché, aucune
 * ligne n'est écrite. Migration ADDITIVE seulement (une table, deux
 * colonnes nullables, deux déclencheurs) : rien n'est réécrit, rien n'est
 * supprimé. (Numéro 000080 : 000070 appartient à #311.)
 *
 * ── `contacts_provenances_tiers` ───────────────────────────────────────────
 *
 * D'où vient une personne apportée par un tiers : son `origine` — vocabulaire
 * EXACT du contrat Partners (`Taxonomy::FIELD_ORIGINS_TIERS` : `apporteur`,
 * `commercial`, `societe`) —, la `reference_externe` OPAQUE que le tiers lui
 * donne (jamais une coordonnée : on ne la lit pas, on la recopie), et
 * `information_tiers_version`, la version du texte d'information que la
 * personne a reçu du tiers (art. 14 RGPD), stockée TELLE QUE REÇUE — le
 * contrat Partners l'écrit en texte (`information-article-14/v5`, 32
 * caractères au plus). Le numéro en est déduit par UNE règle
 * (`ProvenanceTiers::numeroVersion`) : tout autre format, ou une version
 * inconnue, ou < 5, exclut la personne de toute campagne
 * (`EligibiliteAdresse`, motif `information_tiers_insuffisante`).
 *
 * Aucune date d'acte : le contrat Partners n'en fait JAMAIS sortir (« le CRM
 * date la réception ») — `recu_le` suffit.
 *
 * `derniere_sequence` : la `sequence` de l'enveloppe Partners qui a écrit la
 * ligne. Un message plus ancien, livré en retard ou rejoué, n'écrase JAMAIS
 * une provenance plus récente (déclencheur `provenances_tiers_sequence` : la
 * mise à jour est ignorée). Une information complémentaire v4 → v5 ne peut
 * donc pas revenir à v4.
 *
 * VIDE à la livraison. Lecture réservée au rôle owner
 * (`ProvenancesTiersController`) ; aucune autre route ne la lit.
 *
 * Les personnes apportées sont ACQUISES par Axion-IA : aucune échéance,
 * aucune mise à l'écart, aucun archivage à 3 ans (décision de Will).
 *
 * RÈGLE ABSOLUE du propriétaire (Williams, 03/10/2026) : on GARDE TOUT, on
 * n'efface JAMAIS rien automatiquement ; une demande d'effacement = mise à
 * l'écart + décision humaine au cas par cas. Donc :
 *  - `contact_id` et `workspace_id` en `ON DELETE RESTRICT` : supprimer une
 *    personne (ou un espace) qui porte une provenance est REFUSÉ par la base
 *    — la preuve de l'information art. 14 ne part jamais par cascade ;
 *  - le rôle applicatif n'a ni DELETE ni TRUNCATE sur la table (REVOKE,
 *    patron de `partners_idempotence`). ⚠️ Relancer le `GRANT … ON ALL
 *    TABLES` de `2026_08_14_000001` les rétablirait : les retirer à nouveau.
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
                workspace_id               UUID NOT NULL REFERENCES workspaces(id) ON DELETE RESTRICT,
                contact_id                 BIGINT NOT NULL REFERENCES contacts(id) ON DELETE RESTRICT,
                origine                    TEXT NOT NULL,
                reference_externe          TEXT NOT NULL CHECK (btrim(reference_externe) <> '' AND length(reference_externe) <= 200),
                information_tiers_version  TEXT CHECK (information_tiers_version IS NULL OR (btrim(information_tiers_version) <> '' AND length(information_tiers_version) <= 32)),
                derniere_sequence          BIGINT CHECK (derniere_sequence IS NULL OR derniere_sequence >= 0),
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

        // Un message Partners plus ancien (ou rejoué) n'écrase jamais une
        // provenance plus récente : la mise à jour est IGNORÉE (pas d'erreur —
        // une livraison dans le désordre est normale et ne doit pas boucler).
        DB::unprepared(
            <<<'SQL'
            CREATE OR REPLACE FUNCTION public.provenances_tiers_sequence() RETURNS trigger
            LANGUAGE plpgsql
            SET search_path = public, pg_catalog
            AS $$
            BEGIN
                IF OLD.derniere_sequence IS NOT NULL
                   AND (NEW.derniere_sequence IS NULL OR NEW.derniere_sequence <= OLD.derniere_sequence) THEN
                    RETURN NULL;
                END IF;
                RETURN NEW;
            END;
            $$;

            DROP TRIGGER IF EXISTS provenances_tiers_sequence ON contacts_provenances_tiers;
            CREATE TRIGGER provenances_tiers_sequence
                BEFORE UPDATE ON contacts_provenances_tiers
                FOR EACH ROW EXECUTE FUNCTION public.provenances_tiers_sequence();
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
            "COMMENT ON COLUMN contacts_provenances_tiers.information_tiers_version IS 'Version du texte d''information (art. 14) reçu du tiers, telle que reçue (ex. information-article-14/v5). Inconnue, d''un autre format ou < 5 : la personne ne part dans aucune campagne.'",
        );

        // Rien ne supprime une provenance : ni DELETE ni TRUNCATE pour le rôle
        // applicatif (les privilèges par défaut du 14/08 les lui donneraient).
        $roleApplicatif = (string) config('database.connections.pgsql_app.username', 'axion_app');
        if ($roleApplicatif !== '' && DB::selectOne('SELECT 1 AS e FROM pg_roles WHERE rolname = ?', [$roleApplicatif]) !== null) {
            $role = '"' . str_replace('"', '""', $roleApplicatif) . '"';
            DB::statement('GRANT SELECT, INSERT, UPDATE ON public.contacts_provenances_tiers TO ' . $role);
            DB::statement('REVOKE DELETE, TRUNCATE ON public.contacts_provenances_tiers FROM ' . $role);
            DB::statement('GRANT USAGE, SELECT ON SEQUENCE public.contacts_provenances_tiers_id_seq TO ' . $role);
        }
        DB::statement(
            "COMMENT ON TABLE contacts_provenances_tiers IS 'N12 — provenance d''une personne apportée par un tiers (contrat Axion Partners). Lecture réservée au rôle owner. Vide tant que le canal n''est pas branché. "
            . 'Rien ne la supprime : FK en RESTRICT, rôle applicatif sans DELETE ni TRUNCATE. ATTENTION : relancer le GRANT ON ALL TABLES '
            . "de 2026_08_14_000001_harden_workspace_isolation les rétablirait — les retirer à nouveau (REVOKE DELETE, TRUNCATE).'",
        );

        // ── opt_out.phone_hash ─────────────────────────────────────────────
        // Colonne seulement (catalogue). Son index est construit à part,
        // CONCURRENTLY : `2026_10_03_000081_opt_out_index_phone_hash` — un
        // CREATE INDEX ici bloquerait l'enregistrement des oppositions.
        DB::statement('ALTER TABLE opt_out ADD COLUMN IF NOT EXISTS phone_hash TEXT');
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
                FOR EACH ROW
                WHEN (NEW.kind IN ({$kinds}))
                EXECUTE FUNCTION public.activites_echange_initiative();
            SQL,
        );
    }

    public function down(): void
    {
        // Les décomptes ci-dessous sont lus HORS RLS : sous FORCE RLS, un rôle
        // de migration sans BYPASSRLS compterait 0 sans rien dire, et le
        // garde-fou laisserait tout jeter. Avec `row_security = off`, Postgres
        // LÈVE pour un tel rôle au lieu de répondre 0 : le retour arrière
        // échoue, rien n'est perdu.
        DB::statement('SET LOCAL row_security = off');

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
        $echanges = (int) (DB::selectOne(
            "SELECT CASE WHEN EXISTS (SELECT 1 FROM information_schema.columns WHERE table_name = 'contacts' AND column_name = 'dernier_echange_initiative_at')
                    THEN (SELECT count(*) FROM contacts WHERE dernier_echange_initiative_at IS NOT NULL) ELSE 0 END AS n",
        )->n ?? 0);
        if ($echanges > 0) {
            // Aucune commande ne la reconstituerait : on ne la jette pas.
            throw new RuntimeException(
                "Retour arrière refusé : {$echanges} personne(s) portent une date dans contacts.dernier_echange_initiative_at.",
            );
        }

        DB::statement('DROP TRIGGER IF EXISTS activites_echange_initiative ON activities');
        DB::statement('DROP FUNCTION IF EXISTS public.activites_echange_initiative()');
        DB::statement('ALTER TABLE contacts DROP COLUMN IF EXISTS dernier_echange_initiative_at');
        DB::statement('ALTER TABLE opt_out DROP COLUMN IF EXISTS phone_hash');
        DB::statement('DROP TABLE IF EXISTS contacts_provenances_tiers');
        DB::statement('DROP FUNCTION IF EXISTS public.provenances_tiers_sequence()');
        DB::statement('DROP FUNCTION IF EXISTS public.provenances_tiers_meme_espace()');
    }
};
