<?php

namespace App\Crm\Emails\Dns;

use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * Résolveur DNS en UDP, EN PARALLÈLE et à DÉBIT LIMITÉ, vers UN résolveur
 * choisi (configurable : celui du serveur, un Unbound local, un public).
 *
 * Pourquoi pas `dns_get_record()` : il est bloquant, une requête à la fois, et
 * ne dit pas la différence entre « le domaine n'existe pas » et « le
 * résolveur n'a pas répondu ». Sur ~200 000 domaines, en série, la vérification
 * durerait des jours ; et une panne passagère du résolveur ferait passer des
 * milliers d'adresses saines à `invalide`. Ici, chaque réponse est lue (code
 * de retour compris) et une absence de réponse reste `indetermine`.
 *
 *  - `parallele` requêtes en vol au plus (une socket chacune, port source
 *    aléatoire) ;
 *  - `debit` requêtes par seconde au plus (seau à jetons) — pour ne pas se
 *    faire limiter, ni abuser d'un résolveur public ;
 *  - `delaiMs` d'attente par requête, `essais` tentatives au total ;
 *  - une réponse n'est retenue que si son identifiant ET sa question sont
 *    ceux qu'on a posés (une réponse égarée ou forgée est ignorée).
 *
 * Aucun sondage SMTP : on ne parle qu'au résolveur DNS.
 */
final class ResolveurDnsUdp implements ResolveurDns
{
    private readonly string $hote;

    private readonly int $port;

    public function __construct(
        string $serveur,
        private readonly int $parallele = 32,
        private readonly int $debit = 100,
        private readonly int $delaiMs = 3000,
        private readonly int $essais = 2,
    ) {
        [$this->hote, $this->port] = self::adresse($serveur);
        if ($parallele < 1 || $debit < 1 || $delaiMs < 100 || $essais < 1) {
            throw new InvalidArgumentException('Réglages DNS hors bornes (parallèle ≥ 1, débit ≥ 1, délai ≥ 100 ms, essais ≥ 1).');
        }
    }

    /**
     * Le résolveur à employer : celui qu'on désigne, sinon le premier
     * `nameserver` de `/etc/resolv.conf`. Null si l'on n'en connaît aucun —
     * on ne DEVINE pas un résolveur public.
     */
    public static function serveurParDefaut(?string $designe, string $resolvConf = '/etc/resolv.conf'): ?string
    {
        $designe = trim((string) $designe);
        if ($designe !== '') {
            return $designe;
        }
        $contenu = @file_get_contents($resolvConf);
        if (! is_string($contenu)) {
            return null;
        }
        foreach (preg_split('/\R/', $contenu) ?: [] as $ligne) {
            if (preg_match('/^\s*nameserver\s+(\S+)/', $ligne, $m) === 1) {
                return $m[1];
            }
        }

        return null;
    }

    public function nom(): string
    {
        return (str_contains($this->hote, ':') ? '[' . $this->hote . ']' : $this->hote) . ':' . $this->port;
    }

    public function resoudre(array $domaines): array
    {
        return self::enchainer($domaines, fn (array $lot, int $type): array => $this->interroger($lot, $type));
    }

    /**
     * L'ENCHAÎNEMENT, isolé du réseau (et testé sans lui) : MX pour tous ;
     * sans MX, le « MX implicite » (RFC 5321 §5.1) — A, puis AAAA ; ni l'un ni
     * l'autre : `sans_courrier`. Une question restée sans réponse (null) est
     * `indetermine`, jamais « ne reçoit pas ».
     *
     * @param  list<string>  $domaines
     * @param  callable(list<string>, int): array<string, array{rcode: int, tronque: bool, enregistrements: list<array{type: int, cible: ?string}>}|null>  $interroger
     * @return array<string, ResultatDns>
     */
    public static function enchainer(array $domaines, callable $interroger): array
    {
        $resultats = [];
        $suite = [];
        $reponses = $interroger($domaines, MessageDns::TYPE_MX);
        foreach ($domaines as $domaine) {
            $reponse = $reponses[$domaine] ?? null;
            $conclusion = $reponse === null ? new ResultatDns(ResultatDns::INDETERMINE) : MessageDns::conclureMx($reponse);
            if ($conclusion === null) {
                $suite[] = $domaine;
            } else {
                $resultats[$domaine] = $conclusion;
            }
        }

        foreach ([MessageDns::TYPE_A, MessageDns::TYPE_AAAA] as $type) {
            if ($suite === []) {
                break;
            }
            $restants = [];
            $reponses = $interroger($suite, $type);
            foreach ($suite as $domaine) {
                $reponse = $reponses[$domaine] ?? null;
                $conclusion = $reponse === null ? new ResultatDns(ResultatDns::INDETERMINE) : MessageDns::conclureAdresse($reponse, $type);
                if ($conclusion === null) {
                    $restants[] = $domaine;
                } else {
                    $resultats[$domaine] = $conclusion;
                }
            }
            $suite = $restants;
        }
        foreach ($suite as $domaine) {
            $resultats[$domaine] = new ResultatDns(ResultatDns::SANS_COURRIER);
        }

        return $resultats;
    }

    /**
     * @param  list<string>  $domaines
     * @return array<string, array{id: int, reponse: bool, tronque: bool, rcode: int, question: string, qtype: int, enregistrements: list<array{type: int, cible: ?string}>}|null>
     */
    private function interroger(array $domaines, int $type): array
    {
        /** @var list<array{0: string, 1: int}> $file */
        $file = array_map(static fn (string $d): array => [$d, 1], $domaines);
        /** @var array<int, array{socket: resource, domaine: string, id: int, depart: float, essai: int}> $enVol */
        $enVol = [];
        $reponses = [];
        $jetons = (float) min($this->debit, $this->parallele);
        $horloge = microtime(true);

        while ($file !== [] || $enVol !== []) {
            $maintenant = microtime(true);
            $jetons = min((float) $this->debit, $jetons + ($maintenant - $horloge) * $this->debit);
            $horloge = $maintenant;

            while ($file !== [] && count($enVol) < $this->parallele && $jetons >= 1.0) {
                [$domaine, $essai] = array_shift($file);
                $jetons -= 1.0;
                $envoi = $this->envoyer($domaine, $type);
                if ($envoi === null) {
                    // Socket impossible : compte comme une tentative perdue.
                    if ($essai < $this->essais) {
                        $file[] = [$domaine, $essai + 1];
                    } else {
                        $reponses[$domaine] = null;
                    }

                    continue;
                }
                $enVol[(int) $envoi['socket']] = $envoi + ['domaine' => $domaine, 'depart' => microtime(true), 'essai' => $essai];
            }

            if ($enVol === []) {
                usleep(2000);

                continue;
            }

            $lecture = array_map(static fn (array $v) => $v['socket'], array_values($enVol));
            $ecriture = null;
            $exception = null;
            $pretes = @stream_select($lecture, $ecriture, $exception, 0, 20000);
            if ($pretes !== false && $pretes > 0) {
                foreach ($lecture as $socket) {
                    $cle = (int) $socket;
                    $vol = $enVol[$cle] ?? null;
                    if ($vol === null) {
                        continue;
                    }
                    $paquet = @stream_socket_recvfrom($socket, 65535);
                    if (! is_string($paquet) || $paquet === '') {
                        continue;
                    }
                    try {
                        $reponse = MessageDns::lire($paquet);
                    } catch (Throwable) {
                        continue;
                    }
                    if (! $reponse['reponse'] || $reponse['id'] !== $vol['id']
                        || $reponse['question'] !== $vol['domaine'] || $reponse['qtype'] !== $type) {
                        continue;
                    }
                    $reponses[$vol['domaine']] = $reponse;
                    fclose($socket);
                    unset($enVol[$cle]);
                }
            }

            $limite = microtime(true) - $this->delaiMs / 1000;
            foreach ($enVol as $cle => $vol) {
                if ($vol['depart'] > $limite) {
                    continue;
                }
                fclose($vol['socket']);
                unset($enVol[$cle]);
                if ($vol['essai'] < $this->essais) {
                    $file[] = [$vol['domaine'], $vol['essai'] + 1];
                } else {
                    $reponses[$vol['domaine']] = null;
                }
            }
        }

        return $reponses;
    }

    /** @return array{socket: resource, id: int}|null */
    private function envoyer(string $domaine, int $type): ?array
    {
        $cible = str_contains($this->hote, ':') ? '[' . $this->hote . ']' : $this->hote;
        $socket = @stream_socket_client("udp://{$cible}:{$this->port}", $code, $message, 1);
        if ($socket === false) {
            return null;
        }
        stream_set_blocking($socket, false);
        $id = random_int(0, 0xFFFF);
        try {
            $requete = MessageDns::requete($id, $domaine, $type);
        } catch (RuntimeException) {
            fclose($socket);

            return null;
        }
        if (@fwrite($socket, $requete) !== strlen($requete)) {
            fclose($socket);

            return null;
        }

        return ['socket' => $socket, 'id' => $id];
    }

    /** @return array{0: string, 1: int} */
    private static function adresse(string $serveur): array
    {
        $serveur = trim($serveur);
        if (preg_match('/^\[([0-9a-f:.]+)\](?::(\d+))?$/i', $serveur, $m) === 1) {
            return [$m[1], isset($m[2]) ? (int) $m[2] : 53];
        }
        if (substr_count($serveur, ':') > 1 && filter_var($serveur, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
            return [$serveur, 53];
        }
        $parties = explode(':', $serveur);
        $hote = $parties[0];
        $port = isset($parties[1]) ? (int) $parties[1] : 53;
        if (filter_var($hote, FILTER_VALIDATE_IP) === false || $port < 1 || $port > 65535) {
            throw new InvalidArgumentException('Résolveur DNS invalide : une adresse IP, éventuellement suivie de « :port ».');
        }

        return [$hote, $port];
    }
}
