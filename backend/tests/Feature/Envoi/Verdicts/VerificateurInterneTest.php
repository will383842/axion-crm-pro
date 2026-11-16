<?php

/**
 * VERDICTS-CODE-1 — le vérificateur interne (T1.1 à T1.8, T1.10). Aucun réseau : DNS simulé.
 */

use App\Crm\Emails\CacheDomaines;
use App\Crm\Emails\Dns\ResultatDns;
use App\Envoi\Verdicts\QuotaDns;
use App\Envoi\Verdicts\VerdictAdresse;
use App\Envoi\Verdicts\VerificateurInterne;
use App\Services\Email\MxEmailValidator;
use App\Support\WorkspaceContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Support\ResolveurDnsSimule;
use Tests\TestCase;

require_once __DIR__ . '/VerdictsOutils.php';

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    Http::preventStrayRequests();
    $this->maintenant = new DateTimeImmutable('now');
});

afterEach(fn () => WorkspaceContext::clear());

function vdcUn(VerificateurInterne $v, string $ws, string $adresse, DateTimeImmutable $t): ?VerdictAdresse
{
    return $v->verifier($ws, [$adresse], $t)[vdcEmpreinte($adresse)];
}

test('T1.1 syntaxe invalide → invalide/syntaxe sans aucun appel DNS', function () {
    $ws = vdcEspace();
    $dns = new ResolveurDnsSimule;
    $v = vdcVerificateur($dns);
    foreach (['pas-une-adresse', 'zoe@@societe-a.example.test', 'zoe@localhost'] as $a) {
        $r = vdcUn($v, $ws, $a, $this->maintenant);
        expect($r?->verdict)->toBe('invalide')->and($r->motif)->toBe('syntaxe');
    }
    expect($dns->demandes)->toBe([])->and($dns->appels)->toBe([]);
});

test('T1.2 domaine jetable → invalide/jetable sans DNS', function () {
    $ws = vdcEspace();
    $dns = new ResolveurDnsSimule;
    $r = vdcUn(vdcVerificateur($dns), $ws, 'zoe@' . MxEmailValidator::DISPOSABLE_DOMAINS[0], $this->maintenant);
    expect($r?->verdict)->toBe('invalide')->and($r->motif)->toBe('jetable')->and($dns->demandes)->toBe([]);
});

test('T1.3 rebond dur connu par chacune des trois sources → invalide/rebond_dur même si le DNS dit mx', function () {
    $ws = vdcEspace();
    $autre = vdcEspace(false);
    $dns = new ResolveurDnsSimule; // tout reçoit
    $v = vdcVerificateur($dns);
    $a1 = 'zoe.a@societe-a.example.test';
    $a2 = 'zoe.b@societe-a.example.test';
    $a3 = 'zoe.c@societe-a.example.test';
    $sain = 'zoe.d@societe-a.example.test';
    $voisin = 'zoe.e@societe-a.example.test';
    $plainte = 'zoe.f@societe-a.example.test';

    // 1. liste de suppression (scope business, hard_bounce, par empreinte)
    DB::table('email_suppressions')->insert(['scope' => 'business', 'email_hash' => vdcEmpreinte($a1), 'reason' => 'hard_bounce', 'source' => 'test']);
    // une plainte n'est PAS un rebond dur ici : la porte la refuse déjà avec le bon motif
    DB::table('email_suppressions')->insert(['scope' => 'business', 'email_hash' => vdcEmpreinte($plainte), 'reason' => 'complaint', 'source' => 'test']);
    // 2. événement de rebond dur de l'espace (la clé étrangère n'est pas l'objet du test)
    DB::statement("SET LOCAL session_replication_role = 'replica'");
    $evt = fn (string $espace, string $adresse) => DB::table('envoi_inscriptions_evenements')->insert([
        'workspace_id' => $espace, 'inscription_id' => (string) Str::uuid(), 'entreprise_id' => 1,
        'empreinte_adresse' => vdcEmpreinte($adresse), 'evenement' => 'rebond_dur',
    ]);
    $evt($ws, $a2);
    $evt($autre, $voisin); // autre espace : sans effet ici
    DB::statement("SET LOCAL session_replication_role = 'origin'");
    // 3. contact de l'espace dont la fiche dit invalide
    vdcContact($ws, $a3, ['email_status' => 'invalid']);
    vdcContact($autre, $sain, ['email_status' => 'invalid']); // autre espace : sans effet

    $res = $v->verifier($ws, [$a1, $a2, $a3, $sain, $voisin, $plainte], $this->maintenant);
    foreach ([$a1, $a2, $a3] as $a) {
        expect($res[vdcEmpreinte($a)]?->verdict)->toBe('invalide')->and($res[vdcEmpreinte($a)]->motif)->toBe('rebond_dur');
    }
    foreach ([$sain, $voisin, $plainte] as $a) {
        expect($res[vdcEmpreinte($a)]?->verdict)->toBe('valide');
    }
});

test('T1.4 rôle technique → invalide ; boîte générique → risque/generique', function () {
    $ws = vdcEspace();
    $v = vdcVerificateur(new ResolveurDnsSimule);
    foreach (['postmaster@societe-a.example.test', 'no-reply+x@societe-a.example.test', 'Abuse@societe-a.example.test'] as $a) {
        $r = vdcUn($v, $ws, $a, $this->maintenant);
        expect($r?->verdict)->toBe('invalide')->and($r->motif)->toBe('role_technique');
    }
    $r = vdcUn($v, $ws, 'contact@societe-a.example.test', $this->maintenant);
    expect($r?->verdict)->toBe('risque')->and($r->motif)->toBe('generique');
    $r = vdcUn($v, $ws, 'zoe.martin@societe-a.example.test', $this->maintenant);
    expect($r?->verdict)->toBe('valide');
});

test('T1.5 DNS mx → valide/mx daté du cache ; inexistant (témoin mx) → invalide/inexistant', function () {
    $ws = vdcEspace();
    $dns = new ResolveurDnsSimule(['societe-b.example.test' => ResultatDns::INEXISTANT]);
    $v = vdcVerificateur($dns);
    // un domaine déjà en cache depuis 6 jours : la date du verdict est CELLE du cache, jamais « maintenant »
    $il_y_a_6j = CarbonImmutable::now()->subDays(6)->startOfSecond();
    CacheDomaines::enregistrer(['societe-a.example.test' => new ResultatDns('mx', 'mx.societe-a.example.test')], 'test', $il_y_a_6j);

    $r = vdcUn($v, $ws, 'zoe@societe-a.example.test', $this->maintenant);
    expect($r?->verdict)->toBe('valide')->and($r->motif)->toBe('mx')
        ->and($r->verifieLe->getTimestamp())->toBe($il_y_a_6j->getTimestamp());

    $r = vdcUn($v, $ws, 'zoe@societe-b.example.test', $this->maintenant);
    expect($r?->verdict)->toBe('invalide')->and($r->motif)->toBe('inexistant')
        ->and($dns->questionsTemoin)->toBeGreaterThan(0);
});

test('T1.6 DNS indéterminé ou en panne → null, aucune ligne', function () {
    $ws = vdcEspace();
    $dns = new ResolveurDnsSimule(['indet.example.test' => ResultatDns::INDETERMINE], ResultatDns::MX, ['panne.example.test']);
    $v = vdcVerificateur($dns);
    $res = $v->verifier($ws, ['zoe@indet.example.test', 'zoe@panne.example.test', 'zoe@ok.example.test'], $this->maintenant);
    expect($res[vdcEmpreinte('zoe@indet.example.test')])->toBeNull()
        ->and($res[vdcEmpreinte('zoe@panne.example.test')])->toBeNull()
        ->and($res[vdcEmpreinte('zoe@ok.example.test')]?->verdict)->toBe('valide')
        ->and(vdcLignes($ws))->toBe([]);
});

test('T1.7 témoin qui ne rend pas mx → aucun verdict négatif, positifs conservés, rien en cache', function () {
    $ws = vdcEspace();
    $dns = new ResolveurDnsSimule(['mort.example.test' => ResultatDns::INEXISTANT]);
    $dns->verdictTemoin = ResultatDns::INEXISTANT;
    $res = vdcVerificateur($dns)->verifier($ws, ['zoe@mort.example.test', 'zoe@vivant.example.test'], $this->maintenant);
    expect($res[vdcEmpreinte('zoe@mort.example.test')])->toBeNull()
        ->and($res[vdcEmpreinte('zoe@vivant.example.test')]?->verdict)->toBe('valide')
        ->and(DB::table('email_domaines')->where('domaine', 'mort.example.test')->count())->toBe(0);
});

test('T1.8 cache frais (< 7 j) → aucune résolution ; vieux de 8 j → résolu de nouveau', function () {
    $ws = vdcEspace();
    $dns = new ResolveurDnsSimule;
    $v = vdcVerificateur($dns);
    CacheDomaines::enregistrer(['frais.example.test' => new ResultatDns('mx', 'mx.frais.example.test')], 'test', CarbonImmutable::now()->subDays(2));
    CacheDomaines::enregistrer(['vieux.example.test' => new ResultatDns('mx', 'mx.vieux.example.test')], 'test', CarbonImmutable::now()->subDays(8));

    $v->verifier($ws, ['zoe@frais.example.test', 'zoe@vieux.example.test'], $this->maintenant);
    expect($dns->demandes)->toBe(['vieux.example.test']);
});

test('T1.10 quota : 201 domaines → 200 résolus, 1 sans réponse ; cache qui lève → rien résolu', function () {
    $ws = vdcEspace();
    $dns = new ResolveurDnsSimule;
    $adresses = array_map(fn (int $i): string => "zoe@d{$i}.example.test", range(1, 201));
    $res = vdcVerificateur($dns)->verifier($ws, $adresses, $this->maintenant);
    expect(count($dns->demandes))->toBe(200)
        ->and(count(array_filter($res, fn ($r) => $r === null)))->toBe(1);

    $dns2 = new ResolveurDnsSimule;
    Cache::shouldReceive('add')->andThrow(new RuntimeException('cache indisponible'));
    Cache::shouldReceive('increment')->andThrow(new RuntimeException('cache indisponible'));
    Cache::shouldReceive('get')->andThrow(new RuntimeException('cache indisponible'));
    $res2 = vdcVerificateur($dns2)->verifier($ws, ['zoe@e1.example.test'], $this->maintenant);
    expect($dns2->demandes)->toBe([])->and($res2[vdcEmpreinte('zoe@e1.example.test')])->toBeNull();
});

test('QuotaDns : plafond par jour partagé entre instances', function () {
    $q1 = new QuotaDns(10, 12);
    $q2 = new QuotaDns(10, 12);
    $t = new DateTimeImmutable('now');
    $d = fn (string $p, int $n): array => array_map(fn (int $i): string => "$p$i.example.test", range(1, $n));
    expect(count($q1->accorder($d('a', 10), $t)))->toBe(10)
        ->and(count($q1->accorder($d('b', 5), $t)))->toBe(0) // plafond par passage atteint
        ->and(count($q2->accorder($d('c', 10), $t)))->toBe(2); // il ne reste que 2 pour la journée
});
