<?php

namespace App\Envoi\Smtp;

use App\Support\WorkspaceContext;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Accès aux boîtes. Identifiants chiffrés (Crypt, clé d'application) ; aucun effacement. */
final class DepotBoites
{
    private readonly ResolveurHote $resolveur;

    public function __construct(?ResolveurHote $resolveur = null)
    {
        $this->resolveur = $resolveur ?? new ResolveurDns;
    }

    /**
     * Refuse (EnvoiSmtpRefuse) tout hôte/port/chiffrement dangereux AVANT d'écrire quoi que ce soit :
     * ports hors liste blanche, adresses privées/bouclage/link-local/métadonnées, absence de TLS.
     */
    public function creer(string $workspaceId, string $adresse, string $hote, int $port, string $chiffrement, string $utilisateur, #[\SensitiveParameter] string $secret, int $limiteJour = 20, int $limiteHeure = 5): string
    {
        $hote = trim($hote);
        GardeHoteSmtp::controlerBoite($hote, $port, $chiffrement, $this->resolveur);
        $id = (string) Str::uuid();
        $chiffres = Crypt::encryptString((string) json_encode(['u' => $utilisateur, 's' => $secret], JSON_THROW_ON_ERROR));
        WorkspaceContext::run($workspaceId, fn () => DB::table('envoi_boites')->insert([
            'id' => $id,
            'workspace_id' => $workspaceId,
            'adresse' => $adresse,
            'hote' => $hote,
            'port' => $port,
            'chiffrement' => $chiffrement,
            'identifiants_chiffres' => $chiffres,
            'limite_jour' => $limiteJour,
            'limite_heure' => $limiteHeure,
        ]));

        return $id;
    }

    public function trouver(string $workspaceId, string $boiteId): ?BoiteSmtp
    {
        $l = WorkspaceContext::run($workspaceId, fn () => DB::table('envoi_boites')
            ->where('workspace_id', $workspaceId)->where('id', $boiteId)->first());
        if ($l === null) {
            return null;
        }
        /** @var array{u: string, s: string} $ident */
        $ident = json_decode(Crypt::decryptString((string) $l->identifiants_chiffres), true, 512, JSON_THROW_ON_ERROR);

        return new BoiteSmtp((string) $l->id, $workspaceId, (string) $l->adresse, (string) $l->hote, (int) $l->port, (string) $l->chiffrement, $ident['u'], $ident['s'], $l->etat === 'en_pause');
    }

    public function mettreEnPause(string $workspaceId, string $boiteId, string $motif): void
    {
        WorkspaceContext::run($workspaceId, fn () => DB::table('envoi_boites')
            ->where('workspace_id', $workspaceId)->where('id', $boiteId)
            ->update(['etat' => 'en_pause', 'pause_motif' => $motif, 'pause_le' => now(), 'maj_le' => now()]));
    }

    public function reprendre(string $workspaceId, string $boiteId): void
    {
        WorkspaceContext::run($workspaceId, fn () => DB::table('envoi_boites')
            ->where('workspace_id', $workspaceId)->where('id', $boiteId)
            ->update(['etat' => 'active', 'pause_motif' => null, 'maj_le' => now()]));
    }
}
