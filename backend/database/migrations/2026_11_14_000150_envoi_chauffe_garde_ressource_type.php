<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Envoi K8 (durcissement) — AJOUT SEUL, rien n'est supprimé ni retiré.
 *  1. Le garde `envoi_chauffe_jours_garde()` fige AUSSI `ressource_type` : sans cela, un UPDATE sans trace faisait
 *     passer l'historique d'une IP en domaine (et le frein plaintes, qui lit le domaine, tombait).
 *  2. Un CHECK relie type et clé : `ip` <=> `boite` préfixée `ip:` (voir DepotChauffe::cleRessource). Une IP ne peut
 *     donc jamais s'écrire dans une ligne de boîte, ni l'inverse (la clé unique existante reste en place).
 * down() est inerte ; up() est rejouable.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION envoi_chauffe_jours_garde() RETURNS trigger AS $f$
            BEGIN
                IF NEW.workspace_id IS DISTINCT FROM OLD.workspace_id OR NEW.boite IS DISTINCT FROM OLD.boite
                   OR NEW.jour IS DISTINCT FROM OLD.jour OR NEW.fournisseur IS DISTINCT FROM OLD.fournisseur
                   OR NEW.ressource_type IS DISTINCT FROM OLD.ressource_type THEN
                    RAISE EXCEPTION 'envoi_chauffe_jours : identité figée';
                END IF;
                IF NEW.envoyes < OLD.envoyes OR NEW.rebonds_durs < OLD.rebonds_durs
                   OR NEW.plaintes < OLD.plaintes OR NEW.reponses < OLD.reponses THEN
                    RAISE EXCEPTION 'envoi_chauffe_jours : compteurs en croissance seule';
                END IF;
                RETURN NEW;
            END;
            $f$ LANGUAGE plpgsql SET search_path = pg_catalog, public, pg_temp;

            DO $$
            BEGIN
                IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'envoi_chauffe_jours_type_cle_coherents') THEN
                    ALTER TABLE envoi_chauffe_jours ADD CONSTRAINT envoi_chauffe_jours_type_cle_coherents
                        CHECK ((ressource_type = 'ip') = (boite LIKE 'ip:%'));
                END IF;
            END $$;
            SQL);
    }

    public function down(): void
    {
        // Inerte voulu : on ne relâche jamais un garde de compteurs d'envoi (ajout seul, aucun effacement).
    }
};
