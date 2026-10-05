<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * PROPOSITIONS : L'ORIGINE `site-verifie` (04/10/2026).
 *
 * `crm:entreprises:email-site-verifie` relève l'adresse e-mail AFFICHÉE sur
 * un site vérifié. Quand la fiche porte déjà une valeur — saisie à la main,
 * déclarée, ou d'une autre source — l'adresse relevée ne l'écrase JAMAIS :
 * elle devient une PROPOSITION de la file existante (`propositions_champs`),
 * que le propriétaire accepte ou refuse.
 *
 * Le CHECK `propositions_champs_origine_check` est ÉLARGI à `site-verifie`
 * (`Taxonomy::FIELD_ORIGINS_AUTOMATISMES`). Comme la migration de l'annuaire
 * officiel (`2026_10_07_000010`), elle pose l'UNION des origines DÉJÀ
 * admises par le CHECK en place (lues dans le catalogue) et de la sienne :
 * aucune valeur admise par une autre migration n'est retirée, quel que soit
 * l'ordre de passage (relecture #328, défaut 1). Elle passe après celle de
 * l'annuaire.
 *
 * PUREMENT ADDITIVE : aucune ligne n'est réécrite ni supprimée ; `NOT VALID`
 * puis `VALIDATE` sous `lock_timeout` : pas de verrou exclusif long. `down()`
 * est inerte : des propositions `site-verifie` peuvent exister, et rien
 * n'est jamais supprimé.
 */
return new class extends Migration
{
    /** Le socle si aucun CHECK n'est en place (base neuve hors ordre normal). */
    private const ORIGINES = ['apporteur', 'commercial', 'societe', 'site-verifie'];

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
     * Retour arrière INERTE : des propositions `site-verifie` peuvent exister,
     * et rien n'est jamais supprimé. Resserrer le CHECK se ferait à la main.
     */
    public function down(): void
    {
        // Volontairement vide.
    }
};
