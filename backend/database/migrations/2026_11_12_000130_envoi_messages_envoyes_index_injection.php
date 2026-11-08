<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Envoi K11 — index (workspace_id, id_injection) sur `envoi_messages_envoyes` : le rattachement par un jeton VERP
 * cherche par injection, pas par Message-ID. AJOUT SEUL (un index ; aucune donnée touchée). `down()` retire seulement l'index (aucune donnée).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared('CREATE INDEX IF NOT EXISTS envoi_messages_envoyes_injection ON envoi_messages_envoyes (workspace_id, id_injection)');
    }

    public function down(): void
    {
        DB::unprepared('DROP INDEX IF EXISTS envoi_messages_envoyes_injection');
    }
};
