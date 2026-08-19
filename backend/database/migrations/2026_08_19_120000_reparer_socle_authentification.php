<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Audit 360 — agent 35. Deux réparations du socle d'authentification.
 *
 * 1. `totp_recovery_codes` était déclaré `TEXT[]` (tableau Postgres). Le modèle
 *    `User` le cast en `encrypted:array`, ce qui produit UNE chaîne chiffrée —
 *    impossible à écrire dans un tableau. La colonne n'a jamais reçu la moindre
 *    valeur, et pour cause : le chemin d'écriture (`TwoFactorService`) partait
 *    de toute façon en erreur sur un autre nom de colonne (A07-001). On la passe
 *    en `TEXT`, seul type compatible avec un chiffrement au repos.
 *    La conversion préserve l'existant sous forme JSON — il n'y en a aucun, mais
 *    une migration qui jette des données sans le dire est une mauvaise migration.
 *
 * 2. `last_failed_login_at` est ajoutée pour donner une MÉMOIRE FINIE au compteur
 *    d'échecs. Sans elle, `failed_login_count` ne redescendait jamais autrement
 *    que par une connexion réussie : dix fautes de frappe étalées sur des mois
 *    verrouillaient un compte légitime pour 24 h (F35-012).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            ALTER TABLE users
                ALTER COLUMN totp_recovery_codes TYPE TEXT
                USING CASE
                    WHEN totp_recovery_codes IS NULL THEN NULL
                    ELSE array_to_json(totp_recovery_codes)::text
                END
        SQL);

        DB::statement('ALTER TABLE users ADD COLUMN IF NOT EXISTS last_failed_login_at TIMESTAMPTZ');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE users DROP COLUMN IF EXISTS last_failed_login_at');

        // Retour au tableau : on ne peut pas reconstituer un TEXT[] depuis une
        // chaîne chiffrée. On revient donc à une colonne vide, ce que la
        // situation d'origine était de toute façon.
        DB::statement('ALTER TABLE users ALTER COLUMN totp_recovery_codes TYPE TEXT[] USING NULL');
    }
};
