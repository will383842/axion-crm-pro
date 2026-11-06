<?php

namespace App\Envoi\Message;

use InvalidArgumentException;
use OpenSSLAsymmetricKey;

/**
 * Signature DKIM (RFC 6376, rsa-sha256, canonisation relaxed/relaxed) faite par
 * le CRM avec un sélecteur dédié. La clé privée est FOURNIE (contenu PEM ou
 * chemin lu à l'exécution depuis la configuration) : elle n'est jamais dans le
 * dépôt.
 */
final class DkimSigneur
{
    /** En-têtes signés — liste minimale exigée (ordre = ordre de `h=`). */
    public const ENTETES_SIGNES = [
        'From', 'To', 'Subject', 'Date', 'Message-ID', 'MIME-Version',
        'Content-Type', 'List-Unsubscribe', 'List-Unsubscribe-Post',
    ];

    private readonly OpenSSLAsymmetricKey $cle;

    public function __construct(
        private readonly string $domaine,
        private readonly string $selecteur,
        string $clePriveePem,
    ) {
        $cle = openssl_pkey_get_private($clePriveePem);
        if ($cle === false) {
            throw new InvalidArgumentException('Clé privée DKIM illisible.');
        }
        $this->cle = $cle;
        if (preg_match('/^[a-z0-9.-]+$/i', $domaine . $selecteur) !== 1) {
            throw new InvalidArgumentException('Domaine ou sélecteur DKIM invalide.');
        }
    }

    public static function depuisFichier(string $domaine, string $selecteur, string $chemin): self
    {
        $pem = is_readable($chemin) ? file_get_contents($chemin) : false;
        if ($pem === false) {
            throw new InvalidArgumentException('Fichier de clé DKIM illisible.');
        }

        return new self($domaine, $selecteur, $pem);
    }

    /**
     * @param array<string,string> $entetes nom => valeur (non pliée), doit contenir les ENTETES_SIGNES
     * @return string ligne « DKIM-Signature: … » (sans CRLF final)
     */
    public function signer(array $entetes, string $corps, ?int $horodatage = null): string
    {
        $parNom = [];
        foreach ($entetes as $nom => $valeur) {
            $parNom[strtolower($nom)] = $valeur;
        }

        $h = [];
        $canon = '';
        foreach (self::ENTETES_SIGNES as $nom) {
            if (! isset($parNom[strtolower($nom)])) {
                throw new InvalidArgumentException("En-tête à signer absent : {$nom}.");
            }
            $h[] = strtolower($nom);
            $canon .= self::canonEntete($nom, $parNom[strtolower($nom)]) . "\r\n";
        }

        $bh = base64_encode(hash('sha256', self::canonCorps($corps), true));
        $sig = 'v=1; a=rsa-sha256; c=relaxed/relaxed; d=' . $this->domaine . '; s=' . $this->selecteur
            . '; t=' . ($horodatage ?? time()) . '; h=' . implode(':', $h) . '; bh=' . $bh . '; b=';

        $aSigner = $canon . self::canonEntete('DKIM-Signature', $sig);
        if (! openssl_sign($aSigner, $signature, $this->cle, OPENSSL_ALGO_SHA256)) {
            throw new InvalidArgumentException('Échec de la signature DKIM.');
        }

        return 'DKIM-Signature: ' . $sig . rtrim(chunk_split(base64_encode($signature), 72, "\r\n\t"));
    }

    public static function canonEntete(string $nom, string $valeur): string
    {
        $valeur = preg_replace('/\r\n(?=[ \t])/', '', $valeur) ?? $valeur;
        $valeur = trim((string) preg_replace('/[ \t]+/', ' ', $valeur));

        return strtolower($nom) . ':' . $valeur;
    }

    public static function canonCorps(string $corps): string
    {
        $lignes = explode("\r\n", $corps);
        foreach ($lignes as $i => $l) {
            $lignes[$i] = rtrim((string) preg_replace('/[ \t]+/', ' ', $l), ' ');
        }
        $corps = rtrim(implode("\r\n", $lignes), "\r\n");

        return $corps === '' ? '' : $corps . "\r\n";
    }
}
