<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * L'ÉTAT DES CLÉS CLAUDE (bascule automatique).
 *
 * Une ligne par ALIAS de clé (`compte_a`, `compte_b`…), jamais la clé elle-même.
 * `epuisee` : plus de crédit, retentée au plus une fois par 24 h ;
 * `invalide` : écartée jusqu'à `crm:claude:cles --reactiver=<alias>`.
 * Un redémarrage ne réessaie donc pas en boucle une clé vide.
 *
 * Pas de `workspace_id` : les clés sont celles du serveur. Ajout seul.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(
            <<<'SQL'
            CREATE TABLE IF NOT EXISTS cles_claude_etat (
                alias       TEXT        NOT NULL PRIMARY KEY,
                etat        TEXT        NOT NULL DEFAULT 'disponible',
                depuis      TIMESTAMPTZ NOT NULL DEFAULT now(),
                created_at  TIMESTAMPTZ NOT NULL DEFAULT now(),
                updated_at  TIMESTAMPTZ NOT NULL DEFAULT now(),
                CONSTRAINT cles_claude_etat_etat_check CHECK (etat IN ('disponible', 'epuisee', 'invalide'))
            )
            SQL
        );
        DB::statement("COMMENT ON TABLE cles_claude_etat IS 'Etat de chaque cle Claude par alias (jamais la cle) : disponible, epuisee (retentee apres 24 h), invalide.'");
    }

    public function down(): void
    {
        $lignes = (int) DB::table('cles_claude_etat')->count();
        if ($lignes > 0) {
            throw new RuntimeException("Retour arrière refusé : cles_claude_etat contient {$lignes} ligne(s).");
        }

        DB::statement('DROP TABLE IF EXISTS cles_claude_etat');
    }
};
