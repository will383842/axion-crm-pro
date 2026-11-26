<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * LE COMPTEUR GLOBAL DE DÉPENSE CLAUDE (chantier d'enrichissement).
 *
 * ── `depenses_claude_jour` ────────────────────────────────────────────────
 *
 * Une ligne par jour (UTC) et par poste (`recherche-web`, `arbitrage`,
 * `extraction`). Montants en micro-dollars (entiers). Le coût MAXIMAL d'un
 * appel est RÉSERVÉ avant l'envoi (`App\Crm\Claude\BudgetClaude::reserver()`,
 * sous verrou consultatif), puis remplacé par le coût réel.
 *
 * Pas de `workspace_id` : le budget vaut pour TOUTES les clés et tous les
 * comptes, comme `brave_quota_mensuel`. Aucune donnée de personne : un jour,
 * un poste, des nombres. Moins de 1 000 lignes sur tout le chantier.
 *
 * ── `llm_usage` : quatre colonnes AJOUTÉES (journal) ──────────────────────
 *
 * `cout_usd_micro`, `recherches_web`, `cle_alias`, `poste` : toutes nulles par
 * défaut, donc les lignes existantes ne changent pas. Seul l'ALIAS de la clé
 * est écrit, jamais la clé.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("SET LOCAL lock_timeout = '30s'");

        DB::statement(
            <<<'SQL'
            CREATE TABLE IF NOT EXISTS depenses_claude_jour (
                jour          DATE        NOT NULL,
                poste         TEXT        NOT NULL,
                reserve_micro BIGINT      NOT NULL DEFAULT 0,
                reel_micro    BIGINT      NOT NULL DEFAULT 0,
                appels        INTEGER     NOT NULL DEFAULT 0,
                created_at    TIMESTAMPTZ NOT NULL DEFAULT now(),
                updated_at    TIMESTAMPTZ NOT NULL DEFAULT now(),
                CONSTRAINT depenses_claude_jour_positives CHECK (reserve_micro >= 0 AND reel_micro >= 0 AND appels >= 0),
                CONSTRAINT depenses_claude_jour_poste_check CHECK (poste IN ('recherche-web', 'arbitrage', 'extraction')),
                PRIMARY KEY (jour, poste)
            )
            SQL
        );
        DB::statement("COMMENT ON TABLE depenses_claude_jour IS 'Depense Claude en micro-dollars, par jour UTC et par poste, toutes cles et tous comptes. Le cout maximal est RESERVE avant l appel puis remplace par le reel.'");

        DB::statement('ALTER TABLE llm_usage ADD COLUMN IF NOT EXISTS cout_usd_micro BIGINT');
        DB::statement('ALTER TABLE llm_usage ADD COLUMN IF NOT EXISTS recherches_web INTEGER');
        DB::statement('ALTER TABLE llm_usage ADD COLUMN IF NOT EXISTS cle_alias TEXT');
        DB::statement('ALTER TABLE llm_usage ADD COLUMN IF NOT EXISTS poste TEXT');
    }

    /**
     * Retour réel, sans jamais effacer de dépense déjà écrite : une table ou une
     * colonne qui contient des données est laissée en place (journal d'alerte).
     */
    public function down(): void
    {
        DB::statement("SET LOCAL lock_timeout = '30s'");

        $remplies = (int) DB::table('llm_usage')
            ->where(fn ($q) => $q->whereNotNull('cout_usd_micro')->orWhereNotNull('recherches_web')
                ->orWhereNotNull('cle_alias')->orWhereNotNull('poste'))
            ->count();
        if ($remplies === 0) {
            DB::statement('ALTER TABLE llm_usage DROP COLUMN IF EXISTS cout_usd_micro, DROP COLUMN IF EXISTS recherches_web, DROP COLUMN IF EXISTS cle_alias, DROP COLUMN IF EXISTS poste');
        } else {
            Log::warning("Retour arrière : {$remplies} ligne(s) de llm_usage portent le journal Claude, colonnes conservées.");
        }

        $lignes = (int) DB::table('depenses_claude_jour')->count();
        if ($lignes === 0) {
            DB::statement('DROP TABLE IF EXISTS depenses_claude_jour');
        } else {
            Log::warning("Retour arrière : depenses_claude_jour contient {$lignes} ligne(s), table conservée.");
        }
    }
};
