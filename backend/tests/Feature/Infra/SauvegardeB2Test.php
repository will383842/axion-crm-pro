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
    expect($code)->not->toMatch('/\brm\s+-[a-z]*r?f?\s+(?!"\$TMP")/');
    expect($code)->not->toMatch('/\bfind\b[^\n]*(-delete|-exec\s+rm)/');
    expect($code)->not->toMatch('/\b(sftp|sshpass|scp|unlink|shred)\b/');
    expect($code)->not->toMatch('/\bSB_[A-Z]+/');
    expect($code)->not->toContain('BACKUP_DIR/$NOM');

    // Seule suppression distante : une VERSION de fichier du bucket B2.
    expect(substr_count($code, 'b2_delete_file_version'))->toBe(1);
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
