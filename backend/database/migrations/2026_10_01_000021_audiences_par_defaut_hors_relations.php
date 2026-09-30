<?php

use App\Crm\Relations\RelationsProspection;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * LES TROIS AUDIENCES PAR DÉFAUT EXCLUENT LES RELATIONS ÉTABLIES (chantier B).
 *
 * `DefaultAudiencesSeeder` pose, dans chaque espace, trois audiences de
 * PROSPECTION (« Prospects contactables », « … — Île-de-France »,
 * « Confiance email A »). Le seeder ne tourne pas au déploiement : celles qui
 * existent déjà en base reçoivent ici le même bloc `not` que le seeder pose
 * désormais (`RelationsProspection::conditionExclusion()`).
 *
 * Ce qui la rend sûre :
 *  - elle ne touche une audience QUE si ses critères sont EXACTEMENT ceux que
 *    le seeder avait posés (comparaison sur le JSON décodé). Une audience que
 *    Will a réécrite à l'écran n'est pas modifiée : elle est comptée dans le
 *    journal, et c'est à lui de décider ;
 *  - aujourd'hui, TOUTES les fiches valent `prospect` : l'exclusion ne retire
 *    personne. Elle ne mord que lorsque `crm:relations:importer` ou la fiche
 *    entreprise poseront `client`, `partenaire`… ;
 *  - rejouable : une audience qui porte déjà le bloc n'est plus « celle du
 *    seeder », elle n'est pas retouchée ; `down()` retire EXACTEMENT ce bloc,
 *    et seulement s'il est seul dans `not`.
 *
 * `email_audiences` porte une RLS : on passe espace par espace, contexte posé
 * LOCALEMENT à la transaction de la migration (même patron que
 * `2026_09_28_000001`).
 */
return new class extends Migration
{
    /**
     * Les critères posés par le seeder AVANT ce chantier — figés ici : le
     * seeder, lui, évolue.
     *
     * @var array<string, array<string, mixed>>
     */
    private const CRITERES_D_ORIGINE = [
        'Prospects contactables' => [
            'all' => [
                ['field' => 'has_email', 'op' => 'eq', 'value' => true],
                ['field' => 'prospection_status', 'op' => 'eq', 'value' => 'ready_for_outreach'],
            ],
        ],
        'Prospects contactables — Île-de-France' => [
            'all' => [
                ['field' => 'has_email', 'op' => 'eq', 'value' => true],
                ['field' => 'prospection_status', 'op' => 'eq', 'value' => 'ready_for_outreach'],
                ['field' => 'region_code', 'op' => 'eq', 'value' => '11'],
            ],
        ],
        'Confiance email A (domaine = site)' => [
            'all' => [
                ['field' => 'has_email', 'op' => 'eq', 'value' => true],
                ['field' => 'best_email_confidence', 'op' => 'eq', 'value' => 'A'],
            ],
        ],
    ];

    public function up(): void
    {
        $this->parEspace(function (object $audience, array $criteres): ?array {
            $origine = self::CRITERES_D_ORIGINE[(string) $audience->name] ?? null;
            if ($origine === null) {
                return null;
            }
            if ($criteres != $origine) {
                Log::notice('Audience par défaut réécrite à la main : exclusion des relations NON ajoutée', [
                    'audience_id' => $audience->id,
                ]);

                return null;
            }

            return $origine + ['not' => [RelationsProspection::conditionExclusion()]];
        });
    }

    public function down(): void
    {
        $this->parEspace(function (object $audience, array $criteres): ?array {
            if (! array_key_exists((string) $audience->name, self::CRITERES_D_ORIGINE)) {
                return null;
            }
            if (($criteres['not'] ?? null) != [RelationsProspection::conditionExclusion()]) {
                return null;
            }
            unset($criteres['not']);

            return $criteres;
        });
    }

    /**
     * @param  Closure(object, array<string, mixed>): ?array<string, mixed>  $nouveaux
     */
    private function parEspace(Closure $nouveaux): void
    {
        foreach (DB::table('workspaces')->pluck('id') as $espace) {
            DB::select('SELECT set_config(?, ?, true)', ['app.current_workspace_id', (string) $espace]);
            $audiences = DB::table('email_audiences')
                ->where('workspace_id', $espace)
                ->whereIn('name', array_keys(self::CRITERES_D_ORIGINE))
                ->whereNull('deleted_at')
                ->get(['id', 'name', 'criteria']);
            foreach ($audiences as $audience) {
                $criteres = json_decode((string) $audience->criteria, true);
                if (! is_array($criteres)) {
                    continue;
                }
                $apres = $nouveaux($audience, $criteres);
                if ($apres !== null) {
                    DB::table('email_audiences')->where('id', $audience->id)
                        ->update(['criteria' => json_encode($apres, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)]);
                }
            }
        }
        DB::select('SELECT set_config(?, ?, true)', ['app.current_workspace_id', '']);
    }
};
