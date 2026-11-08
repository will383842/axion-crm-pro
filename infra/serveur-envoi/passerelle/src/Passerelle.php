<?php

declare(strict_types=1);

namespace Passerelle;

/** Traitement d'une requête : authentification, rejeu, routage. Aucune route hors contrat. */
final class Passerelle
{
    public const FENETRE_HORODATAGE = 300;

    public const REJEU_EVENEMENTS = 72 * 3600;

    public const TAILLE_MAX_CORPS = 65536;

    public const LIMITE_EVENEMENTS = 500;

    /** Commandes autorisées : les noms de K1 (ClientServeurEnvoiHmac::COMMANDES_AUTORISEES). Le reste est refusé. */
    private const OPERATIONS = ['pause', 'reprise', 'retrait'];

    private const OPERATIONS_INTERDITES = ['eteindre_temoins', 'modifier_socle', 'plafond', 'registre', 'domaine_principal'];

    /** Attente d'une requête concurrente de même commande_id : 50 × 100 ms au plus, puis 503 (K1 rejouera). */
    private const ATTENTES = 50;

    private const PAUSE_MS = 100;

    /** @var \Closure():int */
    private \Closure $horloge;

    /** @var array<string,array<string,string>> clés par espace puis par identifiant (`k=<id>`) */
    private array $cles;

    private ?Stockage $stockage = null;

    /** @var \Closure(string,array<string,string>):mixed */
    private \Closure $alerte;

    /** @var \Closure(int):mixed */
    private \Closure $dormir;

    /**
     * @param Stockage|\Closure():Stockage $base   la base, ou sa fabrique : ouverte à la demande, dans le try de traiter()
     * @param array<string,string|array<string,string>> $cles clés HMAC par espace (UUID) : une clé (id « 1 ») ou {id: clé}
     * @param \Closure():int|null    $horloge
     * @param \Closure(int):mixed|null $dormir pause en millisecondes (injectable pour les tests)
     * @param \Closure(string,array<string,string>):mixed|null $alerte alerte de dérive (26 § 1.8) ; défaut : error_log
     */
    public function __construct(
        private readonly Stockage|\Closure $base,
        private readonly Kumo $kumo,
        array $cles,
        ?\Closure $horloge = null,
        ?\Closure $dormir = null,
        ?\Closure $alerte = null,
    ) {
        if ($kumo->delaiMaximal() >= Stockage::DELAI_RESERVATION || $kumo->delaiMaximal() > Kumo::DELAI_MAX_SECONDES) {
            // K4c : un client plus lent que la réservation ferait exécuter une commande deux fois.
            throw new \InvalidArgumentException('délai du client KumoMTA trop long (< DELAI_RESERVATION exigé)');
        }
        $this->cles = [];
        foreach ($cles as $ws => $v) {
            $v = is_string($v) ? ['1' => $v] : $v;
            $this->cles[(string) $ws] = array_slice(array_filter($v, static fn ($c): bool => is_string($c) && $c !== ''), 0, Configuration::CLES_ACTIVES_MAX, true);
        }
        $this->alerte = $alerte ?? static function (string $code, array $ctx): void {
            error_log('passerelle: ALERTE derive '.$code.' '.json_encode($ctx));
        };
        $this->horloge = $horloge ?? static fn (): int => time();
        $this->dormir = $dormir ?? static fn (int $ms) => usleep($ms * 1000);
    }

    private function stockage(): Stockage
    {
        return $this->stockage ??= ($this->base instanceof \Closure ? ($this->base)() : $this->base);
    }

    public static function empreinte(string $operation, string $commandeId): string
    {
        return hash('sha256', $operation."\n".$commandeId);
    }

    public function traiter(Requete $r): Reponse
    {
        try {
            $ws = $r->entete('x-envoi-workspace');
            $ts = $r->entete('x-envoi-timestamp');
            $nonce = $r->entete('x-envoi-nonce');
            $sig = $r->entete('x-envoi-signature');

            if ($ws === null || $ts === null || $nonce === null || $sig === null
                || ! Signature::estUuid($ws) || ! Signature::estUuid($nonce)
                || preg_match('/^\d{1,12}$/', $ts) !== 1
                || ! isset($this->cles[$ws])
                || strlen($r->corps) > self::TAILLE_MAX_CORPS * 2
            ) {
                return $this->nonAutorise();
            }
            // Rotation : la clé est choisie par `k=<id>`. Sans `k` (formes héritées), seulement s'il n'y a qu'une clé active.
            $cles = $this->cles[$ws];
            $cleId = Signature::analyser($sig)[0] ?? (count($cles) === 1 ? (string) array_key_first($cles) : null);
            if ($cleId === null || ! isset($cles[$cleId])) {
                return $this->nonAutorise();
            }
            $cle = $cles[$cleId];

            // Chemin SANS requête ; pour un GET, le « corps » signé est la chaîne de requête exacte (comme K1).
            [$chemin, $requete] = array_pad(explode('?', $r->chemin, 2), 2, '');
            $signe = $r->methode === 'GET' ? $requete : $r->corps;
            if (! Signature::verifier($cle, $sig, Signature::chargeRequete($r->methode, $chemin, $ts, $nonce, $ws, $signe))) {
                return $this->nonAutorise();
            }
            if (abs(($this->horloge)() - (int) $ts) > self::FENETRE_HORODATAGE) {
                return $this->nonAutorise();
            }
        } catch (\Throwable) {
            return Reponse::erreur(500, 'erreur_interne'); // jamais de trace dans la réponse
        }

        // Authentifié : toute panne suivante (ouverture de la base comprise) donne un 500 SIGNÉ et propre.
        try {
            $stockage = $this->stockage();
        } catch (\Throwable) {
            return $this->signer(Reponse::erreur(500, 'erreur_interne'), $cle, $ws, $nonce, (string) $cleId);
        }
        try {
            $rep = $stockage->retenirNonce($ws, $nonce)
                ? $this->router($r, $chemin, $requete, $ws)
                : Reponse::erreur(409, 'nonce_rejoue'); // contrat 26 § 1.5 (VerificateurHmacEnvoi) : 409, pas 401
        } catch (\Throwable) {
            $rep = Reponse::erreur(503, 'indisponible');
        }

        return $this->signer($rep, $cle, $ws, $nonce, (string) $cleId);
    }

    private function nonAutorise(): Reponse
    {
        return Reponse::erreur(401, 'non_autorise');
    }

    /** Ajoute `workspace_id` au JSON (K1 le contrôle) puis signe : « REPONSE \n nonce \n ws \n statut \n corps ». */
    private function signer(Reponse $rep, string $cle, string $ws, string $nonce, string $cleId): Reponse
    {
        $d = json_decode($rep->corps, true);
        $corps = is_array($d) ? json_encode(['workspace_id' => $ws] + $d, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) : $rep->corps;

        return new Reponse($rep->statut, $corps, $rep->entetes + [
            'X-Envoi-Signature' => Signature::entete($cleId, Signature::signer($cle, Signature::chargeReponse($nonce, $ws, $rep->statut, $corps))),
        ]);
    }

    private function router(Requete $r, string $chemin, string $requete, string $ws): Reponse
    {
        if ($chemin === '/v1/evenements') {
            return $r->methode === 'GET' ? $this->evenements($ws, $requete) : Reponse::erreur(405, 'methode');
        }
        if (preg_match('#^/v1/commandes/([a-z_]{1,32})$#', $chemin, $m) === 1) {
            return $r->methode === 'POST' ? $this->commande($ws, $m[1], $r->corps, $requete) : Reponse::erreur(405, 'methode');
        }

        return Reponse::erreur(404, 'introuvable');
    }

    private function commande(string $ws, string $operation, string $corps, string $requete): Reponse
    {
        if ($requete !== '') {
            return Reponse::erreur(400, 'requete');
        }
        if (in_array($operation, self::OPERATIONS_INTERDITES, true)) {
            // Dérive (26 § 1.8) : une commande interdite signée valablement est un signal à ne pas perdre.
            try {
                ($this->alerte)('commande_interdite', ['workspace_id' => $ws, 'operation' => $operation]);
            } catch (\Throwable) {
                // l'alerte ne doit jamais changer la réponse
            }

            return Reponse::erreur(403, 'interdit');
        }
        if (! in_array($operation, self::OPERATIONS, true)) {
            return Reponse::erreur(404, 'introuvable');
        }
        if (strlen($corps) > self::TAILLE_MAX_CORPS) {
            return Reponse::erreur(413, 'trop_gros');
        }
        // Corps K1 : exactement {commande_id, commande}. L'espace vient de la signature, jamais du corps.
        $d = json_decode($corps, true, 4);
        if (! is_array($d) || array_keys($d) === [] || array_diff(array_keys($d), ['commande_id', 'commande']) !== []
            || ! isset($d['commande_id'], $d['commande']) || ! is_string($d['commande_id']) || ! is_string($d['commande'])
            || ! Signature::estUuid($d['commande_id']) || $d['commande'] !== $operation) {
            return Reponse::erreur(400, 'corps');
        }
        $id = $d['commande_id'];
        $empreinte = self::empreinte($operation, $id);

        // Réservation AVANT l'appel Kumo : une seule requête exécute ; les concurrentes attendent puis rejouent la réponse.
        for ($essai = 0; ; $essai++) {
            $res = $this->stockage()->reserverCommande($ws, $id, $operation, $empreinte);
            if ($res['action'] === 'conflit') {
                return Reponse::erreur(409, 'commande_id_deja_utilise');
            }
            if ($res['action'] === 'rejouer') {
                return new Reponse($res['statut'] ?? 200, $res['reponse'] ?? '{}', ['Content-Type' => 'application/json']);
            }
            if ($res['action'] === 'executer') {
                break;
            }
            if ($essai >= self::ATTENTES) {
                return Reponse::erreur(503, 'commande_en_cours'); // réessayable avec le même commande_id
            }
            ($this->dormir)(self::PAUSE_MS);
        }

        // Les opérations sont idempotentes côté KumoMTA : une réservation abandonnée se refait sans effet nouveau.
        try {
            $resultat = $this->kumo->executer($ws, $operation, []);
        } catch (\RuntimeException) {
            $this->stockage()->echouerCommande($ws, $id); // le commande_id reste utilisable : K1 rejoue
            return Reponse::json(502, ['commande_id' => $id, 'statut' => 'echec']);
        } catch (\Throwable $e) {
            $this->stockage()->echouerCommande($ws, $id);
            throw $e;
        }
        $rep = Reponse::json(200, ['workspace_id' => $ws, 'commande_id' => $id, 'statut' => 'ok', 'resultat' => $resultat]);
        $this->stockage()->terminerCommande($ws, $id, $operation, $rep->statut, $rep->corps);

        return $rep;
    }

    /** ISO 8601 strict « AAAA-MM-JJThh:mm:ss[.fraction](Z|±hh:mm) » ; rien d'autre (« demain », « @123 », dates débordantes). */
    private static function horodatageIso(string $v): ?int
    {
        if (preg_match('/^(\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2})(?:\.\d{1,9})?(Z|[+-]\d{2}:\d{2})$/', $v, $m) !== 1) {
            return null;
        }
        $norm = $m[1].($m[2] === 'Z' ? '+00:00' : $m[2]);
        $d = \DateTimeImmutable::createFromFormat('Y-m-d\TH:i:sP', $norm);
        $e = \DateTimeImmutable::getLastErrors();
        if ($d === false || ($e !== false && ($e['warning_count'] > 0 || $e['error_count'] > 0))) {
            return null;
        }

        return $d->getTimestamp();
    }

    private function desynchronise(string $ws, string $epoque): Reponse
    {
        $this->alerteSilencieuse('curseur_desynchronise', ['workspace_id' => $ws, 'epoque' => $epoque]);

        return Reponse::json(409, ['erreur' => 'curseur_desynchronise', 'epoque' => $epoque, 'dernier' => $this->stockage()->dernierSequence($ws)]);
    }

    /** @param array<string,string> $ctx */
    private function alerteSilencieuse(string $code, array $ctx): void
    {
        try {
            ($this->alerte)($code, $ctx);
        } catch (\Throwable) {
        }
    }

    private function evenements(string $ws, string $requete): Reponse
    {
        parse_str($requete, $q);
        $noms = array_map(static fn (string $p): string => urldecode(explode('=', $p, 2)[0]), $requete === '' ? [] : explode('&', $requete));
        if (count($noms) !== count(array_unique($noms))) {
            return Reponse::erreur(400, 'curseur'); // paramètre en double : ambigu
        }
        if ($requete === '' || array_diff(array_keys($q), ['curseur', 'depuis', 'limite', 'epoque']) !== []
            || ! isset($q['curseur']) || ! is_string($q['curseur']) || ($q['curseur'] !== '' && preg_match('/^\d{1,15}$/', $q['curseur']) !== 1)) {
            return Reponse::erreur(400, 'curseur');
        }
        $limite = self::LIMITE_EVENEMENTS;
        if (isset($q['limite'])) {
            if (! is_string($q['limite']) || preg_match('/^\d{1,4}$/', $q['limite']) !== 1
                || (int) $q['limite'] < 1 || (int) $q['limite'] > self::LIMITE_EVENEMENTS) {
                return Reponse::erreur(400, 'limite');
            }
            $limite = (int) $q['limite'];
        }
        $epoque = $this->stockage()->epoque();
        if (isset($q['epoque'])) {
            if (! is_string($q['epoque']) || preg_match('/^[0-9a-f]{16}$/', $q['epoque']) !== 1) {
                return Reponse::erreur(400, 'epoque');
            }
            if (! hash_equals($epoque, $q['epoque'])) {
                return $this->desynchronise($ws, $epoque); // le client parle d'une autre base
            }
        }
        $depuis = null;
        if (isset($q['depuis']) && $q['depuis'] !== '') {
            $depuis = is_string($q['depuis']) ? self::horodatageIso($q['depuis']) : null;
            if ($depuis === null) {
                return Reponse::erreur(400, 'depuis');
            }
        }

        if ($q['curseur'] === '') {
            // Premier tirage : tout ce qui reste après la borne de purge ; « depuis » n'a d'effet que dans ce cas.
            $depart = $this->stockage()->dernierPurge($ws);
            if ($depuis !== null) {
                $depart = max($depart, $this->stockage()->curseurDepuis($ws, $depuis));
            }
        } else {
            $depart = (int) $q['curseur'];
            if ($depart > $this->stockage()->dernierSequence($ws)) {
                // Curseur au-delà du dernier numéro : base recréée ou client désynchronisé. Jamais un 200 vide qui
                // ferait perdre sans signal des désinscriptions ou des rebonds durs.
                return $this->desynchronise($ws, $epoque);
            }
            $min = $this->stockage()->curseurMinimal($ws, self::REJEU_EVENEMENTS);
            if ($depart < $min) {
                return Reponse::json(410, ['erreur' => 'rejeu_hors_fenetre', 'curseur_minimal' => $min]);
            }
        }
        if ($this->stockage()->trous($ws) !== []) {
            return Reponse::erreur(500, 'sequence_incoherente'); // alerte : on ne sert jamais une séquence trouée
        }
        $ev = $this->stockage()->evenementsApres($ws, $depart, $limite);
        $dernier = $ev === [] ? $depart : $ev[array_key_last($ev)]['sequence'];

        return Reponse::json(200, [
            'epoque' => $epoque,
            'evenements' => array_map(static function (array $e) use ($ws, $epoque): array {
                $id = is_array($e['donnees']) ? ($e['donnees']['id_injection'] ?? '') : '';

                // Les « donnees » (adresses possibles) ne sortent pas : K1 ne lit que ces champs. `observation` (K7) :
                // un rebond dur en observation ne doit alimenter AUCUN frein côté CRM.
                $obs = is_array($e['donnees']) && ($e['donnees']['observation'] ?? false) === true;

                $sortie = ['event_id' => $ws.':'.$epoque.':'.$e['sequence'], 'seq' => $e['sequence'], 'type' => $e['type'], 'id_injection' => is_string($id) ? $id : ''];

                // Le champ n'existe QUE pour un événement en observation : le format historique (4 champs) est inchangé.
                return $obs ? $sortie + ['observation' => true] : $sortie;
            }, $ev),
            'curseur_suivant' => (string) $dernier,
        ]);
    }
}
