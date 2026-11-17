<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Envoi RR-0 — le registre doit dire vrai : une opposition posée par la relève a le canal `releve` (ni un clic, ni un geste humain),
 * et annuler un classement humain est un geste `annulation` (ajout seul : la ligne annulée n'est ni modifiée ni effacée).
 * Élargit deux listes fermées ; aucune ligne relue ni réécrite (contraintes reposées NOT VALID). Une annulation garde
 * `auteur = 'humain'` et `contact_id` nul (la contrainte `_fiche` reste intacte). Ne lève aucune opposition. `down()` INERTE.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE envoi_oppositions DROP CONSTRAINT IF EXISTS envoi_oppositions_canal_check;
            ALTER TABLE envoi_oppositions ADD CONSTRAINT envoi_oppositions_canal_check
                CHECK (canal IN ('page', 'one_click', 'reponse', 'releve')) NOT VALID;
            ALTER TABLE envoi_reponses_classements DROP CONSTRAINT IF EXISTS envoi_reponses_classements_geste_check;
            ALTER TABLE envoi_reponses_classements ADD CONSTRAINT envoi_reponses_classements_geste_check
                CHECK (geste IN ('classement', 'fiche', 'annulation')) NOT VALID;
            COMMENT ON COLUMN envoi_reponses_classements.geste IS
                'classement | fiche | annulation (annule le dernier classement humain ; classement = celui annulé). Ajout seul.';
        SQL);
    }

    public function down(): void {}
};
