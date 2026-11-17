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

    /** Motif posé par une suspension faite à la main (le seul que `reactiver` lève). Tout autre motif est une pause automatique. */
    public const MOTIF_SUSPENSION_MANUELLE = 'suspension_manuelle';

    /**
     * Boîtes de l'espace pour l'écran : aucune colonne secrète n'est lue. Lecture bornée (la page n'a ni pagination ni filtre au contrat).
     *
     * @return list<array<string, mixed>>
     */
    public function lister(string $workspaceId, int $limite = 200): array
    {
        return WorkspaceContext::run($workspaceId, function () use ($workspaceId, $limite) {
            $lignes = DB::table('envoi_boites')->where('workspace_id', $workspaceId)
                ->orderBy('cree_le')->orderBy('id')->limit(max(1, min($limite, 200)))
                ->get(['id', 'adresse', 'etat', 'pause_motif', 'limite_jour']);

            return array_values($lignes->map(fn (\stdClass $l) => $this->resume($workspaceId, $l))->all());
        });
    }

    /** @return ?array<string, mixed> */
    public function resumer(string $workspaceId, string $boiteId): ?array
    {
        return WorkspaceContext::run($workspaceId, function () use ($workspaceId, $boiteId) {
            $l = DB::table('envoi_boites')->where('workspace_id', $workspaceId)->where('id', $boiteId)
                ->first(['id', 'adresse', 'etat', 'pause_motif', 'limite_jour']);

            return $l === null ? null : $this->resume($workspaceId, $l);
        });
    }

    /** Suspension à la main : n'écrit que si la boîte n'est pas déjà en pause (le stop gagne). Rend vrai si elle a écrit. */
    public function suspendreManuellement(string $workspaceId, string $boiteId): bool
    {
        return WorkspaceContext::run($workspaceId, fn () => DB::table('envoi_boites')
            ->where('workspace_id', $workspaceId)->where('id', $boiteId)->where('etat', '<>', 'en_pause')
            ->update(['etat' => 'en_pause', 'pause_motif' => self::MOTIF_SUSPENSION_MANUELLE, 'pause_le' => now(), 'maj_le' => now()]) > 0);
    }

    /**
     * Réactivation : ne lève QUE `suspension_manuelle`. Rend `absente`, `reactivee`, `deja_active` ou `pause_automatique`
     * (tout autre motif, y compris `manuelle` posé par « Tout arrêter » : rien n'est écrit).
     */
    public function reactiverSuspensionManuelle(string $workspaceId, string $boiteId): string
    {
        return WorkspaceContext::run($workspaceId, function () use ($workspaceId, $boiteId) {
            $base = fn () => DB::table('envoi_boites')->where('workspace_id', $workspaceId)->where('id', $boiteId);
            $leve = $base()->where('etat', 'en_pause')->where('pause_motif', self::MOTIF_SUSPENSION_MANUELLE)
                ->update(['etat' => 'active', 'pause_motif' => null, 'maj_le' => now()]);
            if ($leve > 0) {
                return 'reactivee';
            }
            $etat = $base()->value('etat');

            return $etat === null ? 'absente' : ($etat === 'active' ? 'deja_active' : 'pause_automatique');
        });
    }

    /** @return array<string, mixed> */
    private function resume(string $workspaceId, \stdClass $l): array
    {
        $domaine = strtolower(substr(strrchr((string) $l->adresse, '@') ?: '@', 1));
        $domaineId = $domaine === '' ? null : DB::table('envoi_domaines')->where('workspace_id', $workspaceId)->where('nom', $domaine)->value('id');
        $enPause = $l->etat === 'en_pause';

        return [
            'id' => (string) $l->id,
            'adresse' => (string) $l->adresse,
            'domaine_id' => $domaineId === null ? null : (string) $domaineId,
            'nom_affiche' => (string) $l->adresse,
            'etat' => $enPause ? 'rouge' : 'vert',
            'statut' => $enPause ? 'suspendue' : 'prete',
            'quota_jour' => (int) $l->limite_jour,
            'envoyes_aujourdhui' => (int) DB::table('envoi_injections_smtp')->where('workspace_id', $workspaceId)->where('boite_id', $l->id)
                ->where('etat', 'accepte')->whereRaw("(cree_le AT TIME ZONE 'Europe/Paris')::date = (now() AT TIME ZONE 'Europe/Paris')::date")->count(),
            'taux_refus' => null,
            'reponses_7j' => (int) DB::table('envoi_evenements')->where('workspace_id', $workspaceId)->whereIn('boite', [(string) $l->id, (string) $l->adresse])
                ->where('classe', 'like', 'reponse%')->where('recu_le', '>=', now()->subDays(7))->count(),
        ];
    }
}
