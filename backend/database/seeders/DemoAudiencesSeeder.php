<?php

namespace Database\Seeders;

use App\Models\EmailAudience;
use App\Models\Workspace;
use Illuminate\Database\Seeder;

/**
 * Seed 4 audiences exemple par workspace (utile pour démo Will).
 * Idempotent : firstOrCreate par (workspace_id, name).
 */
class DemoAudiencesSeeder extends Seeder
{
    public function run(): void
    {
        $workspaces = Workspace::query()->get();
        if ($workspaces->isEmpty()) {
            $this->command->warn('No workspaces found, skipping demo audiences.');
            return;
        }

        $demos = [
            [
                'name'        => 'PME IT Île-de-France',
                'description' => 'PME et ETI tech qualifiées dans la région IDF, status ready_for_outreach.',
                'criteria'    => [
                    'all' => [
                        ['field' => 'prospection_status', 'op' => 'in', 'value' => ['ready_for_outreach']],
                        ['field' => 'size_category', 'op' => 'in', 'value' => ['pme', 'eti']],
                        ['field' => 'region_code', 'op' => 'eq', 'value' => '11'],
                        ['field' => 'tags', 'op' => 'contains_any', 'value' => ['sector-it-saas']],
                    ],
                ],
            ],
            [
                'name'        => 'TPE Sud-Ouest tous secteurs',
                'description' => 'TPE de Nouvelle-Aquitaine, status prêts ou partiels.',
                'criteria'    => [
                    'all' => [
                        ['field' => 'prospection_status', 'op' => 'in', 'value' => ['ready_for_outreach', 'partial_email']],
                        ['field' => 'size_category', 'op' => 'eq', 'value' => 'tpe'],
                        ['field' => 'department_code', 'op' => 'in', 'value' => ['33', '40', '47', '64', '24']],
                    ],
                ],
            ],
            [
                'name'        => 'Grandes entreprises France entière',
                'description' => 'ETI et grandes entreprises prospectables, toutes régions.',
                'criteria'    => [
                    'all' => [
                        ['field' => 'size_category', 'op' => 'in', 'value' => ['eti', 'grande']],
                        ['field' => 'prospection_status', 'op' => 'eq', 'value' => 'ready_for_outreach'],
                    ],
                ],
            ],
            [
                'name'        => 'À tester (qualité moyenne)',
                'description' => 'Entreprises de qualité moyenne pour validation manuelle.',
                'criteria'    => [
                    'all' => [
                        ['field' => 'quality_score', 'op' => 'gte', 'value' => 40],
                        ['field' => 'quality_score', 'op' => 'lt', 'value' => 70],
                        ['field' => 'prospection_status', 'op' => 'neq', 'value' => 'archived_no_email'],
                    ],
                ],
            ],
        ];

        $totalCreated = 0;
        foreach ($workspaces as $workspace) {
            foreach ($demos as $demo) {
                $audience = EmailAudience::firstOrCreate(
                    [
                        'workspace_id' => $workspace->id,
                        'name'         => $demo['name'],
                    ],
                    [
                        'description'  => $demo['description'],
                        'criteria'     => $demo['criteria'],
                        'is_active'    => true,
                        'auto_refresh' => true,
                        'member_count' => 0,
                    ],
                );
                if ($audience->wasRecentlyCreated) {
                    $totalCreated++;
                }
            }
        }

        $this->command->info("DemoAudiencesSeeder: {$totalCreated} audiences créées (par workspace).");
    }
}
