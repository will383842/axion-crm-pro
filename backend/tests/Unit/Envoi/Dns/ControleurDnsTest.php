<?php

use App\Envoi\Dns\ConfigurationDomaine;
use App\Envoi\Dns\ControleurDns;
use App\Envoi\Dns\PlanDns;
use App\Envoi\Dns\RapportControle;
use Tests\Unit\Envoi\Dns\FauxResolveurDns;

require_once __DIR__ . '/FauxResolveurDns.php';

function cfg(): ConfigurationDomaine
{
    return new ConfigurationDomaine('envoi.example.test', 'sel1', 'mx.envoi.example.test', '192.0.2.10');
}

function statut(RapportControle $r, string $code): string
{
    foreach ($r->resultats as $p) {
        if ($p->code === $code) {
            return $p->statut;
        }
    }

    return 'absent';
}

test('domaine conforme : 20 points, tous ok, prêt, pas de pause', function () {
    $r = (new ControleurDns(FauxResolveurDns::conforme()))->controler(cfg());
    expect($r->resultats)->toHaveCount(20)
        ->and(array_map(fn ($p) => $p->code, $r->resultats))->toBe(PlanDns::codesPointsDeControle())
        ->and(array_filter($r->resultats, fn ($p) => $p->statut !== 'ok'))->toBe([])
        ->and($r->pret())->toBeTrue()
        ->and($r->pause())->toBeFalse();
});

test('PTR incohérent avec le HELO : domaine pas prêt, sans pause', function () {
    $f = FauxResolveurDns::conforme();
    $f->ptr['192.0.2.10'] = ['autre.envoi.example.test'];
    $r = (new ControleurDns($f))->controler(cfg());
    expect(statut($r, 'ptr_egal_helo'))->toBe('ko')
        ->and($r->pret())->toBeFalse()
        ->and($r->pause())->toBeFalse();
});

test('PTR absent : pas prêt', function () {
    $f = FauxResolveurDns::conforme();
    $f->ptr = [];
    $r = (new ControleurDns($f))->controler(cfg());
    expect(statut($r, 'ptr_existe'))->toBe('ko')->and($r->pret())->toBeFalse();
});

test('HELO qui ne résout pas vers l IP : pas prêt', function () {
    $f = FauxResolveurDns::conforme();
    $f->a = ['mx.envoi.example.test' => ['192.0.2.77']];
    $r = (new ControleurDns($f))->controler(cfg());
    expect(statut($r, 'helo_a_vers_ip'))->toBe('ko')->and($r->pret())->toBeFalse();
});

test('DMARC absent : pause du domaine (et pas prêt)', function () {
    $f = FauxResolveurDns::conforme();
    unset($f->txt['_dmarc.envoi.example.test']);
    $r = (new ControleurDns($f))->controler(cfg());
    expect(statut($r, 'dmarc_present'))->toBe('ko')
        ->and($r->pause())->toBeTrue()
        ->and($r->pret())->toBeFalse();
});

test('deux SPF : ko ; SPF sans -all ou ~all : ko ; IP non autorisée : ko', function () {
    $f = FauxResolveurDns::conforme();
    $f->txt['envoi.example.test'] = ['v=spf1 ip4:192.0.2.10 -all', 'v=spf1 -all'];
    expect(statut((new ControleurDns($f))->controler(cfg()), 'spf_unique'))->toBe('ko');

    $f->txt['envoi.example.test'] = ['v=spf1 ip4:192.0.2.10 +all'];
    expect(statut((new ControleurDns($f))->controler(cfg()), 'spf_se_termine_par_all'))->toBe('ko');

    $f->txt['envoi.example.test'] = ['v=spf1 ip4:192.0.2.200 -all'];
    expect(statut((new ControleurDns($f))->controler(cfg()), 'spf_autorise_ip'))->toBe('ko');

    $f->txt['envoi.example.test'] = ['v=spf1 ip4:192.0.2.0/24 ~all'];
    $r = (new ControleurDns($f))->controler(cfg());
    expect(statut($r, 'spf_autorise_ip'))->toBe('ok')->and(statut($r, 'spf_se_termine_par_all'))->toBe('ok');
});

test('SPF : plus de 10 résolutions (include suivis) : ko', function () {
    $f = FauxResolveurDns::conforme();
    $inc = [];
    for ($i = 1; $i <= 11; $i++) {
        $inc[] = "include:i$i.example.test";
        $f->txt["i$i.example.test"] = ['v=spf1 -all'];
    }
    $f->txt['envoi.example.test'] = ['v=spf1 ip4:192.0.2.10 ' . implode(' ', $inc) . ' -all'];
    expect(statut((new ControleurDns($f))->controler(cfg()), 'spf_10_resolutions_max'))->toBe('ko');
});

test('boucle d include SPF : pas de boucle infinie', function () {
    $f = FauxResolveurDns::conforme();
    $f->txt['envoi.example.test'] = ['v=spf1 include:envoi.example.test -all'];
    expect(fn () => (new ControleurDns($f))->controler(cfg()))->not->toThrow(Throwable::class);
});

test('DKIM : clé révoquée (p vide) ko ; clé courte : avertissement non bloquant', function () {
    $f = FauxResolveurDns::conforme();
    $f->txt['sel1._domainkey.envoi.example.test'] = ['v=DKIM1; k=rsa; p='];
    $r = (new ControleurDns($f))->controler(cfg());
    expect(statut($r, 'dkim_cle_publique'))->toBe('ko')->and($r->pret())->toBeFalse();

    $f->txt['sel1._domainkey.envoi.example.test'] = ['v=DKIM1; k=rsa; p=' . base64_encode(str_repeat('x', 162))];
    $r = (new ControleurDns($f))->controler(cfg());
    expect(statut($r, 'dkim_cle_2048'))->toBe('avert')->and($r->pret())->toBeTrue();
});

test('MTA-STS et TLS-RPT absents : avertissements, le domaine reste prêt', function () {
    $f = FauxResolveurDns::conforme();
    unset($f->txt['_mta-sts.envoi.example.test'], $f->txt['_smtp._tls.envoi.example.test']);
    $f->politiques = [];
    $r = (new ControleurDns($f))->controler(cfg());
    expect(statut($r, 'mta_sts_enregistrement'))->toBe('avert')
        ->and(statut($r, 'mta_sts_politique'))->toBe('avert')
        ->and(statut($r, 'tls_rpt'))->toBe('avert')
        ->and($r->pret())->toBeTrue();
});

test('MTA-STS en enforce : avertissement (le plan impose testing)', function () {
    $f = FauxResolveurDns::conforme();
    $f->politiques['envoi.example.test'] = "version: STSv1\nmode: enforce\nmx: mx.envoi.example.test\n";
    expect(statut((new ControleurDns($f))->controler(cfg()), 'mta_sts_mode_testing'))->toBe('avert');
});

test('DMARC : politique invalide ou rua absent signalés', function () {
    $f = FauxResolveurDns::conforme();
    $f->txt['_dmarc.envoi.example.test'] = ['v=DMARC1; p=n-importe-quoi'];
    $r = (new ControleurDns($f))->controler(cfg());
    expect(statut($r, 'dmarc_politique'))->toBe('ko')->and(statut($r, 'dmarc_rua'))->toBe('avert');
});

test('aucune requête hors DNS/MTA-STS : le contrôle ne fait que lire', function () {
    $f = FauxResolveurDns::conforme();
    (new ControleurDns($f))->controler(cfg());
    expect($f->requetes)->not->toBeEmpty();
    foreach ($f->requetes as $q) {
        expect($q)->toMatch('/^(TXT|A|PTR|STS) /');
    }
});

test('DMARC : repli sur le domaine parent quand le sous-domaine n en publie pas', function () {
    $f = FauxResolveurDns::conforme('example.test', 'sel1', 'mx.envoi.example.test');
    $f->txt['_dmarc.example.test'] = ['v=DMARC1; p=reject; rua=mailto:d@example.test'];
    $f->txt['sel1._domainkey.envoi.example.test'] = $f->txt['sel1._domainkey.example.test'];
    $f->txt['envoi.example.test'] = ['v=spf1 ip4:192.0.2.10 -all'];
    $f->txt['_mta-sts.envoi.example.test'] = ['v=STSv1; id=1'];
    $r = (new ControleurDns($f))->controler(new ConfigurationDomaine('envoi.example.test', 'sel1', 'mx.envoi.example.test', '192.0.2.10'));
    expect(statut($r, 'dmarc_present'))->toBe('ok')->and(statut($r, 'dmarc_politique'))->toBe('ok');
});

test('DMARC : p = avec espaces toléré', function () {
    $f = FauxResolveurDns::conforme();
    $f->txt['_dmarc.envoi.example.test'] = ['v=DMARC1 ; p = quarantine ; rua = mailto:d@envoi.example.test'];
    $r = (new ControleurDns($f))->controler(new ConfigurationDomaine('envoi.example.test', 'sel1', 'mx.envoi.example.test', '192.0.2.10'));
    expect(statut($r, 'dmarc_politique'))->toBe('ok')->and(statut($r, 'dmarc_rua'))->toBe('ok');
});

test('DKIM : v=DKIM1 facultatif', function () {
    $f = FauxResolveurDns::conforme();
    $f->txt['sel1._domainkey.envoi.example.test'] = ['k=rsa; p=' . base64_encode(str_repeat('x', 294))];
    $r = (new ControleurDns($f))->controler(new ConfigurationDomaine('envoi.example.test', 'sel1', 'mx.envoi.example.test', '192.0.2.10'));
    expect(statut($r, 'dkim_present'))->toBe('ok')->and(statut($r, 'dkim_cle_2048'))->toBe('ok');
});
