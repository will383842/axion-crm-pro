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
 * 2. `envoi_compteur_global_liberer` : un refus ou un report libère la place réservée dans le plafond global
 *    (décrément de 1, jamais sous 0). Seule voie, comme `envoi_compteur_global_incrementer`.
 * down() inerte : colonne, index, fonction et lignes sont conservés.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE envoi_journal_commandes
                ADD COLUMN IF NOT EXISTS statut TEXT NOT NULL DEFAULT 'acceptee'
                    CHECK (statut IN ('reservee', 'acceptee', 'liberee', 'a_verifier'));
            CREATE UNIQUE INDEX IF NOT EXISTS envoi_journal_commandes_injection_issue_uniq
                ON envoi_journal_commandes (workspace_id, cle_idempotence)
                WHERE commande = 'injection_issue' AND cle_idempotence IS NOT NULL;
            CREATE UNIQUE INDEX IF NOT EXISTS envoi_journal_commandes_injection_confirmee_uniq
                ON envoi_journal_commandes (workspace_id, cle_idempotence)
                WHERE commande = 'injection_confirmee' AND cle_idempotence IS NOT NULL;
            CREATE OR REPLACE FUNCTION envoi_compteur_global_liberer(p_jour DATE) RETURNS integer AS $f$
                UPDATE public.envoi_compteur_global SET n = n - 1 WHERE jour = p_jour AND n > 0 RETURNING n;
            $f$ LANGUAGE sql SECURITY DEFINER SET search_path = pg_catalog, public;
            REVOKE ALL ON FUNCTION envoi_compteur_global_liberer(DATE) FROM PUBLIC;
        SQL);

        $nom = (string) config('database.connections.pgsql_app.username', 'axion_app');
        if ($nom !== '' && DB::selectOne('SELECT 1 AS e FROM pg_roles WHERE rolname = ?', [$nom]) !== null) {
            DB::statement('GRANT EXECUTE ON FUNCTION public.envoi_compteur_global_liberer(DATE) TO "' . str_replace('"', '""', $nom) . '"');
        }
    }

    /** Retour arrière INERTE : aucune colonne, aucun index, aucune ligne retirés. */
    public function down(): void
    {
        // Volontairement vide.
    }
};
