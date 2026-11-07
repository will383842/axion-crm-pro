<?php

/**
 * FAUX serveur SMTP local (socket PHP, 127.0.0.1 uniquement) pour les tests.
 * Usage : php faux_smtp_serveur.php <mode> <fichier_recu>
 * Modes : ok | coupure_apres_data | 451_apres_data | 550_apres_data
 * Écrit le port sur la 1re ligne de stdout, enregistre le brut reçu (DATA) dans <fichier_recu>.
 * Enregistre aussi chaque commande reçue (hors DATA) dans <fichier_recu>.cmd, et ANNONCE
 * AUTH PLAIN LOGIN sans jamais proposer STARTTLS : sert à prouver qu'aucun identifiant
 * n'est envoyé en clair.
 * Sert une seule connexion puis s'arrête. Aucun accès réseau externe.
 */
[$_, $mode, $fichier] = $argv;
$srv = stream_socket_server('tcp://127.0.0.1:0', $errno, $err);
$nom = stream_socket_get_name($srv, false);
fwrite(STDOUT, substr($nom, strrpos($nom, ':') + 1) . "\n");
fflush(STDOUT);

$c = @stream_socket_accept($srv, 20);
if ($c === false) {
    exit(1);
}
fwrite($c, "220 faux ESMTP\r\n");
$donnees = false;
$recu = '';
while (($ligne = fgets($c)) !== false) {
    if ($donnees) {
        if ($ligne === ".\r\n") {
            file_put_contents($fichier, $recu);
            switch ($mode) {
                case 'coupure_apres_data':
                    fclose($c);   // coupure entre la fin de transmission et l'accusé
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
    if ($cmd === 'EHLO' || $cmd === 'HELO') {
        fwrite($c, "250-faux\r\n250-AUTH PLAIN LOGIN\r\n250 8BITMIME\r\n");
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
