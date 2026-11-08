<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Envoi P0 L7 (audit sécurité) — l'état de frein ne se modifie plus SANS TRACE. AJOUTS SEULS (triggers) sur les
 * tables que L7 a créées ; aucune donnée n'est ni supprimée ni réécrite (aucun DROP / DELETE de données) :
 *  - `envoi_pauses`  : une pause ne se LÈVE que par le chemin journalisé (ligne `pause_levee` du journal en ajout seul,
 *    écrite dans la MÊME transaction) ou par EXPIRATION réelle (levée = l'instant d'expiration, tracée dans le journal) ;
 *    l'identité, la durée et la date de déclenchement ne bougent plus ; une levée datée est définitive ;
 *  - `envoi_chauffe_jours` : compteurs en CROISSANCE SEULE (jamais baissés), identité figée ;
 *  - `envoi_sante_battement` : battement BORNÉ à l'horloge de référence (jamais dans le futur) et MONOTONE ;
 *  - `envoi_fournisseur_domaines` : un fournisseur résolu ne change qu'après expiration du cache (7 jours) ;
 *  - aucun DELETE ni TRUNCATE sur ces tables (même pour un superutilisateur : triggers).
 * Horloge de référence : le réglage local à la transaction `app.maintenant` (posé par l'appli depuis son horloge
 * injectable, donc figeable en test) sinon l'horloge de la base. Un UPDATE à la main sur la base ne le pose pas.
 * Les fonctions fixent leur `search_path`. down() retire triggers et fonctions seulement.
 */
return new class extends Migration
{
    private const TABLES = ['envoi_pauses', 'envoi_chauffe_jours', 'envoi_sante_battement', 'envoi_fournisseur_domaines'];

    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION envoi_l7_horloge() RETURNS timestamptz AS $f$
            BEGIN
                RETURN COALESCE(NULLIF(current_setting('app.maintenant', true), '')::timestamptz, clock_timestamp());
            END;
            $f$ LANGUAGE plpgsql STABLE SET search_path = pg_catalog, public, pg_temp;

            CREATE OR REPLACE FUNCTION envoi_l7_aucun_effacement() RETURNS trigger AS $f$
            BEGIN
                RAISE EXCEPTION '% : ajout seulement (DELETE/TRUNCATE refusés)', TG_TABLE_NAME;
            END;
            $f$ LANGUAGE plpgsql SET search_path = pg_catalog, public, pg_temp;

            CREATE OR REPLACE FUNCTION envoi_pauses_garde() RETURNS trigger AS $f$
            DECLARE
                ref timestamptz := envoi_l7_horloge();
            BEGIN
                IF NEW.workspace_id IS DISTINCT FROM OLD.workspace_id OR NEW.portee IS DISTINCT FROM OLD.portee
                   OR NEW.fournisseur IS DISTINCT FROM OLD.fournisseur OR NEW.motif IS DISTINCT FROM OLD.motif
                   OR NEW.declenchee_le IS DISTINCT FROM OLD.declenchee_le OR NEW.expire_le IS DISTINCT FROM OLD.expire_le THEN
                    RAISE EXCEPTION 'envoi_pauses : identité, durée et déclenchement d''une pause sont figés';
                END IF;
                IF OLD.levee_le IS NOT NULL THEN
                    IF NEW.levee_le IS DISTINCT FROM OLD.levee_le OR NEW.levee_par IS DISTINCT FROM OLD.levee_par THEN
                        RAISE EXCEPTION 'envoi_pauses : une levée datée est définitive';
                    END IF;
                    RETURN NEW;
                END IF;
                IF NEW.levee_le IS NULL THEN
                    IF NEW.levee_par IS NOT NULL THEN
                        RAISE EXCEPTION 'envoi_pauses : levee_par sans levee_le';
                    END IF;
                    RETURN NEW;
                END IF;
                -- Levée d'une pause active : expiration réelle, ou chemin journalisé (journal en ajout seul, même transaction).
                IF NEW.levee_par = 'expiration' THEN
                    IF OLD.expire_le IS NULL OR OLD.expire_le > ref OR NEW.levee_le IS DISTINCT FROM OLD.expire_le THEN
                        RAISE EXCEPTION 'envoi_pauses : expiration refusée (pause non expirée, ou levée différente de l''instant d''expiration)';
                    END IF;
                    INSERT INTO envoi_journal_commandes (workspace_id, commande, auteur, details)
                    VALUES (OLD.workspace_id, 'pause_expiree', 'trigger', json_build_object('pause', OLD.id, 'motif', OLD.motif)::text);
                    RETURN NEW;
                END IF;
                IF NEW.levee_par IS NULL OR NEW.levee_le > ref + interval '1 minute' THEN
                    RAISE EXCEPTION 'envoi_pauses : levée refusée (auteur absent ou date dans le futur)';
                END IF;
                IF NOT EXISTS (
                    SELECT 1 FROM envoi_journal_commandes j
                    WHERE j.workspace_id = OLD.workspace_id AND j.commande = 'pause_levee'
                      AND j.cree_le = now() AND j.details::jsonb ->> 'pause' = OLD.id::text
                ) THEN
                    RAISE EXCEPTION 'envoi_pauses : levée refusée hors chemin journalisé (envoi:lever-pause)';
                END IF;
                RETURN NEW;
            END;
            $f$ LANGUAGE plpgsql SET search_path = pg_catalog, public, pg_temp;

            CREATE OR REPLACE FUNCTION envoi_chauffe_jours_garde() RETURNS trigger AS $f$
            BEGIN
                IF NEW.workspace_id IS DISTINCT FROM OLD.workspace_id OR NEW.boite IS DISTINCT FROM OLD.boite
                   OR NEW.jour IS DISTINCT FROM OLD.jour OR NEW.fournisseur IS DISTINCT FROM OLD.fournisseur THEN
                    RAISE EXCEPTION 'envoi_chauffe_jours : identité figée';
                END IF;
                IF NEW.envoyes < OLD.envoyes OR NEW.rebonds_durs < OLD.rebonds_durs
                   OR NEW.plaintes < OLD.plaintes OR NEW.reponses < OLD.reponses THEN
                    RAISE EXCEPTION 'envoi_chauffe_jours : compteurs en croissance seule';
                END IF;
                RETURN NEW;
            END;
            $f$ LANGUAGE plpgsql SET search_path = pg_catalog, public, pg_temp;

            CREATE OR REPLACE FUNCTION envoi_sante_battement_garde() RETURNS trigger AS $f$
            DECLARE
                ref timestamptz := envoi_l7_horloge();
            BEGIN
                -- Borné à l'horloge (jamais dans le futur) et monotone ; un ancien battement déjà dans le futur est ramené à l'horloge.
                IF TG_OP = 'UPDATE' THEN
                    NEW.dernier_le := GREATEST(LEAST(NEW.dernier_le, ref), LEAST(OLD.dernier_le, ref));
                ELSE
                    NEW.dernier_le := LEAST(NEW.dernier_le, ref);
                END IF;
                RETURN NEW;
            END;
            $f$ LANGUAGE plpgsql SET search_path = pg_catalog, public, pg_temp;

            CREATE OR REPLACE FUNCTION envoi_fournisseur_domaines_garde() RETURNS trigger AS $f$
            BEGIN
                IF NEW.domaine IS DISTINCT FROM OLD.domaine THEN
                    RAISE EXCEPTION 'envoi_fournisseur_domaines : domaine figé';
                END IF;
                IF NEW.fournisseur IS DISTINCT FROM OLD.fournisseur AND OLD.resolu_le > envoi_l7_horloge() - interval '7 days' THEN
                    RAISE EXCEPTION 'envoi_fournisseur_domaines : le fournisseur résolu ne change qu''après expiration du cache (7 jours)';
                END IF;
                IF NEW.resolu_le < OLD.resolu_le THEN
                    NEW.resolu_le := OLD.resolu_le;
                END IF;
                RETURN NEW;
            END;
            $f$ LANGUAGE plpgsql SET search_path = pg_catalog, public, pg_temp;

            DROP TRIGGER IF EXISTS envoi_pauses_garde ON envoi_pauses;
            CREATE TRIGGER envoi_pauses_garde BEFORE UPDATE ON envoi_pauses FOR EACH ROW EXECUTE FUNCTION envoi_pauses_garde();
            DROP TRIGGER IF EXISTS envoi_chauffe_jours_garde ON envoi_chauffe_jours;
            CREATE TRIGGER envoi_chauffe_jours_garde BEFORE UPDATE ON envoi_chauffe_jours FOR EACH ROW EXECUTE FUNCTION envoi_chauffe_jours_garde();
            DROP TRIGGER IF EXISTS envoi_sante_battement_garde ON envoi_sante_battement;
            CREATE TRIGGER envoi_sante_battement_garde BEFORE INSERT OR UPDATE ON envoi_sante_battement FOR EACH ROW EXECUTE FUNCTION envoi_sante_battement_garde();
            DROP TRIGGER IF EXISTS envoi_fournisseur_domaines_garde ON envoi_fournisseur_domaines;
            CREATE TRIGGER envoi_fournisseur_domaines_garde BEFORE UPDATE ON envoi_fournisseur_domaines FOR EACH ROW EXECUTE FUNCTION envoi_fournisseur_domaines_garde();
        SQL);
        foreach (self::TABLES as $t) {
            DB::unprepared(<<<SQL
                DROP TRIGGER IF EXISTS {$t}_pas_de_delete ON {$t};
                CREATE TRIGGER {$t}_pas_de_delete BEFORE DELETE ON {$t} FOR EACH ROW EXECUTE FUNCTION envoi_l7_aucun_effacement();
                DROP TRIGGER IF EXISTS {$t}_pas_de_truncate ON {$t};
                CREATE TRIGGER {$t}_pas_de_truncate BEFORE TRUNCATE ON {$t} FOR EACH STATEMENT EXECUTE FUNCTION envoi_l7_aucun_effacement();
            SQL);
        }
    }

    /** Retire les triggers et les fonctions posés ici (aucune donnée touchée). */
    public function down(): void
    {
        foreach (self::TABLES as $t) {
            DB::unprepared("DROP TRIGGER IF EXISTS {$t}_pas_de_delete ON {$t}; DROP TRIGGER IF EXISTS {$t}_pas_de_truncate ON {$t}; DROP TRIGGER IF EXISTS {$t}_garde ON {$t}");
        }
        DB::unprepared(<<<'SQL'
            DROP FUNCTION IF EXISTS envoi_pauses_garde();
            DROP FUNCTION IF EXISTS envoi_chauffe_jours_garde();
            DROP FUNCTION IF EXISTS envoi_sante_battement_garde();
            DROP FUNCTION IF EXISTS envoi_fournisseur_domaines_garde();
            DROP FUNCTION IF EXISTS envoi_l7_aucun_effacement();
            DROP FUNCTION IF EXISTS envoi_l7_horloge();
        SQL);
    }
};
