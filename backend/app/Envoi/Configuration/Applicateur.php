<?php

namespace App\Envoi\Configuration;

use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Valide, fait valider par le serveur, applique. Le retour arrière appartient au serveur. Aucun envoi réel.
 * Ordre : bornes de structure, garde du socle, validation du paquet, validerConfiguration, appliquerConfiguration.
 */
final class Applicateur
{
    public function __construct(
        private readonly ServeurEnvoiConfiguration $serveur,
        private readonly DepotVersions $versions,
        private readonly AlerteDerive $alerte,
    ) {}

    /**
     * Rejouer le même paquet est sans danger : le serveur reçoit un fichier par espace (remplacé, jamais cumulé)
     * et chaque tentative est repérée par son empreinte.
     *
     * @param  array<string, mixed>  $brut
     * @param  ?string  $note  complément noté avec une application réussie (ex. domaines ignorés)
     */
    public function appliquer(string $workspaceId, array $brut, ?string $note = null): StatutVersion
    {
        $empreinte = Paquet::empreinteBrute($brut);

        if (! ValidateurPaquet::dansLesBornes($brut)) {
            return $this->noter($workspaceId, $empreinte, StatutVersion::Invalide, 'structure hors bornes');
        }
        $cles = GardeSocle::clesVisees($brut);
        if ($cles !== []) {
            $this->alerte->signaler($workspaceId, $cles);

            return $this->noter($workspaceId, $empreinte, StatutVersion::DeriveRefusee, implode(',', $cles));
        }
        try {
            $paquet = Paquet::valide($workspaceId, $brut);
        } catch (PaquetInvalide $e) {
            return $this->noter($workspaceId, $empreinte, StatutVersion::Invalide, $e->getMessage());
        }
        try {
            $verdict = $this->serveur->validerConfiguration($paquet);
        } catch (Throwable $e) {
            return $this->incertaine($workspaceId, $empreinte, $e);
        }
        if (! $verdict->accepte) {
            return $this->noter($workspaceId, $empreinte, StatutVersion::Invalide, 'refusé par le serveur : ' . ($verdict->motif ?? 'sans motif'));
        }
        try {
            $this->serveur->appliquerConfiguration($paquet);
        } catch (ConfigurationRefusee $e) {
            return $this->noter($workspaceId, $empreinte, StatutVersion::RetourArriere, $e->getMessage());
        } catch (Throwable $e) {
            return $this->incertaine($workspaceId, $empreinte, $e);
        }

        return $this->noter($workspaceId, $empreinte, StatutVersion::Appliquee, $note);
    }

    private function incertaine(string $workspaceId, string $empreinte, Throwable $e): StatutVersion
    {
        Log::critical('envoi.config.incertaine', ['workspace' => $workspaceId, 'empreinte' => $empreinte, 'erreur' => $e::class]);

        return $this->noter($workspaceId, $empreinte, StatutVersion::Incertaine, $e::class);
    }

    /** Si la ligne ne s'écrit pas : trace critique, puis l'exception remonte (la commande sort en échec). */
    private function noter(string $workspaceId, string $empreinte, StatutVersion $statut, ?string $motif): StatutVersion
    {
        try {
            $this->versions->noter($workspaceId, $empreinte, $statut, $motif);
        } catch (Throwable $e) {
            Log::critical('envoi.config.version_non_ecrite', ['workspace' => $workspaceId, 'empreinte' => $empreinte, 'statut' => $statut->value]);

            throw $e;
        }

        return $statut;
    }
}
