<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Sprint 18.4 — onboarding tour react-joyride.
 *
 * Ajoute la colonne `onboarding_tour_completed_at` sur users.
 * Distincte de `first_login_completed_at` (qui marque le 2FA enrolment).
 *
 * Le tour est déclenché au mount RootLayout si la valeur est NULL.
 */
return new class extends Migration
{
    public function up(): void
    {
        $exists = DB::selectOne(
            "SELECT 1 AS ok FROM information_schema.columns
             WHERE table_schema = current_schema() AND table_name = 'users' AND column_name = 'onboarding_tour_completed_at'"
        );
        if (! $exists) {
            DB::statement('ALTER TABLE users ADD COLUMN onboarding_tour_completed_at TIMESTAMPTZ');
        }
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE users DROP COLUMN IF EXISTS onboarding_tour_completed_at');
    }
};
