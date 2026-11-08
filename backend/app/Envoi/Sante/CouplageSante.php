<?php

namespace App\Envoi\Sante;

use App\Socle\Alertes\Alerte;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Couplage ADR K0 (homme mort) : le serveur d'envoi se met en pause après 15 min sans appel `sante` signé. Si l'envoi réel
 * et K1 sont allumés alors que la supervision (ENVOI_SANTE_ACTIF, faux par défaut) est éteinte, personne ne lit les
 * seuils, les certificats, les DNS ni les listes noires : la configuration est signalée (journal critique au démarrage,
 * alerte du Socle et échec de la commande planifiée), jamais passée sous silence.
 */
final class CouplageSante
{
    public const MESSAGE = 'Envoi réel et K1 allumés mais la supervision de santé est éteinte (ENVOI_SANTE_ACTIF) : seuils, certificats, DNS, listes noires et retours ne sont pas surveillés.';

    private static bool $journalise = false;

    public static function supervisionManquante(): bool
    {
        return config('envoi.reel_actif') === true && config('envoi.k1_actif') === true && config('envoi.sante.actif') !== true;
    }

    /** Journal critique (une fois par processus) ; avec $alerter, ajoute l'alerte du Socle (cloche + e-mail, regroupée). */
    public static function signaler(bool $alerter = false): void
    {
        if (! self::supervisionManquante()) {
            return;
        }
        if (! self::$journalise) {
            self::$journalise = true;
            Log::critical('[ENVOI] ' . self::MESSAGE);
        }
        // M2 : la commande tourne toutes les 5 min ; l'alerte du Socle (cloche + e-mail) part au plus UNE fois par jour (le journal et l'échec de la commande, eux, restent à chaque passage).
        if ($alerter && Cache::add('envoi:sante:couplage-alerte', true, now()->addDay())) {
            try {
                app(Alerte::class)->envoyer('Santé des envois : supervision éteinte alors que l’envoi réel est allumé', self::MESSAGE, 'envoi', 'critique', '/envoi/sante');
            } catch (\Throwable $e) {
                Cache::forget('envoi:sante:couplage-alerte'); // non partie : retentée au passage suivant
                Log::error('[ENVOI] alerte de couplage en échec.', ['exception' => $e::class]);
            }
        }
    }

    public static function oublier(): void
    {
        self::$journalise = false;
    }
}
