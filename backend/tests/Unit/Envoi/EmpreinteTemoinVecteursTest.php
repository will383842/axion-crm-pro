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
