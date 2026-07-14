<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Confiance email A/B/C sur les MÉDIAS (même barème que les contacts entreprises).
 *  A = email sur le domaine du site du média (le + fiable), B = domaine pro, C = conso.
 * Sert à prioriser l'envoi presse via un ESP à gestion de rebonds (sans SMTP).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE media ADD COLUMN IF NOT EXISTS email_confidence CHAR(1)");
        DB::statement('ALTER TABLE media DROP CONSTRAINT IF EXISTS media_email_confidence_check');
        DB::statement("ALTER TABLE media ADD CONSTRAINT media_email_confidence_check CHECK (email_confidence IN ('A','B','C'))");
        DB::statement('CREATE INDEX IF NOT EXISTS idx_media_email_confidence ON media (email_confidence) WHERE email_confidence IS NOT NULL');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE media DROP CONSTRAINT IF EXISTS media_email_confidence_check');
        DB::statement('DROP INDEX IF EXISTS idx_media_email_confidence');
        DB::statement('ALTER TABLE media DROP COLUMN IF EXISTS email_confidence');
    }
};
