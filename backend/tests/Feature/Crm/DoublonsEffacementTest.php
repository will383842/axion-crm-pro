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

test('l effacement par la CONSOLE retire la ligne de l adresse effacée, et d elle seule', function () {
    app(GdprErasureService::class)->erase($this->adresses['console']);

    expect(dePresente($this->ws, $this->adresses['console']))->toBeFalse()
        ->and(dePresente($this->ws, $this->adresses['site']))->toBeTrue()
        ->and(dePresente($this->ws, $this->adresses['temoin']))->toBeTrue();
});

test('l effacement par le SITE retire la ligne de l adresse effacée, et d elle seule', function () {
    app(SiteGdprService::class)->erase('zz-pk-efface-site', $this->adresses['site'], 'business');

    expect(dePresente($this->ws, $this->adresses['site']))->toBeFalse()
        ->and(dePresente($this->ws, $this->adresses['console']))->toBeTrue()
        ->and(dePresente($this->ws, $this->adresses['temoin']))->toBeTrue();
});

test('l effacement retire AUSSI du journal des fusions les empreintes de l adresse et du mobile effacés', function () {
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
    $champs = static function () use ($fusion): array {
        $cles = array_keys((array) json_decode((string) DB::table('fusions_fiches')->where('id', $fusion)->value('journal'), true)['champs']);
        sort($cles);

        return $cles;
    };
    expect($champs())->toBe(['email_generic', 'phone', 'website']);

    app(GdprErasureService::class)->erase($email);

    $journal = (string) DB::table('fusions_fiches')->where('id', $fusion)->value('journal');
    // TÉMOIN : le site, qui n'est pas une donnée de la personne, reste.
    expect($champs())->toBe(['website'])
        ->and($journal)->not->toContain(F::empreinteAdresse($email));
});
