<?php

namespace App\Socle\Alertes;

use App\Support\WorkspaceContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Écrit dans la cloche. Les destinataires de la console sont les comptes dont
 * l'adresse est l'adresse d'alerte principale (`alertes.email.destinataire`) :
 * chaque ligne appartient à un espace ET à une personne (double cloisonnement).
 */
final class Notifier
{
    public const PRODUITS = ['crm', 'envoi', 'socle'];

    public const GRAVITES = ['critique', 'attention', 'info'];

    /**
     * @param  array<string, mixed>  $contexte  réservé au journal, jamais stocké
     * @return int nombre de lignes écrites dans la cloche
     */
    public function envoyer(
        string $titre,
        string $corps,
        string $produit = 'crm',
        string $gravite = 'info',
        ?string $lien = null,
        array $contexte = [],
    ): int {
        $produit = in_array($produit, self::PRODUITS, true) ? $produit : 'socle';
        $gravite = in_array($gravite, self::GRAVITES, true) ? $gravite : 'info';
        $lien = $lien !== null && str_starts_with($lien, '/') && ! str_starts_with($lien, '//') ? $lien : null;

        $principale = strtolower(trim((string) config('alertes.email.destinataire', '')));
        if ($principale === '') {
            return 0;
        }

        $comptes = DB::table('users')
            ->whereRaw('lower(email) = ?', [$principale])
            ->whereNotNull('current_workspace_id')
            ->get(['id', 'current_workspace_id']);

        $ecrites = 0;
        foreach ($comptes as $compte) {
            $id = (string) Str::uuid();
            try {
                DB::transaction(function () use ($compte, $id, $titre, $corps, $produit, $gravite, $lien): void {
                    WorkspaceContext::set((string) $compte->current_workspace_id);
                    DB::table('notifications')->insert([
                        'id' => $id,
                        'workspace_id' => $compte->current_workspace_id,
                        'user_id' => $compte->id,
                        'type' => 'alerte.' . $gravite,
                        'title' => mb_substr($titre, 0, 200),
                        'body' => $corps,
                        'action_url' => $lien,
                        'produit' => $produit,
                        'gravite' => $gravite,
                        'created_at' => now(),
                    ]);
                });
                $ecrites++;
                event(new NotificationCreee((string) $compte->id, $id, $titre, $corps, $produit, $gravite, $lien));
            } catch (\Throwable $e) {
                Log::error('Cloche : écriture de l’alerte impossible.', ['exception' => $e::class] + $contexte);
            }
        }

        return $ecrites;
    }
}
