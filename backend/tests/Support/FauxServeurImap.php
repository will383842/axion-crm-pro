<?php

namespace Tests\Support;

use Webklex\PHPIMAP\Connection\Protocols\ImapProtocol;

/**
 * Faux serveur IMAP AU NIVEAU DU FIL (flux PHP) : il ENREGISTRE chaque commande
 * envoyée et répond comme un vrai serveur. Aucun réseau. Les tests prouvent la
 * lecture seule en lisant `commandes()`, pas en cherchant des chaînes dans le code.
 *
 * Fixtures FICTIVES (dépôt public).
 */
final class FauxServeurImap
{
    /** @var list<string> commandes reçues, sans étiquette ni fin de ligne */
    public static array $commandes = [];

    /** @var array<int, array{raw: string, date: string, taille: ?int}> */
    public static array $messages = [];

    public static int $uidValidity = 777;

    /** UID dont le téléchargement du corps coupe la connexion (panne passagère). */
    public static ?int $panneSurCorps = null;

    /** Coupe la connexion dès la commande de recherche. */
    public static bool $panneSurRecherche = false;

    private static string $tampon = '';

    private static bool $coupe = false;

    public static function reinitialiser(): void
    {
        self::$commandes = [];
        self::$messages = [];
        self::$uidValidity = 777;
        self::$panneSurCorps = null;
        self::$panneSurRecherche = false;
        self::$tampon = '';
        self::$coupe = false;
        if (! in_array('fauximap', stream_get_wrappers(), true)) {
            stream_wrapper_register('fauximap', self::class);
        }
    }

    public static function deposer(int $uid, string $raw, string $date = '02-Dec-2026 10:00:00 +0000', ?int $taille = null): void
    {
        self::$messages[$uid] = ['raw' => $raw, 'date' => $date, 'taille' => $taille ?? strlen($raw)];
    }

    /** Message RFC 5322 minimal, valide. */
    public static function brut(string $messageId, string $corps, string $de = 'client@zz-client.example.invalid', string $reponseA = 'envoi-1@zz.example.invalid', string $sujet = 'Re: bonjour'): string
    {
        return "From: {$de}\r\nTo: boite@zz.example.invalid\r\nSubject: {$sujet}\r\nMessage-ID: <{$messageId}>\r\nIn-Reply-To: <{$reponseA}>\r\nDate: Wed, 02 Dec 2026 10:00:00 +0000\r\nContent-Type: text/plain; charset=UTF-8\r\n\r\n{$corps}\r\n";
    }

    /** @return list<string> */
    public static function commandes(): array
    {
        return self::$commandes;
    }

    // ---- flux PHP -------------------------------------------------------------------------------

    /** @var resource|null */
    public $context;

    public function stream_open(string $path, string $mode, int $options, ?string &$opened): bool
    {
        self::$tampon = "* OK IMAP faux pret\r\n";

        return true;
    }

    public function stream_write(string $data): int
    {
        if (preg_match('/^(TAG\d+) (.*)\r\n$/s', $data, $m) !== 1) {
            return strlen($data);
        }
        [$tag, $cmd] = [$m[1], $m[2]];
        self::$commandes[] = $cmd;
        self::repondre($tag, $cmd);

        return strlen($data);
    }

    public function stream_read(int $count): string|false
    {
        if (self::$tampon === '') {
            return self::$coupe ? '' : '';
        }
        $sortie = substr(self::$tampon, 0, $count);
        self::$tampon = (string) substr(self::$tampon, strlen($sortie));

        return $sortie;
    }

    public function stream_eof(): bool
    {
        return self::$tampon === '';
    }

    public function stream_close(): void {}

    public function stream_flush(): bool
    {
        return true;
    }

    /** @return array<string, int> */
    public function stream_stat(): array
    {
        return [];
    }

    public function stream_set_option(int $option, int $arg1, ?int $arg2): bool
    {
        return false;
    }

    private static function repondre(string $tag, string $cmd): void
    {
        $r = &self::$tampon;
        if (str_starts_with($cmd, 'LOGIN')) {
            $r .= "{$tag} OK connecte\r\n";
        } elseif (str_starts_with($cmd, 'EXAMINE') || str_starts_with($cmd, 'SELECT')) {
            $n = count(self::$messages);
            $r .= "* {$n} EXISTS\r\n* OK [UIDVALIDITY " . self::$uidValidity . "] UIDs valides\r\n* OK [UIDNEXT 9999] suivant\r\n{$tag} OK [READ-ONLY] fait\r\n";
        } elseif (str_starts_with($cmd, 'UID SEARCH')) {
            if (self::$panneSurRecherche) {
                $r = ''; // connexion coupée : lecture vide

                return;
            }
            preg_match('/UID (\d+):\*/', $cmd, $mm);
            $depuis = (int) ($mm[1] ?? 1);
            $uids = array_values(array_filter(array_keys(self::$messages), static fn (int $u): bool => $u >= $depuis));
            if ($uids === [] && self::$messages !== []) {
                $uids = [max(array_keys(self::$messages))]; // « n:* » renvoie toujours le dernier message
            }
            sort($uids);
            $r .= '* SEARCH ' . implode(' ', $uids) . "\r\n{$tag} OK recherche faite\r\n";
        } elseif (str_starts_with($cmd, 'UID FETCH')) {
            preg_match('/^UID FETCH (\S+) \((.*)\)$/', $cmd, $mm);
            $uids = array_map('intval', explode(',', (string) preg_replace('/^(\d+):\1$/', '$1', $mm[1] ?? '')));
            foreach ($uids as $i => $uid) {
                $msg = self::$messages[$uid] ?? null;
                if ($msg === null) {
                    continue;
                }
                $n = $i + 1;
                if (str_contains($cmd, 'BODY.PEEK[]')) {
                    if (self::$panneSurCorps === $uid) {
                        $r = '';

                        return;
                    }
                    $r .= "* {$n} FETCH (UID {$uid} BODY[] {" . strlen($msg['raw']) . "}\r\n" . $msg['raw'] . ")\r\n";
                } else {
                    $r .= "* {$n} FETCH (UID {$uid} RFC822.SIZE " . $msg['taille'] . ' INTERNALDATE "' . $msg['date'] . "\")\r\n";
                }
            }
            $r .= "{$tag} OK fetch fait\r\n";
        } elseif (str_starts_with($cmd, 'LOGOUT')) {
            $r .= "* BYE a bientot\r\n{$tag} OK fin\r\n";
        } else { // STORE, COPY, EXPUNGE… : répond OK (le test prouve qu'ils ne sont JAMAIS envoyés)
            $r .= "{$tag} OK {$cmd}\r\n";
        }
    }
}

/** Protocole webklex dont le flux est le faux serveur : aucune socket. */
final class FauxProtocoleImap extends ImapProtocol
{
    public function createStream($transport, string $host, int $port, int $timeout)
    {
        return fopen('fauximap://serveur', 'r+');
    }
}
