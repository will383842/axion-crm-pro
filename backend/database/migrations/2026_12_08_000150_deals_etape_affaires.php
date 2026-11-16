<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * AFFAIRES — colonnes de l'écran (lot G2-C, cahier docs/crm/lots/G1-G2-cahier.md § 10).
 *
 * Les 7 étapes de l'écran (`a_contacter, contacte, repondu, rendez_vous, devis, gagne, perdu`) n'existent pas en base :
 * `deals.status` n'a que 4 valeurs. On ajoute `etape` (défaut constant : pas de réécriture de table), `raison_perte`
 * et `titre`, plus deux CHECK (posées NOT VALID puis validées). `status` reste inchangé ; son recalcul sera fait par
 * le code du lot G2-D, déployé APRÈS cette migration. Aucun code ne lit ces colonnes dans ce lot.
 *
 * PUREMENT ADDITIVE et REJOUABLE : ADD COLUMN IF NOT EXISTS ; la seule contrainte retirée avant d'être reposée est
 * l'une des DEUX contraintes de ce fichier. Aucune donnée touchée, aucune ligne créée. La RLS et les droits de `deals`
 * (au niveau de la table) couvrent les nouvelles colonnes : rien à y changer.
 * Pas de déclencheur de refus de DELETE (il casserait la cascade de suppression d'un espace).
 */
return new class extends Migration
{
    private const COLONNES = ['etape', 'raison_perte', 'titre'];

    public function up(): void
    {
        if (! Schema::hasTable('deals')) {
            return;
        }

        DB::statement("SET LOCAL lock_timeout = '5s'");
        DB::statement("ALTER TABLE deals ADD COLUMN IF NOT EXISTS etape TEXT NOT NULL DEFAULT 'a_contacter'");
        DB::statement('ALTER TABLE deals ADD COLUMN IF NOT EXISTS raison_perte TEXT');
        DB::statement('ALTER TABLE deals ADD COLUMN IF NOT EXISTS titre TEXT');

        DB::statement('ALTER TABLE deals DROP CONSTRAINT IF EXISTS deals_etape_valeur');
        DB::statement("ALTER TABLE deals ADD CONSTRAINT deals_etape_valeur
            CHECK (etape IN ('a_contacter','contacte','repondu','rendez_vous','devis','gagne','perdu')) NOT VALID");
        DB::statement('ALTER TABLE deals VALIDATE CONSTRAINT deals_etape_valeur');

        DB::statement('ALTER TABLE deals DROP CONSTRAINT IF EXISTS deals_raison_perte_longueur');
        DB::statement('ALTER TABLE deals ADD CONSTRAINT deals_raison_perte_longueur
            CHECK (raison_perte IS NULL OR length(raison_perte) <= 500) NOT VALID');
        DB::statement('ALTER TABLE deals VALIDATE CONSTRAINT deals_raison_perte_longueur');
    }

    /**
     * Retire contraintes et colonnes SEULEMENT si aucune ligne n'a de valeur posée (étape avancée, titre ou raison) :
     * sinon INERTE (aucune perte).
     */
    public function down(): void
    {
        if (! Schema::hasTable('deals') || ! Schema::hasColumns('deals', self::COLONNES)) {
            return;
        }

        $utilisee = DB::selectOne(
            "SELECT EXISTS (SELECT 1 FROM deals WHERE etape <> 'a_contacter' OR raison_perte IS NOT NULL OR titre IS NOT NULL) AS u",
        );
        if ((bool) $utilisee->u) {
            return;
        }

        DB::statement("SET LOCAL lock_timeout = '5s'");
        DB::statement('ALTER TABLE deals DROP CONSTRAINT IF EXISTS deals_etape_valeur');
        DB::statement('ALTER TABLE deals DROP CONSTRAINT IF EXISTS deals_raison_perte_longueur');
        DB::statement('ALTER TABLE deals DROP COLUMN IF EXISTS etape');
        DB::statement('ALTER TABLE deals DROP COLUMN IF EXISTS raison_perte');
        DB::statement('ALTER TABLE deals DROP COLUMN IF EXISTS titre');
    }
};
