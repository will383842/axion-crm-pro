<?php

namespace App\Support;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Contrôle d'authentification COMMUN aux routes internes signées par le site
 * (`/internal/site-sync`, `/internal/site-sync/gdpr`).
 *
 * Les deux contrôleurs portaient chacun leur copie des mêmes lignes ; elles
 * sont réunies ici pour qu'aucune route signée par le site ne puisse en
 * oublier une étape.
 *
 * ORDRE DES CONTRÔLES :
 *   1. horodatage `X-Site-Timestamp` présent, entier, dans la fenêtre — AVANT
 *      tout calcul de signature (absent ou hors fenêtre → 401 `stale_signature`) ;
 *   2. signature `X-Site-Signature` sur « <horodatage>.<corps> »
 *      (→ 401 `bad_signature`) ;
 *   3. mémoire des requêtes déjà vues, UNIQUEMENT pour les routes sans
 *      identifiant d'idempotence (`$memoireRequetes = true`) : une requête
 *      signée identique (même horodatage, même corps) déjà acceptée dans la
 *      fenêtre est refusée (→ 401 `stale_signature`). L'émetteur re-signe
 *      chaque tentative avec un horodatage neuf : seule une copie exacte est
 *      concernée.
 *
 *      `/internal/site-sync` n'en a pas besoin et ne doit PAS l'avoir : chaque
 *      événement porte un `event_id`, l'ingestion est idempotente, et le site
 *      peut légitimement émettre deux fois le même message dans la même
 *      seconde (job d'émission et balayage de la file) ; le second doit
 *      continuer à recevoir sa réponse 200 `noop_idempotent`.
 *
 * Chaque refus incrémente aussi `CompteurRefusCanal` (un entier par motif,
 * sans donnée personnelle), lu par la surveillance externe des canaux.
 *
 * La mémoire ne contient qu'une empreinte sha256 — jamais le corps, jamais une
 * donnée personnelle — et expire avec la fenêtre. Si le magasin est
 * indisponible, la requête est refusée (503, que l'émetteur rejoue plus tard),
 * jamais acceptée sans contrôle.
 */
final class CanalSigneSite
{
    /**
     * Les canaux qui passent par ce contrôle (cf. `SiteSyncController`,
     * `SiteGdprController`). Lu par `crm:canaux:etat` pour agréger les refus.
     */
    public const CANAUX = ['site-sync', 'site-sync/gdpr'];

    /**
     * @return JsonResponse|null null si la requête est authentifiée, sinon la réponse de refus
     */
    public static function controler(Request $request, string $canal, bool $memoireRequetes): ?JsonResponse
    {
        $body = $request->getContent();
        $timestamp = $request->header('X-Site-Timestamp');
        $timestamp = is_string($timestamp) ? $timestamp : null;
        $secret = (string) config('crm.ingest.hmac_secret', '');
        $fenetre = (int) config('crm.ingest.max_clock_skew_seconds', FenetreHorodatage::DEFAUT_SECONDES);

        // 1. Horodatage d'abord : sans lui, aucune signature n'est calculée.
        if ($timestamp === null || ! HmacSignature::timestampWithinWindow($timestamp, $fenetre)) {
            Log::warning("{$canal} rejeté (horodatage absent ou hors fenêtre)", ['ip' => $request->ip()]);
            CompteurRefusCanal::incrementer($canal, 'stale_signature');

            return response()->json(['error' => 'stale_signature'], 401);
        }

        // 2. Signature.
        $signedPayload = HmacSignature::signedPayload($timestamp, $body);

        if (! HmacSignature::verify($secret, $signedPayload, $request->header('X-Site-Signature'))) {
            Log::warning("{$canal} rejeté (signature invalide)", ['ip' => $request->ip()]);
            CompteurRefusCanal::incrementer($canal, 'bad_signature');

            return response()->json(['error' => 'bad_signature'], 401);
        }

        if (! $memoireRequetes) {
            return null;
        }

        // 3. Requête déjà vue. L'empreinte est calculée sur la signature
        //    ATTENDUE (et non sur l'en-tête reçu) : une variante d'écriture de
        //    l'en-tête (préfixe `sha256=`) ne produit pas une nouvelle empreinte.
        $empreinte = hash('sha256', $canal . '|' . HmacSignature::sign($secret, $signedPayload));
        // Durée de vie : ce qu'il reste de validité à cet horodatage (au plus
        // deux fenêtres pour un horodatage en avance), plus une seconde.
        $ttl = max(1, (int) $timestamp + $fenetre - time() + 1);

        try {
            $premiereFois = Cache::store((string) config('crm.ingest.replay_store', 'redis'))
                ->add('canal-signe:vu:' . $empreinte, 1, $ttl);
        } catch (Throwable $e) {
            Log::warning("{$canal} rejeté (mémoire anti-rejeu indisponible)", [
                'ip' => $request->ip(),
                'exception' => $e::class,
            ]);
            CompteurRefusCanal::incrementer($canal, 'replay_guard_unavailable');

            return response()->json(['error' => 'replay_guard_unavailable'], 503);
        }

        if (! $premiereFois) {
            Log::warning("{$canal} rejeté (requête déjà reçue)", ['ip' => $request->ip()]);
            CompteurRefusCanal::incrementer($canal, 'stale_signature');

            return response()->json(['error' => 'stale_signature'], 401);
        }

        return null;
    }
}
