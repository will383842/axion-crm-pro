<?php

/**
 * NR-05 — R16, les segments de campagne (liste FERMÉE, `App\Crm\Campagnes\Segments`).
 *
 * ⚠️ ÉCART AVEC LE PLAN 22 (à lire avant de modifier ce test). Le plan écrit :
 * « sans `CRM_SEGMENTS_OUVERTS`, aucun segment sensible n’est ouvert ». Le CODE
 * actuel fait l’inverse, par décision de Will (27/09, 28/09 et 01/10/2026) :
 * valeur ABSENTE ou VIDE = les trois segments connus sont OUVERTS
 * (`Segments::OUVERTS_PAR_DEFAUT`). Le filet CONSTATE, il ne corrige pas : ce
 * test fige donc le comportement RÉEL. Fermer par défaut est le travail du lot
 * S6 (« segments fermés par défaut, par espace ») ; il changera ce test avec la
 * ligne « Filet modifié : pourquoi ».
 *
 * Ce qui est déjà verrouillé et doit le rester :
 *  - la liste des segments que le CRM sait extraire est FERMÉE (3 segments) ;
 *  - `aucun` ferme tout ; une valeur inconnue ne peut que FERMER, jamais ouvrir ;
 *  - un segment inconnu (prospects INSEE, vivier, lettre) est refusé par la commande ;
 *  - un nouvel espace n’a aucun segment « à lui » : le réglage est global à ce jour.
 */

use App\Crm\Campagnes\Segments;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\Feature\NonRegression\Instantane;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

test('NR-05 la liste des segments connus est celle de la photo (fermée)', function () {
    Instantane::verifier('nr05-segments', [
        'connus' => Segments::CONNUS,
        'ouverts_par_defaut' => Segments::OUVERTS_PAR_DEFAUT,
        'valeur_pour_tout_fermer' => Segments::AUCUN,
    ]);
});

test('NR-05 valeur absente ou vide : les trois segments connus sont ouverts (comportement actuel, écart du plan 22)', function () {
    foreach ([null, '', '   '] as $valeur) {
        config(['crm.segments_ouverts' => $valeur]);
        expect(Segments::ouverts())->toBe(Segments::CONNUS);
    }
});

test('NR-05 « aucun » ferme tout', function () {
    config(['crm.segments_ouverts' => 'aucun']);

    expect(Segments::ouverts())->toBe([])
        ->and(Segments::ouvert(Segments::PRESSE))->toBeFalse();
});

test('NR-05 une valeur inconnue ne peut que fermer, jamais ouvrir', function () {
    config(['crm.segments_ouverts' => 'prospects-insee,vivier,federatoins']);

    expect(Segments::ouverts())->toBe([])
        ->and(Segments::ouvert('prospects-insee'))->toBeFalse()
        ->and(Segments::ouvert('vivier'))->toBeFalse();
});

test('NR-05 une liste explicite n’ouvre que ce qu’elle nomme, dans l’ordre de CONNUS', function () {
    config(['crm.segments_ouverts' => 'federations, organisateurs-evenements']);

    expect(Segments::ouverts())->toBe([Segments::ORGANISATEURS_EVENEMENTS, Segments::FEDERATIONS])
        ->and(Segments::ouvert(Segments::PRESSE))->toBeFalse();
});

test('NR-05 la commande refuse un segment fermé ou inconnu', function () {
    $sortie = tempnam(sys_get_temp_dir(), 'nr05-');
    try {
        config(['crm.segments_ouverts' => 'aucun']);
        expect(Artisan::call('crm:campagne:destinataires', ['segment' => Segments::PRESSE, 'sortie' => $sortie]))->toBe(1);

        config(['crm.segments_ouverts' => '']);
        foreach (['prospects-insee', 'vivier-candidats', 'personnes-lettre'] as $inconnu) {
            expect(Artisan::call('crm:campagne:destinataires', ['segment' => $inconnu, 'sortie' => $sortie]))->toBe(1);
        }
    } finally {
        @unlink($sortie);
    }
});

test('NR-05 un segment inconnu n’a pas de tag : aucune fiche ne peut y être rattachée', function () {
    expect(fn () => Segments::tag('prospects-insee'))->toThrow(InvalidArgumentException::class);
});
