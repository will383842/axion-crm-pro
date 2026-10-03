<?php

namespace App\Http\Middleware;

use App\Support\FenetreHorodatage;
use App\Support\HmacSignature;
use App\Support\Partners\ConfigurationCanalPartners;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Lot N11 — authentification du futur canal Axion Partners → CRM.
 *
 * Même logique que `App\Support\CanalSigneSite` (patron du dépôt), avec les
 * en-têtes du canal Partners et une clé choisie par identifiant :
 *
 *   0. mode `off` → 404 au corps VIDE : la route « n'existe pas » (rien
 *      n'est lu, rien n'est calculé, aucune ligne n'est écrite) ;
 *   1. `X-Partners-Timestamp` présent, entier, dans la fenêtre — AVANT tout
 *      calcul de signature ;
 *   2. `X-Partners-Kid` au format fermé, cherché dans la liste des clés
 *      entrantes ACCEPTÉES dans ce mode (la clé d'essai ne l'est qu'en
 *      `essai`) ;
 *   3. `X-Partners-Signature` = HMAC-SHA256 de « <horodatage>.<corps> » avec
 *      ce secret (comparaison à temps constant, `HmacSignature`) ;
 *   4. mémoire anti-rejeu partagée (Redis) : une copie exacte d'une requête
 *      déjà acceptée dans la fenêtre est refusée. L'émetteur re-signe chaque
 *      tentative avec un horodatage neuf ; ses reprises légitimes passent, et
 *      l'en-tête `Idempotency-Key` leur rend la réponse d'origine.
 *
 * TOUS les refus d'authentification (1 à 4) rendent le MÊME 401, au corps
 * identique à l'octet (`CORPS_REFUS`) : un appelant ne peut pas distinguer un
 * `kid` inconnu d'une signature fausse, d'un horodatage périmé ou d'un rejeu.
 * La cause exacte n'est écrite qu'au journal (sans corps ni secret).
 *
 * Seule exception : la mémoire anti-rejeu indisponible → 503, que l'émetteur
 * rejoue plus tard ; jamais une acceptation sans contrôle.
 */
final class VerificateurCanalPartners
{
    /** Corps unique de TOUS les refus d'authentification. */
    public const CORPS_REFUS = '{"erreur":"non_autorise"}';

    public function handle(Request $request, Closure $next): Response
    {
        $configuration = ConfigurationCanalPartners::depuisConfig();

        if (! $configuration->estOuvert()) {
            return response('', 404);
        }

        $corps = $request->getContent();
        $horodatage = self::entete($request, 'X-Partners-Timestamp');
        $fenetre = (int) config('crm.ingest.max_clock_skew_seconds', FenetreHorodatage::DEFAUT_SECONDES);

        // 1. Horodatage d'abord : sans lui, aucune signature n'est calculée.
        if ($horodatage === null || ! HmacSignature::timestampWithinWindow($horodatage, $fenetre)) {
            return self::refus($request, 'horodatage absent ou hors fenêtre');
        }

        // 2. Clé choisie par identifiant, dans la liste fermée du mode courant.
        $kid = self::entete($request, 'X-Partners-Kid');
        $secret = $configuration->secretEntrantPour($kid);
        if ($secret === null) {
            return self::refus($request, 'identifiant de clé inconnu ou non accepté dans ce mode');
        }

        // 3. Signature.
        $charge = HmacSignature::signedPayload($horodatage, $corps);
        if (! HmacSignature::verify($secret, $charge, self::entete($request, 'X-Partners-Signature'))) {
            return self::refus($request, 'signature invalide');
        }

        // 4. Requête déjà vue. Empreinte sur la signature ATTENDUE (une
        //    variante d'écriture de l'en-tête ne produit pas une empreinte
        //    neuve) ; jamais le corps.
        $empreinte = hash('sha256', 'partners|' . (string) $kid . '|' . HmacSignature::sign($secret, $charge));
        $ttl = max(1, (int) $horodatage + $fenetre - time() + 1);

        try {
            $premiereFois = Cache::store((string) config('crm.ingest.replay_store', 'redis'))
                ->add('canal-partners:vu:' . $empreinte, 1, $ttl);
        } catch (Throwable $e) {
            Log::warning('canal Partners : requête refusée (mémoire anti-rejeu indisponible)', [
                'ip' => $request->ip(),
                'exception' => $e::class,
            ]);

            return response()->json(['erreur' => 'indisponible'], 503);
        }

        if (! $premiereFois) {
            return self::refus($request, 'requête déjà reçue');
        }

        return $next($request);
    }

    private static function entete(Request $request, string $nom): ?string
    {
        $valeur = $request->header($nom);

        return is_string($valeur) && $valeur !== '' ? $valeur : null;
    }

    private static function refus(Request $request, string $cause): Response
    {
        Log::warning("canal Partners : requête refusée ({$cause})", ['ip' => $request->ip()]);

        return response(self::CORPS_REFUS, 401, ['Content-Type' => 'application/json']);
    }
}
