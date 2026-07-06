<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Suivi par entreprise de l'étape « recherche de site web » :
 *  - website_status : pending | found | not_found
 *  - website_method : par quelle méthode trouvé (guess, brave, google, manual)
 *  - website_checked_at : dernier essai
 * Permet de reprendre, de cibler les manquants et de suivre la couverture.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->string('website_status', 16)->default('pending')->index();
            $table->string('website_method', 16)->nullable();
            $table->timestampTz('website_checked_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn(['website_status', 'website_method', 'website_checked_at']);
        });
    }
};
