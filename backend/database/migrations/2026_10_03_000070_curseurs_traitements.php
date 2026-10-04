<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * CURSEURS DES TRAITEMENTS PAR LOTS (lot N6, 03/10/2026).
 *
 * `crm:entreprises:verifier-sites` parcourt ≈ 824 000 fiches sur plusieurs
 * jours, par fenêtres (tous les jours, 08:00-19:00). Il doit reprendre
 * EXACTEMENT où il s'est arrêté — arrêt à l'heure, coupure, redémarrage du
 * serveur. Le cache (Redis) peut être vidé ou évincé : le curseur vit donc en
 * base, écrit dans la MÊME transaction que le paquet qu'il clôt (un paquet
 * validé = son curseur validé, jamais l'un sans l'autre).
 *
 * Une ligne par (espace, traitement) : `traitement` est une clé interne
 * (`entreprises:verifier-sites`, `entreprises:verifier-sites:audience:1`),
 * `dernier_id` la dernière fiche traitée. Aucune donnée personnelle.
 *
 * Table neuve, additive : rien n'est réécrit ni supprimé ailleurs.
 * RLS : ENABLE + FORCE, politique stricte (pas de repli permissif), comme
 * `listes_manuelles`.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("SET LOCAL lock_timeout = '30s'");

        DB::statement(
            <<<'SQL'
            CREATE TABLE IF NOT EXISTS curseurs_traitements (
                workspace_id   UUID NOT NULL REFERENCES workspaces(id) ON DELETE CASCADE,
                traitement     TEXT NOT NULL CHECK (traitement ~ '^[a-z0-9:._-]{1,120}$'),
                dernier_id     BIGINT NOT NULL DEFAULT 0 CHECK (dernier_id >= 0),
                mis_a_jour_le  TIMESTAMPTZ NOT NULL DEFAULT now(),
                PRIMARY KEY (workspace_id, traitement)
            )
            SQL,
        );

        DB::statement('ALTER TABLE curseurs_traitements ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE curseurs_traitements FORCE ROW LEVEL SECURITY');
        DB::statement('DROP POLICY IF EXISTS curseurs_traitements_workspace_isolation ON curseurs_traitements');
        DB::statement(
            "CREATE POLICY curseurs_traitements_workspace_isolation ON curseurs_traitements FOR ALL
             USING (workspace_id::TEXT = NULLIF(current_setting('app.current_workspace_id', true), ''))
             WITH CHECK (workspace_id::TEXT = NULLIF(current_setting('app.current_workspace_id', true), ''))",
        );

        // Le rôle applicatif lit, crée et avance un curseur — il ne le
        // SUPPRIME jamais (`remettreAZero` réécrit 0). Les privilèges par
        // défaut du schéma lui donneraient DELETE et TRUNCATE : on les lui
        // retire (#314, relecture sécurité n° 4).
        $roleApplicatif = (string) config('database.connections.pgsql_app.username', 'axion_app');
        if ($roleApplicatif !== '' && DB::selectOne('SELECT 1 AS e FROM pg_roles WHERE rolname = ?', [$roleApplicatif]) !== null) {
            $role = '"' . str_replace('"', '""', $roleApplicatif) . '"';
            DB::statement('REVOKE DELETE, TRUNCATE ON public.curseurs_traitements FROM ' . $role);
            DB::statement('GRANT SELECT, INSERT, UPDATE ON public.curseurs_traitements TO ' . $role);
        }
    }

    /**
     * Retour arrière INERTE : rien n'est jamais supprimé, positions de reprise
     * comprises (#314, relecture sécurité n° 4). La table, additive, ne gêne
     * aucun code antérieur ; la retirer se ferait à la main, en connaissance
     * de cause.
     */
    public function down(): void
    {
        // Volontairement vide.
    }
};
