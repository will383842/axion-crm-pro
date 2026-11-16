<?php

namespace App\Envoi\Imap;

use Closure;
use Illuminate\Support\Facades\DB;

/**
 * Registre réel des messages envoyés : table `envoi_messages_envoyes` (Message-ID en empreinte → injection,
 * clé étrangère vers `envoi_injections_smtp`). La table ne stocke PAS l'adresse : `$adresseDe` la retrouve
 * à partir de son empreinte (CRM). Sans adresse, le retour reste « à rattacher » (jamais d'effet deviné).
 */
final class RegistreMessagesEnvoyesDb implements RegistreMessagesEnvoyes
{
    /** @param Closure(string, string): ?string $adresseDe (espace, empreinte du destinataire) → adresse, ou null */
    public function __construct(private readonly Closure $adresseDe) {}

    private const UUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

    public function retrouverParInjection(string $workspaceId, string $idInjection): ?MessageOrigine
    {
        // Jamais « la première ligne » : si l'injection a plusieurs destinataires différents, c'est ambigu => null
        // (le retour reste « à rattacher »). Plusieurs lignes du MÊME destinataire ne sont pas une ambiguïté.
        // La colonne du registre est un UUID : un identifiant d'une autre forme (serveur) n'est pas comparé à elle.
        if (preg_match(self::UUID, $idInjection) === 1) {
            $empreintes = DB::table('envoi_messages_envoyes')
                ->where('workspace_id', $workspaceId)->where('id_injection', strtolower($idInjection))
                ->distinct()->limit(2)->pluck('empreinte_destinataire');
            if ($empreintes->isNotEmpty()) {
                return $this->origine($workspaceId, array_values($empreintes->all()), strtolower($idInjection));
            }
        }

        return $this->parIdentifiantServeur($workspaceId, $idInjection);
    }

    /**
     * C1-2 — VERP signé posé par le tunnel Kumo = identifiant du SERVEUR : le registre ne le connaît pas. On passe par
     * `envoi_messages` (même espace) -> Message-ID -> empreinte du registre. Plusieurs destinataires => null.
     * Le VERP est signé : ce repli n'affaiblit pas la règle « ARF seulement par VERP ». Branche à retirer en fin de transition.
     */
    private function parIdentifiantServeur(string $workspaceId, string $id): ?MessageOrigine
    {
        $messageIds = DB::table('envoi_messages')->where('workspace_id', $workspaceId)
            ->where(fn ($q) => $q->where('id_injection', $id)->orWhere('id_injection_serveur', $id))
            ->distinct()->limit(2)->pluck('message_id');
        if ($messageIds->count() !== 1) {
            return null;
        }
        $ligne = DB::table('envoi_messages_envoyes')->where('workspace_id', $workspaceId)
            ->where('message_id_empreinte', hash('sha256', 'mid:' . trim((string) $messageIds->first(), " \t\r\n<>")))
            ->first(['id_injection', 'empreinte_destinataire']);

        return $ligne === null ? null : $this->origine($workspaceId, [(string) $ligne->empreinte_destinataire], (string) $ligne->id_injection);
    }

    /** @param list<mixed> $empreintes */
    private function origine(string $workspaceId, array $empreintes, string $idRegistre): ?MessageOrigine
    {
        $adresse = count($empreintes) === 1 ? ($this->adresseDe)($workspaceId, (string) $empreintes[0]) : null;

        return $adresse === null || $adresse === '' ? null : new MessageOrigine($idRegistre, $adresse);
    }

    public function retrouver(string $workspaceId, array $messageIds): ?MessageOrigine
    {
        $empreintes = [];
        foreach ($messageIds as $id) {
            $empreintes[hash('sha256', 'mid:' . trim($id, " \t\r\n<>"))] = true;
        }
        if ($empreintes === []) {
            return null;
        }
        $lignes = DB::table('envoi_messages_envoyes')
            ->where('workspace_id', $workspaceId)
            ->whereIn('message_id_empreinte', array_keys($empreintes))
            ->get()
            ->keyBy('message_id_empreinte');
        foreach (array_keys($empreintes) as $empreinte) { // ordre de préférence des identifiants
            $ligne = $lignes->get($empreinte);
            if ($ligne === null) {
                continue;
            }
            $adresse = ($this->adresseDe)($workspaceId, (string) $ligne->empreinte_destinataire);
            if ($adresse !== null && $adresse !== '') {
                return new MessageOrigine((string) $ligne->id_injection, $adresse);
            }
        }

        return null;
    }
}
