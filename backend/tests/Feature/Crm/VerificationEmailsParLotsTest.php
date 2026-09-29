<?php

/**
 * LA VÉRIFICATION DES E-MAILS PAR LOTS — verrous bornés, reprise.
 *
 * SANS `RefreshDatabase` : sous elle, toute la commande vivrait dans la
 * transaction du test, et un lot « validé » ne rendrait rien (même raison que
 * `FederationsImportParPaquetsTest`, #256). Ici chaque lot est une VRAIE
 * transaction. Espace propre au test, nettoyé à la fin ; chaîne d'audit
 * simulée ; DNS simulé. Fixtures FICTIVES.
 */

use App\Crm\Emails\Dns\ResolveurDns;
use App\Crm\Emails\Dns\ResultatDns;
use App\Services\Audit\AuditHashChain;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\ResolveurDnsSimule;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    $this->mock(AuditHashChain::class)->shouldReceive('record')->andReturn(1);

    $this->espace = (string) Str::uuid();
    $this->slug = 'zz-vem-lots-' . substr(str_replace('-', '', $this->espace), 0, 8);
    DB::table('workspaces')->insert([
        'id' => $this->espace, 'slug' => $this->slug, 'name' => 'ZZ vérification par lots', 'settings' => '{}',
        'cost_cap_eur' => 100, 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
    ]);
    config(['crm.ingest.business_workspace' => $this->slug]);
    $this->fiche = (int) DB::table('companies')->insertGetId([
        'workspace_id' => $this->espace, 'siren' => (string) random_int(900000000, 999999999),
        'denomination' => 'ZZ LOTS', 'created_at' => now(), 'updated_at' => now(),
    ]);
});

afterEach(function () {
    while (DB::transactionLevel() > 0) {
        DB::rollBack();
    }
    $espace = $this->espace;
    DB::table('contacts')->where('workspace_id', $espace)->delete();
    DB::table('companies')->where('workspace_id', $espace)->delete();
    DB::table('email_domaines')->where('domaine', 'like', 'zz-lots-%')->delete();
    DB::table('workspaces')->where('id', $espace)->delete();
});

/** `$n` personnes, chacune sur SON domaine (`zz-lots-<prefixe>-<i>.example`). */
function vemlContacts(object $t, string $prefixe, int $n): void
{
    $lignes = [];
    for ($i = 1; $i <= $n; $i++) {
        $lignes[] = [
            'workspace_id' => $t->espace, 'company_id' => $t->fiche, 'last_name' => "ZZ {$prefixe} {$i}",
            'email' => "p{$i}@zz-lots-{$prefixe}-{$i}.example", 'created_at' => now(), 'updated_at' => now(),
        ];
    }
    DB::table('contacts')->insert($lignes);
}

/**
 * @param  array<string, mixed>  $options
 * @return array{code: int, sortie: string, verrous: int, tx: int}
 */
function vemlVerifier(array $options): array
{
    $code = Artisan::call('crm:emails:verifier', ['--source' => 'contacts'] + $options);
    $sortie = Artisan::output();
    preg_match('/Verrous tenus au plus en fin de lot : (\d+) \(dont (\d+) d/', $sortie, $m);

    return ['code' => $code, 'sortie' => $sortie, 'verrous' => (int) ($m[1] ?? -1), 'tx' => (int) ($m[2] ?? -1)];
}

test('les verrous tenus ne dependent ni du nombre de lots ni de leur taille', function () {
    app()->instance(ResolveurDns::class, new ResolveurDnsSimule([], ResultatDns::MX));
    vemlContacts($this, 'a', 12);
    $petit = vemlVerifier(['--lot' => '4']);

    DB::table('contacts')->where('workspace_id', $this->espace)->delete();
    vemlContacts($this, 'b', 60);
    $grandsLots = vemlVerifier(['--lot' => '60']);

    DB::table('contacts')->where('workspace_id', $this->espace)->delete();
    vemlContacts($this, 'c', 60);
    $nombreuxLots = vemlVerifier(['--lot' => '4']);

    expect($petit['code'])->toBe(0)
        ->and($petit['verrous'])->toBeGreaterThan(0)
        ->and(DB::table('contacts')->where('workspace_id', $this->espace)->where('email_status', 'valid')->count())->toBe(60)
        // Une transaction par lot, sans point de sauvegarde : UN identifiant
        // de transaction, quel que soit le lot.
        ->and($petit['tx'])->toBe(1)
        ->and($grandsLots['tx'])->toBe(1)
        ->and($nombreuxLots['tx'])->toBe(1)
        ->and($grandsLots['verrous'])->toBeLessThanOrEqual($petit['verrous'] + 2)
        ->and($nombreuxLots['verrous'])->toBeLessThanOrEqual($petit['verrous'] + 2);
});

test('une verification INTERROMPUE garde ses lots valides, dit ou reprendre, et reprend sans rien refaire', function () {
    vemlContacts($this, 'd', 10);
    // Panne DNS au 3e lot (contacts 7 à 9, lots de 3).
    $dns = new ResolveurDnsSimule([], ResultatDns::MX, ['zz-lots-d-8.example']);
    app()->instance(ResolveurDns::class, $dns);

    $interrompu = vemlVerifier(['--lot' => '3']);
    $ids = DB::table('contacts')->where('workspace_id', $this->espace)->orderBy('id')->pluck('id')->all();

    expect($interrompu['code'])->toBe(1)
        ->and(DB::transactionLevel())->toBe(0)
        ->and(DB::table('contacts')->where('workspace_id', $this->espace)->where('email_status', 'valid')->count())->toBe(6)
        ->and($interrompu['sortie'])->toContain('Reprendre avec : --source=contacts --depuis-id=' . $ids[5]);

    $reprise = new ResolveurDnsSimule([], ResultatDns::MX);
    app()->instance(ResolveurDns::class, $reprise);
    $fin = vemlVerifier(['--lot' => '3', '--depuis-id' => (string) $ids[5]]);

    expect($fin['code'])->toBe(0)
        ->and(DB::table('contacts')->where('workspace_id', $this->espace)->where('email_status', 'valid')->count())->toBe(10)
        // Rien n'est refait : seuls les quatre derniers domaines sont demandés.
        ->and($reprise->demandes)->toBe(['zz-lots-d-7.example', 'zz-lots-d-8.example', 'zz-lots-d-9.example', 'zz-lots-d-10.example']);
});
