<?php

/**
 * DOUBLONS — UNE FUSION, UNE TRANSACTION : le nombre de verrous ne dépend pas
 * du nombre de paires (chantier 5).
 *
 * #256 a débordé `max_locks_per_transaction` en production : dans une seule
 * transaction, chaque point de sauvegarde qui écrit garde le verrou de son
 * identifiant de transaction jusqu'au bout. `crm:doublons:fusionner` ouvre
 * donc UNE transaction par fusion, jamais une pour toutes.
 *
 * Ces tests tournent SANS `RefreshDatabase` : sous elle, toute la commande
 * vivrait dans la transaction du test et la mesure serait fausse. Espace
 * propre au test, nettoyé à la fin ; la chaîne d'audit est simulée.
 *
 * Fixtures FICTIVES (dépôt public).
 */

use App\Crm\Doublons\FusionFiches;
use App\Crm\Doublons\Rapprochement;
use App\Services\Audit\AuditHashChain;
use App\Support\WorkspaceContext;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Support\DoublonsFixtures as F;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    $this->mock(AuditHashChain::class)->shouldReceive('record')->andReturn(1);
    $this->ws = F::espace('zz-doublons-lots');
});

afterEach(function () {
    while (DB::transactionLevel() > 0) {
        DB::rollBack();
    }
    $ws = $this->ws;
    DB::transaction(function () use ($ws): void {
        // Levées volontaires documentées : fiches absorbées et protégées.
        DB::statement("SET LOCAL app.autoriser_suppression_absorbee = 'on'");
        DB::statement("SET LOCAL app.autoriser_suppression_protegee = 'on'");
        foreach (['fusions_fiches', 'duplicate_flags', 'company_tag', 'contacts', 'activities'] as $table) {
            DB::table($table)->where('workspace_id', $ws)->delete();
        }
        DB::table('companies')->where('workspace_id', $ws)->delete();
        DB::table('contacts_retires')->where('workspace_id', $ws)->delete();
        DB::table('tags')->where('workspace_id', $ws)->delete();
        DB::table('workspaces')->where('id', $ws)->delete();
    });
});

/** `$n` paires CERTAINES, chacune avec une personne et une étiquette à rattacher. */
function dlPaires(string $ws, int $n, string $prefixe): void
{
    $tag = F::tag($ws, 'zz-lot');
    for ($i = 1; $i <= $n; $i++) {
        $nom = "ZZ Lot {$prefixe} {$i}";
        $site = "https://zz-lot-{$prefixe}-{$i}.example.invalid";
        $garde = F::fiche($ws, $nom, ['postcode' => '69100', 'website' => $site]);
        $absorbee = F::sansSiren($ws, $nom, ['postcode' => '69100', 'website' => $site]);
        F::contact($ws, $absorbee, 'Zoe', "ZZLOT{$prefixe}{$i}");
        F::lier($ws, $absorbee, $tag);
        DB::table('duplicate_flags')->insert([
            'workspace_id' => $ws, 'entity_type' => 'company', 'entity_a_id' => $garde, 'entity_b_id' => $absorbee,
            'similarity' => 0.99, 'motif' => Rapprochement::NOM_CP_SITE, 'fusion_auto' => true,
        ]);
    }
}

/** @return array{sortie: string, verrous: int, tx: int} */
function dlFusionner(string $ws, array $options = []): array
{
    Artisan::call('crm:doublons:fusionner', ['--workspace' => $ws] + $options);
    $sortie = Artisan::output();
    preg_match('/Verrous tenus au plus pendant une fusion : (\d+) \(dont (\d+) d/', $sortie, $m);
    expect($m)->not->toBeEmpty('La sortie ne dit pas les verrous tenus.');

    return ['sortie' => $sortie, 'verrous' => (int) $m[1], 'tx' => (int) $m[2]];
}

test('les verrous tenus sont ceux d UNE fusion, quel que soit le nombre de paires — en réel comme à blanc', function () {
    dlPaires($this->ws, 2, 'a');
    $petit = dlFusionner($this->ws);
    dlPaires($this->ws, 10, 'b');
    $blanc = dlFusionner($this->ws, ['--dry-run' => true]);
    $grand = dlFusionner($this->ws);

    expect(F::compteur($petit['sortie'], 'fusionnees'))->toBe(2)
        ->and(F::compteur($grand['sortie'], 'fusionnees'))->toBe(10)
        ->and($petit['tx'])->toBeGreaterThan(0)
        // Cinq fois plus de paires, pas un verrou de plus.
        ->and($grand['tx'])->toBeLessThanOrEqual($petit['tx'] + 2)
        ->and($grand['verrous'])->toBeLessThanOrEqual($petit['verrous'] + 8)
        ->and($blanc['tx'])->toBeLessThanOrEqual($petit['tx'] + 2)
        ->and(DB::transactionLevel())->toBe(0);
});

test('TÉMOIN — la sonde voit grandir les verrous quand les fusions partagent UNE transaction', function () {
    dlPaires($this->ws, 10, 'c');
    $seule = new FusionFiches(app(AuditHashChain::class));
    $paire = DB::table('duplicate_flags')->where('workspace_id', $this->ws)->orderBy('id')->first();
    WorkspaceContext::run($this->ws, fn () => $seule->fusionner($this->ws, (int) $paire->entity_a_id, (int) $paire->entity_b_id, (string) $paire->motif, FusionFiches::MODE_AUTO, (int) $paire->id, null, 'test', true));

    // La structure qu'on s'interdit : toutes les fusions dans une transaction.
    $ensemble = new FusionFiches(app(AuditHashChain::class));
    DB::beginTransaction();
    try {
        WorkspaceContext::run($this->ws, function () use ($ensemble): void {
            foreach (DB::table('duplicate_flags')->where('workspace_id', $this->ws)->orderBy('id')->get() as $p) {
                $ensemble->fusionner($this->ws, (int) $p->entity_a_id, (int) $p->entity_b_id, (string) $p->motif, FusionFiches::MODE_AUTO, (int) $p->id, null, 'test');
            }
        });
    } finally {
        DB::rollBack();
    }

    expect($seule->verrousTxMax)->toBeGreaterThan(0)
        ->and($ensemble->verrousTxMax)->toBeGreaterThan($seule->verrousTxMax * 3);
});
