<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * ANNUAIRE OFFICIEL DE L'ADMINISTRATION (Service-public / DILA, 04/10/2026).
 *
 * `crm:public:annuaire-officiel` enrichit les unités du secteur public avec
 * l'e-mail, le téléphone et le site de l'annuaire officiel. Quand la fiche
 * porte déjà une AUTRE valeur (saisie, autre source), rien n'est écrasé :
 * une PROPOSITION est ouverte dans `propositions_champs`, d'origine
 * `annuaire-service-public`.
 *
 * Migration ADDITIVE : le CHECK `propositions_champs_origine_check` admet une
 * valeur de plus. Aucune ligne n'est réécrite ; toutes les lignes existantes
 * satisfont déjà la nouvelle contrainte (`NOT VALID` puis `VALIDATE` : pas de
 * verrou exclusif long).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("SET LOCAL lock_timeout = '30s'");
        DB::statement('ALTER TABLE propositions_champs DROP CONSTRAINT IF EXISTS propositions_champs_origine_check');
        DB::statement(
            "ALTER TABLE propositions_champs ADD CONSTRAINT propositions_champs_origine_check
             CHECK (origine IN ('apporteur', 'commercial', 'societe', 'annuaire-service-public')) NOT VALID",
        );
        DB::statement('ALTER TABLE propositions_champs VALIDATE CONSTRAINT propositions_champs_origine_check');
    }

    /**
     * Retour arrière INERTE : des propositions de l'annuaire peuvent exister,
     * et rien n'est jamais supprimé. Resserrer le CHECK se ferait à la main.
     */
    public function down(): void
    {
        // Volontairement vide.
    }
};
