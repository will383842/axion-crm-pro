<?php

/**
 * L2 (PLAN ADRESSES GÉNÉRALES, #680) — le domaine d'une adresse générale passe AUSSI par le DNS.
 * « Ne reçoit pas » → invalide/<motif DNS> ; sinon (reçoit, panne, indéterminé, quota épuisé) → risque/generique,
 * jamais valide. Aucun réseau : DNS simulé. Fixtures fictives (*.example.test).
 */

use App\Crm\Emails\Dns\ResultatDns;
use App\Envoi\Verdicts\QuotaDns;
use App\Envoi\Verdicts\VerificateurInterne;
use App\Support\WorkspaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Support\ResolveurDnsSimule;
use Tests\TestCase;

require_once __DIR__ . '/Verdicts/VerdictsOutils.php';

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    Http::preventStrayRequests();
    $this->maintenant = new DateTimeImmutable('now');
});

afterEach(fn () => WorkspaceContext::clear());

function vgdUn(VerificateurInterne $v, string $ws, string $adresse, DateTimeImmutable $t): mixed
{
    return $v->verifier($ws, [$adresse], $t)[vdcEmpreinte($adresse)];
}

test('contact@ d\'un domaine sans courrier → invalide avec le motif DNS', function () {
    $ws = vdcEspace();
    $dns = new ResolveurDnsSimule([
        'sans.example.test' => ResultatDns::SANS_COURRIER,
        'mort.example.test' => ResultatDns::INEXISTANT,
        'nul.example.test' => ResultatDns::MX_NUL,
    ]);
    $v = vdcVerificateur($dns);
    foreach (['sans' => 'sans_courrier', 'mort' => 'inexistant', 'nul' => 'mx_nul'] as $d => $motif) {
        $r = vgdUn($v, $ws, "contact@{$d}.example.test", $this->maintenant);
        expect($r?->verdict)->toBe('invalide')->and($r->motif)->toBe($motif);
    }
    expect($dns->demandes)->toContain('sans.example.test');
});

test('contact@ d\'un domaine qui reçoit → risque/generique daté de maintenant, jamais valide', function () {
    $ws = vdcEspace();
    $dns = new ResolveurDnsSimule;
    $r = vgdUn(vdcVerificateur($dns), $ws, 'info@societe-a.example.test', $this->maintenant);
    expect($r?->verdict)->toBe('risque')->and($r->motif)->toBe('generique')
        ->and($r->verifieLe->getTimestamp())->toBe($this->maintenant->getTimestamp())
        ->and($dns->demandes)->toBe(['societe-a.example.test']);
});

test('DNS en panne, indéterminé ou témoin défaillant → risque/generique (jamais valide, jamais invalide)', function () {
    $ws = vdcEspace();
    $dns = new ResolveurDnsSimule(['indet.example.test' => ResultatDns::INDETERMINE], ResultatDns::MX, ['panne.example.test']);
    $res = vdcVerificateur($dns)->verifier($ws, ['contact@indet.example.test', 'contact@panne.example.test'], $this->maintenant);
    foreach (['indet', 'panne'] as $d) {
        $r = $res[vdcEmpreinte("contact@{$d}.example.test")];
        expect($r?->verdict)->toBe('risque')->and($r->motif)->toBe('generique');
    }

    // Témoin qui ne reçoit pas : le négatif n'est pas retenu, donc pas d'invalide.
    $dns2 = new ResolveurDnsSimule(['mort.example.test' => ResultatDns::INEXISTANT]);
    $dns2->verdictTemoin = ResultatDns::INEXISTANT;
    $r = vgdUn(vdcVerificateur($dns2), $ws, 'contact@mort.example.test', $this->maintenant);
    expect($r?->verdict)->toBe('risque')->and($r->motif)->toBe('generique');
});

test('le quota DNS est respecté : 201 génériques → 200 résolus, tous restent risque/generique', function () {
    $ws = vdcEspace();
    $dns = new ResolveurDnsSimule;
    $adresses = array_map(fn (int $i): string => "contact@g{$i}.example.test", range(1, 201));
    $res = vdcVerificateur($dns)->verifier($ws, $adresses, $this->maintenant);
    expect(count($dns->demandes))->toBe(200)
        ->and(count(array_filter($res, fn ($r) => $r?->verdict === 'risque' && $r->motif === 'generique')))->toBe(201);

    // Quota à zéro : aucune résolution, toujours risque/generique.
    $dns2 = new ResolveurDnsSimule;
    $r = vgdUn(vdcVerificateur($dns2, new QuotaDns(0, 0)), $ws, 'contact@h1.example.test', $this->maintenant);
    expect($dns2->demandes)->toBe([])->and($r?->verdict)->toBe('risque');
});

test('non-régression : rôle technique, nominatif, syntaxe inchangés ; un « valide » ne sort jamais d\'un générique', function () {
    $ws = vdcEspace();
    $dns = new ResolveurDnsSimule;
    $v = vdcVerificateur($dns);
    $r = vgdUn($v, $ws, 'postmaster@societe-a.example.test', $this->maintenant);
    expect($r?->verdict)->toBe('invalide')->and($r->motif)->toBe('role_technique');
    expect($dns->demandes)->toBe([]);
    $r = vgdUn($v, $ws, 'zoe.martin@societe-a.example.test', $this->maintenant);
    expect($r?->verdict)->toBe('valide');
});
