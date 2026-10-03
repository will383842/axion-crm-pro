<?php

namespace App\Http\Middleware;

use App\Support\EmpreinteIp;
use App\Support\FenetreHorodatage;
use App\Support\HmacSignature;
use App\Support\Partners\ConfigurationCanalPartners;
use App\Support\Partners\IdempotencePartners;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

/**
 * Lot N11 — authentification du futur canal Axion Partners → CRM.
 *
 * Même logique que `App\Support\CanalSigneSite` (patron du dépôt), avec les
 * en-têtes du canal Partners et une clé choisie par identifiant.
 *
 * ── Ce que Partners signe ────────────────────────────────────────────────
 *
 *     X-Partners-Signature = hex( HMAC-SHA256( secret, "<horodatage>.<Idempotency-Key>.<corps>" ) )
 *
 * La clé d'idempotence EST dans la signature : un intermédiaire qui réécrit
 * les en-têtes ne peut pas présenter une requête neuve sous la clé d'un
 * événement déjà reçu (il obtiendrait un rejeu ou un 409 à la place du
 * traitement). La clé ne contient jamais de point (`IdempotencePartners::MOTIF_CLE`),
 * le découpage « horodatage . clé . corps » est donc sans ambiguïté. C'est la
 * forme la plus simple pour l'émetteur : trois valeurs qu'il a déjà en main,
 * jointes par un point, sans toucher au corps.
 *
 * ── Ordre ────────────────────────────────────────────────────────────────
 *
 *   0. mode `off` → 404 INDISCERNABLE d'une route absente : la même exception
 *      que le routeur lève pour une route inconnue, rendue par le même
 *      gestionnaire. Le limiteur `throttle:partners` (placé AVANT ce
 *      vérificateur par le tri de priorité de Laravel 12) ne limite rien en
 *      `off` : ni en-tête `X-RateLimit-*`, ni 429. ⚠️ Le journal d'audit
 *      chaîné (`AuditHashChainLogger`, groupe `api`) écrit en revanche sa
 *      ligne générique pour ce POST (statut 404, empreinte, IP), comme pour
 *      toute requête POST d'une route déclarée ; aucune ligne d'idempotence
 *      n'est écrite ;
 *   1. `X-Partners-Timestamp` présent, entier, dans la fenêtre — AVANT tout
 *      calcul de signature ;
 *   2. `X-Partners-Kid` au format fermé, cherché dans la liste des clés
 *      entrantes ACCEPTÉES dans ce mode (la clé d'essai ne l'est qu'en
 *      `essai`) ;
 *   3. `Idempotency-Key` au format fermé (sinon la signature ne peut pas être
 *      calculée) ;
 *   4. `X-Partners-Signature` (comparaison à temps constant, `HmacSignature`) ;
 *   5. mémoire anti-rejeu partagée (Redis) : une copie exacte d'une requête
 *      déjà acceptée dans la fenêtre est refusée.
 *
 * TOUS les refus d'authentification (1 à 5) rendent le MÊME 401, au corps
 * identique à l'octet (`CORPS_REFUS`). La cause n'est écrite qu'au journal,
 * avec l'empreinte HMAC à clé de l'IP (`EmpreinteIp`), jamais l'IP en clair.
 * Seule exception : mémoire anti-rejeu indisponible → 503, jamais une
 * acceptation sans contrôle.
 */
final class VerificateurCanalPartners
{
    /** Corps unique de TOUS les refus d'authentification. */
    public const CORPS_REFUS = '{"erreur":"non_autorise"}';

    public function handle(Request $request, Closure $next): Response
    {
        $configuration = ConfigurationCanalPartners::depuisConfig();

        if (! $configuration->estOuvert()) {
            // Mot pour mot l'exception du routeur pour une route inconnue
            // (`RouteCollection::handleMatchedRoute`) : même rendu, même corps.
            throw new NotFoundHttpException(sprintf('The route %s could not be found.', $request->path()));
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

        // 3. Clé d'idempotence : elle fait partie de ce qui est signé.
        $cle = self::entete($request, IdempotencePartners::ENTETE);
        if ($cle === null || preg_match(IdempotencePartners::MOTIF_CLE, $cle) !== 1) {
            return self::refus($request, 'clé d’idempotence absente ou hors format');
        }

        // 4. Signature de « <horodatage>.<clé>.<corps> ».
        $charge = HmacSignature::signedPayload($horodatage, $cle . '.' . $corps);
        if (! HmacSignature::verify($secret, $charge, self::entete($request, 'X-Partners-Signature'))) {
            return self::refus($request, 'signature invalide');
        }

        // 5. Requête déjà vue. Empreinte sur la signature ATTENDUE ; jamais le corps.
        $empreinte = hash('sha256', 'partners|' . (string) $kid . '|' . HmacSignature::sign($secret, $charge));
        $ttl = max(1, (int) $horodatage + $fenetre - time() + 1);

        try {
            $premiereFois = Cache::store((string) config('crm.ingest.replay_store', 'redis'))
                ->add('canal-partners:vu:' . $empreinte, 1, $ttl);
        } catch (Throwable $e) {
            Log::warning('canal Partners : requête refusée (mémoire anti-rejeu indisponible)', [
                'ip_empreinte' => EmpreinteIp::de($request->ip()),
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
        // Jamais l'IP en clair : empreinte HMAC à clé (`EmpreinteIp`).
        Log::warning("canal Partners : requête refusée ({$cause})", ['ip_empreinte' => EmpreinteIp::de($request->ip())]);

        return response(self::CORPS_REFUS, 401, ['Content-Type' => 'application/json']);
    }
}
