<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Envoi X1-L1 — le lien campagne -> séquence devient réel.
 * 1. Les `sequence_id` ORPHELINS (sans séquence du même espace) font ÉCHOUER la migration avec leur nombre : elle ne remet JAMAIS la colonne à NULL.
 * 2. Clé étrangère (workspace_id, sequence_id) -> envoi_sequences (workspace_id, id), sans action de suppression (NO ACTION), NOT VALID puis VALIDATE.
 * 3. `envoi_campagnes_garde()` (tous les rôles, propriétaire compris) GARDE toutes les règles de R08 et AJOUTE :
 *    a. sequence_id ne change qu'ainsi : NULL -> valeur, campagne « programmee » avant ET après, séquence de CETTE campagne et de cet espace
 *       (lue FOR KEY SHARE), dont le nombre d'étapes égale nb_etapes ; ni retrait, ni second lien ;
 *    b. campagne reliée : cible, boîtes, nb_etapes et saisie figés ; « programmee -> a_valider » refusé (corriger = arrêter puis dupliquer).
 * 4. Pause, fin ou arrêt d'une campagne reliée : geste « pause » sur sa séquence (auteur « campagne:<statut> », horloge réelle).
 * 5. Un geste « reprise » sur une séquence née d'une campagne n'est accepté que si la campagne est « en_cours » ET sa matérialisation « terminee ».
 * 6. Droit : UPDATE (sequence_id) pour le rôle applicatif ; jamais INSERT (sequence_id).
 * `down()` inerte (il ne remet pas l'ancienne garde). `up()` rejouable.
 */
return new class extends Migration
{
    public function up(): void
    {
        $orphelins = (int) DB::selectOne(
            'SELECT count(*) AS n FROM envoi_campagnes c WHERE c.sequence_id IS NOT NULL
             AND NOT EXISTS (SELECT 1 FROM envoi_sequences s WHERE s.workspace_id = c.workspace_id AND s.id = c.sequence_id)',
        )->n;
        if ($orphelins > 0) {
            throw new RuntimeException("envoi_campagnes : {$orphelins} campagne(s) portent un sequence_id orphelin (aucune séquence du même espace) ; la migration s'arrête, rien n'est modifié. À traiter à la main avant de relancer.");
        }

        DB::unprepared(<<<'SQL'
            DO $d$
            BEGIN
                IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'envoi_campagnes_sequence_fk' AND conrelid = 'public.envoi_campagnes'::regclass) THEN
                    ALTER TABLE envoi_campagnes ADD CONSTRAINT envoi_campagnes_sequence_fk
                        FOREIGN KEY (workspace_id, sequence_id) REFERENCES envoi_sequences (workspace_id, id) NOT VALID;
                END IF;
            END $d$;
            ALTER TABLE envoi_campagnes VALIDATE CONSTRAINT envoi_campagnes_sequence_fk;

            CREATE OR REPLACE FUNCTION envoi_campagnes_garde() RETURNS trigger AS $f$
            DECLARE
                trouvee UUID;
                nb INTEGER;
            BEGIN
                IF NEW.id <> OLD.id OR NEW.workspace_id <> OLD.workspace_id OR NEW.cree_par <> OLD.cree_par OR NEW.cree_le <> OLD.cree_le THEN
                    RAISE EXCEPTION 'envoi_campagnes : id, espace, créateur et date de création sont figés';
                END IF;
                IF NEW.version <> OLD.version + 1 THEN
                    RAISE EXCEPTION 'envoi_campagnes : version périmée ou saut de version (attendu %)', OLD.version + 1;
                END IF;
                IF NEW.statut <> OLD.statut AND NOT envoi_campagnes_transition_permise(OLD.statut, NEW.statut) THEN
                    RAISE EXCEPTION 'envoi_campagnes : transition % -> % interdite', OLD.statut, NEW.statut;
                END IF;
                IF NEW.sequence_id IS DISTINCT FROM OLD.sequence_id THEN
                    IF OLD.sequence_id IS NOT NULL OR NEW.sequence_id IS NULL THEN
                        RAISE EXCEPTION 'envoi_campagnes : le lien vers la séquence ne se pose qu''une fois (ni retrait, ni second lien)';
                    END IF;
                    IF OLD.statut <> 'programmee' OR NEW.statut <> 'programmee' THEN
                        RAISE EXCEPTION 'envoi_campagnes : le lien vers la séquence ne se pose que sur une campagne programmée';
                    END IF;
                    SELECT s.id INTO trouvee FROM envoi_sequences s
                        WHERE s.id = NEW.sequence_id AND s.workspace_id = NEW.workspace_id AND s.campagne_id = NEW.id FOR KEY SHARE;
                    IF NOT FOUND THEN
                        RAISE EXCEPTION 'envoi_campagnes : la séquence n''existe pas, ou n''est pas celle de cette campagne dans cet espace';
                    END IF;
                    SELECT count(*) INTO nb FROM envoi_sequence_etapes e WHERE e.sequence_id = NEW.sequence_id AND e.workspace_id = NEW.workspace_id;
                    IF nb <> NEW.nb_etapes THEN
                        RAISE EXCEPTION 'envoi_campagnes : la séquence a % étape(s), la campagne en annonce %', nb, NEW.nb_etapes;
                    END IF;
                END IF;
                IF OLD.sequence_id IS NOT NULL THEN
                    IF NEW.cible IS DISTINCT FROM OLD.cible OR NEW.boites IS DISTINCT FROM OLD.boites
                       OR NEW.nb_etapes <> OLD.nb_etapes OR NEW.saisie IS DISTINCT FROM OLD.saisie THEN
                        RAISE EXCEPTION 'envoi_campagnes : cible, boîtes, nombre d''étapes et saisie sont figés une fois la campagne reliée';
                    END IF;
                    IF OLD.statut = 'programmee' AND NEW.statut = 'a_valider' THEN
                        RAISE EXCEPTION 'envoi_campagnes : une campagne reliée ne revient pas à valider (l''arrêter puis la dupliquer)';
                    END IF;
                END IF;
                RETURN NEW;
            END $f$ LANGUAGE plpgsql SET search_path = pg_catalog, public;

            CREATE OR REPLACE FUNCTION envoi_campagnes_pause_sequence() RETURNS trigger AS $f$
            BEGIN
                IF NEW.sequence_id IS NOT NULL AND NEW.statut IS DISTINCT FROM OLD.statut AND NEW.statut IN ('en_pause', 'terminee', 'arretee') THEN
                    INSERT INTO envoi_sequences_gestes (workspace_id, sequence_id, geste, auteur, cree_le)
                    VALUES (NEW.workspace_id, NEW.sequence_id, 'pause', 'campagne:' || NEW.statut, clock_timestamp());
                END IF;
                RETURN NULL;
            END $f$ LANGUAGE plpgsql SET search_path = pg_catalog, public;
            DROP TRIGGER IF EXISTS envoi_campagnes_pause_sequence ON envoi_campagnes;
            CREATE TRIGGER envoi_campagnes_pause_sequence AFTER UPDATE ON envoi_campagnes
                FOR EACH ROW EXECUTE FUNCTION envoi_campagnes_pause_sequence();

            CREATE OR REPLACE FUNCTION envoi_sequences_gestes_reprise_campagne() RETURNS trigger AS $f$
            DECLARE
                campagne UUID;
                statut_campagne TEXT;
                etat_mat TEXT;
            BEGIN
                IF NEW.geste <> 'reprise' THEN
                    RETURN NEW;
                END IF;
                SELECT s.campagne_id INTO campagne FROM envoi_sequences s WHERE s.id = NEW.sequence_id AND s.workspace_id = NEW.workspace_id;
                IF campagne IS NULL THEN
                    RETURN NEW;
                END IF;
                SELECT c.statut INTO statut_campagne FROM envoi_campagnes c WHERE c.id = campagne AND c.workspace_id = NEW.workspace_id FOR SHARE;
                SELECT m.etat INTO etat_mat FROM envoi_campagnes_materialisations m WHERE m.campagne_id = campagne AND m.workspace_id = NEW.workspace_id;
                IF statut_campagne IS DISTINCT FROM 'en_cours' OR etat_mat IS DISTINCT FROM 'terminee' THEN
                    RAISE EXCEPTION 'envoi_sequences_gestes : reprise refusée (campagne en_cours et matérialisation terminée exigées)';
                END IF;
                RETURN NEW;
            END $f$ LANGUAGE plpgsql SET search_path = pg_catalog, public;
            DROP TRIGGER IF EXISTS envoi_sequences_gestes_reprise_campagne ON envoi_sequences_gestes;
            CREATE TRIGGER envoi_sequences_gestes_reprise_campagne BEFORE INSERT ON envoi_sequences_gestes
                FOR EACH ROW EXECUTE FUNCTION envoi_sequences_gestes_reprise_campagne();
        SQL);

        $roleApplicatif = (string) config('database.connections.pgsql_app.username', 'axion_app');
        if ($roleApplicatif !== '' && DB::selectOne('SELECT 1 AS e FROM pg_roles WHERE rolname = ?', [$roleApplicatif]) !== null) {
            // Volontairement SANS INSERT (sequence_id) : une campagne naît sans séquence (déclencheur d'insertion de R08).
            DB::statement('GRANT UPDATE (sequence_id) ON public.envoi_campagnes TO "' . str_replace('"', '""', $roleApplicatif) . '"');
        }
    }

    /** Retour arrière INERTE : il ne remet ni l'ancienne garde, ni l'absence de clé étrangère (ce serait rouvrir un lien sans contrôle). */
    public function down(): void {}
};
