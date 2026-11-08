<?php

use App\Envoi\Chauffe\DecisionFrein;
use App\Envoi\Chauffe\DepotChauffe;
use App\Envoi\Chauffe\GardeChauffe;
use App\Envoi\Chauffe\LecteurReports;
use App\Envoi\Chauffe\ResolveurFournisseur;
use App\Envoi\Depot\DepotSocle;
use App\Support\WorkspaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

test('K8 reports > 20 % chez gmail => une pause « gmail » seulement : ni microsoft, ni autre, ni globale', function () {
    $ws = (string) Str::uuid();
    DB::table('workspaces')->insert(['id' => $ws, 'name' => 'Rep', 'slug' => 'rep-' . substr($ws, 0, 8), 'created_at' => now(), 'updated_at' => now()]);
    $lecteur = new class implements LecteurReports
    {
        public function sur1Heure(string $ws, string $fournisseur, DateTimeImmutable $maintenant): array
        {
            return match ($fournisseur) {
                'gmail' => ['envoyes' => 100, 'reports' => 30],
                'microsoft' => ['envoyes' => 100, 'reports' => 20],   // 20 % pile : rien
                default => ['envoyes' => 100, 'reports' => 5],
            };
        }
    };
    $garde = new GardeChauffe(app(DepotChauffe::class), app(DepotSocle::class), app(ResolveurFournisseur::class), null, $lecteur);

    $garde->evaluer($ws, new DateTimeImmutable('2026-12-01 10:00:00', new DateTimeZone('Europe/Paris')));

    $pauses = WorkspaceContext::run($ws, fn () => DB::table('envoi_pauses')->where('workspace_id', $ws)
        ->where('motif', DecisionFrein::REPORTS_EN_HAUSSE)->whereNull('levee_le')->get(['portee', 'fournisseur']));
    expect($pauses)->toHaveCount(1)
        ->and($pauses[0]->portee)->toBe('fournisseur')
        ->and($pauses[0]->fournisseur)->toBe('gmail');
});
