<?php

namespace App\Envoi\Imap;

use App\Support\WorkspaceContext;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;
use SensitiveParameter;
use Throwable;

/**
 * Accès IMAP des boîtes d'envoi (`envoi_boites_acces_imap`, ajout seul : la dernière ligne fait foi). Le secret est chiffré
 * (`Crypt`) et `parametres` est le SEUL endroit qui le déchiffre. Aucune connexion n'est ouverte ici. Port 993 et chiffrement
 * `ssl` sont imposés : jamais lus d'un argument.
 */
final class DepotAccesImap
{
    /** Ajoute une ligne active (chiffrée). */
    public function poser(string $ws, string $boiteId, string $hote, string $identifiant, #[SensitiveParameter] string $motDePasse): void
    {
        if (! HotesImapBoites::hoteAutorise($hote) || filter_var($identifiant, FILTER_VALIDATE_EMAIL) === false || $motDePasse === '') {
            throw new InvalidArgumentException('Accès IMAP refusé : hôte, adresse ou mot de passe invalide.');
        }
        $chiffre = Crypt::encryptString((string) json_encode(['u' => $identifiant, 's' => $motDePasse], JSON_THROW_ON_ERROR));
        $this->ajouter($ws, $boiteId, ['hote' => $hote, 'port' => 993, 'dossier' => 'INBOX', 'identifiant_chiffre' => $chiffre, 'actif' => true]);
    }

    /** Ajoute une ligne inactive (aucun secret). */
    public function retirer(string $ws, string $boiteId): void
    {
        $this->ajouter($ws, $boiteId, ['hote' => null, 'port' => null, 'dossier' => null, 'identifiant_chiffre' => null, 'actif' => false]);
    }

    /**
     * Dernière ligne de la boîte, sans déchiffrer ; null si aucune ou inactive.
     *
     * @return array{hote: string, port: int, dossier: string}|null
     */
    public function decrire(string $ws, string $boiteId): ?array
    {
        $l = $this->derniere($ws, $boiteId);

        return $l === null ? null : ['hote' => (string) $l->hote, 'port' => (int) $l->port, 'dossier' => (string) $l->dossier];
    }

    /**
     * Seul endroit qui déchiffre.
     *
     * @return array{host: string, port: int, encryption: string, username: string, password: string}|null
     */
    public function parametres(string $ws, string $boiteId): ?array
    {
        $l = $this->derniere($ws, $boiteId);
        if ($l === null) {
            return null;
        }
        try {
            $d = json_decode(Crypt::decryptString((string) $l->identifiant_chiffre), true, 4, JSON_THROW_ON_ERROR);

            return ['host' => (string) $l->hote, 'port' => 993, 'encryption' => 'ssl', 'username' => (string) $d['u'], 'password' => (string) $d['s']];
        } catch (Throwable) {
            throw new RuntimeException('accès IMAP illisible'); // message fixe : jamais celui d'origine
        }
    }

    private function derniere(string $ws, string $boiteId): ?\stdClass
    {
        $l = WorkspaceContext::run($ws, fn () => DB::table('envoi_boites_acces_imap')
            ->where('workspace_id', $ws)->where('boite_id', $boiteId)->orderByDesc('id')->first());

        return $l !== null && $l->actif ? $l : null;
    }

    /** @param array<string, mixed> $valeurs */
    private function ajouter(string $ws, string $boiteId, array $valeurs): void
    {
        WorkspaceContext::run($ws, function () use ($ws, $boiteId, $valeurs) {
            if (WorkspaceContext::current() !== $ws || ! DB::table('envoi_boites')->where('workspace_id', $ws)->where('id', $boiteId)->exists()) {
                throw new InvalidArgumentException('Boîte inconnue de cet espace.');
            }
            DB::table('envoi_boites_acces_imap')->insert(['workspace_id' => $ws, 'boite_id' => $boiteId] + $valeurs);
        });
    }
}
