<?php

use App\ProspectionLinkedIn\TextePret;

// LI-ROB-B : drapeau lu par filter_var, longueur contrôlée APRÈS le préfixe « [ESSAI] ».
// Aucune donnée d'espace : mêmes résultats sous le propriétaire et sous pgsql_app.

const LR_POLITIQUE = 'https://site.example.test/confidentialite';

beforeEach(function () {
    config(['prospection_linkedin.politique_confidentialite_url' => LR_POLITIQUE]);
});

function lrConfigAvec(?string $valeur): array
{
    $avant = getenv('PROSPECTION_LINKEDIN_ACTIVE');
    $avantEnv = $_ENV['PROSPECTION_LINKEDIN_ACTIVE'] ?? null;
    $avantSrv = $_SERVER['PROSPECTION_LINKEDIN_ACTIVE'] ?? null;
    try {
        if ($valeur === null) {
            putenv('PROSPECTION_LINKEDIN_ACTIVE');
            unset($_ENV['PROSPECTION_LINKEDIN_ACTIVE'], $_SERVER['PROSPECTION_LINKEDIN_ACTIVE']);
        } else {
            putenv('PROSPECTION_LINKEDIN_ACTIVE=' . $valeur);
            $_ENV['PROSPECTION_LINKEDIN_ACTIVE'] = $_SERVER['PROSPECTION_LINKEDIN_ACTIVE'] = $valeur;
        }

        return require base_path('config/prospection_linkedin.php');
    } finally {
        $avant === false ? putenv('PROSPECTION_LINKEDIN_ACTIVE') : putenv('PROSPECTION_LINKEDIN_ACTIVE=' . $avant);
        $avantEnv === null ? true : $_ENV['PROSPECTION_LINKEDIN_ACTIVE'] = $avantEnv;
        $avantSrv === null ? true : $_SERVER['PROSPECTION_LINKEDIN_ACTIVE'] = $avantSrv;
        if ($avantEnv === null) {
            unset($_ENV['PROSPECTION_LINKEDIN_ACTIVE']);
        }
        if ($avantSrv === null) {
            unset($_SERVER['PROSPECTION_LINKEDIN_ACTIVE']);
        }
    }
}

it('li-rob-b 1 : les valeurs « éteintes » éteignent le module', function (string $v) {
    expect(lrConfigAvec($v)['active'])->toBeFalse();
})->with(['off', 'OFF', 'no', 'non', 'false', '0', '', 'n\'importe quoi']);

it('li-rob-b 2 : les valeurs « allumées » l\'allument', function (string $v) {
    expect(lrConfigAvec($v)['active'])->toBeTrue();
})->with(['on', 'true', '1', 'yes']);

it('li-rob-b 3 : drapeau absent ⇒ faux (défaut inchangé)', function () {
    expect(lrConfigAvec(null)['active'])->toBeFalse();
});

it('li-rob-b 4 : invitation de 298 caractères en essai ⇒ jamais plus de 300', function () {
    $gabarit = str_repeat('a', 298);
    $texte = null;
    try {
        $texte = TextePret::composer($gabarit, [], true, 'invitation');
    } catch (InvalidArgumentException $e) {
        expect($e->getMessage())->toBe('Invitation trop longue.');
    }
    if ($texte !== null) {
        expect(mb_strlen($texte))->toBeLessThanOrEqual(300);
    }
});

it('li-rob-b 5 : invitation de 292 caractères en essai ⇒ acceptée à 300 pile', function () {
    $texte = TextePret::composer(str_repeat('a', 292), [], true, 'invitation');
    expect(mb_strlen($texte))->toBe(300)->and($texte)->toStartWith('[ESSAI] ');
});

it('li-rob-b 6 : hors essai, 300 caractères restent acceptés, 301 refusés', function () {
    expect(mb_strlen(TextePret::composer(str_repeat('a', 300), [], false, 'invitation')))->toBe(300);
    expect(fn () => TextePret::composer(str_repeat('a', 301), [], false, 'invitation'))->toThrow(InvalidArgumentException::class);
});

it('li-rob-b 7 : message en essai, jamais plus de MAX_MESSAGE après préfixe', function () {
    $fin = ' répondez STOP ' . LR_POLITIQUE;
    $corps = str_repeat('a', TextePret::MAX_MESSAGE - mb_strlen($fin) - 3) . $fin;
    expect(fn () => TextePret::composer($corps, [], true, 'message'))->toThrow(InvalidArgumentException::class);
    expect(mb_strlen(TextePret::composer($corps, [], false, 'message')))->toBeLessThanOrEqual(TextePret::MAX_MESSAGE);
});
