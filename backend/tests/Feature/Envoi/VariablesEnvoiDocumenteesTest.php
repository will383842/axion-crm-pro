<?php

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

uses(TestCase::class); // démarre l'application (Artisan::all()) ; aucune base n'est ouverte.

// X9-A : chaque variable ENVOI_* lue par config/envoi.php est documentée dans .env.example ET dans
// docs/envoi/JOUR-J.md. Test de fichiers seulement : aucune base, aucun réseau (donc pas de double passage
// pgsql_app / propriétaire : rien n'est lu ni écrit en base).

function x9Racine(): string
{
    return dirname(__DIR__, 4);
}

/** @return list<string> */
function x9VariablesConfig(): array
{
    $source = (string) file_get_contents(dirname(__DIR__, 3) . '/config/envoi.php');
    preg_match_all("/env\\(\\s*'(ENVOI_[A-Z0-9_]+)'/", $source, $m);

    return array_values(array_unique($m[1]));
}

/** @return list<string> lignes du bloc envoi de .env.example (hors commentaires) */
function x9LignesEnvoi(): array
{
    $lignes = file(x9Racine() . '/.env.example', FILE_IGNORE_NEW_LINES) ?: [];

    return array_values(array_filter($lignes, fn (string $l) => str_starts_with($l, 'ENVOI_')));
}

it('lit bien des variables ENVOI_ dans la config (garde contre une regex vide)', function () {
    expect(count(x9VariablesConfig()))->toBeGreaterThanOrEqual(29);
});

it('documente chaque variable ENVOI_ de config/envoi.php dans .env.example', function () {
    $texte = (string) file_get_contents(x9Racine() . '/.env.example');
    $absentes = array_values(array_filter(
        x9VariablesConfig(),
        fn (string $v) => preg_match('/^' . preg_quote($v, '/') . '=/m', $texte) !== 1,
    ));

    expect($absentes)->toBe([]);
});

it('documente chaque variable ENVOI_ de config/envoi.php dans docs/envoi/JOUR-J.md', function () {
    $texte = (string) @file_get_contents(x9Racine() . '/docs/envoi/JOUR-J.md');
    $absentes = array_values(array_filter(
        x9VariablesConfig(),
        fn (string $v) => preg_match('/(?<![A-Z0-9_])' . preg_quote($v, '/') . '(?![A-Z0-9_])/', $texte) !== 1,
    ));

    expect($texte)->not->toBe('')->and($absentes)->toBe([]);
});

it('ne recommande pas de vider ENVOI_LISTES_IPS comme retour arrière (cela ouvre la garde)', function () {
    $texte = (string) file_get_contents(x9Racine() . '/docs/envoi/JOUR-J.md');

    expect(preg_match('/Retour arrière\s*:\s*vider/u', $texte))->toBe(0)
        ->and($texte)->toContain('envoi:tout-arreter')
        ->and($texte)->toContain('Ne PAS vider `ENVOI_LISTES_IPS`');
});

it('garde ENVOI_REEL_ACTIF=false dans .env.example', function () {
    $texte = (string) file_get_contents(x9Racine() . '/.env.example');

    expect(preg_match_all('/^ENVOI_REEL_ACTIF=(.*)$/m', $texte, $m))->toBe(1)
        ->and($m[1][0])->toBe('false');
});

it('ne met dans le bloc envoi aucune IP publique, aucun domaine réel, aucune longue clé', function () {
    foreach (x9LignesEnvoi() as $ligne) {
        $valeur = trim(explode('=', $ligne, 2)[1] ?? '');

        if (preg_match_all('/\b(\d{1,3})\.(\d{1,3})\.(\d{1,3})\.(\d{1,3})\b/', $valeur, $ips, PREG_SET_ORDER)) {
            foreach ($ips as $ip) {
                $ok = str_starts_with($ip[0], '192.0.2.') || str_starts_with($ip[0], '198.51.100.');
                expect($ok)->toBeTrue("IP non RFC 5737 dans : {$ligne}");
            }
        }
        if (preg_match_all('/(?:[a-z0-9-]+\.)+[a-z]{2,}/i', $valeur, $hotes)) {
            foreach ($hotes[0] as $hote) {
                if (preg_match('/\.(pem|key|crt)$/i', $hote)) {
                    continue;
                }
                expect(str_ends_with(strtolower($hote), 'example.test'))->toBeTrue("domaine réel dans : {$ligne}");
            }
        }
        // Une valeur sans marqueur <…> ne doit pas ressembler à une clé de plus de 16 caractères.
        $sansMarqueur = preg_replace('/<[^>]*>/', '', $valeur) ?? '';
        expect(preg_match('/[A-Za-z0-9+\/_=-]{17,}/', $sansMarqueur))->toBe(0, "clé suspecte dans : {$ligne}");
    }
});

// X9-B : la procédure du jour J ne cite que des commandes qui existent vraiment.
it('ne cite dans docs/envoi/JOUR-J.md que des commandes envoi:* qui existent (Artisan::all())', function () {
    $texte = (string) file_get_contents(x9Racine() . '/docs/envoi/JOUR-J.md');
    preg_match_all('/(?<![A-Za-z0-9_:-])(envoi:[a-z0-9][a-z0-9:-]*)/', $texte, $m);
    $citees = array_values(array_unique($m[1]));
    $existantes = Artisan::all();
    $inconnues = array_values(array_filter(
        $citees,
        fn (string $c) => ! array_key_exists($c, $existantes),
    ));

    expect(count($citees))->toBeGreaterThanOrEqual(10)->and($inconnues)->toBe([]);
});

it('ne laisse aucune marque « (lot X… » dans docs/envoi/JOUR-J.md', function () {
    $texte = (string) file_get_contents(x9Racine() . '/docs/envoi/JOUR-J.md');

    expect(preg_match('/\(lot X/u', $texte))->toBe(0);
});

it('documente PASSERELLE_KUMO_URL en marqueur dans RUNBOOK.md, sans vraie valeur', function () {
    $texte = (string) file_get_contents(x9Racine() . '/infra/serveur-envoi/RUNBOOK.md');

    expect($texte)->toContain('PASSERELLE_KUMO_URL=<')
        ->and(preg_match('/PASSERELLE_KUMO_URL=(?!<)\S/', $texte))->toBe(0);
});
