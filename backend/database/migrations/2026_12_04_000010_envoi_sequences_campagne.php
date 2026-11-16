<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Envoi X1-L1 — une séquence naît d'UNE campagne validée (colonne `envoi_sequences.campagne_id`, NULL pour les séquences libres).
 * - clé étrangère composite (campagne_id, workspace_id) -> envoi_campagnes (id, workspace_id), NO ACTION, NOT VALID puis VALIDATE ;
 * - index unique partiel (workspace_id, campagne_id) : UNE séquence par campagne (clé de reprise) ;
 * - BEFORE INSERT : la campagne (lue FOR SHARE) doit être « programmee » et sans lien ; BEFORE UPDATE : campagne_id figé (il ne se pose
 *   qu'à l'insertion), fenêtres figées pour une séquence née d'une campagne ;
 * - AFTER INSERT : la séquence NAÎT EN PAUSE (geste « pause », auteur « campagne:naissance », horloge réelle clock_timestamp()) ;
 * - étapes figées dès que la séquence est reliée (une campagne y pointe par sequence_id ; verrou FOR NO KEY UPDATE sur la séquence).
 * Les gardes sont des déclencheurs : elles s'appliquent à tous les rôles qui écrivent, propriétaire et superutilisateur compris, tant qu'elles sont
 * actives. Le propriétaire de la table (ou un superutilisateur) PEUT les désactiver (ALTER TABLE ... DISABLE TRIGGER) : ce n'est pas une barrière
 * contre lui, seulement contre le rôle applicatif et les écritures ordinaires. Les fonctions fixent `search_path = pg_catalog, public, pg_temp`
 * (pg_temp EN DERNIER : sans lui, une table temporaire homonyme serait cherchée en premier). Droits : aucun changement.
 * ADDITIF SEULEMENT (aucun DROP de colonne ou de table, aucune ligne touchée). `down()` inerte ; `up()` rejouable.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE envoi_sequences ADD COLUMN IF NOT EXISTS campagne_id UUID NULL;
            DO $d$
            BEGIN
                IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'envoi_sequences_campagne_fk' AND conrelid = 'public.envoi_sequences'::regclass) THEN
                    ALTER TABLE envoi_sequences ADD CONSTRAINT envoi_sequences_campagne_fk
                        FOREIGN KEY (campagne_id, workspace_id) REFERENCES envoi_campagnes (id, workspace_id) NOT VALID;
                END IF;
            END $d$;
            ALTER TABLE envoi_sequences VALIDATE CONSTRAINT envoi_sequences_campagne_fk;
            CREATE UNIQUE INDEX IF NOT EXISTS envoi_sequences_campagne_unique ON envoi_sequences (workspace_id, campagne_id) WHERE campagne_id IS NOT NULL;

            CREATE OR REPLACE FUNCTION envoi_sequences_garde_campagne_insert() RETURNS trigger AS $f$
            DECLARE
                c RECORD;
            BEGIN
                IF NEW.campagne_id IS NULL THEN
                    RETURN NEW;
                END IF;
                SELECT statut, sequence_id INTO c FROM envoi_campagnes
                    WHERE id = NEW.campagne_id AND workspace_id = NEW.workspace_id FOR SHARE;
                IF NOT FOUND OR c.statut <> 'programmee' OR c.sequence_id IS NOT NULL THEN
                    RAISE EXCEPTION 'envoi_sequences : une séquence ne naît que d''une campagne programmée, de cet espace, sans séquence';
                END IF;
                RETURN NEW;
            END $f$ LANGUAGE plpgsql SET search_path = pg_catalog, public, pg_temp;
            DROP TRIGGER IF EXISTS envoi_sequences_garde_campagne_insert ON envoi_sequences;
            CREATE TRIGGER envoi_sequences_garde_campagne_insert BEFORE INSERT ON envoi_sequences
                FOR EACH ROW EXECUTE FUNCTION envoi_sequences_garde_campagne_insert();

            CREATE OR REPLACE FUNCTION envoi_sequences_garde_campagne() RETURNS trigger AS $f$
            BEGIN
                IF NEW.campagne_id IS DISTINCT FROM OLD.campagne_id THEN
                    RAISE EXCEPTION 'envoi_sequences : campagne_id est figé (il ne se pose qu''à l''insertion)';
                END IF;
                IF OLD.campagne_id IS NOT NULL AND (NEW.fenetre_debut <> OLD.fenetre_debut OR NEW.fenetre_fin <> OLD.fenetre_fin) THEN
                    RAISE EXCEPTION 'envoi_sequences : les fenêtres d''une séquence née d''une campagne sont figées';
                END IF;
                RETURN NEW;
            END $f$ LANGUAGE plpgsql SET search_path = pg_catalog, public, pg_temp;
            DROP TRIGGER IF EXISTS envoi_sequences_garde_campagne ON envoi_sequences;
            CREATE TRIGGER envoi_sequences_garde_campagne BEFORE UPDATE ON envoi_sequences
                FOR EACH ROW EXECUTE FUNCTION envoi_sequences_garde_campagne();

            CREATE OR REPLACE FUNCTION envoi_sequences_nee_en_pause() RETURNS trigger AS $f$
            BEGIN
                IF NEW.campagne_id IS NOT NULL THEN
                    -- clock_timestamp() et NON now() : now() est figé pour toute la transaction, le geste de pause d'une campagne arrêtée dans la
                    -- même transaction ne serait alors pas strictement postérieur à celui de la naissance (l'état d'une séquence est son DERNIER geste).
                    INSERT INTO envoi_sequences_gestes (workspace_id, sequence_id, geste, auteur, cree_le)
                    VALUES (NEW.workspace_id, NEW.id, 'pause', 'campagne:naissance', clock_timestamp());
                END IF;
                RETURN NULL;
            END $f$ LANGUAGE plpgsql SET search_path = pg_catalog, public, pg_temp;
            DROP TRIGGER IF EXISTS envoi_sequences_nee_en_pause ON envoi_sequences;
            CREATE TRIGGER envoi_sequences_nee_en_pause AFTER INSERT ON envoi_sequences
                FOR EACH ROW EXECUTE FUNCTION envoi_sequences_nee_en_pause();

            CREATE OR REPLACE FUNCTION envoi_sequence_etapes_figees() RETURNS trigger AS $f$
            DECLARE
                ligne RECORD;
            BEGIN
                FOR ligne IN
                    SELECT DISTINCT t.workspace_id AS ws, t.sequence_id AS seq
                    FROM (SELECT OLD.workspace_id, OLD.sequence_id WHERE TG_OP IN ('UPDATE', 'DELETE')
                          UNION ALL SELECT NEW.workspace_id, NEW.sequence_id WHERE TG_OP IN ('INSERT', 'UPDATE')) AS t (workspace_id, sequence_id)
                LOOP
                    -- Verrou de ligne sur la séquence : une liaison concurrente (FOR SHARE) et une écriture d'étape se sérialisent (FOR NO KEY UPDATE
                    -- ne gêne pas les contrôles de clé étrangère, au contraire de FOR UPDATE) ;
                    -- celui qui passe en second voit l'état validé du premier.
                    PERFORM 1 FROM envoi_sequences WHERE id = ligne.seq AND workspace_id = ligne.ws FOR NO KEY UPDATE;
                    IF EXISTS (SELECT 1 FROM envoi_campagnes c WHERE c.sequence_id = ligne.seq AND c.workspace_id = ligne.ws) THEN
                        RAISE EXCEPTION 'envoi_sequence_etapes : les étapes d''une séquence reliée à une campagne sont figées';
                    END IF;
                END LOOP;
                IF TG_OP = 'DELETE' THEN
                    RETURN OLD;
                END IF;
                RETURN NEW;
            END $f$ LANGUAGE plpgsql SET search_path = pg_catalog, public, pg_temp;
            DROP TRIGGER IF EXISTS envoi_sequence_etapes_figees ON envoi_sequence_etapes;
            CREATE TRIGGER envoi_sequence_etapes_figees BEFORE INSERT OR UPDATE OR DELETE ON envoi_sequence_etapes
                FOR EACH ROW EXECUTE FUNCTION envoi_sequence_etapes_figees();
        SQL);
    }

    /** Retour arrière INERTE : la colonne, l'index et les gardes sont conservés (les retirer rouvrirait des séquences orphelines). */
    public function down(): void {}
};
