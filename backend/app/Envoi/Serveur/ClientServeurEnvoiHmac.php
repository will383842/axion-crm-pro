<?php

namespace App\Envoi\Serveur;

use App\Envoi\Serveur\Contrat\CommandeurServeur;
use App\Envoi\Serveur\Contrat\EvenementSuivi;
use App\Envoi\Serveur\Contrat\LotSuivi;
use App\Envoi\Serveur\Contrat\TireurEvenements;
use App\Envoi\Smtp\ResolveurHote;
use App\Support\WorkspaceContext;
use Carbon\Carbon;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Client signé du serveur d'envoi (ADR K0 D3/D4/D6/D7). TIRAGE seulement : le CRM n'expose aucune route
 * entrante. L'adresse du serveur vient de la CONFIGURATION d'environnement, jamais de la base.
 * Interrupteurs `envoi.reel_actif` ET `envoi.k1_actif` (faux par défaut) : tout appel est refusé tant que l'un est éteint.
 * Chaque source a SA configuration (URL, secret) : sinon refus, aucun repli global. TLS mutuel + AC privée
 * obligatoires (voir GardeHoteServeur::optionsHttp). Le client ne garde aucun état (non singleton).
 */
final class ClientServeurEnvoiHmac implements CommandeurServeur, TireurEvenements
{
    public const MAX_EVENEMENTS = 500;

    public const MAX_CORPS_OCTETS = 1_048_576;

    /** Liste blanche des commandes. « injection » est un nom RÉSERVÉ (journal local) : jamais émis vers le serveur. */
    public const COMMANDES_AUTORISEES = ['pause', 'reprise', 'retrait'];

    private const TYPE_AUTORISE = '/^[a-z][a-z0-9_]{0,63}$/';

    private const EPOQUE = '/^[0-9a-f]{16}$/';

    public function __construct(private readonly JournalCommandes $journal, private readonly ResolveurHote $resolveur) {}

    public function tirer(string $workspaceId, string $source, ?string $curseur, int $limite, DateTimeImmutable $depuis): LotSuivi
    {
        $limite = max(1, min($limite, self::MAX_EVENEMENTS));
        $prep = $this->preparer($workspaceId, $source);
        // Désynchronisation déjà constatée : AUCUN appel tant qu'un humain n'a pas vérifié (jamais d'oubli silencieux).
        $etat = $this->etatTirage($workspaceId, $source);
        if ($etat['bloque']) {
            throw ServeurEnvoiRefuse::motif('tirage arrêté pour ce serveur (désynchronisation constatée) : intervention humaine requise.');
        }
        $parametres = ['curseur' => $curseur ?? '', 'depuis' => DateTimeImmutable::createFromInterface($depuis)->setTimezone(new DateTimeZone('UTC'))->format('c'), 'limite' => $limite];
        if ($etat['epoque'] !== null) {
            $parametres['epoque'] = $etat['epoque']; // la passerelle répond 409 si sa base n'est plus la même
        }
        $requete = http_build_query($parametres);
        ['statut' => $statut, 'json' => $json] = $this->appeler($prep, $workspaceId, 'GET', SignatureEnvoi::CHEMIN_EVENEMENTS, $requete, '', [409]);
        if ($statut === 409) {
            if (($json['erreur'] ?? null) !== 'curseur_desynchronise') {
                throw ServeurEnvoiRefuse::motif('réponse en erreur (statut 409).'); // ex. nonce rejoué : pas une dérive de curseur
            }
            $this->bloquerTirage($workspaceId, $source, 'la passerelle signale un curseur ou une époque désynchronisé (409)');
        }
        $epoque = $json['epoque'] ?? null;
        if (! is_string($epoque) || preg_match(self::EPOQUE, $epoque) !== 1) {
            throw ServeurEnvoiRefuse::motif('réponse invalide (époque).');
        }
        if ($etat['epoque'] !== null && ! hash_equals($etat['epoque'], $epoque)) {
            // Base recréée : même si le compteur a rattrapé l'ancien curseur, rien n'est cru ni écrasé en silence.
            $this->bloquerTirage($workspaceId, $source, 'époque de la passerelle changée (base recréée ?)');
        }

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
            $evenements[] = new EvenementSuivi($e['event_id'], $e['seq'], $e['type'], $e['id_injection'], ($e['observation'] ?? false) === true);
        }
        $suivant = $json['curseur_suivant'] ?? null;
        if ($suivant !== null && (! is_string($suivant) || strlen($suivant) > 128)) {
            throw ServeurEnvoiRefuse::motif('réponse invalide (curseur).');
        }
        if ($suivant !== null && $curseur !== null && ctype_digit($curseur) && ctype_digit($suivant) && (int) $suivant < (int) $curseur) {
            throw ServeurEnvoiRefuse::motif('le curseur ne peut pas reculer.');
        }

        if ($etat['epoque'] === null) {
            WorkspaceContext::run($workspaceId, fn () => DB::table('envoi_serveurs')->where('workspace_id', $workspaceId)->where('source', $source)
                ->whereNull('epoque_passerelle')->update(['epoque_passerelle' => $epoque]));
        }

        return new LotSuivi($evenements, $suivant, $epoque);
    }

    /** @return array{epoque: ?string, bloque: bool} */
    private function etatTirage(string $workspaceId, string $source): array
    {
        $ligne = WorkspaceContext::run($workspaceId, fn () => DB::table('envoi_serveurs')->where('workspace_id', $workspaceId)->where('source', $source)->first(['epoque_passerelle', 'tirage_bloque_le']));

        $epoque = $ligne?->epoque_passerelle;

        return ['epoque' => is_string($epoque) ? $epoque : null, 'bloque' => $ligne?->tirage_bloque_le !== null];
    }

    /** Alerte critique, arrêt du tirage de CE serveur (persistant), puis exception : le lot n'est jamais utilisé. */
    private function bloquerTirage(string $workspaceId, string $source, string $motif): never
    {
        Log::critical('envoi.serveur_desynchronise : tirage arrêté, intervention humaine requise.', ['workspace_id' => $workspaceId, 'source' => $source, 'motif' => $motif]);
        WorkspaceContext::run($workspaceId, fn () => DB::table('envoi_serveurs')->where('workspace_id', $workspaceId)->where('source', $source)
            ->update(['tirage_bloque_le' => now(), 'tirage_bloque_motif' => $motif]));

        throw ServeurEnvoiRefuse::motif('désynchronisation constatée : tirage arrêté pour ce serveur.');
    }

    /**
     * Mesures de santé du serveur (K15) : GET signé, mêmes contrôles locaux que le tirage. Aucune écriture.
     *
     * @return array<string, mixed>
     */
    public function lireSante(string $workspaceId, string $source): array
    {
        // 401 lu : la passerelle SIGNE son refus pour écart d'horloge (`horloge_hors_fenetre`), seulement après avoir vérifié la signature.
        ['statut' => $statut, 'json' => $json] = $this->appeler($this->preparer($workspaceId, $source), $workspaceId, 'GET', SignatureEnvoi::CHEMIN_SANTE, '', '', [401]);
        if ($statut === 401) {
            throw ($json['erreur'] ?? null) === 'horloge_hors_fenetre' ? ServeurEnvoiRefuse::horloge() : ServeurEnvoiRefuse::motif('réponse en erreur (statut 401).');
        }

        return $json;
    }

    public function commander(string $workspaceId, string $source, string $commandeId, string $commande): bool
    {
        // TOUS les contrôles locaux (interrupteurs, source active, adresse, garde d'hôte, TLS) AVANT toute trace :
        // une erreur de configuration ou d'appel doit se voir tout de suite, sans intention orpheline.
        $prep = $this->preparer($workspaceId, $source);
        if (! SignatureEnvoi::estUuid($commandeId)) {
            throw ServeurEnvoiRefuse::motif('commande_id : UUID aléatoire exigé.');
        }
        if (! in_array($commande, self::COMMANDES_AUTORISEES, true)) {
            throw ServeurEnvoiRefuse::motif('commande hors liste blanche.');
        }
        // Intention journalisée (avec sa source) AVANT le réseau : si l'appel échoue, la commande reste « non
        // acquittée » et sera rejouée avec le MÊME commande_id (le serveur est idempotent). Jamais perdue.
        $this->journal->enregistrer($workspaceId, $commandeId, $commande, $source);
        if ($this->journal->estAcquittee($workspaceId, $commandeId)) {
            return false;
        }
        $corps = json_encode(['commande_id' => $commandeId, 'commande' => $commande], JSON_THROW_ON_ERROR);
        $this->appeler($prep, $workspaceId, 'POST', SignatureEnvoi::PREFIXE_COMMANDES . $commande, $corps, $corps);
        $this->journal->acquitter($workspaceId, $commandeId);

        return true;
    }

    private function controlesGeneraux(): void
    {
        if (DB::transactionLevel() > 0
            && ! ((bool) config('envoi.serveur.tolere_transaction_englobante', false) && app()->environment('testing'))) {
            throw ServeurEnvoiRefuse::motif('appel réseau refusé dans une transaction ouverte.');
        }
        if (config('envoi.reel_actif') !== true) {
            throw ServeurEnvoiRefuse::motif('envoi réel éteint (ENVOI_REEL_ACTIF), appel refusé.');
        }
        if (config('envoi.k1_actif') !== true) {
            throw ServeurEnvoiRefuse::motif('serveur d’envoi K1 éteint (ENVOI_K1_ACTIF), appel refusé.');
        }
    }

    /** Configuration PROPRE à la source, sans aucun repli sur une valeur globale. */
    private function propre(string $cle, string $source): string
    {
        $table = config('envoi.serveur.' . $cle, []);
        $valeur = is_array($table) ? ($table[$source] ?? '') : '';

        return is_string($valeur) ? $valeur : '';
    }

    private function cleIdPour(string $source): string
    {
        $cle = $this->propre('cles', $source);

        return preg_match('/^[A-Za-z0-9_-]{1,32}$/', $cle) === 1 ? $cle : (string) config('envoi.serveur.cle_id', '1');
    }

    private static function delai(string $cle, int $defaut): int
    {
        return max(1, min(30, (int) config('envoi.serveur.' . $cle, $defaut))); // jamais 0 (= infini), jamais > 30 s
    }

    /**
     * Tous les contrôles locaux, sans réseau ni écriture.
     *
     * @return array{secret: string, cle: string, cible: array{base: string, hote: string, port: int, ip: string}, options: array<string, mixed>}
     */
    private function preparer(string $workspaceId, string $source): array
    {
        $this->controlesGeneraux();
        $secret = $this->propre('secrets', $source);
        if ($secret === '') {
            throw ServeurEnvoiRefuse::configuration('secret HMAC propre à la source absent, appel refusé.');
        }
        $actif = WorkspaceContext::run($workspaceId, fn () => DB::table('envoi_serveurs')
            ->where('workspace_id', $workspaceId)->where('source', $source)->where('actif', true)->exists());
        if (! $actif) {
            throw ServeurEnvoiRefuse::motif('source inconnue ou inactive.');
        }
        $url = $this->propre('urls', $source);
        if ($url === '') {
            throw ServeurEnvoiRefuse::configuration('adresse propre à la source absente de la configuration.');
        }
        $cible = GardeHoteServeur::controler($url, $this->resolveur);
        $max = self::MAX_CORPS_OCTETS;
        $options = GardeHoteServeur::optionsHttp($cible) + [
            // Plafond en FLUX (compteur d'octets) : vaut aussi sans Content-Length ; l'en-tête sert de coupe-circuit précoce.
            'sink' => new FluxReponsePlafonne($max),
            'on_headers' => function ($r) use ($max): void {
                if ((int) $r->getHeaderLine('Content-Length') > $max) {
                    throw new \RuntimeException('corps trop volumineux');
                }
            },
        ];

        return ['secret' => $secret, 'cle' => $this->cleIdPour($source), 'cible' => $cible, 'options' => $options];
    }

    /**
     * @param  array{secret: string, cle: string, cible: array{base: string, hote: string, port: int, ip: string}, options: array<string, mixed>}  $prep
     * @param  list<int>  $statutsLus  statuts non 2xx dont le corps SIGNÉ est lu (au lieu d'un refus)
     * @return array{statut: int, json: array<string, mixed>}
     */
    private function appeler(array $prep, string $workspaceId, string $methode, string $chemin, string $signe, string $corps, array $statutsLus = []): array
    {
        $secret = $prep['secret'];
        $ts = (string) Carbon::now('UTC')->getTimestamp();
        $nonce = (string) Str::uuid();
        $requete = Http::connectTimeout(self::delai('connect_timeout', 5))
            ->timeout(self::delai('timeout', 10))
            ->withOptions($prep['options'])
            ->withHeaders([
                'X-Envoi-Timestamp' => $ts,
                'X-Envoi-Nonce' => $nonce,
                'X-Envoi-Workspace' => $workspaceId,
                'X-Envoi-Signature' => SignatureEnvoi::entete($prep['cle'], SignatureEnvoi::signer($secret, SignatureEnvoi::chargeRequete($methode, $chemin, $ts, $nonce, $workspaceId, $signe))),
            ]);
        try {
            $reponse = $methode === 'GET'
                ? $requete->get($prep['cible']['base'] . $chemin . '?' . $signe)
                : $requete->withBody($corps, 'application/json')->post($prep['cible']['base'] . $chemin);
        } catch (Throwable) {
            throw ServeurEnvoiRefuse::motif('injoignable ou en échec réseau.');
        }

        return ['statut' => $reponse->status(), 'json' => $this->lireReponse($reponse, $secret, $nonce, $workspaceId, $statutsLus)];
    }

    /**
     * @param  list<int>  $statutsLus
     * @return array<string, mixed>
     */
    private function lireReponse(Response $reponse, #[\SensitiveParameter] string $secret, string $nonce, string $workspaceId, array $statutsLus = []): array
    {
        if (! $reponse->successful() && ! in_array($reponse->status(), $statutsLus, true)) {
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
