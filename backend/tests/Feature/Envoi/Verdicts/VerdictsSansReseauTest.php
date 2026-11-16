<?php

/**
 * VERDICTS-CODE-1 — T1.9 : aucun réseau autre que le DNS dans `app/Envoi/Verdicts/**` (cahier §1.2).
 */

use Tests\TestCase;

uses(TestCase::class);

test('T1.9 le dossier des verdicts ne contient aucun appel réseau ni sondage SMTP', function () {
    $fichiers = glob(app_path('Envoi/Verdicts/*.php')) ?: [];
    expect(count($fichiers))->toBeGreaterThanOrEqual(6);
    $interdits = ['Http::', 'curl_', 'fsockopen', 'stream_socket_client', 'RCPT', 'SmtpProber', 'Guzzle', 'pfsockopen', 'socket_create', 'Mail::', 'Illuminate\\Support\\Facades\\Http'];
    foreach ($fichiers as $f) {
        $code = (string) file_get_contents($f);
        foreach ($interdits as $mot) {
            expect(str_contains($code, $mot))->toBeFalse(basename($f) . ' contient « ' . $mot . ' »');
        }
    }
});
