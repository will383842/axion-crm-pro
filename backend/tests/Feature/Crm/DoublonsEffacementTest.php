<?php

/**
 * S1 — L'EFFACEMENT D'UNE PERSONNE RETIRE SON ADRESSE DES ADRESSES PARTAGÉES
 * (chantier 5).
 *
 * `adresses_partagees` ne garde qu'une EMPREINTE SALÉE (HMAC, clé de la base) ;
 * l'effacement, par la console (`GdprErasureService`) comme par le site
 * (`SiteGdprService`), retire quand même la ligne de l'adresse effacée — les
 * deux passent par `EffacementCoordonneesFiches::effacer`. Témoin : l'adresse
 * d'une autre personne reste.
 *
 * Fixtures FICTIVES (dépôt public).
 */

use App\Crm\Doublons\FusionFiches;
use App\Crm\Doublons\Rapprochement;
use App\Crm\Rgpd\SiteGdprService;
use App\Services\Audit\AuditHashChain;
use App\Services\Rgpd\GdprErasureService;
use App\Support\WorkspaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Support\DoublonsFixtures as F;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    $this->mock(AuditHashChain::class)->shouldReceive('record')->andReturn(1);
    Queue::fake();
    config(['crm.ingest.business_workspace' => 'axion-ia']);
    $this->ws = (string) (DB::table('workspaces')->where('slug', 'axion-ia')->value('id') ?? Str::uuid());
    if (! DB::table('workspaces')->where('id', $this->ws)->exists()) {
        DB::table('workspaces')->insert([
            'id' => $this->ws, 'slug' => 'axion-ia', 'name' => 'Axion-IA', 'settings' => '{}',
            'cost_cap_eur' => 100, 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }
    $this->adresses = ['console' => 'zoe.console@zz-efface.example.invalid', 'site' => 'zoe.site@zz-efface.example.invalid', 'temoin' => 'temoin@zz-garde.example.invalid'];
    foreach ($this->adresses as $cle => $email) {
        F::fiche($this->ws, "ZZ Effacement {$cle} 1", ['email_generic' => $email]);
        F::fiche($this->ws, "ZZ Effacement {$cle} 2", ['email_generic' => $email]);
    }
    Artisan::call('crm:doublons:detecter', ['--workspace' => $this->ws]);
});

function dePresente(string $ws, string $email): bool
{
    $empreinte = F::empreinteAdresse($email);

    return DB::table('adresses_partagees')->where('workspace_id', $ws)->where('email_empreinte', $empreinte)->exists();
}

test('les trois adresses partagées sont inscrites, par une empreinte SALÉE (pas le sha256 de l adresse)', function () {
    foreach ($this->adresses as $email) {
        expect(dePresente($this->ws, $email))->toBeTrue()
            ->and(DB::table('adresses_partagees')->where('email_empreinte', hash('sha256', $email))->exists())->toBeFalse();
    }
});

test('L3c-0 : le traitement par la CONSOLE ne retire aucune ligne d adresse partagée (rien n est effacé)', function () {
    app(GdprErasureService::class)->erase($this->adresses['console']);

    expect(dePresente($this->ws, $this->adresses['console']))->toBeTrue()
        ->and(dePresente($this->ws, $this->adresses['site']))->toBeTrue()
        ->and(dePresente($this->ws, $this->adresses['temoin']))->toBeTrue();
});

test('L3c-0 : la demande d effacement du SITE ne retire aucune ligne d adresse partagée (rien n est effacé)', function () {
    app(SiteGdprService::class)->erase('zz-pk-efface-site', $this->adresses['site'], 'business');

    expect(dePresente($this->ws, $this->adresses['site']))->toBeTrue()
        ->and(dePresente($this->ws, $this->adresses['console']))->toBeTrue()
        ->and(dePresente($this->ws, $this->adresses['temoin']))->toBeTrue();
});

test('L3c-0 : le traitement console GARDE les empreintes du journal des fusions (rien n est efface)', function () {
    $email = 'zoe.journal@zz-efface.example.invalid';
    $garde = F::fiche($this->ws, 'ZZ Journal Efface', ['postcode' => '69060']);
    $absorbee = F::sansSiren($this->ws, 'ZZ Journal Efface', ['postcode' => '69060', 'email_generic' => $email, 'phone' => '06 12 34 56 78', 'website' => 'https://zz-journal.example.invalid']);
    F::contact($this->ws, $garde, 'Zoe', 'ZZJOURNAL', ['email' => $email, 'phone' => '06 12 34 56 78']);
    $fusion = WorkspaceContext::run($this->ws, fn (): int => app(FusionFiches::class)->fusionner(
        $this->ws,
        $garde,
        $absorbee,
        Rapprochement::NOM_CP,
        FusionFiches::MODE_MANUEL,
        null,
        null,
        'test',
    ));
    $chemins = static fn (): array => DB::table('fusions_empreintes')->where('fusion_id', $fusion)->orderBy('chemin')->pluck('chemin')->all();
    $journalAvant = (string) DB::table('fusions_fiches')->where('id', $fusion)->value('journal');
    expect($chemins())->toBe(['champs.email_generic', 'champs.phone', 'champs.website']);

    app(GdprErasureService::class)->erase($email);

    // Rien n'est supprimé : les empreintes restent, le journal n'est pas réécrit.
    expect($chemins())->toBe(['champs.email_generic', 'champs.phone', 'champs.website'])
        ->and((string) DB::table('fusions_fiches')->where('id', $fusion)->value('journal'))->toBe($journalAvant);
});
