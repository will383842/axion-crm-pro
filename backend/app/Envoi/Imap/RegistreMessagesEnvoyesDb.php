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
