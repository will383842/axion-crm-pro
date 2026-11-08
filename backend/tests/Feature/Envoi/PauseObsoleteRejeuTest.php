<?php

use App\Envoi\Serveur\JournalCommandes;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

// Audit K9 (5) : commande_id neuf à chaque appel + rejeu des intentions non acquittées => une ancienne `pause`
// (passerelle injoignable) était rejouée APRÈS une `reprise` réussie : le serveur repartait en pause à l'insu du CRM.

function wsRejeu(): string
{
    $id = (string) Str::uuid();
    DB::statement("INSERT INTO workspaces (id, name, slug, created_at, updated_at) VALUES (?, 'w', ?, now(), now())", [$id, 'w-' . substr($id, 0, 8)]);

    return $id;
}

test('une pause non acquittée n’est PAS rejouée après une reprise acquittée', function () {
    $ws = wsRejeu();
    $j = new JournalCommandes;
    $j->enregistrer($ws, 'cmd-pause-1', 'pause', 'src1');     // passerelle injoignable : jamais acquittée
    $j->enregistrer($ws, 'cmd-reprise-1', 'reprise', 'src1');
    $j->acquitter($ws, 'cmd-reprise-1');
    expect(array_column($j->nonAcquittees($ws, 50), 'commande_id'))->toBe([]);
});

test('une reprise non acquittée n’est PAS rejouée après une pause acquittée', function () {
    $ws = wsRejeu();
    $j = new JournalCommandes;
    $j->enregistrer($ws, 'cmd-reprise-1', 'reprise', 'src1');
    $j->enregistrer($ws, 'cmd-pause-2', 'pause', 'src1');
    $j->acquitter($ws, 'cmd-pause-2');
    expect(array_column($j->nonAcquittees($ws, 50), 'commande_id'))->toBe([]);
});

test('la dernière intention reste rejouable ; un retrait n’est jamais écarté', function () {
    $ws = wsRejeu();
    $j = new JournalCommandes;
    $j->enregistrer($ws, 'cmd-reprise-1', 'reprise', 'src1');
    $j->acquitter($ws, 'cmd-reprise-1');
    $j->enregistrer($ws, 'cmd-retrait-1', 'retrait', 'src1');
    $j->enregistrer($ws, 'cmd-pause-2', 'pause', 'src1');     // postérieure à la reprise : toujours à rejouer
    expect(array_column($j->nonAcquittees($ws, 50), 'commande_id'))->toBe(['cmd-retrait-1', 'cmd-pause-2']);
});
