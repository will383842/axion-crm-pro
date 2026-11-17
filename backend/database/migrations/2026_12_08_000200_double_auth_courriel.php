<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * G3-0 — double authentification par courriel : migration SEULE (rien ne la lit encore).
 *
 *  - users.double_auth_methode (NULL = comportement actuel : TOTP si totp_enabled_at, sinon rien) ;
 *  - codes_connexion_courriel : code hâché (HMAC), jamais le code ; « remplacé » et « consommé » sont des dates ;
 *  - appareils_confiance : jeton hâché (SHA-256) ; « retiré » est une date.
 *
 * Ajouts seuls : aucune ligne existante modifiée. Authentification = avant tout contexte d'espace : pas de
 * workspace_id ni de RLS, cloisonnement par user_id. Rien ne s'efface : DELETE et TRUNCATE sont retirés au
 * rôle applicatif. Aucune colonne identifiante (email, phone…) ni JSON : l'adresse se lit sur users.email.
 * down() : seul DROP admis, sur des objets créés ici (vides tant que G3-A n'est pas déployé).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function () {
            DB::statement("SET LOCAL lock_timeout = '5s'");

            if (! Schema::hasColumn('users', 'double_auth_methode')) {
                DB::statement("ALTER TABLE users ADD COLUMN double_auth_methode TEXT NULL CHECK (double_auth_methode IN ('totp', 'email'))");
            }

            if (! Schema::hasTable('codes_connexion_courriel')) {
                DB::statement(<<<'SQL'
                    CREATE TABLE codes_connexion_courriel (
                        id          UUID PRIMARY KEY DEFAULT gen_random_uuid(),
                        user_id     UUID NOT NULL REFERENCES users(id) ON DELETE RESTRICT,
                        objet       TEXT NOT NULL CHECK (objet IN ('connexion', 'geste')),
                        code_hash   TEXT NOT NULL,
                        essais      SMALLINT NOT NULL DEFAULT 0,
                        expire_le   TIMESTAMPTZ NOT NULL,
                        consomme_le TIMESTAMPTZ,
                        remplace_le TIMESTAMPTZ,
                        ip          INET,
                        cree_le     TIMESTAMPTZ NOT NULL DEFAULT now()
                    )
                    SQL);
                DB::statement('CREATE INDEX idx_codes_courriel_actifs ON codes_connexion_courriel (user_id, objet, cree_le DESC) WHERE consomme_le IS NULL AND remplace_le IS NULL');
            }

            if (! Schema::hasTable('appareils_confiance')) {
                DB::statement(<<<'SQL'
                    CREATE TABLE appareils_confiance (
                        id         UUID PRIMARY KEY DEFAULT gen_random_uuid(),
                        user_id    UUID NOT NULL REFERENCES users(id) ON DELETE RESTRICT,
                        jeton_hash TEXT NOT NULL UNIQUE,
                        nom        TEXT NOT NULL CHECK (char_length(nom) <= 80),
                        ip         INET,
                        ajoute_le  TIMESTAMPTZ NOT NULL DEFAULT now(),
                        expire_le  TIMESTAMPTZ NOT NULL,
                        vu_le      TIMESTAMPTZ,
                        retire_le  TIMESTAMPTZ
                    )
                    SQL);
                DB::statement('CREATE INDEX idx_appareils_confiance_actifs ON appareils_confiance (user_id, ajoute_le DESC) WHERE retire_le IS NULL');
            }

            $roleApplicatif = (string) config('database.connections.pgsql_app.username', 'axion_app');
            if ($roleApplicatif !== '' && DB::selectOne('SELECT 1 AS e FROM pg_roles WHERE rolname = ?', [$roleApplicatif]) !== null) {
                $role = '"' . str_replace('"', '""', $roleApplicatif) . '"';
                DB::statement('REVOKE DELETE, TRUNCATE ON codes_connexion_courriel, appareils_confiance FROM ' . $role);
            }
        });
    }

    /**
     * Ajout seul : rien n'est retiré (un retour arrière ferait perdre la double
     * authentification par courriel et effacerait des données de sécurité).
     */
    public function down(): void {}
};
