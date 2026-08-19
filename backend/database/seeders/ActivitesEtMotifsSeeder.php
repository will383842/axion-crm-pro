<?php

namespace Database\Seeders;

use App\Crm\ActivitesEtMotifs;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Semis des ACTIVITÉS et MOTIFS livrés d'origine (§2.3, étape 1a).
 *
 * ⚠️ `insertOrIgnore`, JAMAIS `upsert`. La différence n'est pas stylistique :
 *
 *   - `upsert` remet `label`, `ordre` et `actif` à leur valeur d'usine à chaque
 *     exécution. Comme la migration appelle ce seeder et que la migration
 *     tourne à CHAQUE déploiement d'une machine neuve, un libellé changé depuis
 *     la console reviendrait à sa valeur d'origine sans que personne ne le
 *     demande. La promesse « extensible depuis la console » (§2.3) serait
 *     fausse une fois par déploiement, en silence.
 *   - `insertOrIgnore` pose ce qui manque et ne touche à rien d'autre. Le semis
 *     est un PLANCHER, jamais un plafond.
 *
 * Conséquence assumée : une faute de frappe dans un libellé d'origine ne se
 * corrige pas par un déploiement une fois la ligne posée. C'est le bon
 * compromis — elle se corrige depuis la console, en dix secondes, ce qui est
 * exactement ce que le §29 critère 2 exige.
 *
 * Ce seeder ne SUPPRIME jamais rien : un motif retiré du code reste en base.
 * Une ligne qui a servi à classer des échanges ne peut pas disparaître sans
 * casser leur historique — on la désactive (`actif = false`), on ne l'efface
 * pas.
 */
class ActivitesEtMotifsSeeder extends Seeder
{
    public function run(): void
    {
        $now = now();
        $activites = [];

        foreach (ActivitesEtMotifs::ACTIVITES as $slug => $activite) {
            $activites[] = [
                'slug' => $slug,
                'label' => $activite['label'],
                'qualiopi' => $activite['qualiopi'],
                'ordre' => $activite['ordre'],
                'actif' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        DB::table('crm_activites')->insertOrIgnore($activites);

        $motifs = [];

        foreach (ActivitesEtMotifs::MOTIFS as $slug => $motif) {
            $motifs[] = [
                'slug' => $slug,
                'label' => $motif['label'],
                'espace' => $motif['espace'],
                'ordre' => $motif['ordre'],
                'actif' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        DB::table('crm_motifs')->insertOrIgnore($motifs);
    }
}
