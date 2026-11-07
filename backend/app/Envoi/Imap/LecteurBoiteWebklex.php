<?php

namespace App\Envoi\Imap;

use Carbon\Carbon;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use RuntimeException;
use Throwable;
use Webklex\PHPIMAP\Client;
use Webklex\PHPIMAP\ClientManager;
use Webklex\PHPIMAP\Folder;
use Webklex\PHPIMAP\IMAP;
use Webklex\PHPIMAP\Message;

/**
 * Lecteur IMAP RÉEL (webklex/php-imap, 100 % PHP : pas d'extension `imap`, qui
 * a quitté le cœur de PHP). Lecture seule : `leaveUnread()` (BODY.PEEK) ne pose
 * pas le drapeau « lu », rien n'est déplacé ni supprimé. TLS vérifié toujours.
 *
 * Les identifiants ne sont JAMAIS dans le dépôt : `$parametres` vient du
 * gestionnaire de secrets / de l'environnement du serveur.
 * Aucune pièce jointe n'est enregistrée ; le message n'est jamais lu en entier
 * (`RFC822.SIZE` d'abord, seuil MAX_OCTETS, lot limité). Remplaçable : tout
 * dépend de l'interface `LecteurBoite`.
 * Non couvert par les tests (aucun réseau en test) : la logique testable est
 * dans `AnalyseDsn`, `Classificateur` et `ReleveImap`.
 */
final class LecteurBoiteWebklex implements LecteurBoite
{
    /** Au-delà : message écarté (« à rattacher », sans effet), jamais téléchargé. */
    public const MAX_OCTETS = 262_144;

    /** Chiffrement imposé : aucun paramètre ne permet de l'affaiblir. */
    private const CHIFFREMENTS = ['ssl', 'tls', 'starttls'];

    private ?Client $client = null;

    /** @param array{host: string, port?: int, encryption?: string, username: string, password: string} $parametres */
    public function __construct(private readonly array $parametres, private readonly string $dossier = 'INBOX')
    {
        if (! in_array($parametres['encryption'] ?? 'ssl', self::CHIFFREMENTS, true)) {
            throw new InvalidArgumentException('Chiffrement IMAP imposé : ssl, tls ou starttls.');
        }
    }

    public function uidValidity(string $boite): int
    {
        $etat = $this->dossier()->examine();
        $valeur = $etat['uidvalidity'] ?? null;
        if (! is_int($valeur)) {
            throw new RuntimeException('UIDVALIDITY introuvable.');
        }

        return $valeur;
    }

    public function lire(string $boite, int $uidApres, ?DateTimeImmutable $depuis = null, int $limite = ReleveImap::LIMITE_LOT): array
    {
        // 1) En-têtes seulement, au plus $limite messages : jamais le corps d'emblée.
        $requete = $this->dossier()->query()->leaveUnread()->setFetchBody(false)->setFetchFlags(false)->fetchOrderAsc();
        $requete->whereUid(($uidApres + 1) . ':*');
        if ($depuis !== null) {
            $requete->whereSince(Carbon::instance($depuis));
        }
        $requete->limit($limite, 1);

        $messages = [];
        foreach ($requete->get() as $message) {
            /** @var Message $message */
            if ((int) $message->getUid() > $uidApres) { // « UID n:* » renvoie toujours le dernier message, même plus ancien
                $messages[(int) $message->getUid()] = $message;
            }
        }
        if ($messages === []) {
            return [];
        }

        // 2) RFC822.SIZE et INTERNALDATE d'abord, en UNE requête.
        $meta = $this->taillesEtDates(array_keys($messages));

        $sortie = [];
        foreach ($messages as $uid => $message) {
            $taille = $meta[$uid]['taille'] ?? null;
            $recuLe = $meta[$uid]['date'] ?? null;
            try {
                if ($recuLe === null) {
                    $date = $message->getDate()->first(); // à défaut seulement : l'en-tête Date est choisi par l'expéditeur
                    $recuLe = $date !== null ? (new DateTimeImmutable($date->toIso8601String()))->setTimezone(new DateTimeZone('Europe/Paris')) : new DateTimeImmutable('now', new DateTimeZone('Europe/Paris'));
                }
                if ($depuis !== null && $recuLe < $depuis) {
                    continue;
                }
                $sortie[] = $taille === null || $taille > self::MAX_OCTETS
                    ? self::ecarte($uid, $recuLe, 'taille', $message)
                    : $this->lireUn($uid, $recuLe, $message);
            } catch (Throwable) {
                // Un message illisible ne bloque pas la boîte : il est écarté, « à rattacher ».
                $sortie[] = self::ecarte($uid, $recuLe ?? new DateTimeImmutable('now', new DateTimeZone('Europe/Paris')), 'illisible', null);
            }
        }

        return $sortie;
    }

    /** Message sous le seuil de taille : corps analysé en mémoire, jamais enregistré, jamais `getRawBody()`. */
    private function lireUn(int $uid, DateTimeImmutable $recuLe, Message $message): MessageBrut
    {
        $message->parseBody(); // borné par MAX_OCTETS, vérifié plus haut
        $entete = (string) ($message->getHeader()->raw ?? '');
        $enTetes = AnalyseDsn::enTetes($entete);
        $dsn = ['statut' => null, 'messageId' => null, 'destinataire' => null];
        if (AnalyseDsn::estRapport($enTetes['content-type'] ?? '')) {
            // Pièces lues EN MÉMOIRE seulement pour le rapport ; aucune n'est jamais enregistrée.
            $contenu = '';
            foreach ($message->getAttachments() as $piece) {
                $contenu .= "\n" . mb_substr((string) $piece->getContent(), 0, ExtraitTexte::LIMITE * 4);
            }
            $dsn = AnalyseDsn::pourContenu($enTetes['content-type'], $contenu . "\n" . (string) $message->getTextBody());
        }

        return new MessageBrut(
            $uid,
            self::texte($message->getMessageId()),
            self::texte($message->getInReplyTo()),
            array_values(array_filter(preg_split('/[\s,]+/', trim(self::texte($message->getReferences()) ?? '')) ?: [], static fn ($r) => $r !== '')),
            self::texte($message->getFrom()) ?? '',
            $recuLe,
            self::texte($message->getSubject()) ?? '',
            ExtraitTexte::choisir((string) $message->getTextBody(), (string) $message->getHTMLBody()),
            $enTetes,
            $dsn['statut'],
            $dsn['messageId'],
            $dsn['destinataire'],
        );
    }

    private static function ecarte(int $uid, DateTimeImmutable $recuLe, string $raison, ?Message $message): MessageBrut
    {
        // On garde de quoi rattacher (identifiants d'en-tête) mais jamais le corps.
        $id = $inReplyTo = null;
        $refs = [];
        if ($message !== null) {
            try {
                $id = self::texte($message->getMessageId());
                $inReplyTo = self::texte($message->getInReplyTo());
                $refs = array_values(array_filter(preg_split('/[\s,]+/', trim(self::texte($message->getReferences()) ?? '')) ?: [], static fn ($r) => $r !== ''));
            } catch (Throwable) {
                $id = $inReplyTo = null;
                $refs = [];
            }
        }

        return new MessageBrut($uid, $id, $inReplyTo, $refs, '', $recuLe, '', '', [], null, null, null, $raison);
    }

    /**
     * @param  list<int>  $uids
     * @return array<int, array{taille: ?int, date: ?DateTimeImmutable}>
     */
    private function taillesEtDates(array $uids): array
    {
        $reponse = $this->client()->getConnection()->fetch(['RFC822.SIZE', 'INTERNALDATE'], $uids, null, IMAP::ST_UID);
        $donnees = is_object($reponse) && method_exists($reponse, 'data') ? $reponse->data() : $reponse;
        if (! is_array($donnees)) {
            throw new RuntimeException('RFC822.SIZE illisible : relève suspendue.'); // bruyant, pas de silence
        }
        $sortie = [];
        foreach ($donnees as $uid => $valeurs) {
            $valeurs = is_array($valeurs) ? $valeurs : [];
            $taille = $valeurs['RFC822.SIZE'] ?? null;
            $date = null;
            if (is_string($valeurs['INTERNALDATE'] ?? null)) {
                try {
                    $date = (new DateTimeImmutable($valeurs['INTERNALDATE']))->setTimezone(new DateTimeZone('Europe/Paris'));
                } catch (Throwable) {
                    $date = null;
                }
            }
            $sortie[(int) $uid] = ['taille' => is_numeric($taille) ? (int) $taille : null, 'date' => $date];
        }

        return $sortie;
    }

    private function dossier(): Folder
    {
        $dossier = $this->client()->getFolder($this->dossier);
        if ($dossier === null) {
            throw new RuntimeException('Dossier IMAP introuvable.');
        }

        return $dossier;
    }

    private function client(): Client
    {
        if ($this->client === null) {
            $this->client = (new ClientManager)->make([
                'host' => $this->parametres['host'],
                'port' => $this->parametres['port'] ?? 993,
                'encryption' => $this->parametres['encryption'] ?? 'ssl',
                'validate_cert' => true,
                'username' => $this->parametres['username'],
                'password' => $this->parametres['password'],
                'protocol' => 'imap',
            ]);
            $this->client->connect();
        }

        return $this->client;
    }

    private static function texte(mixed $attribut): ?string
    {
        $t = trim((string) $attribut);

        return $t === '' ? null : $t;
    }
}
