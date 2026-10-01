<?php

use App\Crm\Doublons\Rapprochement;
use App\Crm\Taxonomy;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * LES DOUBLONS DE LA PRESSE (2026-10-01, `crm:presse:doublons`).
 *
 * Deux motifs entrent dans les deux listes fermées des doublons (#260) — la
 * file de vérification (`duplicate_flags`) et le journal des fusions
 * (`fusions_fiches`) :
 *  - `presse_meme_titre` : la fusion automatique STRICTE de deux fiches du
 *    même titre sans SIREN (annulable, journalisée) ;
 *  - `presse_homonyme` : la paire à vérifier par un humain.
 *
 * Purement additive : aucune ligne n'est touchée. Le retour arrière est
 * refusé par la base (contrainte) tant qu'une ligne porte l'un des deux motifs.
 */
return new class extends Migration
{
    private const NOUVEAUX = [Rapprochement::PRESSE_MEME_TITRE, Rapprochement::PRESSE_HOMONYME];

    public function up(): void
    {
        DB::statement("SET LOCAL lock_timeout = '30s'");
        $this->installer(array_keys(Rapprochement::MOTIFS));
    }

    public function down(): void
    {
        DB::statement("SET LOCAL lock_timeout = '30s'");
        $this->installer(array_values(array_diff(array_keys(Rapprochement::MOTIFS), self::NOUVEAUX)));
    }

    /** @param  list<string>  $motifs */
    private function installer(array $motifs): void
    {
        $liste = Taxonomy::sqlList($motifs);
        DB::statement('ALTER TABLE duplicate_flags DROP CONSTRAINT IF EXISTS duplicate_flags_motif_check');
        DB::statement("ALTER TABLE duplicate_flags ADD CONSTRAINT duplicate_flags_motif_check CHECK (motif IS NULL OR motif IN ({$liste}))");
        DB::statement('ALTER TABLE fusions_fiches DROP CONSTRAINT IF EXISTS fusions_fiches_motif_check');
        DB::statement("ALTER TABLE fusions_fiches ADD CONSTRAINT fusions_fiches_motif_check CHECK (motif IN ({$liste}))");
    }
};
