<?php

/**
 * VERDICTS-CODE-1 — la préparation appelée avant l'inscription (T1.13, T1.14) et les liaisons du fournisseur.
 */

use App\Crm\Emails\Dns\ResolveurDns;
use App\Envoi\Verdicts\PreparationVerdicts;
use App\Envoi\Verdicts\QuotaDns;
use App\Envoi\Verdicts\VerdictAdresse;
use App\Envoi\Verdicts\VerificateurAdresse;
use App\Envoi\Verdicts\VerificateurInterne;
use App\Support\WorkspaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Support\ResolveurDnsSimule;
use Tests\TestCase;

require_once __DIR__ . '/VerdictsOutils.php';

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    Http::preventStrayRequests();
    $this->maintenant = new DateTimeImmutable('now');
});

afterEach(fn () => WorkspaceContext::clear());

test('pourContacts écrit puis rend le dernier verdict ; contact sans adresse ou supprimé absent', function () {
    $ws = vdcEspace();
    $dns = new ResolveurDnsSimule;
    vdcVerificateur($dns);
    $c1 = vdcContact($ws, 'Zoe.Martin@Societe-A.example.test');
    $c2 = vdcContact($ws, 'contact@societe-a.example.test');
    $c3 = vdcContact($ws, 'efface@societe-a.example.test', ['deleted_at' => now()]);
    $c4 = vdcContact($ws, 'zoe@societe-b.example.test', ['email' => null]);

    $res = app(PreparationVerdicts::class)->pourContacts($ws, [$c1, $c2, $c3, $c4], $this->maintenant);
    expect(array_keys($res))->toBe([$c1, $c2])
        ->and($res[$c1]?->verdict)->toBe('valide')
        ->and($res[$c2]?->verdict)->toBe('risque')
        ->and(PreparationVerdicts::permetInscription($res[$c1], $this->maintenant))->toBeTrue()
        ->and(PreparationVerdicts::permetInscription($res[$c2], $this->maintenant))->toBeFalse();
    // l'empreinte de la porte (casse mélangée) : la ligne écrite est celle que la porte relira
    expect(DB::table('envoi_verdicts_adresse')->where('workspace_id', $ws)->where('empreinte_adresse', vdcEmpreinte('zoe.martin@societe-a.example.test'))->count())->toBe(1)
        ->and(DB::table('envoi_verdicts_adresse')->where('workspace_id', $ws)->count())->toBe(2);
});

test('T1.13 un contact de l’Espace B demandé depuis A → absent du résultat, rien d’écrit', function () {
    $a = vdcEspace(false);
    $b = vdcEspace(false);
    vdcVerificateur(new ResolveurDnsSimule);
    $cb = vdcContact($b, 'zoe@societe-b.example.test');
    WorkspaceContext::set($a);

    $res = app(PreparationVerdicts::class)->pourContacts($a, [$cb], $this->maintenant);
    expect($res)->toBe([]);
    WorkspaceContext::set($b);
    expect(DB::table('envoi_verdicts_adresse')->count())->toBe(0);
});

test('pourContacts sans DNS exploitable → null et rien d’écrit', function () {
    $ws = vdcEspace();
    vdcVerificateur(new ResolveurDnsSimule([], 'mx', ['societe-a.example.test']));
    $c = vdcContact($ws, 'zoe@societe-a.example.test');
    $res = app(PreparationVerdicts::class)->pourContacts($ws, [$c], $this->maintenant);
    expect($res)->toBe([$c => null])->and(DB::table('envoi_verdicts_adresse')->count())->toBe(0);
});

test('pourContacts sans la colonne motif → RuntimeException (L4 n’inscrit rien)', function () {
    $ws = vdcEspace();
    vdcVerificateur(new ResolveurDnsSimule);
    DB::statement('ALTER TABLE envoi_verdicts_adresse DROP CONSTRAINT envoi_verdicts_adresse_motif_ck');
    DB::statement('ALTER TABLE envoi_verdicts_adresse DROP COLUMN motif'); // annulé par RefreshDatabase
    expect(fn () => app(PreparationVerdicts::class)->pourContacts($ws, [1], $this->maintenant))->toThrow(RuntimeException::class);
});

test('T1.14 permetInscription : valide 19 j vrai ; 20 j faux ; risque, null, futur faux', function () {
    $t = new DateTimeImmutable('2026-11-20 12:00:00');
    $v = fn (string $verdict, string $motif, string $decalage) => new VerdictAdresse($verdict, $motif, $t->modify($decalage));
    expect(PreparationVerdicts::permetInscription($v('valide', 'mx', '-19 days'), $t))->toBeTrue()
        ->and(PreparationVerdicts::permetInscription($v('valide', 'mx', '-20 days'), $t))->toBeFalse()
        ->and(PreparationVerdicts::permetInscription($v('risque', 'generique', '-1 day'), $t))->toBeFalse()
        ->and(PreparationVerdicts::permetInscription($v('invalide', 'syntaxe', '-1 day'), $t))->toBeFalse()
        ->and(PreparationVerdicts::permetInscription(null, $t))->toBeFalse()
        ->and(PreparationVerdicts::permetInscription($v('valide', 'mx', '+1 minute'), $t))->toBeFalse();
});

test('le fournisseur lie VerificateurAdresse au vérificateur interne et QuotaDns en « scoped »', function () {
    expect(app(VerificateurAdresse::class))->toBeInstanceOf(VerificateurInterne::class)
        ->and(app(VerificateurAdresse::class)->source())->toBe('interne-v1')
        ->and(app(QuotaDns::class))->toBe(app(QuotaDns::class));
    // en test, le résolveur lié est l'interdit : la fermeture de résolveur le reprend, rien n'est résolu
    expect(app()->bound(ResolveurDns::class))->toBeTrue();
});
