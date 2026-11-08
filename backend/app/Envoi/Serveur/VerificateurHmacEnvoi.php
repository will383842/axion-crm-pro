<?php

namespace App\Envoi\Serveur;

use App\Support\HmacSignature;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Vérifie une requête du serveur d'envoi : « MÉTHODE, chemin, horodatage, nonce, workspace_id, corps » (voir `SignatureEnvoi`),
 * signés par `HmacSignature` (patron du dépôt). Rend un statut HTTP : 200 accepté ;
 * 401 secret vide / en-tête absent / nonce non UUID / horodatage hors 5 min / signature fausse ;
 * 409 nonce rejoué ; 503 mémoire anti-rejeu indisponible (jamais accepté sans contrôle).
 * Le nonce n'est consommé qu'APRÈS une signature valide, par `Cache::add` (SET NX +
 * expiration) sur le même magasin que les autres canaux (`crm.ingest.replay_store`).
 */
final class VerificateurHmacEnvoi
{
    public const FENETRE_SECONDES = 300;

    public function verifier(#[\SensitiveParameter] string $secret, string $source, ?string $horodatage, ?string $nonce, ?string $signature, string $corps, string $methode = 'POST', string $chemin = '', string $workspaceId = ''): int
    {
        if ($secret === '' || $nonce === null || ! SignatureEnvoi::estUuid($nonce) || ! SignatureEnvoi::estUuid($workspaceId)) {
            return 401;
        }
        [, $signature] = SignatureEnvoi::analyser($signature);
        if (! HmacSignature::timestampWithinWindow($horodatage, self::FENETRE_SECONDES)) {
            return 401;
        }
        if (! HmacSignature::verify($secret, SignatureEnvoi::chargeRequete($methode, $chemin, (string) $horodatage, $nonce, $workspaceId, $corps), $signature)) {
            return 401;
        }

        $ttl = max(1, (int) $horodatage + self::FENETRE_SECONDES - time() + 1);
        try {
            $neuf = Cache::store((string) config('crm.ingest.replay_store', 'redis'))
                ->add('envoi-serveur:nonce:' . hash('sha256', $source . '|' . $workspaceId . '|' . $nonce), 1, $ttl);
        } catch (Throwable) {
            return 503;
        }

        return $neuf ? 200 : 409;
    }
}
