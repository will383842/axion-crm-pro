<?php

use App\Envoi\EmpreinteTemoin;

/*
 * Vecteurs d'empreintes de témoins PARTAGÉS avec le serveur d'envoi (Python, infra/serveur-envoi/socle).
 * Si la normalisation change d'un seul côté, l'un des deux tests rougit.
 */
function vecteursEmpreintes(): array
{
    $json = json_decode((string) file_get_contents(base_path('../envoi-serveur/contrat/vecteurs-empreintes-temoins.json')), true);
    expect($json['cas'])->not->toBeEmpty();

    return [$json['cle'], $json['cas']];
}

test('vecteurs d’empreintes communs : normalisation et HMAC identiques au serveur d’envoi', function () {
    [$cle, $cas] = vecteursEmpreintes();
    foreach ($cas as $c) {
        expect(EmpreinteTemoin::normaliser($c['adresse']))->toBe($c['normalisee'], $c['libelle'])
            ->and(EmpreinteTemoin::calculer($c['adresse'], $cle))->toBe($c['empreinte'], $c['libelle']);
    }
});

test('I-3 : pourDecision() = null pour toute adresse non ASCII (comme empreinte_temoin côté serveur)', function () {
    $cle = 'cle-de-test';
    expect(EmpreinteTemoin::pourDecision('a@fass.de', $cle))->toBe(EmpreinteTemoin::calculer('a@fass.de', $cle));
    expect(EmpreinteTemoin::pourDecision("  A@Fass.DE \n", $cle))->toBe(EmpreinteTemoin::calculer('a@fass.de', $cle)); // espaces et casse ASCII
    expect(EmpreinteTemoin::pourDecision('a@faß.de', $cle))->toBeNull();          // punycode transitoire : même empreinte que fass.de
    expect(EmpreinteTemoin::pourDecision("a@fass.d\u{212A}", $cle))->toBeNull();  // signe KELVIN
    expect(EmpreinteTemoin::pourDecision('é@example.test', $cle))->toBeNull();
    expect(EmpreinteTemoin::calculer('a@faß.de', $cle))->toBe(EmpreinteTemoin::calculer('a@fass.de', $cle)); // le contrat partagé reste inchangé
});
