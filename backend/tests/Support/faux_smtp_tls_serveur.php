<?php

/**
 * FAUX serveur SMTP local AVEC STARTTLS (127.0.0.1 uniquement), dérivé de faux_smtp_serveur.php (L4), pour prouver
 * le transport du tunnel K9. Usage : php faux_smtp_tls_serveur.php <mode> <fichier_recu> <pem_cert_et_cle>
 * Modes : ok | coupure_apres_data | muet_apres_data | 451_apres_data | 550_apres_data | rcpt_550 | mail_550 | banniere_554 | ehlo_554 | starttls_554 | auth_535 | sans_starttls | banniere_muette
 * Port sur la 1re ligne de stdout, brut reçu (DATA) dans <fichier_recu>, commandes dans <fichier_recu>.cmd.
 * Une seule connexion. Aucun accès réseau externe.
 */
[$_, $mode, $fichier, $pem] = $argv;
$srv = stream_socket_server('tcp://127.0.0.1:0', $errno, $err);
$nom = stream_socket_get_name($srv, false);
fwrite(STDOUT, substr($nom, strrpos($nom, ':') + 1) . "\n");
fflush(STDOUT);

$c = @stream_socket_accept($srv, 20);
if ($c === false) {
    exit(1);
}
if ($mode === 'banniere_muette') {
    sleep(8);
    exit(0);
}
if ($mode === 'banniere_554') {
    fwrite($c, "554 5.7.1 IP non autorisee\r\n");
    sleep(2);
    exit(0);
}
fwrite($c, "220 faux ESMTP\r\n");
$donnees = false;
$tls = false;
$recu = '';
while (($ligne = fgets($c)) !== false) {
    if ($donnees) {
        if ($ligne === ".\r\n") {
            file_put_contents($fichier, $recu);
            switch ($mode) {
                case 'coupure_apres_data':
                    fclose($c);
                    exit(0);
                case 'muet_apres_data':
                    sleep(10);   // aucun accusé : le client doit abandonner sur son délai
                    exit(0);
                case '451_apres_data':
                    fwrite($c, "451 4.3.0 reessayez plus tard\r\n");
                    break;
                case '550_apres_data':
                    fwrite($c, "550 5.7.1 refuse\r\n");
                    break;
                default:
                    fwrite($c, "250 2.0.0 ok\r\n");
            }
            $donnees = false;

            continue;
        }
        $recu .= $ligne;

        continue;
    }
    file_put_contents($fichier . '.cmd', $ligne, FILE_APPEND);
    $cmd = strtoupper(substr($ligne, 0, 4));
    if (($cmd === 'EHLO' || $cmd === 'HELO') && $mode === 'ehlo_554') {
        fwrite($c, "554 5.7.1 refuse\r\n");
    } elseif ($cmd === 'EHLO' || $cmd === 'HELO') {
        $ext = '';
        if (! $tls && $mode !== 'sans_starttls') {
            $ext .= "250-STARTTLS\r\n";
        }
        if ($tls || $mode === 'sans_starttls') {
            $ext .= "250-AUTH PLAIN\r\n";
        }
        fwrite($c, "250-faux\r\n" . $ext . "250 8BITMIME\r\n");
    } elseif ($cmd === 'STAR' && $mode === 'starttls_554') {
        fwrite($c, "554 5.7.1 STARTTLS refuse\r\n");
    } elseif ($cmd === 'STAR') {
        fwrite($c, "220 go\r\n");
        stream_context_set_option($c, 'ssl', 'local_cert', $pem);
        if (! @stream_socket_enable_crypto($c, true, STREAM_CRYPTO_METHOD_TLSv1_2_SERVER | STREAM_CRYPTO_METHOD_TLSv1_3_SERVER)) {
            exit(2);
        }
        $tls = true;
    } elseif ($cmd === 'AUTH') {
        fwrite($c, $mode === 'auth_535' ? "535 5.7.8 identifiants refuses\r\n" : "235 ok\r\n");
    } elseif ($cmd === 'RCPT' && $mode === 'rcpt_550') {
        fwrite($c, "550 5.1.1 destinataire inconnu\r\n");
    } elseif ($cmd === 'MAIL' && $mode === 'mail_550') {
        fwrite($c, "550 5.7.1 expediteur hors registre\r\n");
    } elseif ($cmd === 'DATA') {
        fwrite($c, "354 go\r\n");
        $donnees = true;
    } elseif ($cmd === 'QUIT') {
        fwrite($c, "221 bye\r\n");
        break;
    } else {
        fwrite($c, "250 ok\r\n");
    }
}
fclose($c);
