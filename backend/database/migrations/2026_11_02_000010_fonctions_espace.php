<?php

use App\Crm\Campagnes\Segments;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `fonctions_espace` — les fonctions sensibles OUVERTES, espace par espace (ADR-08, lot S6).
 *
 * Une ligne = « pour cet espace, cette fonction est ouverte (ou fermée) ».
 * Une fonction SANS ligne est FERMÉE : un nouvel espace naît tout fermé.
 * Première fonction réglée ici : les segments de campagne (`segment.presse`,
 * `segment.federations`, `segment.organisateurs-evenements`).
 *
 * La colonne s'appelle `espace_id` (et non `workspace_id`) : c'est un RÉGLAGE
 * lu par le système, pas une donnée métier cloisonnée par RLS.
 *
 * DONNÉES : l'espace d'Axion (`slug = 'axion-ia'`) reçoit exactement l'état
 * d'aujourd'hui — les segments ouverts d'après `crm.segments_ouverts`, lu par
 * `Segments::ouverts()` en mode global. Rien ne change pour Will. Les autres
 * espaces ne reçoivent aucune ligne (= tout fermé).
 *
 * COMPATIBILITÉ : on AJOUTE une table, rien n'est retiré ni modifié ; l'ancien
 * code l'ignore. La table ne porte aucune donnée de personne : `down()` peut
 * la retirer.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('fonctions_espace')) {
            DB::statement(
                <<<'SQL'
                CREATE TABLE fonctions_espace (
                    id         BIGSERIAL PRIMARY KEY,
                    espace_id  UUID NOT NULL REFERENCES workspaces(id) ON DELETE RESTRICT,
                    fonction   TEXT NOT NULL CHECK (fonction ~ '^[a-z0-9._-]{1,80}$'),
                    ouverte    BOOLEAN NOT NULL DEFAULT false,
                    created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
                    updated_at TIMESTAMPTZ NOT NULL DEFAULT now(),
                    CONSTRAINT fonctions_espace_unique UNIQUE (espace_id, fonction)
                )
                SQL,
            );
            DB::statement("COMMENT ON TABLE fonctions_espace IS 'Fonctions sensibles ouvertes par espace (S6). Sans ligne = fermée.'");
        }

        $this->poserEtatActuelPourAxion();
    }

    /**
     * Pose, pour l'espace d'Axion, l'état d'AUJOURD'HUI : un segment connu est
     * « ouvert » s'il l'est d'après le réglage global. Idempotent.
     */
    public function poserEtatActuelPourAxion(): void
    {
        $axion = DB::table('workspaces')->where('slug', 'axion-ia')->value('id');
        if (! is_string($axion) || $axion === '') {
            return;
        }

        $ouverts = Segments::ouvertsGlobalement();
        foreach (Segments::CONNUS as $segment) {
            DB::table('fonctions_espace')->updateOrInsert(
                ['espace_id' => $axion, 'fonction' => 'segment.' . $segment],
                ['ouverte' => in_array($segment, $ouverts, true), 'updated_at' => now()],
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('fonctions_espace');
    }
};
