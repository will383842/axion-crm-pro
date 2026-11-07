<?php

namespace App\Envoi\Imap;

use Carbon\Carbon;
use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;
use Webklex\PHPIMAP\Client;
use Webklex\PHPIMAP\ClientManager;
use Webklex\PHPIMAP\Folder;
use Webklex\PHPIMAP\Message;

/**
 * Lecteur IMAP RÉEL (webklex/php-imap, 100 % PHP : pas d'extension `imap`, qui
 * a quitté le cœur de PHP). Lecture seule : `leaveUnread()` (BODY.PEEK) ne pose
 * pas le drapeau « lu », rien n'est déplacé ni supprimé. TLS vérifié toujours.
 *
 * Les identifiants ne sont JAMAIS dans le dépôt : `$parametres` vient du
 * gestionnaire de secrets / de l'environnement du serveur.
 * Non couvert par les tests (aucun réseau en test) : la logique testable est
 * dans `AnalyseDsn`, `Classificateur` et `ReleveImap`.
 */
final class LecteurBoiteWebklex implements LecteurBoite
{
    private ?Client $client = null;

    /** @param array{host: string, port?: int, encryption?: string, username: string, password: string} $parametres */
    public function __construct(private readonly array $parametres, private readonly string $dossier = 'INBOX') {}

    public function uidValidity(string $boite): int
    {
        $etat = $this->dossier()->examine();
        $valeur = $etat['uidvalidity'] ?? null;
        if (! is_int($valeur)) {
            throw new RuntimeException('UIDVALIDITY introuvable.');
        }

        return $valeur;
    }

    public function lire(string $boite, int $uidApres, ?DateTimeImmutable $depuis = null): array
    {
        $requete = $this->dossier()->query()->leaveUnread()->setFetchBody(true)->setFetchFlags(false)->fetchOrderAsc();
        $requete->whereUid(($uidApres + 1) . ':*');
        if ($depuis !== null) {
            $requete->whereSince(Carbon::instance($depuis));
        }

        $sortie = [];
        foreach ($requete->get() as $message) {
            /** @var Message $message */
            $uid = (int) $message->getUid();
            if ($uid <= $uidApres) {
                continue; // « UID n:* » renvoie toujours le dernier message, même plus ancien
            }
            $recu = $message->getDate()->first();
            $recuLe = $recu !== null
                ? (new DateTimeImmutable($recu->toIso8601String()))->setTimezone(new DateTimeZone('Europe/Paris'))
                : new DateTimeImmutable('now', new DateTimeZone('Europe/Paris'));
            if ($depuis !== null && $recuLe < $depuis) {
                continue;
            }

            $brut = (string) $message->getRawBody();
            $dsn = AnalyseDsn::depuisBrut($brut);
            $entete = (string) ($message->getHeader()->raw ?? '');
            $enTetes = AnalyseDsn::enTetes($entete);
            $estRapport = str_contains(strtolower($enTetes['content-type'] ?? ''), 'report-type=delivery-status');

            $sortie[] = new MessageBrut(
                $uid,
                self::texte($message->getMessageId()),
                self::texte($message->getInReplyTo()),
                array_values(array_filter(preg_split('/[\s,]+/', trim(self::texte($message->getReferences()) ?? '')) ?: [], static fn ($r) => $r !== '')),
                self::texte($message->getFrom()) ?? '',
                $recuLe,
                self::texte($message->getSubject()) ?? '',
                mb_substr((string) $message->getTextBody(), 0, 4000),
                $enTetes,
                $estRapport ? $dsn['statut'] : null,
                $dsn['messageId'],
                $dsn['destinataire'],
            );
        }

        return $sortie;
    }

    private function dossier(): Folder
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
        $dossier = $this->client->getFolder($this->dossier);
        if ($dossier === null) {
            throw new RuntimeException('Dossier IMAP introuvable.');
        }

        return $dossier;
    }

    private static function texte(mixed $attribut): ?string
    {
        $t = trim((string) $attribut);

        return $t === '' ? null : $t;
    }
}
