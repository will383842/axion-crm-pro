<?php

namespace App\Crm\Claude;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * LE CLIENT CLAUDE HAIKU — appel HTTP brut `POST /v1/messages` (comme
 * `AnthropicProvider`, pour garder `Http::fake` en test).
 *
 * Contrat :
 *  - réflexion coupée, pas de `temperature`, `max_tokens` petit, délai 30 s,
 *    AUCUNE relance automatique ;
 *  - recherche web (poste A seulement) : `max_uses` 1, domaines bloqués de la config ;
 *  - sortie JSON imposée par un schéma, puis revalidée ici ;
 *  - le coût est RÉSERVÉ avant l'appel (`$reserver`), puis remplacé par le coût
 *    réel d'une réponse 200 (`$regler`). Le plafond total est la SOMME de toutes
 *    les clés : la bascule ne le remet jamais à zéro ;
 *  - crédit épuisé (402, `billing_error`, 400 « credit balance ») et clé
 *    invalide (401, 403) : bascule vers la clé suivante, la MÊME fiche repart,
 *    sans coût (seule une réponse 200 coûte) ;
 *  - 429 et `overloaded` : pas de bascule, on lève une pause ;
 *  - on ne journalise jamais la clé ni le corps de la réponse (alias et statut seulement).
 */
class ClientClaude
{
    public const POSTE_RECHERCHE = 'recherche-web';

    private const URL = 'https://api.anthropic.com/v1/messages';

    /**
     * @param  \Closure(string,int):bool  $reserver  (poste, micro) : false = borne atteinte
     * @param  \Closure(string,int,int):void  $regler  (poste, réservé, réel)
     */
    public function __construct(
        private readonly RotationCles $rotation,
        private readonly \Closure $reserver,
        private readonly \Closure $regler,
    ) {}

    /**
     * @param  array<string,mixed>  $schema  schéma JSON de la sortie
     * @return array{donnees:array<string,mixed>, cout_micro:int, anomalie:bool, alias:string, recherches:int}
     */
    public function appeler(string $poste, string $prompt, array $schema): array
    {
        // Interrupteur payant : fermé par défaut, aucun appel payant tant qu'il n'est pas ouvert.
        if (! (bool) config('crm.enrichissement.payant_actif', false)) {
            throw new ErreurClaude(ErreurClaude::PAYANT_INACTIF, 'Enrichissement payant désactivé : aucun appel.');
        }

        $modele = (string) config('services.anthropic.modele');
        PrixClaude::verifierModele($modele);

        if ($this->rotation->aliasValides() === []) {
            throw new ErreurClaude(ErreurClaude::AUCUNE_CLE, 'Aucun alias de clé valide : refus avant tout appel.');
        }

        $recherche = $poste === self::POSTE_RECHERCHE;
        $maxTokens = $recherche ? 300 : 100;
        $reserve = PrixClaude::coutMaximal((int) ceil(strlen($prompt) / 3) + ($recherche ? 50_000 : 0), $maxTokens, $recherche ? 1 : 0);
        if (! ($this->reserver)($poste, $reserve)) {
            throw new ErreurClaude(ErreurClaude::BUDGET, 'Budget Claude atteint : aucun appel.');
        }

        $corps = $this->corps($modele, $prompt, $schema, $maxTokens, $recherche);

        // Borne : au plus un tour par clé valide, même si l'état de la clé n'est pas enregistré.
        $toursMax = count($this->rotation->aliasValides());
        for ($tour = 0; $tour < $toursMax && ($alias = $this->rotation->courante()) !== null; $tour++) {
            try {
                $rep = Http::withHeaders([
                    'x-api-key' => $this->rotation->cle($alias),
                    'anthropic-version' => (string) config('services.anthropic.version_api'),
                    'content-type' => 'application/json',
                ])->timeout(30)->post(self::URL, $corps);
            } catch (ConnectionException) {
                // Coupure après envoi : on GARDE la réservation maximale.
                throw new ErreurClaude(ErreurClaude::RESEAU, 'Claude : coupure réseau.', 0, $alias);
            }

            $type = $this->classer($rep);
            if ($type === 'epuisee' || $type === 'invalide') {
                Log::warning('Claude : bascule vers la clé suivante.', ['alias' => $alias, 'etat' => $type, 'statut' => $rep->status()]);
                $this->rotation->marquer($alias, $type);

                continue;
            }
            if ($type === 'pause') {
                // Une réponse non 200 ne coûte rien : on rend la réservation.
                ($this->regler)($poste, $reserve, 0);

                throw new ErreurClaude(ErreurClaude::PAUSE, 'Claude : trop de débit (429 ou surcharge).', $rep->status(), $alias);
            }
            if (! $rep->successful()) {
                ($this->regler)($poste, $reserve, 0);

                throw new ErreurClaude(ErreurClaude::API, 'Claude : erreur API.', $rep->status(), $alias);
            }

            return $this->traiter($rep, $modele, $poste, $reserve, $alias, $schema);
        }

        // Aucune clé n'a répondu 200 : rien n'a été dépensé, on rend la réservation.
        ($this->regler)($poste, $reserve, 0);
        Log::error('Claude : plus de crédit sur aucun compte.');
        throw new ErreurClaude(ErreurClaude::TOUTES_EPUISEES, 'Plus de crédit sur aucun compte.');
    }

    /** @return 'epuisee'|'invalide'|'pause'|'ok' */
    private function classer(Response $rep): string
    {
        $statut = $rep->status();
        $typeErreur = (string) ($rep->json('error.type') ?? '');
        if ($statut === 402 || $typeErreur === 'billing_error'
            || ($statut === 400 && stripos((string) ($rep->json('error.message') ?? ''), 'credit balance') !== false)) {
            return 'epuisee';
        }
        if ($statut === 401 || $statut === 403) {
            return 'invalide';
        }
        if ($statut === 429 || $statut === 529 || $typeErreur === 'overloaded_error') {
            return 'pause';
        }

        return 'ok';
    }

    /**
     * @param  array<string,mixed>  $schema
     * @return array{donnees:array<string,mixed>, cout_micro:int, anomalie:bool, alias:string, recherches:int}
     */
    private function traiter(Response $rep, string $modele, string $poste, int $reserve, string $alias, array $schema): array
    {
        /** @var array<string,mixed> $usage */
        $usage = (array) ($rep->json('usage') ?? []);
        $cout = PrixClaude::cout($modele, $usage);
        // Le coût réel remplace la réservation, même si la sortie est ensuite rejetée.
        ($this->regler)($poste, $reserve, $cout['micro']);

        $texte = '';
        foreach ((array) ($rep->json('content') ?? []) as $bloc) {
            $b = (array) $bloc;
            if (($b['type'] ?? '') === 'web_search_tool_result' && ($b['content']['type'] ?? '') === 'web_search_tool_result_error') {
                $code = (string) ($b['content']['error_code'] ?? '');
                throw new ErreurClaude(
                    $code === 'too_many_requests' ? ErreurClaude::PAUSE : ErreurClaude::RECHERCHE,
                    'Claude : erreur dans le résultat de recherche (' . $code . ').',
                    200,
                    $alias,
                );
            }
            if (($b['type'] ?? '') === 'text') {
                $texte .= (string) ($b['text'] ?? '');
            }
        }

        $donnees = json_decode($texte, true);
        if (! is_array($donnees) || ! $this->valide($donnees, $schema)) {
            throw new ErreurClaude(ErreurClaude::SORTIE_INVALIDE, 'Claude : sortie hors schéma.', 200, $alias);
        }

        return [
            'donnees' => $donnees,
            'cout_micro' => $cout['micro'],
            'anomalie' => $cout['anomalie'],
            'alias' => $alias,
            'recherches' => (int) ($usage['server_tool_use']['web_search_requests'] ?? 0),
        ];
    }

    /**
     * @param  array<string,mixed>  $schema
     * @return array<string,mixed>
     */
    private function corps(string $modele, string $prompt, array $schema, int $maxTokens, bool $recherche): array
    {
        $corps = [
            'model' => $modele,
            'max_tokens' => $maxTokens,
            'thinking' => ['type' => 'disabled'],
            'messages' => [['role' => 'user', 'content' => $prompt]],
            'output_config' => ['format' => ['type' => 'json_schema', 'schema' => $schema]],
        ];
        if ($recherche) {
            $corps['tools'] = [[
                'type' => 'web_search_20250305',
                'name' => 'web_search',
                'max_uses' => 1,
                'blocked_domains' => (array) config('services.anthropic.domaines_bloques', []),
                'user_location' => ['type' => 'approximate', 'country' => 'FR'],
            ]];
        }

        return $corps;
    }

    /**
     * Revalidation minimale : champs requis présents, types simples respectés.
     *
     * @param  array<string,mixed>  $donnees
     * @param  array<string,mixed>  $schema
     */
    private function valide(array $donnees, array $schema): bool
    {
        foreach ((array) ($schema['required'] ?? []) as $champ) {
            if (! array_key_exists($champ, $donnees)) {
                return false;
            }
        }
        $types = ['string' => 'is_string', 'integer' => 'is_int', 'number' => 'is_numeric', 'boolean' => 'is_bool', 'array' => 'is_array'];
        foreach ((array) ($schema['properties'] ?? []) as $champ => $def) {
            $t = $def['type'] ?? null;
            if (array_key_exists($champ, $donnees) && is_string($t) && isset($types[$t]) && ! $types[$t]($donnees[$champ])) {
                return false;
            }
        }

        return true;
    }
}
