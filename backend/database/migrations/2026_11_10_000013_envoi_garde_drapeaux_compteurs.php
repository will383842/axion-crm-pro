<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Envoi P0 L0 — correctifs de relecture sécurité. AJOUTS SEULS.
 *
 * 1. TRIGGER sur `envoi_drapeaux` : le CRM peut ALLUMER le mode témoins, jamais
 *    l'éteindre, et n'écrit pas `feu_vert_*`, sauf si la transaction pose
 *    `app.envoi_ecriture_gardee = 'oui'` (set_config(..., true), c.-à-d. SET LOCAL),
 *    ce que seule la commande `envoi:temoins-off` fait. Les privilèges par défaut
 *    donnent UPDATE au rôle applicatif : sans ce trigger, un futur code pourrait
 *    éteindre le mode d'un simple UPDATE. Limite assumée : le trigger arrête le
 *    code involontaire, pas un attaquant qui exécute du SQL arbitraire.
 * 2. `cle_idempotence` sur le journal : une réinjection (même clé) ne compte pas deux fois.
 * 3. `envoi_compteur_global` : plafond global (tous espaces) par jour, sans donnée de personne.
 *
 * down() est INERTE : aucune protection retirée, aucune donnée effacée.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION envoi_drapeaux_garde() RETURNS trigger AS $f$
            BEGIN
                IF COALESCE(current_setting('app.envoi_ecriture_gardee', true), '') = 'oui' THEN
                    RETURN NEW;
                END IF;
                IF TG_OP = 'INSERT' THEN
                    IF NEW.mode_temoins = false OR NEW.feu_vert_date IS NOT NULL OR NEW.feu_vert_signature IS NOT NULL THEN
                        RAISE EXCEPTION 'envoi_drapeaux : mode_temoins=false et feu_vert_* réservés à la commande locale';
                    END IF;
                ELSE
                    IF OLD.mode_temoins = true AND NEW.mode_temoins = false THEN
                        RAISE EXCEPTION 'envoi_drapeaux : le mode témoins ne s''éteint que par envoi:temoins-off';
                    END IF;
                    IF NEW.feu_vert_date IS DISTINCT FROM OLD.feu_vert_date
                       OR NEW.feu_vert_signature IS DISTINCT FROM OLD.feu_vert_signature THEN
                        RAISE EXCEPTION 'envoi_drapeaux : feu_vert_* réservé à la commande locale';
                    END IF;
                END IF;
                RETURN NEW;
            END;
            $f$ LANGUAGE plpgsql SET search_path = pg_catalog, public;
            DROP TRIGGER IF EXISTS envoi_drapeaux_garde ON envoi_drapeaux;
            CREATE TRIGGER envoi_drapeaux_garde
                BEFORE INSERT OR UPDATE ON envoi_drapeaux
                FOR EACH ROW EXECUTE FUNCTION envoi_drapeaux_garde();

            ALTER TABLE envoi_journal_commandes ADD COLUMN IF NOT EXISTS cle_idempotence TEXT NULL;
            CREATE UNIQUE INDEX IF NOT EXISTS envoi_journal_commandes_cle_idem
                ON envoi_journal_commandes (workspace_id, cle_idempotence)
                WHERE commande = 'injection' AND cle_idempotence IS NOT NULL;

            CREATE TABLE IF NOT EXISTS envoi_compteur_global (
                jour DATE PRIMARY KEY,
                n    INTEGER NOT NULL DEFAULT 0 CHECK (n >= 0)
            );
        SQL);

        $roleApplicatif = (string) config('database.connections.pgsql_app.username', 'axion_app');
        if ($roleApplicatif !== '' && DB::selectOne('SELECT 1 AS e FROM pg_roles WHERE rolname = ?', [$roleApplicatif]) !== null) {
            $role = '"' . str_replace('"', '""', $roleApplicatif) . '"';
            DB::statement('REVOKE DELETE, TRUNCATE ON public.envoi_compteur_global FROM ' . $role);
            DB::statement('GRANT SELECT, INSERT, UPDATE ON public.envoi_compteur_global TO ' . $role);
        }
    }

    /** Retour arrière INERTE : protections, colonne et compteur sont conservés. */
    public function down(): void
    {
        // Volontairement vide (aucun effacement, aucune protection retirée).
    }
};
