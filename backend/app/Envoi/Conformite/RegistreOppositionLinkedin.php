<?php

namespace App\Envoi\Conformite;

use App\Support\LinkedinSlug;
use App\Support\WorkspaceContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

/**
 * PONT-LINKEDIN LI-L3 — registre d'opposition par PROFIL LinkedIn, par espace. AJOUT SEUL : aucune suppression possible
 * (la table refuse UPDATE, DELETE et TRUNCATE).
 *
 * Le profil est une donnée de personne : il n'est écrit qu'en empreinte (même calcul que `RegistreOppositionManuel`) et en
 * valeur chiffrée, jamais en clair. Rien ici n'appelle LinkedIn. Lecture : `StatutOpposition::profilsOpposes`.
 */
final class RegistreOppositionLinkedin
{
    public const SOURCES = ['prospection_linkedin', 'manuel'];

    /** Empreinte d'un slug déjà normalisé. */
    public static function empreinte(string $slugNormalise): string
    {
        return RegistreOppositionManuel::empreinte($slugNormalise);
    }

    /**
     * Inscrit (idempotent : un rejeu ne change rien).
     *
     * @return bool `true` si l'inscription est nouvelle, `false` si le profil était déjà au registre
     *
     * @throws InvalidArgumentException profil non normalisable (ex. page d'entreprise), motif ou source invalides
     * @throws RuntimeException lecture/écriture impossible : l'appelant refuse (échec fermé)
     */
    public function inscrire(string $workspaceId, string $profil, string $motif, string $auteur, string $source): bool
    {
        $slug = LinkedinSlug::normalize($profil);
        if ($slug === null) {
            throw new InvalidArgumentException('Profil LinkedIn invalide : seule l’adresse d’un profil de personne (/in/…) est acceptée.');
        }
        $motif = trim($motif);
        if (mb_strlen($motif) < 3 || mb_strlen($motif) > 200 || str_contains($motif, '@')) {
            throw new InvalidArgumentException('Motif invalide (3 à 200 caractères, sans adresse e-mail).');
        }
        if (! in_array($source, self::SOURCES, true)) {
            throw new InvalidArgumentException('Source inconnue.');
        }
        if (trim($auteur) === '') {
            throw new InvalidArgumentException('Auteur requis.');
        }

        try {
            return WorkspaceContext::run($workspaceId, static fn (): bool => DB::table('envoi_oppositions_linkedin')->insertOrIgnore([
                'id' => (string) Str::uuid(),
                'workspace_id' => $workspaceId,
                'empreinte' => self::empreinte($slug),
                'valeur_chiffree' => Crypt::encryptString($slug),
                'source' => $source,
                'motif' => $motif,
                'auteur' => $auteur,
            ]) === 1);
        } catch (QueryException) {
            // Le message d'une exception SQL embarque les valeurs liées (empreinte, valeur chiffrée) : jamais propagé.
            throw new RuntimeException('Échec de l’écriture du registre d’opposition LinkedIn.');
        }
    }
}
