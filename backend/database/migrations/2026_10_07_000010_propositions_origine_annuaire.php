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
 * valeur de plus. Elle pose l'UNION des origines DÉJÀ admises par le CHECK en
 * place (lues dans le catalogue) et des siennes : une autre migration qui
 * élargit le même CHECK (#328, `site-verifie`) n'est jamais défaite, quel que
 * soit l'ordre de passage (relecture #329, défaut 3). Aucune ligne n'est
 * réécrite ; `NOT VALID` puis `VALIDATE` : pas de verrou exclusif long.
 */
return new class extends Migration
{
    private const ORIGINES = ['apporteur', 'commercial', 'societe', 'annuaire-service-public'];

    public function up(): void
    {
        DB::statement("SET LOCAL lock_timeout = '30s'");

        $origines = self::ORIGINES;
        $definition = DB::selectOne(
            "SELECT pg_get_constraintdef(oid) AS d FROM pg_constraint
              WHERE conname = 'propositions_champs_origine_check' AND conrelid = 'propositions_champs'::regclass",
        );
        if ($definition !== null && preg_match_all("/'([a-z][a-z0-9_-]{0,63})'/", (string) $definition->d, $m) > 0) {
            $origines = array_values(array_unique(array_merge($m[1], $origines)));
        }
        sort($origines);

        DB::statement('ALTER TABLE propositions_champs DROP CONSTRAINT IF EXISTS propositions_champs_origine_check');
        DB::statement(
            'ALTER TABLE propositions_champs ADD CONSTRAINT propositions_champs_origine_check CHECK (origine IN ('
            . implode(', ', array_map(static fn (string $o): string => "'{$o}'", $origines)) . ')) NOT VALID',
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
