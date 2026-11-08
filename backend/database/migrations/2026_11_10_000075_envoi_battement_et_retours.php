<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Envoi P0 L7 — AJOUTS SEULS :
 *  - `envoi_sante_battement` : dernier appel à sante() (homme mort INF-025). Une seule ligne, sans
 *    donnée de personne (comme `envoi_compteur_global`). Pas de DELETE ni TRUNCATE pour le rôle applicatif ;
 *  - index unique partiel sur le journal : un événement de retour (rebond, plainte, réponse) ne compte qu'une fois.
 * down() : l'index se retire ; la table n'est retirée que si elle est vide.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(
            <<<'SQL'
            CREATE TABLE IF NOT EXISTS envoi_sante_battement (
                id          SMALLINT    PRIMARY KEY CHECK (id = 1),
                dernier_le  TIMESTAMPTZ NOT NULL
            )
            SQL,
        );
        DB::statement(
            "CREATE UNIQUE INDEX IF NOT EXISTS envoi_journal_commandes_retour_unique
             ON envoi_journal_commandes (workspace_id, cle_idempotence)
             WHERE commande = 'retour_applique' AND cle_idempotence IS NOT NULL",
        );

        $roleApplicatif = (string) config('database.connections.pgsql_app.username', 'axion_app');
        if ($roleApplicatif !== '' && DB::selectOne('SELECT 1 AS e FROM pg_roles WHERE rolname = ?', [$roleApplicatif]) !== null) {
            $role = '"' . str_replace('"', '""', $roleApplicatif) . '"';
            DB::statement('GRANT SELECT, INSERT, UPDATE ON public.envoi_sante_battement TO ' . $role);
            DB::statement('REVOKE DELETE, TRUNCATE ON public.envoi_sante_battement FROM ' . $role);
        }
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP INDEX IF EXISTS envoi_journal_commandes_retour_unique;
            DO $$
            BEGIN
                IF EXISTS (SELECT 1 FROM envoi_sante_battement) THEN
                    RAISE EXCEPTION 'envoi_sante_battement contient une ligne : retour refusé (aucun effacement)';
                END IF;
            END $$;
            DROP TABLE IF EXISTS envoi_sante_battement;
        SQL);
    }
};
