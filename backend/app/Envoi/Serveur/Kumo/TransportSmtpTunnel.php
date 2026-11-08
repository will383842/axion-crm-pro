<?php

namespace App\Envoi\Serveur\Kumo;

use App\Envoi\Contrat\MessageAEnvoyer;
use App\Envoi\Serveur\ServeurEnvoiRefuse;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\Exception\UnexpectedResponseException;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Symfony\Component\Mailer\Transport\Smtp\Stream\SocketStream;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

/**
 * Injection SMTP par le tunnel : STARTTLS OBLIGATOIRE, AUTH seulement après TLS, certificat validé, en-tête
 * interne X-Envoi-Jeton. N'est construit que lorsque le verrou est ouvert (voir ServeurEnvoiKumo) : le
 * constructeur ne se connecte à rien, la connexion naît à `envoyer()`.
 * Toute exception hors réponse SMTP claire est traitée comme une coupure APRÈS DATA (sort incertain, jamais renvoyé),
 * sauf l'échec de connexion qui ne peut rien avoir transmis.
 */
final class TransportSmtpTunnel implements TransportInjection
{
    public function __construct(
        private readonly string $hoteTunnel,
        private readonly int $port,
        private readonly string $utilisateur,
        private readonly string $secret,
        private readonly int $delaiSecondes = 30,
    ) {
        if ($hoteTunnel === '' || $utilisateur === '' || $secret === '') {
            throw ServeurEnvoiRefuse::motif('transport SMTP du tunnel : hôte, utilisateur et secret exigés.');
        }
    }

    public function envoyer(MessageAEnvoyer $message, string $jeton): int
    {
        $t = new EsmtpTransport($this->hoteTunnel, $this->port, false);
        $t->setAutoTls(true);
        $t->setRequireTls(true); // pas de STARTTLS => échec, jamais d'AUTH en clair
        $t->setUsername($this->utilisateur);
        $t->setPassword($this->secret);
        $flux = $t->getStream();
        if (! $flux instanceof SocketStream) {
            throw ServeurEnvoiRefuse::motif('flux SMTP inattendu : délai de 30 s impossible à imposer.');
        }
        $flux->setTimeout(max(1, min(30, $this->delaiSecondes)));

        $courriel = (new Email)->from($message->expediteur)->to($message->destinataire)
            ->subject($message->sujet)->text($message->corps);
        $courriel->getHeaders()->addTextHeader('X-Envoi-Jeton', $jeton);
        try {
            $t->send($courriel, new Envelope(new Address($message->expediteur), [new Address($message->destinataire)]));

            return 250;
        } catch (UnexpectedResponseException $e) {
            $code = (int) $e->getCode();
            if ($code >= 400 && $code < 600) {
                return $code;
            }
            throw new CoupureTransport(true);
        } catch (TransportException $e) {
            throw new CoupureTransport(! str_contains($e->getMessage(), 'Connection could not be established'));
        } finally {
            try {
                $t->stop();
            } catch (\Throwable) {
            }
        }
    }
}
