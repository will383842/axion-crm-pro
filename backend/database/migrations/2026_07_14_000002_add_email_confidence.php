<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Audit deep fixes 2026-07-14 — scoring de confiance email déterministe A/B/C.
 *
 * Décision produit (Will) : PAS de vérification SMTP depuis le VPS (risque
 * blacklist Spamhaus). À la place, un score de confiance calculé sans I/O
 * réseau, exploitable pour prioriser l'envoi via un ESP à gestion de rebonds :
 *
 *   A → domaine de l'email == domaine racine (eTLD+1) du site de l'entreprise.
 *   B → domaine pro propre (ni A, ni fournisseur grand public).
 *   C → boîte grand public (gmail/orange/free/wanadoo/…).
 *
 * Les domaines jetables/invalides sont exclus en amont → pas de tier « D ».
 *
 * - contacts.email_confidence         : score par contact.
 * - companies.best_email_confidence   : meilleure confiance (A>B>C) portée au
 *   niveau société (contacts + email_generic) — sert au tri/export.
 *
 * Idempotent (IF NOT EXISTS) : rejouable sans casse.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            ALTER TABLE contacts
                ADD COLUMN IF NOT EXISTS email_confidence CHAR(1)
                    CHECK (email_confidence IN ('A', 'B', 'C'));
        SQL);

        DB::statement(<<<'SQL'
            CREATE INDEX IF NOT EXISTS idx_contacts_email_confidence
                ON contacts (email_confidence)
                WHERE email_confidence IS NOT NULL;
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE companies
                ADD COLUMN IF NOT EXISTS best_email_confidence CHAR(1)
                    CHECK (best_email_confidence IN ('A', 'B', 'C'));
        SQL);

        DB::statement(<<<'SQL'
            CREATE INDEX IF NOT EXISTS idx_companies_best_email_confidence
                ON companies (best_email_confidence)
                WHERE best_email_confidence IS NOT NULL;
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS idx_companies_best_email_confidence;');
        DB::statement('ALTER TABLE companies DROP COLUMN IF EXISTS best_email_confidence;');
        DB::statement('DROP INDEX IF EXISTS idx_contacts_email_confidence;');
        DB::statement('ALTER TABLE contacts DROP COLUMN IF EXISTS email_confidence;');
    }
};
