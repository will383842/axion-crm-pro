<?php

namespace App\Envoi\Serveur;

use App\Envoi\Serveur\Contrat\CommandeurServeur;
use App\Envoi\Serveur\Contrat\EvenementSuivi;
use App\Envoi\Serveur\Contrat\LotSuivi;
use App\Envoi\Serveur\Contrat\TireurEvenements;
use App\Envoi\Smtp\ResolveurHote;
use App\Support\WorkspaceContext;
use DateTimeImmutable;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

/**
 * Client signé du serveur d'envoi (ADR K0 D3/D4/D6/D7). TIRAGE seulement : le CRM n'expose aucune route
 * entrante. L'adresse du serveur vient de la CONFIGURATION d'environnement, jamais de la base.
 * Interrupteur `envoi.reel_actif` (faux par défaut) : tout appel est refusé tant qu'il est éteint.
 * Secret vide = refus, aucun appel. Le client ne garde aucun état (non singleton).
 */
final class ClientServeurEnvoiHmac implements CommandeurServeur, TireurEvenements
{
    public const MAX_EVENEMENTS = 500;

    public const MAX_CORPS_OCTETS = 1_048_576;

    private const TYPE_AUTORISE = '/^[a-z][a-z0-9_]{0,63}$/';

    public function __construct(private readonly JournalCommandes $journal, private readonly ResolveurHote $resolveur) {}

    public function tirer(string $workspaceId, string $source, ?string $curseur, int $limite, DateTimeImmutable $depuis): LotSuivi
    {
        $limite = max(1, min($limite, self::MAX_EVENEMENTS));
        $requete = http_build_query(['curseur' => $curseur ?? '', 'depuis' => $depuis->format('c'), 'limite' => $limite]);
        $json = $this->appeler($workspaceId, $source, 'GET', SignatureEnvoi::CHEMIN_EVENEMENTS, $requete, '');

        $brut = $json['evenements'] ?? null;
        if (! is_array($brut) || ! array_is_list($brut) || count($brut) > self::MAX_EVENEMENTS || count($brut) > $limite) {
            throw ServeurEnvoiRefuse::motif('réponse invalide (événements).');
        }
        $evenements = [];
        foreach ($brut as $e) {
            if (! is_array($e) || ! is_string($e['event_id'] ?? null) || $e['event_id'] === '' || strlen($e['event_id']) > 128
                || ! is_int($e['seq'] ?? null) || $e['seq'] < 0
                || ! is_string($e['type'] ?? null) || preg_match(self::TYPE_AUTORISE, $e['type']) !== 1
                || ! is_string($e['id_injection'] ?? null) || strlen($e['id_injection']) > 128) {
                throw ServeurEnvoiRefuse::motif('réponse invalide (événement).');
            }
            $evenements[] = new EvenementSuivi($e['event_id'], $e['seq'], $e['type'], $e['id_injection']);
        }
        $suivant = $json['curseur_suivant'] ?? null;
        if ($suivant !== null && (! is_string($suivant) || strlen($suivant) > 128)) {
            throw ServeurEnvoiRefuse::motif('réponse invalide (curseur).');
        }
        if ($suivant !== null && $curseur !== null && ctype_digit($curseur) && ctype_digit($suivant) && (int) $suivant < (int) $curseur) {
            throw ServeurEnvoiRefuse::motif('le curseur ne peut pas reculer.');
        }

        return new LotSuivi($evenements, $suivant);
    }

    public function commander(string $workspaceId, string $source, string $commandeId, string $commande): bool
    {
        // Contrôles locaux AVANT toute trace : une erreur de configuration ou d'appel doit se voir tout de suite.
        $this->controlesGeneraux($source);
        if (! SignatureEnvoi::estUuid($commandeId)) {
            throw ServeurEnvoiRefuse::motif('commande_id : UUID aléatoire exigé.');
        }
        if (preg_match('/^[a-z][a-z0-9_]{0,31}$/', $commande) !== 1) {
            throw ServeurEnvoiRefuse::motif('nom de commande invalide.');
        }
        // Intention journalisée AVANT le réseau : si l'appel échoue, la commande reste « non acquittée » et
        // peut être renvoyée avec le MÊME commande_id (le serveur est idempotent). Jamais perdue.
        $this->journal->enregistrer($workspaceId, $commandeId, $commande);
        if ($this->journal->estAcquittee($workspaceId, $commandeId)) {
            return false;
        }
        $corps = json_encode(['commande_id' => $commandeId, 'commande' => $commande], JSON_THROW_ON_ERROR);
        $this->appeler($workspaceId, $source, 'POST', SignatureEnvoi::PREFIXE_COMMANDES . $commande, $corps, $corps);
        $this->journal->acquitter($workspaceId, $commandeId);

        return true;
    }

    private function controlesGeneraux(string $source): void
    {
        if (DB::transactionLevel() > 0
            && ! ((bool) config('envoi.serveur.tolere_transaction_englobante', false) && app()->environment('testing'))) {
            throw ServeurEnvoiRefuse::motif('appel réseau refusé dans une transaction ouverte.');
        }
        if (config('envoi.reel_actif') !== true) {
            throw ServeurEnvoiRefuse::motif('envoi réel éteint (ENVOI_REEL_ACTIF), appel refusé.');
        }
        if ($this->secretPour($source) === '') {
            throw ServeurEnvoiRefuse::motif('secret HMAC absent, appel refusé.');
        }
    }

    private function secretPour(string $source): string
    {
        $secrets = config('envoi.serveur.secrets', []);
        $propre = is_array($secrets) ? ($secrets[$source] ?? '') : '';

        return is_string($propre) && $propre !== '' ? $propre : (string) config('envoi.serveur.secret', '');
    }

    private function cleIdPour(string $source): string
    {
        $cles = config('envoi.serveur.cles', []);
        $cle = is_array($cles) ? ($cles[$source] ?? null) : null;

        return is_string($cle) && preg_match('/^[A-Za-z0-9_-]{1,32}$/', $cle) === 1 ? $cle : (string) config('envoi.serveur.cle_id', '1');
    }

    private function urlPour(string $source): string
    {
        $urls = config('envoi.serveur.urls', []);
        $propre = is_array($urls) ? ($urls[$source] ?? '') : '';

        return is_string($propre) && $propre !== '' ? $propre : (string) config('envoi.serveur.url', '');
    }

    /** @return array<string, mixed> */
    private function appeler(string $workspaceId, string $source, string $methode, string $chemin, string $signe, string $corps): array
    {
        $this->controlesGeneraux($source);
        $secret = $this->secretPour($source);
        $actif = WorkspaceContext::run($workspaceId, fn () => DB::table('envoi_serveurs')
            ->where('workspace_id', $workspaceId)->where('source', $source)->where('actif', true)->exists());
        if (! $actif) {
            throw ServeurEnvoiRefuse::motif('source inconnue ou inactive.');
        }
        $url = $this->urlPour($source);
        if ($url === '') {
            throw ServeurEnvoiRefuse::motif('adresse du serveur absente de la configuration.');
        }
        $cible = GardeHoteServeur::controler($url, $this->resolveur);

        $ts = (string) time();
        $nonce = (string) Str::uuid();
        $cle = $this->cleIdPour($source);
        $max = self::MAX_CORPS_OCTETS;
        $options = GardeHoteServeur::optionsHttp($cible) + [
            'on_headers' => function ($r) use ($max): void {
                if ((int) $r->getHeaderLine('Content-Length') > $max) {
                    throw new \RuntimeException('corps trop volumineux');
                }
            },
        ];
        $requete = Http::connectTimeout((int) config('envoi.serveur.connect_timeout', 5))
            ->timeout((int) config('envoi.serveur.timeout', 10))
            ->withOptions($options)
            ->withHeaders([
                'X-Envoi-Timestamp' => $ts,
                'X-Envoi-Nonce' => $nonce,
                'X-Envoi-Workspace' => $workspaceId,
                'X-Envoi-Signature' => SignatureEnvoi::entete($cle, SignatureEnvoi::signer($secret, SignatureEnvoi::chargeRequete($methode, $chemin, $ts, $nonce, $signe))),
            ]);
        try {
            $reponse = $methode === 'GET'
                ? $requete->get($cible['base'] . $chemin . '?' . $signe)
                : $requete->withBody($corps, 'application/json')->post($cible['base'] . $chemin);
        } catch (Throwable) {
            throw ServeurEnvoiRefuse::motif('injoignable ou en échec réseau.');
        }

        return $this->lireReponse($reponse, $secret, $nonce, $workspaceId);
    }

    /** @return array<string, mixed> */
    private function lireReponse(Response $reponse, #[\SensitiveParameter] string $secret, string $nonce, string $workspaceId): array
    {
        if (! $reponse->successful()) {
            throw ServeurEnvoiRefuse::motif('réponse en erreur (statut ' . $reponse->status() . ').');
        }
        $corps = $reponse->body();
        if (strlen($corps) > self::MAX_CORPS_OCTETS) {
            throw ServeurEnvoiRefuse::motif('réponse trop volumineuse.');
        }
        [, $recue] = SignatureEnvoi::analyser($reponse->header('X-Envoi-Signature') ?: null);
        $attendue = SignatureEnvoi::signer($secret, SignatureEnvoi::chargeReponse($nonce, $workspaceId, $reponse->status(), $corps));
        $recue = str_starts_with($recue, 'sha256=') ? substr($recue, 7) : $recue;
        if ($recue === '' || ! hash_equals($attendue, $recue)) {
            throw ServeurEnvoiRefuse::motif('signature de réponse invalide.');
        }
        $json = json_decode($corps, true);
        if (! is_array($json) || ($json['workspace_id'] ?? null) !== $workspaceId) {
            throw ServeurEnvoiRefuse::motif('réponse invalide (espace).');
        }

        return $json;
    }
}
