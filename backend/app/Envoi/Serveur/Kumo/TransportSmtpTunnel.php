<?php

namespace App\Envoi\Serveur\Kumo;

use App\Envoi\Contrat\MessageAEnvoyer;
use App\Envoi\Serveur\ServeurEnvoiRefuse;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\Exception\UnexpectedResponseException;
use Symfony\Component\Mailer\Transport\Smtp\Stream\SocketStream;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

/**
 * Injection SMTP par le tunnel : STARTTLS OBLIGATOIRE, AUTH seulement après TLS, certificat validé (CA et nom
 * configurables), TLS 1.2 minimum, en-tête interne X-Envoi-Jeton (un jeton PAR message). N'est construit que
 * lorsque le verrou est ouvert (voir ServeurEnvoiKumo) : le constructeur ne se connecte à rien.
 *
 * Classement des coupures : « APRÈS DATA » = la réponse 354 à DATA a été reçue (le message a pu partir : sort
 * incertain, jamais renvoyé) ; tout le reste (connexion, bannière, STARTTLS absent, AUTH refusé, certificat,
 * MAIL/RCPT) est « AVANT DATA » : rien n'a pu partir, simple report.
 */
final class TransportSmtpTunnel implements TransportInjection
{
    public function __construct(
        private readonly string $hoteTunnel,
        private readonly int $port,
        private readonly string $utilisateur,
        #[\SensitiveParameter]
        private readonly string $secret,
        private readonly int $delaiSecondes = 30,
        private readonly ?string $cafile = null,
        private readonly ?string $nomCertificat = null,
    ) {
        if ($hoteTunnel === '' || $utilisateur === '' || $secret === '') {
            throw ServeurEnvoiRefuse::motif('transport SMTP du tunnel : hôte, utilisateur et secret exigés.');
        }
    }

    /**
     * Options TLS : certificat et nom validés, TLS 1.2 minimum (jamais 1.0/1.1), CA et nom configurables.
     *
     * @return array{ssl: array<string, mixed>}
     */
    public static function optionsTls(?string $cafile, ?string $nomCertificat): array
    {
        $ssl = [
            'SNI_enabled' => true,
            'verify_peer' => true,
            'verify_peer_name' => true,
            'crypto_method' => STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT,
        ];
        if ($nomCertificat !== null && $nomCertificat !== '') {
            $ssl['peer_name'] = $nomCertificat;
        }
        if ($cafile !== null && $cafile !== '') {
            $ssl['cafile'] = $cafile;
        }

        return ['ssl' => $ssl];
    }

    public function envoyer(MessageAEnvoyer $message, #[\SensitiveParameter] string $jeton): int
    {
        // Même règle que L4 : l'état « injection_en_cours » doit être COMMITÉ avant tout appel réseau.
        if (DB::transactionLevel() > 0
            && ! ((bool) config('envoi.smtp_tolere_transaction_englobante', false) && app()->environment('testing'))) {
            throw ServeurEnvoiRefuse::motif('aucun appel réseau dans une transaction ouverte.');
        }
        if (preg_match('/^[0-9a-f]{32,64}$/', $jeton) !== 1) {
            throw ServeurEnvoiRefuse::motif('jeton d’envoi invalide (32 à 64 caractères hexadécimaux exigés).');
        }

        $t = new EsmtpSuiviData($this->hoteTunnel, $this->port, false);
        $t->setAutoTls(true);
        $t->setRequireTls(true); // pas de STARTTLS => échec, jamais d'AUTH en clair
        $t->setUsername($this->utilisateur);
        $t->setPassword($this->secret);
        $flux = $t->getStream();
        if (! $flux instanceof SocketStream) {
            throw ServeurEnvoiRefuse::motif('flux SMTP inattendu : délai de 30 s impossible à imposer.');
        }
        $flux->setTimeout(max(1, min(30, $this->delaiSecondes)));
        $flux->setStreamOptions(self::optionsTls($this->cafile, $this->nomCertificat));

        $courriel = (new Email)->from($message->expediteur)->to($message->destinataire)
            ->subject($message->sujet)->text($message->corps);
        $courriel->getHeaders()->addTextHeader('X-Envoi-Jeton', $jeton);
        try {
            $t->send($courriel, new Envelope(new Address($message->expediteur), [new Address($message->destinataire)]));

            return 250;
        } catch (TransportException $e) {
            if ($t->donneesEngagees) {
                $code = $e instanceof UnexpectedResponseException ? (int) $e->getCode() : 0;
                if ($code >= 400 && $code < 600) {
                    return $code; // réponse claire à la fin de DATA
                }
                throw new CoupureTransport(true); // coupure ou délai après DATA : sort incertain
            }
            // Avant DATA : rien n'a pu partir. Un refus clair 4xx de MAIL/RCPT est un report ordinaire.
            $code = $e instanceof UnexpectedResponseException ? (int) $e->getCode() : 0;
            if ($code >= 400 && $code < 500) {
                return $code;
            }
            throw new CoupureTransport(false);
        } finally {
            try {
                $t->stop();
            } catch (\Throwable) {
            }
        }
    }
}
