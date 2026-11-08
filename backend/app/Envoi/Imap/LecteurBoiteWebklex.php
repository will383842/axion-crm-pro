<?php

namespace App\Envoi\Imap;

use Carbon\CarbonImmutable;
use Closure;
use DateTimeImmutable;
use DateTimeZone;
use Exception;
use InvalidArgumentException;
use RuntimeException;
use SensitiveParameter;
use Throwable;
use Webklex\PHPIMAP\Config;
use Webklex\PHPIMAP\Connection\Protocols\ImapProtocol;
use Webklex\PHPIMAP\IMAP;
use Webklex\PHPIMAP\Message;

/**
 * Lecteur IMAP RÉEL (webklex/php-imap, 100 % PHP : pas d'extension `imap`). BOÎTE EN LECTURE SEULE,
 * sur le fil :
 *  - `EXAMINE` (jamais `SELECT`) : le dossier est ouvert en lecture seule ;
 *  - `UID SEARCH` pour les UID, `UID FETCH (RFC822.SIZE INTERNALDATE)` pour la taille et la date,
 *    `UID FETCH (UID BODY.PEEK[])` pour le message : `BODY.PEEK[]` ne pose jamais `\Seen`
 *    (jamais `RFC822`, `BODY[]` ni `parseBody()` en ligne) ;
 *  - aucun `STORE`, `COPY`, `MOVE`, `EXPUNGE`, `APPEND`, aucun drapeau posé ou retiré ;
 *  - le message est construit EN MÉMOIRE avec `Message::fromString` ; aucune pièce jointe n'est enregistrée.
 * Le test `ReleveImapAuditTest` le PROUVE en enregistrant les commandes envoyées à un faux serveur.
 *
 * PANNE ≠ ILLISIBLE : une panne (réseau, protocole IMAP) est une EXCEPTION relancée telle quelle ; le message
 * sera relu au prochain passage et le curseur n'avance pas. Seul un message dont le MIME est réellement
 * illisible (analyse locale d'un texte DÉJÀ téléchargé) est écarté (« à rattacher », sans effet).
 *
 * Les identifiants ne sont JAMAIS dans le dépôt : `$parametres` vient du gestionnaire de secrets. TLS vérifié
 * toujours. Lecture bornée : `RFC822.SIZE` d'abord, seuil MAX_OCTETS, lot limité.
 */
final class LecteurBoiteWebklex implements LecteurBoite
{
    /** Au-delà : message écarté (« à rattacher », sans effet), jamais téléchargé. */
    public const MAX_OCTETS = 262_144;

    /** Chiffrement imposé : aucun paramètre ne permet de l'affaiblir. */
    private const CHIFFREMENTS = ['ssl', 'tls', 'starttls'];

    private ?ImapProtocol $protocole = null;

    private bool $examine = false;

    private ?Config $config = null;

    /**
     * @param  array{host: string, port?: int, encryption?: string, username: string, password: string}  $parametres
     * @param  string  $boite  identifiant de la boîte servie : toute autre demande est refusée
     * @param  ?Closure(): ImapProtocol  $fabrique  protocole (tests : faux serveur sur le fil ; défaut : réel)
     */
    public function __construct(
        #[SensitiveParameter]
        private readonly array $parametres,
        private readonly string $boite,
        private readonly string $dossier = 'INBOX',
        private readonly ?Closure $fabrique = null,
    ) {
        if (! in_array($parametres['encryption'] ?? 'ssl', self::CHIFFREMENTS, true)) {
            throw new InvalidArgumentException('Chiffrement IMAP imposé : ssl, tls ou starttls.');
        }
    }

    public function uidValidity(string $boite): int
    {
        $this->verifier($boite);

        return $this->protege(function (): int {
            $valeur = $this->examiner()['uidvalidity'] ?? null;
            if (! is_int($valeur)) {
                throw new RuntimeException('UIDVALIDITY introuvable.');
            }

            return $valeur;
        });
    }

    public function lire(string $boite, int $uidApres, ?DateTimeImmutable $depuis = null, int $limite = ReleveImap::LIMITE_LOT): LotBoite
    {
        $this->verifier($boite);

        // Toute exception de cette zone est TECHNIQUE (réseau, protocole) : elle remonte, rien n'est écarté.
        return $this->protege(function () use ($uidApres, $depuis, $limite): LotBoite {
            if (! $this->examine) {
                $this->examiner();
            }
            // 1) UID bruts au-delà du curseur (« UID n:* » renvoie toujours le dernier message, même plus ancien).
            $reponse = $this->protocole()->search(['UID', ($uidApres + 1) . ':*'], IMAP::ST_UID);
            $ids = $reponse->data();
            $uids = [];
            foreach (is_array($ids) ? $ids : [] as $id) {
                if (is_numeric($id) && (int) $id > $uidApres) {
                    $uids[(int) $id] = (int) $id;
                }
            }
            sort($uids);
            if ($uids === []) {
                return new LotBoite([], $uidApres, false);
            }
            $page = array_slice($uids, 0, $limite);
            $suite = count($uids) > $limite;

            // 2) RFC822.SIZE et INTERNALDATE d'abord, en UNE requête.
            $meta = $this->taillesEtDates($page);

            $messages = [];
            foreach ($page as $uid) {
                if (! isset($meta[$uid])) {
                    continue; // disparu entre la recherche et la lecture : rien à lire
                }
                $recuLe = $meta[$uid]['date'] ?? CarbonImmutable::now('UTC');
                if ($depuis !== null && $recuLe < $depuis) {
                    continue; // filtré APRÈS la pagination sur les UID bruts
                }
                $taille = $meta[$uid]['taille'];
                if ($taille === null || $taille > self::MAX_OCTETS) {
                    $messages[] = new MessageBrut($uid, null, null, [], '', $recuLe, '', '', [], null, null, null, 'taille', hash('sha256', 'taille:' . ($taille ?? 'inconnue') . '|' . $recuLe->format('c')));

                    continue;
                }
                $brut = $this->telecharger($uid);
                if ($brut !== null) {
                    $messages[] = $this->lireUn($uid, $recuLe, $brut);
                }
            }

            return new LotBoite($messages, $page[count($page) - 1], $suite);
        });
    }

    /** `BODY.PEEK[]` : le message entier, SANS poser le drapeau « lu ». Null si le message a disparu. */
    private function telecharger(int $uid): ?string
    {
        $reponse = $this->protocole()->fetch(['UID', 'BODY.PEEK[]'], [$uid], null, IMAP::ST_UID);
        $donnees = $reponse->data();
        $brut = is_array($donnees) ? ($donnees[$uid]['BODY[]'] ?? null) : null;

        return is_string($brut) ? $brut : null;
    }

    /**
     * Analyse LOCALE d'un message déjà téléchargé : aucune panne réseau possible ici. Une exception = message
     * réellement illisible (MIME invalide) → écarté ; un échec de regex → STOP par prudence (jamais « humain »).
     */
    private function lireUn(int $uid, DateTimeImmutable $recuLe, string $brut): MessageBrut
    {
        $empreinte = hash('sha256', $brut);
        try {
            $message = Message::fromString($brut, $this->config());
            $expediteur = self::texte($message->getFrom());
            $messageId = self::texte($message->getMessageId());
            if ($expediteur === null && $messageId === null) {
                return self::ecarte($uid, $recuLe, 'illisible', $empreinte);
            }
            $entete = (string) ($message->getHeader()->raw ?? '');
            $references = array_values(array_filter(Pcre::decouper('/[\s,]+/', trim(self::texte($message->getReferences()) ?? '')), static fn (string $r): bool => $r !== ''));
            $sujet = self::texte($message->getSubject()) ?? '';
            try {
                $enTetes = AnalyseDsn::enTetes($entete);
                $dsn = ['statut' => null, 'messageId' => null, 'destinataire' => null];
                if (AnalyseDsn::estRapport($enTetes['content-type'] ?? '')) {
                    // Pièces lues EN MÉMOIRE seulement pour le rapport ; aucune n'est jamais enregistrée.
                    $contenu = '';
                    foreach ($message->getAttachments() as $piece) {
                        $contenu .= "\n" . mb_substr((string) $piece->getContent(), 0, ExtraitTexte::LIMITE * 4);
                    }
                    $dsn = AnalyseDsn::pourContenu($enTetes['content-type'], $contenu . "\n" . $message->getTextBody());
                }
                $corps = ExtraitTexte::choisir($message->getTextBody(), $message->getHTMLBody());
            } catch (EchecAnalyse) {
                return new MessageBrut($uid, $messageId, self::texte($message->getInReplyTo()), $references, $expediteur ?? '', $recuLe, $sujet, '', [], null, null, null, null, $empreinte, true);
            }

            return new MessageBrut(
                $uid,
                $messageId,
                self::texte($message->getInReplyTo()),
                $references,
                $expediteur ?? '',
                $recuLe,
                $sujet,
                $corps,
                $enTetes,
                $dsn['statut'],
                $dsn['messageId'],
                $dsn['destinataire'],
                null,
                $empreinte,
            );
        } catch (EchecAnalyse) {
            return new MessageBrut($uid, null, null, [], '', $recuLe, '', '', [], null, null, null, null, $empreinte, true);
        } catch (Exception) { // MIME invalide ; une `Error` (bug) ne doit JAMAIS se déguiser en « illisible »
            return self::ecarte($uid, $recuLe, 'illisible', $empreinte);
        }
    }

    private static function ecarte(int $uid, DateTimeImmutable $recuLe, string $raison, string $empreinte): MessageBrut
    {
        return new MessageBrut($uid, null, null, [], '', $recuLe, '', '', [], null, null, null, $raison, $empreinte);
    }

    /**
     * @param  list<int>  $uids
     * @return array<int, array{taille: ?int, date: ?DateTimeImmutable}>
     */
    private function taillesEtDates(array $uids): array
    {
        $reponse = $this->protocole()->fetch(['RFC822.SIZE', 'INTERNALDATE'], $uids, null, IMAP::ST_UID);
        $donnees = $reponse->data();
        if (! is_array($donnees)) {
            throw new RuntimeException('RFC822.SIZE illisible : relève suspendue.'); // bruyant, pas de silence
        }
        $sortie = [];
        foreach ($donnees as $uid => $valeurs) {
            $valeurs = is_array($valeurs) ? $valeurs : [];
            $taille = $valeurs['RFC822.SIZE'] ?? null;
            $date = self::dateInterne($valeurs);
            $sortie[(int) $uid] = ['taille' => is_numeric($taille) ? (int) $taille : null, 'date' => $date];
        }

        return $sortie;
    }

    /**
     * INTERNALDATE (« 02-Dec-2026 10:00:00 +0000 »). Piège de webklex : il découpe la chaîne entre guillemets
     * aux espaces (jour, puis heure => fuseau) ; on la recompose. Null si illisible (jamais d'exception).
     *
     * @param  array<int|string, mixed>  $valeurs
     */
    private static function dateInterne(array $valeurs): ?DateTimeImmutable
    {
        $brut = $valeurs['INTERNALDATE'] ?? null;
        if (! is_string($brut)) {
            return null;
        }
        $texte = trim($brut, '"');
        if (! str_contains($texte, ' ')) {
            $cles = array_keys($valeurs);
            $i = array_search('INTERNALDATE', $cles, true);
            $heure = $i === false ? null : ($cles[$i + 1] ?? null);
            if (is_string($heure) && preg_match('/^\d{1,2}:\d{2}:\d{2}$/', $heure) === 1) {
                $texte .= ' ' . $heure . ' ' . trim((string) $valeurs[$heure], '"');
            }
        }
        try {
            return (new DateTimeImmutable($texte))->setTimezone(new DateTimeZone('UTC'));
        } catch (Throwable) {
            return null;
        }
    }

    /** @return array<string, mixed> */
    private function examiner(): array
    {
        $reponse = $this->protocole()->examineFolder($this->dossier); // EXAMINE : lecture seule
        $etat = $reponse->data();
        $this->examine = true;

        return is_array($etat) ? $etat : [];
    }

    private function verifier(string $boite): void
    {
        if ($boite !== $this->boite) {
            throw new InvalidArgumentException('Boîte inattendue : ce lecteur ne sert qu\'une boîte.');
        }
    }

    /**
     * Toute exception technique ferme la connexion (la prochaine lecture se reconnecte) et REMONTE.
     *
     * @template T
     *
     * @param  Closure(): T  $f
     * @return T
     */
    private function protege(Closure $f): mixed
    {
        try {
            return $f();
        } catch (Throwable $e) {
            $this->protocole = null;
            $this->examine = false;
            throw $e;
        }
    }

    private function protocole(): ImapProtocol
    {
        if ($this->protocole === null) {
            $p = $this->fabrique !== null
                ? ($this->fabrique)()
                : new ImapProtocol($this->config(), true, $this->parametres['encryption'] ?? 'ssl'); // validation du certificat : toujours
            $p->connect($this->parametres['host'], $this->parametres['port'] ?? 993);
            $p->login($this->parametres['username'], $this->parametres['password']);
            $this->protocole = $p;
        }

        return $this->protocole;
    }

    private function config(): Config
    {
        return $this->config ??= Config::make();
    }

    private static function texte(mixed $attribut): ?string
    {
        $t = trim((string) $attribut);

        return $t === '' ? null : $t;
    }
}
