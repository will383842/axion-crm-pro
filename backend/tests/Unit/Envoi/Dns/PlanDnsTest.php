<?php

use App\Envoi\Dns\ConfigurationDomaine;
use App\Envoi\Dns\PlanDns;

function configPlan(): ConfigurationDomaine
{
    return new ConfigurationDomaine('envoi.example.test', 'sel1', 'mx.envoi.example.test', '192.0.2.10');
}

test('le plan couvre SPF, DKIM, DMARC, PTR, HELO, A, MTA-STS et TLS-RPT', function () {
    $types = array_map(fn ($e) => $e->type . ' ' . $e->nom, PlanDns::enregistrements(configPlan()));
    expect($types)->toContain('TXT envoi.example.test')
        ->toContain('TXT sel1._domainkey.envoi.example.test')
        ->toContain('TXT _dmarc.envoi.example.test')
        ->toContain('PTR {IP_ENVOI}')
        ->toContain('A mx.envoi.example.test')
        ->toContain('TXT _mta-sts.envoi.example.test')
        ->toContain('TXT _smtp._tls.envoi.example.test');
});

test('le plan ne contient que des modèles : aucune adresse IP, aucune clé', function () {
    $texte = json_encode(PlanDns::enregistrements(configPlan()), JSON_THROW_ON_ERROR);
    expect(preg_match('/\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3}/', $texte))->toBe(0)
        ->and($texte)->toContain('{IP_ENVOI}')->toContain('{CLE_PUBLIQUE_DKIM}');
});

test('MTA-STS est publié en mode testing, jamais enforce', function () {
    $plan = PlanDns::politiqueMtaSts(configPlan());
    expect($plan)->toContain('mode: testing')->not->toContain('enforce');
});

test('exactement 20 points de contrôle, codes uniques', function () {
    $codes = PlanDns::codesPointsDeControle();
    expect($codes)->toHaveCount(20)->and(array_unique($codes))->toHaveCount(20);
});

test('la configuration refuse un domaine ou un nom d hôte invalide', function () {
    expect(fn () => new ConfigurationDomaine('pas un domaine', 's', 'h.example.test', '192.0.2.1'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => new ConfigurationDomaine('envoi.example.test', 's', 'h.example.test', 'pas-une-ip'))->toThrow(InvalidArgumentException::class);
});

test('une valeur TXT DKIM de 2048 bits est découpée en morceaux de 255 octets au plus', function () {
    $valeur = 'v=DKIM1; k=rsa; p=' . base64_encode(str_repeat('x', 294)); // ~392 caractères
    $morceaux = PlanDns::decouperTxt($valeur);
    expect(strlen($valeur))->toBeGreaterThan(255)
        ->and(count($morceaux))->toBeGreaterThan(1)
        ->and(implode('', $morceaux))->toBe($valeur);
    foreach ($morceaux as $m) {
        expect(strlen($m))->toBeLessThanOrEqual(255)->and($m)->not->toBe('');
    }
});

test('le plan sorti découpe le TXT DKIM une fois le jeton remplacé', function () {
    $dkim = array_values(array_filter(PlanDns::enregistrements(configPlan()), fn ($e) => $e->role === 'DKIM'))[0];
    $cle = base64_encode(str_repeat('y', 294));
    $morceaux = PlanDns::chainesTxt($dkim, ['{CLE_PUBLIQUE_DKIM}' => $cle]);
    expect(implode('', $morceaux))->toBe('v=DKIM1; k=rsa; p=' . $cle);
    foreach ($morceaux as $m) {
        expect(strlen($m))->toBeLessThanOrEqual(255);
    }
});

test('une valeur courte ou vide reste en un seul morceau', function () {
    expect(PlanDns::decouperTxt('v=spf1 -all'))->toBe(['v=spf1 -all'])
        ->and(PlanDns::decouperTxt(''))->toBe(['']);
});
