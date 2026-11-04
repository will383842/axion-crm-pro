<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Toute demande du site devient une personne du CRM, même sans SIREN
 * (chantier « fiche demande », lot C2) : ADDITIF SEULEMENT.
 *
 *  - `personnes.phone` : le téléphone DÉCLARÉ dans la demande ;
 *  - `personnes.entreprise_declaree` : le nom d'entreprise DÉCLARÉ, tel que
 *    saisi (texte libre, jamais un rapprochement : aucun SIREN n'est deviné).
 *
 * Deux colonnes facultatives, aucune donnée touchée, aucun index. Rien n'est
 * lu ni écrit tant que `crm.ingest.demandes_personne_enabled` est éteint.
 *
 * `down()` REFUSE de retirer une colonne qui porte une valeur : on ne détruit
 * jamais la donnée d'une personne (règle de Will). Le refus est un retour sans
 * effet (colonnes conservées, une ligne au journal, jamais de valeur), et non
 * une exception : le filet NR-17 exige qu'une migration nouvelle ait un retour
 * « réel » (sans `throw`) qui fasse aller, retour, aller. Sans aucune valeur,
 * le retour arrière est sans perte.
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
        // serait faux. Faute de preuve, on refuse aussi.
        $role = DB::selectOne('SELECT (rolsuper OR rolbypassrls) AS contourne FROM pg_roles WHERE rolname = current_user');
        $preuve = $role !== null && (bool) $role->contourne;

        $portees = $preuve
            ? (int) (DB::selectOne('SELECT count(*) AS n FROM personnes WHERE phone IS NOT NULL OR entreprise_declaree IS NOT NULL')->n ?? 0)
            : 1;

        if ($portees > 0) {
            Log::warning('personnes_demandes : retour arrière REFUSÉ, colonnes conservées (des personnes portent une valeur, ou l\'absence de valeur n\'est pas prouvable).');

            return;
        }

        DB::statement('ALTER TABLE personnes DROP COLUMN IF EXISTS entreprise_declaree');
        DB::statement('ALTER TABLE personnes DROP COLUMN IF EXISTS phone');
    }
};
