<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Envoi R04 — index de lecture des résultats. INDEX SEULS (aucune colonne, aucune donnée touchée).
 * Créés dans la transaction normale : les tables d'envoi sont quasi vides tant que `ENVOI_REEL_ACTIF=false`, le verrou est
 * négligeable, et la migration reste rejouable (aller, retour, aller). Le retour retire seulement les deux index.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('CREATE INDEX IF NOT EXISTS idx_envoi_resultats_chauffe ON envoi_chauffe_jours (workspace_id, ressource_type, jour) INCLUDE (boite, envoyes, rebonds_durs, plaintes, reponses)');
        DB::statement('CREATE INDEX IF NOT EXISTS idx_envoi_resultats_recherche ON envoi_evenements (workspace_id, empreinte_destinataire, recu_le DESC) WHERE empreinte_destinataire IS NOT NULL');
    }

    /** Retour arrière : retire les deux index (aucune donnée n'est touchée). */
    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS idx_envoi_resultats_recherche');
        DB::statement('DROP INDEX IF EXISTS idx_envoi_resultats_chauffe');
    }
};
