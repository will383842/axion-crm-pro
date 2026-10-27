<?php

/**
 * GARDE — seconde copie hors-site Backblaze B2 (`infra/scripts/backup-b2.sh`).
 *
 * Le contrat : coût NUL, aucune clé dans le dépôt (public), aucune suppression
 * hors du bucket B2, et un cron qui ne casse rien tant que Will n'a pas posé
 * les clés. Ces propriétés sont des promesses d'un script bash qu'aucun test
 * fonctionnel n'exerce contre le vrai B2 : on les garde donc sur le texte
 * exécutable du script (commentaires exclus) et, pour le refus sans clés, en
 * l'EXÉCUTANT.
 */

use Tests\TestCase;

uses(TestCase::class);

function racineDepotB2(): string
{
    return realpath(base_path('..')) ?: base_path('..');
}

function lireB2(string $relatif): string
{
    return (string) file_get_contents(racineDepotB2() . '/' . $relatif);
}

/**
 * Le script sans ses lignes de commentaire.
 */
function codeB2(string $relatif): string
{
    $lignes = array_filter(
        preg_split('/\R/', lireB2($relatif)) ?: [],
        static fn (string $l): bool => ! str_starts_with(trim($l), '#'),
    );

    return implode("\n", $lignes);
}

test('B2 — sans clés, le script sort en 0 en disant « non configuré »', function () {
    $script = racineDepotB2() . '/infra/scripts/backup-b2.sh';
    $sortie = [];
    $code = -1;
    // HOME/.env absents : seul /opt/axion-crm-pro/.env pourrait fournir des clés.
    exec('env -u B2_KEY_ID -u B2_APP_KEY -u B2_BUCKET bash ' . escapeshellarg($script) . ' 2>&1', $sortie, $code);

    expect($code)->toBe(0);
    expect(implode("\n", $sortie))->toContain('non configuré');
})->skip(fn () => is_file('/opt/axion-crm-pro/.env'), 'Un .env de production existe sur cette machine.');

test('B2 — le verifier dit « non configuré » sans échouer', function () {
    $script = racineDepotB2() . '/infra/scripts/verifier-sauvegarde-b2.sh';
    $sortie = [];
    $code = -1;
    exec('env -u B2_KEY_ID -u B2_APP_KEY -u B2_BUCKET bash ' . escapeshellarg($script) . ' 2>&1', $sortie, $code);

    expect($code)->toBe(0);
    expect(implode("\n", $sortie))->toContain('non configuré');
})->skip(fn () => is_file('/opt/axion-crm-pro/.env'), 'Un .env de production existe sur cette machine.');

test('B2 — aucune clé en dur, et la passphrase de chiffrement n est jamais lue', function (string $fichier) {
    $code = codeB2($fichier);

    // Les clés viennent de l'environnement ; aucune valeur littérale.
    expect($code)->not->toMatch('/B2_(KEY_ID|APP_KEY|BUCKET)=["\']?[A-Za-z0-9]{6,}/');
    expect($code)->not->toMatch('/\bK00[0-9A-Za-z]{20,}/');
    // Le motif de chargement ne couvre QUE B2_ : pas la passphrase.
    expect($code)->not->toContain('BACKUP_ENCRYPTION_PASSPHRASE');
    expect($code)->toContain("grep -E '^B2_'");
    // Les clés ne passent jamais en argument de commande (visible dans `ps`).
    expect($code)->not->toMatch('/curl[^\n]*-u\s/');
    expect($code)->toContain('set -euo pipefail');
})->with([
    'infra/scripts/backup-b2.sh',
    'infra/scripts/verifier-sauvegarde-b2.sh',
]);

test('B2 — ne supprime jamais hors du bucket B2', function () {
    $code = codeB2('infra/scripts/backup-b2.sh');

    // Aucune commande de suppression de fichiers/dossiers locaux ni distants.
    // Le SEUL `rm` autorisé est celui du répertoire temporaire du script.
    expect(preg_match_all('/\brm\b/', $code))->toBe(substr_count($code, 'rm -rf "$TMP"\''));
    expect($code)->not->toMatch('/\brm\s+(?!-rf "\$TMP"\')/');
    expect($code)->not->toMatch('/\bfind\b[^\n]*(-delete|-exec\s+rm)/');
    expect($code)->not->toMatch('/\b(sftp|sshpass|scp|unlink|shred)\b/');
    expect($code)->not->toMatch('/\bSB_[A-Z]+/');
    expect($code)->not->toContain('BACKUP_DIR/$NOM');

    // Seule suppression distante : une VERSION de fichier du bucket B2.
    // (purge préalable sous plafond + rotation), toujours au-delà des plus récentes.
    expect(substr_count($code, 'b2_delete_file_version'))->toBe(2);
    // Et seulement dans le préfixe de la rotation, après les 4 dernières.
    expect($code)->toContain('B2_COPIES_A_GARDER=4');
    expect($code)->toContain('.[$k:][]');
});

test('B2 — le plafond dur de 9 Go est contrôlé AVANT l envoi', function () {
    $code = codeB2('infra/scripts/backup-b2.sh');

    expect($code)->toContain('B2_PLAFOND_OCTETS=9000000000');
    expect($code)->toContain('-gt "$B2_PLAFOND_OCTETS"');

    $posPlafond = strpos($code, '-gt "$B2_PLAFOND_OCTETS"');
    $posEnvoi = strpos($code, 'b2_get_upload_url');
    expect($posPlafond)->not->toBeFalse();
    expect($posEnvoi)->not->toBeFalse();
    expect($posPlafond)->toBeLessThan($posEnvoi, 'Le plafond doit être testé avant toute demande d envoi.');

    // L'envoi est suivi d'un contrôle SHA-1 + taille.
    expect($code)->toContain('X-Bz-Content-Sha1');
    expect($code)->toContain('.contentSha1');
    expect($code)->toContain('.contentLength');
});

test('B2 — le cron est hebdomadaire (dimanche 04:30 UTC) et idempotent', function () {
    $setup = lireB2('infra/scripts/setup-backup-b2.sh');

    expect($setup)->toContain('CRON_LINE="30 4 * * 0 $B2_SCRIPT');
    // Idempotent : on retire l'ancienne ligne avant d'ajouter la nouvelle.
    expect($setup)->toContain('grep -vF "$B2_SCRIPT"');
    expect($setup)->toContain('echo "$CRON_LINE" ) | crontab -');
    // Et le script lui-même ne s'exécute que le dimanche.
    expect(codeB2('infra/scripts/backup-b2.sh'))->toContain('date -u +%u');

    // Preuve par simulation : appliquer deux fois la même mise à jour ne
    // double pas la ligne.
    $script = '/opt/axion-crm-pro/infra/scripts/backup-b2.sh';
    $ligne = "30 4 * * 0 {$script} >> /var/log/axion-backup-b2.log 2>&1";
    $crontab = "0 3 * * * /opt/axion-crm-pro/infra/scripts/backup-postgres.sh\n";
    for ($i = 0; $i < 2; $i++) {
        $gardees = array_filter(explode("\n", $crontab), static fn (string $l): bool => $l !== '' && ! str_contains($l, $script));
        $crontab = implode("\n", [...$gardees, $ligne]) . "\n";
    }
    expect(substr_count($crontab, $script))->toBe(1);
});

test('B2 — la surveillance a un job borné qui ne rougit pas sans configuration', function () {
    $wf = lireB2('.github/workflows/surveillance-sauvegarde.yml');

    expect($wf)->toContain('verifier-b2:');
    expect($wf)->toContain('verifier-sauvegarde-b2.sh');
    expect($wf)->toContain('needs: [verifier, verifier-b2]');

    $verif = lireB2('infra/scripts/verifier-sauvegarde-b2.sh');
    expect($verif)->toContain('SEUIL_AGE_J="${SEUIL_AGE_J:-9}"');
    expect($verif)->toContain('SEUIL_TAILLE_MO="${SEUIL_TAILLE_MO:-100}"');
});


/**
 * Lance backup-b2.sh avec un `curl` simulé dans le PATH.
 *
 * @param  list<int>  $taillesVersions  tailles des copies déjà dans le bucket, de la plus ancienne à la plus récente
 * @return array{code: int, sortie: string, appels: string}
 */
function lancerBackupB2Simule(array $taillesVersions, bool $archiveRecente = true): array
{
    $dir = sys_get_temp_dir() . '/b2sim_' . bin2hex(random_bytes(6));
    mkdir($dir . '/bin', 0700, true);
    mkdir($dir . '/backups', 0700, true);

    $versions = [];
    foreach ($taillesVersions as $i => $taille) {
        $versions[] = [
            'fileId' => 'id' . ($i + 1),
            'fileName' => 'axion-crm/axion_crm_2026010' . ($i + 1) . '.sql.gz.enc',
            'action' => 'upload',
            'contentLength' => $taille,
            'uploadTimestamp' => 1_700_000_000_000 + $i * 1000,
        ];
    }
    file_put_contents($dir . '/versions.json', (string) json_encode(['files' => $versions, 'nextFileName' => null]));

    $archive = $dir . '/backups/axion_crm_20260301T030000Z.sql.gz.enc';
    file_put_contents($archive, str_repeat('x', 1000));
    if (! $archiveRecente) {
        touch($archive, time() - 3 * 86400);
    }

    file_put_contents($dir . '/bin/curl', <<<'SH'
#!/usr/bin/env bash
url="${@: -1}"; file=""; data=""
while [ $# -gt 0 ]; do
    case "$1" in -T) file="$2"; shift ;; -d) data="$2"; shift ;; esac
    shift
done
echo "$url $data" >> "$FAKE_LOG"
case "$url" in
    *authorize_account) echo '{"accountId":"acc","authorizationToken":"tok","apiInfo":{"storageApi":{"apiUrl":"https://api.test"}}}' ;;
    *b2_list_buckets) echo '{"buckets":[{"bucketId":"bk"}]}' ;;
    *b2_list_file_versions) cat "$FAKE_VERSIONS" ;;
    *b2_get_upload_url) echo '{"uploadUrl":"https://upload.test/u","authorizationToken":"tok2"}' ;;
    https://upload.test/*) jq -nc --arg s "$(sha1sum "$file" | cut -d' ' -f1)" --argjson l "$(stat -c%s "$file")" '{contentSha1:$s,contentLength:$l}' ;;
    *b2_delete_file_version) echo '{}' ;;
esac
SH);
    chmod($dir . '/bin/curl', 0700);

    $sortie = [];
    $code = -1;
    exec(
        'env PATH=' . escapeshellarg($dir . '/bin:' . (string) getenv('PATH'))
        . ' FAKE_LOG=' . escapeshellarg($dir . '/appels.log')
        . ' FAKE_VERSIONS=' . escapeshellarg($dir . '/versions.json')
        . ' B2_KEY_ID=k B2_APP_KEY=s B2_BUCKET=bucket FORCE_B2=1'
        . ' BACKUP_DIR=' . escapeshellarg($dir . '/backups')
        . ' bash ' . escapeshellarg(racineDepotB2() . '/infra/scripts/backup-b2.sh') . ' 2>&1',
        $sortie,
        $code,
    );
    $appels = is_file($dir . '/appels.log') ? (string) file_get_contents($dir . '/appels.log') : '';
    exec('rm -rf ' . escapeshellarg($dir));

    return ['code' => $code, 'sortie' => implode("\n", $sortie), 'appels' => $appels];
}

$sansEnvProd = fn () => is_file('/opt/axion-crm-pro/.env');

test('B2 simulé — la rotation ne garde que 4 copies et ne supprime que dans B2', function () {
    $r = lancerBackupB2Simule([1_000_000, 1_000_000, 1_000_000, 1_000_000, 1_000_000, 1_000_000]);

    expect($r['code'])->toBe(0);
    expect($r['appels'])->toContain('b2_get_upload_url');
    expect(substr_count($r['appels'], 'b2_delete_file_version'))->toBe(2);
    expect($r['appels'])->toContain('"fileId":"id1"');
    expect($r['appels'])->toContain('"fileId":"id2"');
    expect($r['appels'])->not->toContain('"fileId":"id3"');
})->skip($sansEnvProd, 'Un .env de production existe sur cette machine.');

test('B2 simulé — le plafond purge d abord les copies condamnées puis envoie', function () {
    // 4 × 2,4 Go = 9,6 Go : l'envoi dépasserait 9 Go, mais la plus ancienne sera supprimée par la rotation.
    $r = lancerBackupB2Simule([2_400_000_000, 2_400_000_000, 2_400_000_000, 2_400_000_000]);

    expect($r['code'])->toBe(0);
    expect(substr_count($r['appels'], 'b2_delete_file_version'))->toBe(1);
    expect($r['appels'])->toContain('"fileId":"id1"');
    $posSuppression = strpos($r['appels'], 'b2_delete_file_version');
    $posEnvoi = strpos($r['appels'], 'b2_get_upload_url');
    expect($posSuppression)->toBeLessThan($posEnvoi);
})->skip($sansEnvProd, 'Un .env de production existe sur cette machine.');

test('B2 simulé — le plafond reste DUR : rien n est envoyé ni supprimé s il est dépassé', function () {
    $r = lancerBackupB2Simule([3_100_000_000, 3_100_000_000, 3_100_000_000]);

    expect($r['code'])->toBe(1);
    expect($r['sortie'])->toContain('PLAFOND');
    expect($r['appels'])->not->toContain('b2_get_upload_url');
    expect($r['appels'])->not->toContain('b2_delete_file_version');
})->skip($sansEnvProd, 'Un .env de production existe sur cette machine.');

test('B2 simulé — une archive locale de plus de 2 jours est refusée', function () {
    $r = lancerBackupB2Simule([1_000_000], false);

    expect($r['code'])->toBe(1);
    expect($r['sortie'])->toContain('plus de 2 jours');
    expect($r['appels'])->not->toContain('b2_get_upload_url');
})->skip($sansEnvProd, 'Un .env de production existe sur cette machine.');
