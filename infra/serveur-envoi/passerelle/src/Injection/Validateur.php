<?php

declare(strict_types=1);

namespace Passerelle\Injection;

use Passerelle\Signature;

/**
 * Validation du corps de POST /v1/injections (C2-2). Tout refus lève une \DomainException : `getCode()` = statut HTTP
 * (400 forme, 422 conformité/alignement), `getMessage()` = motif. Rien n'est stocké ni envoyé avant la fin de la validation.
 */
final class Validateur
{
    public const TAILLE_MAX_MESSAGE = 1_048_576;

    private const VERP = '/^rb-[0-9a-f]{42}@bounce\.[a-z0-9.-]+$/D';

    private const RCPT = "/^[A-Za-z0-9.!#\$%&'*+\\/=?^_`{|}~-]{1,64}@[A-Za-z0-9](?:[A-Za-z0-9.-]{0,251}[A-Za-z0-9])?$/D";

    /** @return array{id_injection:string,mail_from:string,rcpt_to:string,message:string} message = octets décodés */
    public static function valider(string $corps): array
    {
        $d = json_decode($corps, true, 4);
        $cles = ['id_injection', 'mail_from', 'rcpt_to', 'message'];
        if (! is_array($d) || array_is_list($d) || array_diff(array_keys($d), $cles) !== [] || array_diff($cles, array_keys($d)) !== []) {
            throw new \DomainException('corps', 400);
        }
        foreach ($cles as $c) {
            if (! is_string($d[$c])) {
                throw new \DomainException('corps', 400);
            }
        }
        if (! Signature::estUuid($d['id_injection'])) {
            throw new \DomainException('id_injection', 400);
        }
        if (preg_match(self::VERP, $d['mail_from']) !== 1 || strlen($d['mail_from']) > 254) {
            throw new \DomainException('mail_from', 400);
        }
        // Un seul RCPT, une adresse ASCII nue : ni virgule, ni nom affiché, ni CR/LF (anti-relais).
        if (strlen($d['rcpt_to']) > 254 || preg_match(self::RCPT, $d['rcpt_to']) !== 1) {
            throw new \DomainException('rcpt_to', 400);
        }
        $message = base64_decode($d['message'], true);
        if ($message === false || $message === '' || base64_encode($message) !== $d['message']) {
            throw new \DomainException('message', 400);
        }
        if (strlen($message) > self::TAILLE_MAX_MESSAGE) {
            throw new \DomainException('message_trop_gros', 400);
        }
        // Anti « SMTP smuggling » : CRLF seulement, ni LF nu ni CR nu, dans les en-têtes comme dans le corps.
        if (preg_match('/(?<!\r)\n|\r(?!\n)/', $message) === 1) {
            throw new \DomainException('fin_de_ligne', 400);
        }
        self::conformite($message, $d['mail_from']);

        return ['id_injection' => $d['id_injection'], 'mail_from' => $d['mail_from'], 'rcpt_to' => $d['rcpt_to'], 'message' => $message];
    }

    private static function conformite(string $message, string $mailFrom): void
    {
        $fin = strpos($message, "\r\n\r\n");
        $entetes = preg_replace('/\r\n[ \t]+/', ' ', $fin === false ? $message : substr($message, 0, $fin)) ?? '';
        $vus = [];
        foreach (explode("\r\n", $entetes) as $ligne) {
            $p = strpos($ligne, ':');
            if ($p === false) {
                continue;
            }
            $nom = strtolower(trim(substr($ligne, 0, $p)));
            if (str_starts_with($nom, 'x-envoi-') || $nom === 'dkim-signature') {
                throw new \DomainException('conformite', 422); // E2/E6 : aucun X-Envoi-* ; D3 : la signature DKIM est celle du moteur
            }
            $vus[$nom][] = trim(substr($ligne, $p + 1));
        }
        if (! isset($vus['list-unsubscribe']) || ($vus['list-unsubscribe-post'] ?? null) !== ['List-Unsubscribe=One-Click']) {
            throw new \DomainException('conformite', 422);
        }
        $from = $vus['from'] ?? [];
        $domaine = count($from) === 1 && preg_match('/<[^<>@\s]+@([^<>@\s]+)>$|^[^<>@\s]+@([^<>@\s]+)$/', $from[0], $m) === 1 ? strtolower($m[2] ?? $m[1]) : null;
        $bounce = strtolower(substr($mailFrom, (int) strpos($mailFrom, '@') + 1));
        if ($domaine === null || $domaine === '' || $bounce !== 'bounce.'.$domaine) {
            throw new \DomainException('alignement', 422);
        }
    }
}
