<?php

namespace App\Envoi\Serveur\Kumo;

use App\Envoi\Contrat\MessageAEnvoyer;
use App\Envoi\Serveur\ClientServeurEnvoiHmac;
use App\Envoi\Serveur\ServeurEnvoiRefuse;
use Illuminate\Support\Facades\Log;

/**
 * Transport d'injection par HTTPS signé (`POST /v1/injections`, ADR K0 D7). Le corps du message, déjà RFC 5322 complet
 * (List-Unsubscribe, pied art. 14), part TEL QUEL en base64 : rien n'est reconstruit, aucun en-tête interne ajouté.
 * Aucun appel réseau dans une transaction ni sans les deux interrupteurs (contrôles du client).
 */
final class TransportHttpsSigne implements TransportInjection
{
    private const MAX_MESSAGE_OCTETS = 1_048_576;

    public function __construct(private readonly ClientServeurEnvoiHmac $client) {}

    public function envoyer(MessageAEnvoyer $message, string $jeton, string $source = ''): int
    {
        if ($message->idInjection === null || $message->cheminRetour === null) {
            throw ServeurEnvoiRefuse::motif('identifiant ou chemin de retour absent');
        }
        if (strlen($message->corps) > self::MAX_MESSAGE_OCTETS) {
            throw ServeurEnvoiRefuse::motif('message trop volumineux');
        }
        $corps = json_encode([
            'id_injection' => $message->idInjection,
            'mail_from' => $message->cheminRetour,
            'rcpt_to' => $message->destinataire,
            'message' => base64_encode($message->corps),
        ], JSON_THROW_ON_ERROR);

        ['statut' => $statut, 'json' => $json] = $this->client->injecter($message->workspaceId, $source, $corps);

        return match ($statut) {
            202 => 250,
            429 => self::codeSmtp($json, 400, 499),
            422 => self::codeSmtp($json, 500, 599),
            503 => throw new CoupureTransport(false),
            504 => throw new CoupureTransport(true),
            409 => ($json['erreur'] ?? null) === 'retire'
                ? throw new InjectionRetiree
                : throw new CoupureTransport(true), // id déjà utilisé : sort incertain, jamais renvoyé
            401 => self::horloge($json, $message->workspaceId),
            default => throw ServeurEnvoiRefuse::motif('réponse inattendue.'),
        };
    }

    /** @param array<string, mixed> $json */
    private static function codeSmtp(array $json, int $min, int $max): int
    {
        $code = $json['code_smtp'] ?? null;
        if (! is_int($code) || $code < $min || $code > $max) {
            throw ServeurEnvoiRefuse::motif('code SMTP absent ou hors plage.'); // jamais inventé
        }

        return $code;
    }

    /** @param array<string, mixed> $json */
    private static function horloge(array $json, string $workspaceId): never
    {
        if (($json['erreur'] ?? null) !== 'horloge_hors_fenetre') {
            throw ServeurEnvoiRefuse::motif('réponse en erreur (statut 401).');
        }
        Log::critical('envoi.passerelle_horloge_hors_fenetre : injection refusée pour écart d\'horloge, rien n\'est parti.', ['workspace_id' => $workspaceId]);

        throw new CoupureTransport(false);
    }
}
