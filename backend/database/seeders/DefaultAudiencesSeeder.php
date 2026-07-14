<?php

namespace Database\Seeders;

use App\Models\EmailAudience;
use App\Models\Workspace;
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
 */
class DefaultAudiencesSeeder extends Seeder
{
    public function run(): void
    {
        $definitions = [
            [
                'name' => 'Prospects contactables',
                'description' => 'Entreprises avec au moins un email et prêtes au démarchage.',
                'criteria' => [
                    'all' => [
                        ['field' => 'has_email', 'op' => 'eq', 'value' => true],
                        ['field' => 'prospection_status', 'op' => 'eq', 'value' => 'ready_for_outreach'],
                    ],
                ],
            ],
            [
                'name' => 'Prospects contactables — Île-de-France',
                'description' => 'Prospects contactables situés en région Île-de-France (code 11).',
                'criteria' => [
                    'all' => [
                        ['field' => 'has_email', 'op' => 'eq', 'value' => true],
                        ['field' => 'prospection_status', 'op' => 'eq', 'value' => 'ready_for_outreach'],
                        ['field' => 'region_code', 'op' => 'eq', 'value' => '11'],
                    ],
                ],
            ],
            [
                'name' => 'Confiance email A (domaine = site)',
                'description' => 'Contactables dont le meilleur email porte la confiance A (domaine == site web).',
                'criteria' => [
                    'all' => [
                        ['field' => 'has_email', 'op' => 'eq', 'value' => true],
                        ['field' => 'best_email_confidence', 'op' => 'eq', 'value' => 'A'],
                    ],
                ],
            ],
        ];

        foreach (Workspace::query()->pluck('id') as $workspaceId) {
            foreach ($definitions as $def) {
                EmailAudience::query()->updateOrCreate(
                    ['workspace_id' => $workspaceId, 'name' => $def['name']],
                    [
                        'description'  => $def['description'],
                        'criteria'     => $def['criteria'],
                        'is_active'    => true,
                        'auto_refresh' => true,
                    ],
                );
            }
        }
    }
}
