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

    /** Opérations autorisées. Tout le reste (dont l'extinction des témoins) est refusé. */
    private const OPERATIONS = ['pause', 'reprendre', 'retirer'];

    private const OPERATIONS_INTERDITES = ['eteindre_temoins', 'modifier_socle', 'plafond', 'registre', 'domaine_principal'];

    /** @var \Closure():int */
    private \Closure $horloge;

    /**
     * @param array<string,string> $cles clé HMAC par espace (lues de l'environnement par Configuration)
     * @param \Closure():int|null  $horloge
     */
    public function __construct(
        private readonly Stockage $stockage,
        private readonly Kumo $kumo,
        private readonly array $cles,
        ?\Closure $horloge = null,
    ) {
        $this->horloge = $horloge ?? static fn (): int => time();
    }

    public function traiter(Requete $r): Reponse
    {
        try {
            $ws = $r->entete('x-workspace-id');
            $ts = $r->entete('x-timestamp');
            $nonce = $r->entete('x-nonce');
            $sig = $r->entete('x-signature');
            $cle = null;

            if ($ws === null || $ts === null || $nonce === null || $sig === null
                || preg_match('/^[A-Za-z0-9_-]{1,64}$/', $ws) !== 1
                || preg_match('/^[A-Za-z0-9_-]{8,128}$/', $nonce) !== 1
                || preg_match('/^\d{1,12}$/', $ts) !== 1
                || ! isset($this->cles[$ws])
                || strlen($r->corps) > self::TAILLE_MAX_CORPS * 2
            ) {
                return $this->nonAutorise();
            }
            $cle = $this->cles[$ws];

            if (! Signature::verifier($cle, $sig, $ws, $ts, $nonce, $r->methode, $r->chemin, $r->corps)) {
                return $this->nonAutorise();
            }
            if (abs(($this->horloge)() - (int) $ts) > self::FENETRE_HORODATAGE) {
                return $this->nonAutorise();
            }
            if (! $this->stockage->retenirNonce($ws, $nonce)) {
                return $this->nonAutorise(); // rejeu
            }

            return $this->signer($this->router($r, $ws), $cle, $ws, $nonce);
        } catch (\Throwable) {
            return Reponse::erreur(503, 'indisponible'); // jamais de trace dans la réponse
        }
    }

    private function nonAutorise(): Reponse
    {
        return Reponse::erreur(401, 'non_autorise');
    }

    private function signer(Reponse $rep, string $cle, string $ws, string $nonce): Reponse
    {
        return new Reponse($rep->statut, $rep->corps, $rep->entetes + [
            'X-Signature' => Signature::signerReponse($cle, $ws, $nonce, $rep->statut, $rep->corps),
        ]);
    }

    private function router(Requete $r, string $ws): Reponse
    {
        $parts = explode('?', $r->chemin, 2);
        $chemin = $parts[0];
        $requete = $parts[1] ?? '';

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
            return Reponse::erreur(403, 'interdit');
        }
        if (! in_array($operation, self::OPERATIONS, true)) {
            return Reponse::erreur(404, 'introuvable');
        }
        if (strlen($corps) > self::TAILLE_MAX_CORPS) {
            return Reponse::erreur(413, 'trop_gros');
        }
        $d = json_decode($corps, true, 8);
        if (! is_array($d) || ! isset($d['commande_id'], $d['workspace_id'])
            || ! is_string($d['commande_id']) || preg_match('/^[A-Za-z0-9_-]{1,64}$/', $d['commande_id']) !== 1
            || ! is_string($d['workspace_id'])
            || (isset($d['params']) && ! is_array($d['params']))) {
            return Reponse::erreur(400, 'corps');
        }
        if ($d['workspace_id'] !== $ws) {
            return Reponse::erreur(403, 'espace');
        }

        $empreinte = hash('sha256', $operation."\n".$corps);
        $deja = $this->stockage->commande($ws, $d['commande_id']);
        if ($deja !== null) {
            if (! hash_equals($deja['empreinte_corps'], $empreinte)) {
                return Reponse::erreur(409, 'commande_id_deja_utilise');
            }

            return new Reponse($deja['statut'], $deja['reponse'], ['Content-Type' => 'application/json']);
        }

        // Les opérations sont idempotentes côté KumoMTA : en cas d'arrêt entre l'appel et le
        // journal, la commande est refaite sans effet nouveau.
        try {
            $rep = Reponse::json(200, ['commande_id' => $d['commande_id'], 'statut' => 'ok', 'resultat' => $this->kumo->executer($ws, $operation, $d['params'] ?? [])]);
        } catch (\RuntimeException) {
            $rep = Reponse::json(502, ['commande_id' => $d['commande_id'], 'statut' => 'echec']);
        }
        $this->stockage->journaliser($ws, $d['commande_id'], $operation, $empreinte, $rep->statut, $rep->corps);

        return $rep;
    }

    private function evenements(string $ws, string $requete): Reponse
    {
        parse_str($requete, $q);
        if ($requete === '' || array_diff(array_keys($q), ['curseur', 'limite']) !== []
            || ! isset($q['curseur']) || ! is_string($q['curseur']) || preg_match('/^\d{1,15}$/', $q['curseur']) !== 1) {
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
        $curseur = (int) $q['curseur'];

        $min = $this->stockage->curseurMinimal($ws, self::REJEU_EVENEMENTS);
        if ($curseur < $min) {
            return Reponse::json(410, ['erreur' => 'rejeu_hors_fenetre', 'curseur_minimal' => $min]);
        }
        if ($this->stockage->trous($ws) !== []) {
            return Reponse::erreur(500, 'sequence_incoherente'); // alerte : on ne sert jamais une séquence trouée
        }
        $ev = $this->stockage->evenementsApres($ws, $curseur, $limite);

        return Reponse::json(200, [
            'evenements' => array_map(fn (array $e): array => ['sequence' => $e['sequence'], 'type' => $e['type'], 'donnees' => $e['donnees'], 'cree_le' => $e['cree_le']], $ev),
            'dernier' => $this->stockage->dernierSequence($ws),
        ]);
    }
}
