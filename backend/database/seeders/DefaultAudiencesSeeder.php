<?php

namespace Database\Seeders;

use App\Crm\Relations\RelationsProspection;
use App\Models\EmailAudience;
use App\Models\Workspace;
use App\Services\Audiences\AudienceBuilderService;
use Illuminate\Database\Seeder;

/**
 * Audiences email par défaut (actives + auto_refresh) pour chaque workspace.
 *
 * Sans AU MOINS une audience `is_active AND auto_refresh`, la segmentation reste vide
 * (WaterfallOrchestrator::step12_auto_segment n'a rien à peupler, et
 * `audiences:full-refresh` ne remplit `audience_members` sur rien). C'était la cause
 * du constat prod « email_audiences=0 / audience_members=0 ».
 *
 * Idempotent : updateOrCreate sur (workspace_id, name). Les critères n'utilisent que
 * des champs de AudienceBuilderService::WHITELIST_FIELDS.
 *
 * 2026-10-01 (chantier B) — ce sont des audiences de PROSPECTION : chacune
 * exclut les relations établies (clients, partenaires, presse, fournisseurs,
 * investisseurs) par un bloc `not` visible, `RelationsProspection`. Les
 * audiences déjà en base le reçoivent par la migration `2026_10_01_000021`.
 *
 * 2026-10-03 (lot N5) — « joignable » = au moins une adresse HORS QUARANTAINE
 * (`email_hors_quarantaine`, `QuarantaineSite`) ; « Confiance email A »
 * exclut les fiches au site deviné non vérifié. Audiences déjà en base :
 * migration conditionnelle `2026_10_03_000070`.
 */
class DefaultAudiencesSeeder extends Seeder
{
    public function run(): void
    {
        $definitions = [
            [
                'name' => 'Prospects contactables',
                'description' => 'Entreprises avec au moins un email joignable (hors sites devinés non vérifiés) et prêtes au démarchage.',
                'criteria' => [
                    'all' => [
                        ['field' => AudienceBuilderService::CHAMP_EMAIL_HORS_QUARANTAINE, 'op' => 'eq', 'value' => true],
                        ['field' => 'prospection_status', 'op' => 'eq', 'value' => 'ready_for_outreach'],
                    ],
                    'not' => [RelationsProspection::conditionExclusion()],
                ],
            ],
            [
                'name' => 'Prospects contactables — Île-de-France',
                'description' => 'Prospects contactables situés en région Île-de-France (code 11).',
                'criteria' => [
                    'all' => [
                        ['field' => AudienceBuilderService::CHAMP_EMAIL_HORS_QUARANTAINE, 'op' => 'eq', 'value' => true],
                        ['field' => 'prospection_status', 'op' => 'eq', 'value' => 'ready_for_outreach'],
                        ['field' => 'region_code', 'op' => 'eq', 'value' => '11'],
                    ],
                    'not' => [RelationsProspection::conditionExclusion()],
                ],
            ],
            [
                'name' => 'Confiance email A (domaine = site)',
                'description' => 'Contactables dont le meilleur email porte la confiance A (domaine == site web vérifié).',
                'criteria' => [
                    'all' => [
                        ['field' => AudienceBuilderService::CHAMP_EMAIL_HORS_QUARANTAINE, 'op' => 'eq', 'value' => true],
                        ['field' => 'best_email_confidence', 'op' => 'eq', 'value' => 'A'],
                    ],
                    'not' => [
                        RelationsProspection::conditionExclusion(),
                        ['field' => AudienceBuilderService::CHAMP_SITE_NON_VERIFIE, 'op' => 'eq', 'value' => true],
                    ],
                ],
            ],
        ];

        foreach (Workspace::query()->pluck('id') as $workspaceId) {
            foreach ($definitions as $def) {
                EmailAudience::query()->updateOrCreate(
                    ['workspace_id' => $workspaceId, 'name' => $def['name']],
                    [
                        'description' => $def['description'],
                        'criteria' => $def['criteria'],
                        'is_active' => true,
                        'auto_refresh' => true,
                    ],
                );
            }
        }
    }
}
