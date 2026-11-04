<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Toute demande du site devient une personne du CRM, même sans SIREN
 * (chantier « fiche demande », lot C2) — ADDITIF SEULEMENT.
 *
 *  - `personnes.phone` : le téléphone DÉCLARÉ dans la demande ;
 *  - `personnes.entreprise_declaree` : le nom d'entreprise DÉCLARÉ, tel que
 *    saisi (texte libre, jamais un rapprochement : aucun SIREN n'est deviné).
 *
 * Deux colonnes facultatives, aucune donnée touchée, aucun index. Rien n'est
 * lu ni écrit tant que `crm.ingest.demandes_personne_enabled` est éteint.
 *
 * `down()` REFUSE de retirer une colonne qui porte une valeur : on ne détruit
 * jamais la donnée d'une personne (règle de Will). Sans aucune valeur, le
 * retour arrière est sans perte.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE personnes ADD COLUMN IF NOT EXISTS phone text NULL');
        DB::statement('ALTER TABLE personnes ADD COLUMN IF NOT EXISTS entreprise_declaree text NULL');
        DB::statement("COMMENT ON COLUMN personnes.phone IS 'Fiche demande : telephone declare dans la demande du site. Facultatif.'");
        DB::statement("COMMENT ON COLUMN personnes.entreprise_declaree IS 'Fiche demande : nom d entreprise declare, texte libre, jamais un rapprochement.'");
    }

    public function down(): void
    {
        // `personnes` est sous RLS forcée : un rôle qui ne contourne pas la RLS
        // verrait zéro ligne sans contexte d'espace, et le « rien à perdre »
        // serait faux. Dans ce cas on refuse aussi : on ne peut rien prouver.
        $role = DB::selectOne('SELECT (rolsuper OR rolbypassrls) AS contourne FROM pg_roles WHERE rolname = current_user');
        if ($role === null || ! (bool) $role->contourne) {
            throw new RuntimeException(
                'Retour arrière refusé : ce rôle ne contourne pas la RLS, impossible de prouver qu\'aucune personne ne porte de valeur.',
            );
        }

        $portees = DB::selectOne(
            'SELECT count(*) AS n FROM personnes WHERE phone IS NOT NULL OR entreprise_declaree IS NOT NULL',
        );
        if ((int) ($portees->n ?? 0) > 0) {
            throw new RuntimeException(
                'Retour arrière refusé : des personnes portent un téléphone ou une entreprise déclarés. '
                . 'Aucune donnée de personne n\'est détruite ; la colonne reste.',
            );
        }

        DB::statement('ALTER TABLE personnes DROP COLUMN IF EXISTS entreprise_declaree');
        DB::statement('ALTER TABLE personnes DROP COLUMN IF EXISTS phone');
    }
};
