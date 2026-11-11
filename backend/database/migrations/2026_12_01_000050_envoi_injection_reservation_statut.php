<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Envoi L8-F14a — « réserver, injecter, conclure » (dette K9 I2). AJOUTS SEULS : aucune ligne touchée.
 *
 * 1. `envoi_journal_commandes.statut` : reservee | acceptee | liberee | a_verifier. Les lignes déjà là (injections
 *    acceptées) prennent la valeur par défaut `acceptee` SANS UPDATE. Le journal reste « ajout seul » : une
 *    réservation est une ligne `injection` ; sa conclusion est une NOUVELLE ligne `injection_issue` (même clé de
 *    journal, une seule par réservation : index unique).
 * 2. `envoi_injection_liberer` : un refus ou un report libère la place réservée : conclusion `liberee` et décrément du
 *    compteur global dans le même acte, une seule fois par réservation (jamais sous 0, jamais sans réservation).
 * down() inerte : colonne, index, fonction et lignes sont conservés.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE envoi_journal_commandes
                ADD COLUMN IF NOT EXISTS statut TEXT NOT NULL DEFAULT 'acceptee';
            -- CHECK en deux temps : NOT VALID (verrou bref, aucun parcours de la table) puis VALIDATE (verrou allégé).
            DO $c$
            BEGIN
                IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'envoi_journal_commandes_statut_check' AND conrelid = 'public.envoi_journal_commandes'::regclass) THEN
                    ALTER TABLE public.envoi_journal_commandes ADD CONSTRAINT envoi_journal_commandes_statut_check
                        CHECK (statut IN ('reservee', 'acceptee', 'liberee', 'a_verifier')) NOT VALID;
                END IF;
            END
            $c$;
            ALTER TABLE public.envoi_journal_commandes VALIDATE CONSTRAINT envoi_journal_commandes_statut_check;
            CREATE UNIQUE INDEX IF NOT EXISTS envoi_journal_commandes_injection_issue_uniq
                ON envoi_journal_commandes (workspace_id, cle_idempotence)
                WHERE commande = 'injection_issue' AND cle_idempotence IS NOT NULL;
            CREATE UNIQUE INDEX IF NOT EXISTS envoi_journal_commandes_injection_confirmee_uniq
                ON envoi_journal_commandes (workspace_id, cle_idempotence)
                WHERE commande = 'injection_confirmee' AND cle_idempotence IS NOT NULL;
            -- Libération : conclusion `liberee` ET décrément du compteur global dans UN seul acte, une fois par réservation
            -- (l'index unique des conclusions fait foi) ; jamais de décrément sans réservation ni sans conclusion posée.
            CREATE OR REPLACE FUNCTION envoi_injection_liberer(p_ws UUID, p_cle TEXT) RETURNS boolean AS $f$
            DECLARE
                v_jour DATE;
                v_n INTEGER;
            BEGIN
                SELECT jour INTO v_jour FROM public.envoi_journal_commandes
                    WHERE workspace_id = p_ws AND commande = 'injection' AND cle_idempotence = p_cle;
                IF v_jour IS NULL THEN
                    RETURN false;
                END IF;
                INSERT INTO public.envoi_journal_commandes (workspace_id, commande, auteur, details, jour, cle_idempotence, statut)
                    VALUES (p_ws, 'injection_issue', 'passerelle', '{}', v_jour, p_cle, 'liberee')
                    ON CONFLICT (workspace_id, cle_idempotence) WHERE commande = 'injection_issue' AND cle_idempotence IS NOT NULL DO NOTHING;
                GET DIAGNOSTICS v_n = ROW_COUNT;
                IF v_n > 0 THEN
                    UPDATE public.envoi_compteur_global SET n = n - 1 WHERE jour = v_jour AND n > 0;
                END IF;
                RETURN v_n > 0;
            END;
            $f$ LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, public;
            REVOKE ALL ON FUNCTION envoi_injection_liberer(UUID, TEXT) FROM PUBLIC;
        SQL);

        $nom = (string) config('database.connections.pgsql_app.username', 'axion_app');
        if ($nom !== '' && DB::selectOne('SELECT 1 AS e FROM pg_roles WHERE rolname = ?', [$nom]) !== null) {
            DB::statement('GRANT EXECUTE ON FUNCTION public.envoi_injection_liberer(UUID, TEXT) TO "' . str_replace('"', '""', $nom) . '"');
        }
    }

    /** Retour arrière INERTE : aucune colonne, aucun index, aucune ligne retirés. */
    public function down(): void
    {
        // Volontairement vide.
    }
};
