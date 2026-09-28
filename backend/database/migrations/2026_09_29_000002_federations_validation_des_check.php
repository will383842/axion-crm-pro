<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * FÉDÉRATIONS — la validation des deux CHECK reposés `NOT VALID` par la
 * migration précédente (`companies_entity_nature_check`,
 * `activities_kind_check`), et les index du chemin SYNCHRONE de l'effacement
 * (relecture P1).
 *
 * `VALIDATE CONSTRAINT` relit la table sous un verrou `SHARE UPDATE
 * EXCLUSIVE` : lectures ET écritures continuent. Dans la transaction de la
 * migration précédente, il aurait tenu le verrou EXCLUSIF de l'`ADD
 * CONSTRAINT` pendant toute la relecture des 4,3 M de fiches. Même patron que
 * `2026_09_28_000002`.
 *
 * ── Les index de l'effacement ───────────────────────────────────────────────
 *
 * L'effacement (site ET console) et l'export des articles 15 et 20 n'emploient
 * que des recherches servies par un index : le site coupe sa requête à 10 s.
 * Chaque index répond à UNE forme de requête de `EffacementCoordonneesFiches`,
 * écrite à l'identique (expression ET prédicat partiel) :
 *
 *   idx_companies_email_generic_minuscules  lower(email_generic) = ?
 *   idx_companies_telephone_chiffres        regexp_replace(phone, '[^0-9]', '', 'g') IN (…)
 *   idx_contacts_telephone_chiffres         regexp_replace(phone, '[^0-9]', '', 'g') IN (…)
 *   idx_companies_canaux_emails     (GIN)   canaux_emails(signals) && ARRAY[…]
 *   idx_companies_canaux_telephones (GIN)   canaux_telephones(signals) && ARRAY[…]
 *
 * `CONCURRENTLY` : pas de verrou bloquant l'écriture pendant la construction
 * (hors transaction, d'où `$withinTransaction = false`). Un index laissé
 * INVALIDE par une construction interrompue est retiré puis reconstruit —
 * `IF NOT EXISTS` seul le garderait, invalide, pour toujours. Noms VÉRIFIÉS
 * LIBRES le 2026-09-29.
 */
return new class extends Migration
{
    public $withinTransaction = false;

    /** @var array<string, string> nom → définition (après `ON`) */
    public const INDEX = [
        'idx_companies_email_generic_minuscules' => 'companies (lower(email_generic)) WHERE email_generic IS NOT NULL',
        'idx_companies_telephone_chiffres' => "companies (regexp_replace(phone, '[^0-9]', '', 'g')) WHERE phone IS NOT NULL",
        'idx_contacts_telephone_chiffres' => "contacts (regexp_replace(phone, '[^0-9]', '', 'g')) WHERE phone IS NOT NULL",
        'idx_companies_canaux_emails' => "companies USING gin (canaux_emails(signals)) WHERE jsonb_exists(signals, 'contact_channels')",
        'idx_companies_canaux_telephones' => "companies USING gin (canaux_telephones(signals)) WHERE jsonb_exists(signals, 'contact_channels')",
    ];

    public function up(): void
    {
        if (! Schema::hasTable('companies')) {
            return;
        }

        // Hors transaction : `SET` vaut pour la session, d'où le `RESET`
        // final. Un verrou qui ne vient pas fait ÉCHOUER la migration (donc le
        // déploiement, qui le dit) au lieu de mettre la console en file.
        DB::statement("SET lock_timeout = '30s'");
        try {
            DB::statement('ALTER TABLE companies VALIDATE CONSTRAINT companies_entity_nature_check');
            DB::statement('ALTER TABLE activities VALIDATE CONSTRAINT activities_kind_check');

            foreach (self::INDEX as $nom => $definition) {
                $invalide = DB::selectOne(
                    'SELECT NOT i.indisvalid AS invalide FROM pg_index i JOIN pg_class c ON c.oid = i.indexrelid WHERE c.relname = ?',
                    [$nom],
                );
                if ($invalide !== null && (bool) $invalide->invalide) {
                    DB::statement("DROP INDEX CONCURRENTLY IF EXISTS {$nom}");
                }
                DB::statement("CREATE INDEX CONCURRENTLY IF NOT EXISTS {$nom} ON {$definition}");
            }
        } finally {
            DB::statement('RESET lock_timeout');
        }
    }

    public function down(): void
    {
        // Une contrainte validée reste la même contrainte : rien à défaire.
        // Les index partent AVANT les fonctions des canaux (migration
        // précédente), qu'ils emploient. Sans `CONCURRENTLY` : un retour
        // arrière est rare, et il doit pouvoir tourner dans une transaction.
        foreach (array_keys(self::INDEX) as $nom) {
            DB::statement("DROP INDEX IF EXISTS {$nom}");
        }
    }
};
