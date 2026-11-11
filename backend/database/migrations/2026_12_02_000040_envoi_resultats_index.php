<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Envoi R04 — index de lecture des résultats. INDEX SEULS (aucune colonne, aucune donnée touchée), hors transaction et
 * CONCURRENTLY : les tables sont grosses en production. `down()` inerte.
 */
return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_envoi_resultats_chauffe ON envoi_chauffe_jours (workspace_id, ressource_type, jour) INCLUDE (boite, envoyes, rebonds_durs, plaintes, reponses)');
        DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_envoi_resultats_recherche ON envoi_evenements (workspace_id, empreinte_destinataire, recu_le DESC) WHERE empreinte_destinataire IS NOT NULL');
    }

    /** Retour arrière INERTE : un index se recrée, rien n'est retiré. */
    public function down(): void
    {
        // Volontairement vide.
    }
};
