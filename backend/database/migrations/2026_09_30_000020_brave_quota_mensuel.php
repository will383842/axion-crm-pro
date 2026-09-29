<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * LE CRÉDIT GRATUIT DE BRAVE, COMPTÉ EN BASE (2026-09-29).
 *
 * Will : trouver le site web des fédérations sans aucun contact, pour ZÉRO
 * euro — seulement le crédit gratuit mensuel de l'API Brave Search (~1 000
 * requêtes). Le plafond doit être STRICT : aucune requête au-delà.
 *
 * ── `brave_quota_mensuel` ─────────────────────────────────────────────────
 *
 * Une ligne par mois civil (UTC), le nombre de requêtes ENVOYÉES. La requête
 * est RÉSERVÉE avant d'être envoyée (`App\Crm\Brave\QuotaBrave::reserver()`,
 * un seul `INSERT … ON CONFLICT … WHERE requetes < plafond`) : deux passages
 * concurrents ne peuvent pas dépasser le plafond à eux deux, et une requête
 * qui échoue (délai, 5xx) reste comptée — Brave la décompte aussi.
 *
 * Pas de `workspace_id` : le crédit est celui de la CLÉ, commune à tout le
 * serveur. Aucune donnée de personne ni d'organisme : un mois et un nombre.
 *
 * ── `companies.website_method` passe de 16 à 32 caractères ────────────────
 *
 * La commande marque ses fiches `brave-federations` (17 caractères, choix de
 * Will). Allonger un `VARCHAR` est une modification de catalogue seulement
 * sous PostgreSQL : ni réécriture de la table (4,3 M de lignes), ni
 * reconstruction d'index (la colonne n'en porte aucun). Le verrou est bref,
 * borné par `lock_timeout` pour ne pas faire la queue derrière une requête
 * longue.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("SET LOCAL lock_timeout = '30s'");

        DB::statement(
            <<<'SQL'
            CREATE TABLE IF NOT EXISTS brave_quota_mensuel (
                mois        DATE        PRIMARY KEY,
                requetes    INTEGER     NOT NULL DEFAULT 0,
                created_at  TIMESTAMPTZ NOT NULL DEFAULT now(),
                updated_at  TIMESTAMPTZ NOT NULL DEFAULT now(),
                CONSTRAINT brave_quota_mensuel_requetes_positives CHECK (requetes >= 0),
                CONSTRAINT brave_quota_mensuel_premier_du_mois CHECK (EXTRACT(DAY FROM mois) = 1)
            )
            SQL
        );
        DB::statement("COMMENT ON TABLE brave_quota_mensuel IS 'Requetes envoyees a l API Brave Search, par mois civil UTC. Reservees AVANT envoi : le plafond (CRM_BRAVE_QUOTA_MENSUEL) ne se depasse jamais.'");

        DB::statement('ALTER TABLE companies ALTER COLUMN website_method TYPE VARCHAR(32)');
    }

    public function down(): void
    {
        $longues = (int) DB::table('companies')->whereRaw('length(website_method) > 16')->count();
        if ($longues > 0) {
            throw new RuntimeException(
                "Retour arrière refusé : {$longues} fiche(s) portent un website_method de plus de 16 caractères (brave-federations).",
            );
        }

        DB::statement("SET LOCAL lock_timeout = '30s'");
        DB::statement('ALTER TABLE companies ALTER COLUMN website_method TYPE VARCHAR(16)');
        DB::statement('DROP TABLE IF EXISTS brave_quota_mensuel');
    }
};
