<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * PROPOSITIONS : L'ORIGINE `site-verifie` (04/10/2026).
 *
 * `crm:entreprises:email-site-verifie` relève l'adresse e-mail AFFICHÉE sur
 * un site vérifié (SIREN prouvé). Quand la fiche porte déjà une valeur —
 * saisie à la main, déclarée, ou d'une autre source — l'adresse relevée ne
 * l'écrase JAMAIS : elle devient une PROPOSITION de la file existante
 * (`propositions_champs`), que le propriétaire accepte ou refuse.
 *
 * Le CHECK d'origine n'admettait que les tiers (`apporteur`, `commercial`,
 * `societe`) : il est ÉLARGI à `site-verifie`
 * (`Taxonomy::FIELD_ORIGINS_AUTOMATISMES`). Aucune valeur jusqu'ici admise
 * n'est refusée.
 *
 * PUREMENT ADDITIVE : aucune ligne n'est réécrite ni supprimée. La table est
 * petite (vide à la livraison de N13) : la contrainte est reposée VALIDÉE,
 * sous `lock_timeout`. `down()` remet l'ancienne liste seulement si aucune
 * ligne ne porte la nouvelle origine (on ne supprime rien pour revenir en
 * arrière).
 */
return new class extends Migration
{
    private const ANCIENNES = ['apporteur', 'commercial', 'societe'];

    private const NOUVELLES = ['apporteur', 'commercial', 'societe', 'site-verifie'];

    public function up(): void
    {
        $this->poser(self::NOUVELLES);
    }

    public function down(): void
    {
        if (DB::table('propositions_champs')->whereNotIn('origine', self::ANCIENNES)->exists()) {
            return;
        }
        $this->poser(self::ANCIENNES);
    }

    /** @param  list<string>  $origines */
    private function poser(array $origines): void
    {
        DB::statement("SET LOCAL lock_timeout = '30s'");
        DB::statement('ALTER TABLE propositions_champs DROP CONSTRAINT IF EXISTS propositions_champs_origine_check');
        DB::statement(
            'ALTER TABLE propositions_champs ADD CONSTRAINT propositions_champs_origine_check CHECK (origine IN ('
            . implode(', ', array_map(static fn (string $o): string => "'{$o}'", $origines)) . '))',
        );
    }
};
