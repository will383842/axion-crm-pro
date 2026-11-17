<?php

/**
 * LI-G — garde d'architecture du module « prospection LinkedIn » (pont LinkedIn).
 *
 * Le fondateur envoie lui-même depuis son navigateur : le module ne contacte
 * jamais LinkedIn, n'utilise aucun outil tiers, ne lit pas les anciennes tables
 * mortes `linkedin_*`, ne supprime aucune donnée et ne lit pas directement
 * ENVOI_REEL_ACTIF. Ce test lit les sources ; il passe vert à vide tant que les
 * dossiers du module n'existent pas, et protège tous les lots suivants.
 */

/** Motifs interdits : libellé => expression régulière. */
function pl_motifs_interdits(): array
{
    return [
        'appel HTTP (Http::)' => '/\bHttp::/',
        'curl_' => '/\bcurl_[a-z_]+/i',
        'fsockopen' => '/\bfsockopen\s*\(/i',
        'file_get_contents vers http' => '/file_get_contents\s*\(\s*[\'"]https?:/i',
        'fetch( vers un domaine externe' => '/\bfetch\s*\(\s*[`\'"]https?:\/\//i',
        'table morte linkedin_*' => '/\blinkedin_(accounts|campaigns|health_checks|invitations|messages|search_results|sequences)\b/i',
        'cookie_blob' => '/cookie_blob/i',
        'proxy_id' => '/proxy_id/i',
        'suppression ->delete(' => '/->\s*delete\s*\(/i',
        'DELETE FROM' => '/DELETE\s+FROM/i',
        'TRUNCATE' => '/\bTRUNCATE\b/i',
        'lecture directe env(ENVOI_REEL_ACTIF)' => '/\benv\s*\(\s*[\'"]ENVOI_REEL_ACTIF[\'"]/',
    ];
}

/** @return list<string> fichiers sources (php, ts, tsx, js) sous les cibles qui existent. */
function pl_fichiers(array $cibles): array
{
    $out = [];
    foreach ($cibles as $cible) {
        if (is_file($cible)) {
            $out[] = $cible;
        } elseif (is_dir($cible)) {
            $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($cible, FilesystemIterator::SKIP_DOTS));
            foreach ($it as $f) {
                if ($f->isFile() && preg_match('/\.(php|tsx?|jsx?|mjs)$/', $f->getFilename())) {
                    $out[] = $f->getPathname();
                }
            }
        }
    }
    sort($out);

    return $out;
}

/** @return list<string> violations « fichier : motif ». */
function pl_violations(array $cibles, ?array $motifs = null): array
{
    $motifs ??= pl_motifs_interdits();
    $v = [];
    foreach (pl_fichiers($cibles) as $fichier) {
        $src = (string) file_get_contents($fichier);
        foreach ($motifs as $libelle => $re) {
            if (preg_match($re, $src) === 1) {
                $v[] = $fichier . ' : ' . $libelle;
            }
        }
    }

    return $v;
}

function pl_cibles_module(): array
{
    $backend = dirname(__DIR__, 3);

    return [
        $backend . '/app/ProspectionLinkedIn',
        $backend . '/routes/prospection-linkedin.php',
        dirname($backend) . '/frontend/src/features/prospection-linkedin',
    ];
}

function pl_dossier_temoin(string $contenu, string $nom = 'Temoin.php'): string
{
    $dir = sys_get_temp_dir() . '/pl_li_g_' . bin2hex(random_bytes(6));
    mkdir($dir, 0700, true);
    file_put_contents($dir . '/' . $nom, $contenu);

    return $dir;
}

function pl_nettoyer(string $dir): void
{
    foreach (glob($dir . '/*') ?: [] as $f) {
        @unlink($f);
    }
    @rmdir($dir);
}

it('le module réel respecte la garde (vert à vide tant que les dossiers sont absents)', function () {
    expect(pl_violations(pl_cibles_module()))->toBe([]);
});

it('détecte un témoin contenant Http::get', function () {
    $dir = pl_dossier_temoin("<?php\nHttp::get('https://exemple.test');\n");
    try {
        expect(pl_violations([$dir]))->not->toBe([])
            ->and(implode(' ', pl_violations([$dir])))->toContain('Http::');
    } finally {
        pl_nettoyer($dir);
    }
});

it('détecte un témoin contenant cookie_blob', function () {
    $dir = pl_dossier_temoin("<?php\n\$x = 'cookie_blob';\n");
    try {
        expect(implode(' ', pl_violations([$dir])))->toContain('cookie_blob');
    } finally {
        pl_nettoyer($dir);
    }
});

it('détecte les autres interdits (table morte, proxy_id, suppression, env, fetch externe)', function (string $contenu, string $nom) {
    $dir = pl_dossier_temoin($contenu, $nom);
    try {
        expect(pl_violations([$dir]))->not->toBe([]);
    } finally {
        pl_nettoyer($dir);
    }
})->with([
    'table morte' => ["<?php DB::table('linkedin_messages');", 'a.php'],
    'proxy_id' => ["<?php \$p = 'proxy_id';", 'b.php'],
    'delete' => ['<?php $m->delete();', 'c.php'],
    'DELETE FROM' => ["<?php DB::statement('DELETE FROM pl_journal');", 'd.php'],
    'TRUNCATE' => ["<?php DB::statement('TRUNCATE pl_journal');", 'e.php'],
    'env drapeau' => ["<?php env('ENVOI_REEL_ACTIF');", 'f.php'],
    'curl' => ['<?php curl_init();', 'g.php'],
    'fsockopen' => ["<?php fsockopen('x.test', 80);", 'h.php'],
    'file_get_contents http' => ["<?php file_get_contents('https://x.test');", 'i.php'],
    'fetch externe' => ["fetch('https://exemple.test/api');", 'j.ts'],
]);

it('accepte un fichier conforme (appel à l\'API du CRM)', function () {
    $dir = pl_dossier_temoin("fetch('/api/prospection-linkedin/file');\n", 'ok.ts');
    try {
        expect(pl_violations([$dir]))->toBe([]);
    } finally {
        pl_nettoyer($dir);
    }
});

it('mutation : sans le motif Http::, le témoin ne serait pas détecté (la garde dépend de ce motif)', function () {
    $dir = pl_dossier_temoin("<?php\nHttp::get('https://exemple.test');\n");
    try {
        $motifs = pl_motifs_interdits();
        unset($motifs['appel HTTP (Http::)']);
        expect(pl_violations([$dir], $motifs))->toBe([]);
    } finally {
        pl_nettoyer($dir);
    }
});
