<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * D1 — index d'expression sur l'empreinte de l'adresse des contacts : la relève IMAP retrouve une adresse par son
 * empreinte (`AdresseParEmpreinteCrm`) ; sans index, chaque retour relisait toute la table `contacts`.
 * AJOUT SEUL : un index partiel, aucune donnée touchée. L'expression est celle de `AdresseParEmpreinteCrm::EXPRESSION_SQL`
 * (même texte : sinon l'index ne sert plus, sans erreur ; un test lit le plan).
 * En production : CONCURRENTLY, hors transaction (aucune écriture bloquée). Dans une transaction (tests), index simple.
 * `down()` INERTE (la règle des lots d'envoi) ; `up()` rejouable (IF NOT EXISTS).
 */
return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        // IMMUTABLE : l'encodage de la base est fixe (UTF8) ; `convert_to` seul est seulement STABLE, donc non indexable.
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION public.empreinte_adresse_sql(adresse text) RETURNS text
            LANGUAGE sql IMMUTABLE PARALLEL SAFE STRICT
            SET search_path = pg_catalog
            AS $f$
                SELECT encode(sha256(convert_to(lower(btrim(adresse, E' \t\n\r\x0B')), 'UTF8')), 'hex')
            $f$
        SQL);
        $simultane = DB::transactionLevel() === 0 ? 'CONCURRENTLY ' : '';
        DB::unprepared(
            "CREATE INDEX {$simultane}IF NOT EXISTS idx_contacts_empreinte_email ON contacts (workspace_id, ("
            . 'public.empreinte_adresse_sql(email::text)' . ')) WHERE email IS NOT NULL AND deleted_at IS NULL',
        );
    }

    public function down(): void
    {
        // Volontairement vide (aucun retrait).
    }
};
