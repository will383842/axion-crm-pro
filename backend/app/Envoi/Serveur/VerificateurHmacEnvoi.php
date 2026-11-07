<?php

namespace App\Envoi\Serveur;

use App\Support\HmacSignature;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Vérifie une requête du serveur d'envoi : « horodatage.nonce.corps » signé par
 * `HmacSignature` (patron du dépôt). Rend un statut HTTP : 200 accepté ;
 * 401 secret vide / en-tête absent / horodatage hors 6 min / signature fausse ;
 * 409 nonce rejoué ; 503 mémoire anti-rejeu indisponible (jamais accepté sans contrôle).
 * Le nonce n'est consommé qu'APRÈS une signature valide, par `Cache::add` (SET NX +
 * expiration) sur le même magasin que les autres canaux (`crm.ingest.replay_store`).
 */
final class VerificateurHmacEnvoi
{
    public const FENETRE_SECONDES = 360;

    public function verifier(string $secret, string $source, ?string $horodatage, ?string $nonce, ?string $signature, string $corps): int
    {
        if ($secret === '' || $nonce === null || $nonce === '' || strlen($nonce) > 128) {
            return 401;
        }
        if (! HmacSignature::timestampWithinWindow($horodatage, self::FENETRE_SECONDES)) {
            return 401;
        }
        if (! HmacSignature::verify($secret, HmacSignature::signedPayload((string) $horodatage, $nonce . '.' . $corps), $signature)) {
            return 401;
        }

        $ttl = max(1, (int) $horodatage + self::FENETRE_SECONDES - time() + 1);
        try {
            $neuf = Cache::store((string) config('crm.ingest.replay_store', 'redis'))
                ->add('envoi-serveur:nonce:' . hash('sha256', $source . '|' . $nonce), 1, $ttl);
        } catch (Throwable) {
            return 503;
        }

        return $neuf ? 200 : 409;
    }
}
