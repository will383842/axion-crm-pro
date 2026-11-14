<?php

/**
 * LE DEPLOIEMENT SAIT POSER LES INTERRUPTEURS DES ORIGINES (TOUJOURS ETEINTS PAR DEFAUT).
 *
 * Garde de CRM-ALLUMAGE-A : le workflow recopie 3 variables GitHub `true|false`
 * dans le .env, cree une cle HMAC dediee SUR LE SERVEUR si elle est absente,
 * et ne peut jamais l'afficher ni l'ecraser.
 */

use Symfony\Component\Yaml\Yaml;

/** L'etape SSH entiere (env + run). */
function deploiementOriginesEtape(): array
{
    $chemin = dirname(__DIR__, 4) . '/.github/workflows/deploy-direct-ssh.yml';
    test()->assertFileExists($chemin);

    foreach (Yaml::parseFile($chemin)['jobs']['deploy']['steps'] as $etape) {
        if (str_contains($etape['run'] ?? '', "'bash -s'")) {
            return $etape;
        }
    }
    test()->fail('Etape SSH introuvable.');
}

function deploiementOriginesScript(): string
{
    return deploiementOriginesEtape()['run'];
}

function blocCleHmac(): string
{
    $s = deploiementOriginesScript();
    $debut = strpos($s, '::group::cle ORIGINES_HMAC_KEY');
    expect($debut)->not->toBeFalse('Le bloc de la cle HMAC dediee a disparu.');
    $fin = strpos($s, '::endgroup::', $debut);

    return substr($s, $debut, $fin - $debut);
}

test('ORIGINES-DEPLOI — les 3 variables sont recopiees, true|false seulement', function (): void {
    $s = deploiementOriginesScript();
    foreach (['ORIGINES_V3_ENABLED', 'ORIGINES_LECTURE_ENABLED', 'ORIGINES_MASQUAGE_COMMENTAIRE_REF_ENABLED'] as $v) {
        expect(deploiementOriginesEtape()['env'][$v] ?? null)->toBe("\${{ vars.{$v} }}");
        expect($s)->toContain("\"{$v}=\$" . $v . '"');
    }
    expect($s)->toContain('case "$val" in true|false) ;; *) continue ;; esac');
});

test('ORIGINES-DEPLOI — le lien du guide n est jamais synchronise', function (): void {
    expect(deploiementOriginesScript())->not->toContain('CRM_GUIDE_LIEN_ENABLED');
});

test('ORIGINES-DEPLOI — la cle HMAC est creee sur le serveur, sans fuite ni ecrasement', function (): void {
    $bloc = blocCleHmac();

    // Conditionnee a l'absence d'une valeur non vide.
    expect($bloc)->toContain("grep -Eq '^ORIGINES_HMAC_KEY=.+' .env");
    expect($bloc)->toContain('openssl rand -hex 32');
    expect($bloc)->toContain('ORIGINES_HMAC_KEY : déjà présente');
    expect($bloc)->toContain('ORIGINES_HMAC_KEY : créée (64 caractères hexadécimaux)');

    // Jamais de trace de la valeur.
    expect($bloc)->not->toContain('set -x');
    expect($bloc)->not->toMatch('/\becho\b[^\n]*(\$\{?#?CLE_HMAC(?!\})|\$\(openssl)/');
    // La seule ecriture de la valeur va dans le .env (jamais sur la sortie standard).
    expect($bloc)->toContain('>> .env');
    expect($bloc)->not->toMatch('/\bcat\b|\btee\b|\bxtrace\b/');
    expect(deploiementOriginesScript())->not->toMatch('/set\s+-[a-z]*x/');

    // Jamais de secret GitHub ni d'argument SSH pour cette cle.
    expect(deploiementOriginesScript())->not->toContain('secrets.ORIGINES_HMAC_KEY');
    expect(deploiementOriginesScript())->not->toContain('vars.ORIGINES_HMAC_KEY');
});

test('ORIGINES-DEPLOI — le bloc de la cle precede le build', function (): void {
    $s = deploiementOriginesScript();
    expect(strpos($s, '::group::cle ORIGINES_HMAC_KEY'))
        ->toBeLessThan(strpos($s, '::group::docker compose build + up'));
});
