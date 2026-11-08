<?php

namespace App\Crm\Ingest;

use Illuminate\Support\Facades\RateLimiter;

/**
 * Contrat v3 de l'origine (ADR I3/I5) — limite de DÉBIT par (`post_ref`, `canal`)
 * à l'accueil des charges.
 *
 * Un même post viral ne peut pas inonder le CRM : au-delà de
 * `origines.limite_par_post_canal.max` charges par fenêtre, la charge reçoit
 * un 429 `origines_trop_de_charges`. C'est TEMPORAIRE : le site garde sa ligne
 * et la renvoie plus tard, rien n'est perdu, rien n'est supprimé.
 *
 * Éteint avec l'interrupteur v3 (sans `origines`, rien n'est compté). Un rejeu
 * d'une charge déjà reçue ne passe jamais ici (idempotence en amont). Les
 * clés du limiteur sont des empreintes : ni le `post_ref` ni rien de personnel
 * n'est écrit dans le cache.
 */
final class LimiteOrigines
{
    /** @throws SiteSyncRejection */
    public function controler(SiteSyncEvent $event): void
    {
        $origines = $event->contenusAccords?->origines;
        if ($origines === null || $origines === [] || ! (bool) config('origines.v3_enabled', false)) {
            return;
        }

        $max = max(1, (int) config('origines.limite_par_post_canal.max', 600));
        $fenetre = max(1, (int) config('origines.limite_par_post_canal.fenetre_secondes', 3600));

        $cles = [];
        foreach ($origines as $o) {
            if (isset($o['post_ref'], $o['canal'])) {
                $cles['origines:pc:' . sha1($o['post_ref'] . '|' . $o['canal'])] = true;
            }
        }
        $cles = array_keys($cles);

        // Tout est vérifié AVANT de compter : un refus ne consomme rien.
        foreach ($cles as $cle) {
            if (RateLimiter::tooManyAttempts($cle, $max)) {
                throw SiteSyncRejection::tropDeCharges(
                    'origines_trop_de_charges',
                    'Contrat v3 de l\'origine : trop de charges pour ce post et ce canal, la charge reste en attente côté site.',
                );
            }
        }
        foreach ($cles as $cle) {
            RateLimiter::hit($cle, $fenetre);
        }
    }
}
