<?php

namespace App\Envoi\Serveur\Kumo;

use App\Envoi\Contrat\MessageAEnvoyer;
use App\Envoi\Imap\Verp;
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
 * Classement : « APRÈS DATA » = la réponse 354 à DATA a été reçue (le message a pu partir : sort incertain, jamais
 * renvoyé). AVANT DATA, rien n'a pu partir : un code NUMÉRIQUE 4xx/5xx de MAIL/RCPT est RENDU tel quel (4xx = report,
 * 5xx = échec). Un 5xx reçu AVANT MAIL FROM (bannière, EHLO, STARTTLS) concerne la connexion, pas le message :
 * RefusConnexion (alerte, pause, report), jamais rendu. Connexion, bannière, STARTTLS absent = CoupureTransport(false)
 * (report) ; AUTH refusé ou certificat refusé = EchecAuthentification, classé À PART (alerte et pause).
 *
 * X-Envoi-Source posé ; chemin de retour VERP `rb-<jeton>@bounce.<domaine>` (si la clé d'empreinte est configurée).
 * ATTENTION : ce VERP n'est PAS accepté par l'écouteur K4 actuel (550 hors registre) : dette I1 et ESCALADE, voir
 * docs/envoi/K9-adaptateur-kumo.md. DETTE (voir docs/envoi/K9-adaptateur-kumo.md) : le ComposeurMessage (List-Unsubscribe,
 * pied art. 14) n'est pas utilisé ici.
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

    public function envoyer(MessageAEnvoyer $message, #[\SensitiveParameter] string $jeton, string $source = ''): int
    {
        // Même règle que L4 : l'état « injection_en_cours » doit être COMMITÉ avant tout appel réseau.
        if (DB::transactionLevel() > 0
            && ! ((bool) config('envoi.smtp_tolere_transaction_englobante', false) && app()->environment('testing'))) {
            throw ServeurEnvoiRefuse::motif('aucun appel réseau dans une transaction ouverte.');
        }
        if (preg_match('/^[0-9a-f]{32}$/', $jeton) !== 1) {
            // 32 exactement : au-delà, le VERP ne serait pas posé, en silence.
            throw ServeurEnvoiRefuse::motif('jeton d’envoi invalide (32 caractères hexadécimaux exigés).');
        }
        if ($source !== '' && preg_match('/^[A-Za-z0-9._-]{1,64}$/', $source) !== 1) {
            throw ServeurEnvoiRefuse::motif('source d’envoi invalide.');
        }
        // Tout ce qui peut être refusé localement l'est ICI, avant la moindre connexion.
        [$courriel, $enveloppe] = self::composer($message, $jeton, $source);

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

        try {
            $t->send($courriel, $enveloppe);

            return 250;
        } catch (TransportException $e) {
            $code = $e instanceof UnexpectedResponseException && $e->getCode() >= 100 && $e->getCode() < 600 ? (int) $e->getCode() : 0;
            // 5xx avant MAIL FROM (bannière, EHLO, STARTTLS) : refus de la connexion, pas du message.
            if ($code >= 500 && ! $t->donneesEngagees && ! $t->mailEmis) {
                throw new RefusConnexion;
            }
            if ($t->donneesEngagees) {
                if ($code >= 400) {
                    return $code; // réponse claire à la fin de DATA
                }
                throw new CoupureTransport(true); // coupure ou délai après DATA : sort incertain
            }
            // Avant DATA : rien n'a pu partir. AUTH ou certificat refusé : classé à part, sans cause chaînée.
            if (self::estEchecAuthentification($e->getMessage())) {
                throw new EchecAuthentification;
            }
            // Un code numérique clair de MAIL/RCPT : 4xx = report, 5xx = échec définitif.
            if ($code >= 400) {
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

    /** Messages de Symfony pour un AUTH refusé (ou sans mécanisme) et un certificat/handshake TLS refusé. */
    private static function estEchecAuthentification(string $message): bool
    {
        return str_starts_with($message, 'Failed to authenticate')
            || str_starts_with($message, 'Failed to find an authenticator')
            || str_starts_with($message, 'Unable to connect with STARTTLS');
    }

    /**
     * Adresses RFC dont la partie locale est ASCII (Symfony ne refuse le non-ASCII qu'À la commande RCPT/MAIL, donc
     * après l'état d'injection : on le refuse ici, avant tout état).
     *
     * @return array{0: Address, 1: Address}
     *
     * @throws \Throwable
     */
    public static function adressesValides(string $expediteur, string $destinataire): array
    {
        $paires = [new Address($expediteur), new Address($destinataire)];
        foreach ($paires as $adresse) {
            if (preg_match('/^[\x21-\x7E]+@/', $adresse->getAddress()) !== 1) {
                throw new \InvalidArgumentException('partie locale non ASCII');
            }
        }

        return $paires;
    }

    /** @return array{0: Email, 1: Envelope} */
    private static function composer(MessageAEnvoyer $message, string $jeton, string $source): array
    {
        try {
            [$de, $a] = self::adressesValides($message->expediteur, $message->destinataire);
            $retour = $de;
            $cle = (string) config('envoi.cle_empreinte', '');
            if ($cle !== '' && strlen($jeton) === 32 && str_contains($de->getAddress(), '@')) {
                $uuid = (string) preg_replace('/^(.{8})(.{4})(.{4})(.{4})(.{12})$/', '$1-$2-$3-$4-$5', $jeton);
                $retour = new Address(Verp::adressePour($uuid, substr($de->getAddress(), (int) strrpos($de->getAddress(), '@') + 1)));
            }
            $enveloppe = new Envelope($retour, [$a]);
            $courriel = (new Email)->from($de)->to($a)->subject($message->sujet)->text($message->corps);
            $courriel->getHeaders()->addTextHeader('X-Envoi-Jeton', $jeton);
            if ($source !== '') {
                $courriel->getHeaders()->addTextHeader('X-Envoi-Source', $source);
            }
        } catch (\Throwable) {
            throw ServeurEnvoiRefuse::motif('enveloppe ou message invalide (adresse non conforme).');
        }

        return [$courriel, $enveloppe];
    }
}
