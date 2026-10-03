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
 * ── Ce que Partners signe (v2, accord du 03/10/2026, axion-apporteurs#220) ─
 *
 *     X-Partners-Signature = hex( HMAC-SHA256( secret,
 *         "<horodatage>.<MÉTHODE> <chemin>.<Idempotency-Key>.<corps brut>" ) )
 *
 *     ex. « 1759510000.POST /api/internal/partners/v1/ping.<clé>.<corps> »
 *
 * MÉTHODE en majuscules ; chemin = `Request::getPathInfo()`, sans domaine ni
 * paramètres de requête. La méthode et le chemin SONT dans la signature : un
 * corps signé pour une route ne peut pas être rejoué sur une autre route ou
 * sous une autre méthode pendant la fenêtre (même 401).
 *
 * La clé d'idempotence EST dans la signature : un intermédiaire qui réécrit
 * les en-têtes ne peut pas présenter une requête neuve sous la clé d'un
 * événement déjà reçu (il obtiendrait un rejeu ou un 409 à la place du
 * traitement). Ni la clé (`IdempotencePartners::MOTIF_CLE`), ni l'horodatage
 * (entier) ne contiennent de point ; la méthode et le chemin sont imposés par
 * la requête reçue, pas lus dans la chaîne : le découpage est sans ambiguïté.
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
 *   1. corps borné à `CORPS_MAX_OCTETS` (256 Kio) — AVANT tout calcul HMAC :
 *      d'abord `Content-Length` annoncé (rien n'est lu s'il dépasse), puis la
 *      taille réelle du corps reçu (un `Content-Length` absent ou menteur ne
 *      contourne pas la borne). ⚠️ La pile globale (`ConvertEmptyStringsToNull`
 *      sur une requête JSON) peut avoir déjà lu le corps avant ce vérificateur :
 *      la borne protège le HMAC et tout ce qui suit, pas la lecture elle-même,
 *      que borne `post_max_size` ;
 *   2. `X-Partners-Timestamp` présent, entier, dans la fenêtre — AVANT tout
 *      calcul de signature ;
 *   3. `X-Partners-Kid` au format fermé, cherché dans la liste des clés
 *      entrantes ACCEPTÉES dans ce mode (la clé d'essai ne l'est qu'en
 *      `essai`) ;
 *   4. `Idempotency-Key` au format fermé (sinon la signature ne peut pas être
 *      calculée) ;
 *   5. `X-Partners-Signature` (comparaison à temps constant, `HmacSignature`) ;
 *   6. mémoire anti-rejeu partagée (Redis) : une copie exacte d'une requête
 *      déjà acceptée dans la fenêtre est refusée.
 *
 * TOUS les refus d'authentification (1 à 6) rendent le MÊME 401, au corps
 * identique à l'octet (`CORPS_REFUS`). La cause n'est écrite qu'au journal,
 * avec l'empreinte HMAC à clé de l'IP (`EmpreinteIp`), jamais l'IP en clair.
 * Seule exception : mémoire anti-rejeu indisponible → 503, jamais une
 * acceptation sans contrôle.
 */
final class VerificateurCanalPartners
{
    /** Corps unique de TOUS les refus d'authentification. */
    public const CORPS_REFUS = '{"erreur":"non_autorise"}';

    /** Taille maximale du corps brut, en octets (256 Kio), contrôlée AVANT tout HMAC. */
    public const CORPS_MAX_OCTETS = 262144;

    public function handle(Request $request, Closure $next): Response
    {
        $configuration = ConfigurationCanalPartners::depuisConfig();

        if (! $configuration->estOuvert()) {
            // Mot pour mot l'exception du routeur pour une route inconnue
            // (`RouteCollection::handleMatchedRoute`) : même rendu, même corps.
            throw new NotFoundHttpException(sprintf('The route %s could not be found.', $request->path()));
        }

        // 1. Corps borné, AVANT tout calcul HMAC : la taille annoncée d'abord
        //    (rien n'est lu si elle dépasse), puis la taille réelle.
        $annonce = $request->headers->get('Content-Length');
        if ($annonce !== null && (! ctype_digit($annonce) || strlen($annonce) > 9 || (int) $annonce > self::CORPS_MAX_OCTETS)) {
            return self::refus($request, 'corps trop volumineux (taille annoncée)');
        }
        $corps = $request->getContent();
        if (strlen($corps) > self::CORPS_MAX_OCTETS) {
            return self::refus($request, 'corps trop volumineux');
        }

        $horodatage = self::entete($request, 'X-Partners-Timestamp');
        $fenetre = (int) config('crm.ingest.max_clock_skew_seconds', FenetreHorodatage::DEFAUT_SECONDES);

        // 2. Horodatage d'abord : sans lui, aucune signature n'est calculée.
        if ($horodatage === null || ! HmacSignature::timestampWithinWindow($horodatage, $fenetre)) {
            return self::refus($request, 'horodatage absent ou hors fenêtre');
        }

        // 3. Clé choisie par identifiant, dans la liste fermée du mode courant.
        $kid = self::entete($request, 'X-Partners-Kid');
        $secret = $configuration->secretEntrantPour($kid);
        if ($secret === null) {
            return self::refus($request, 'identifiant de clé inconnu ou non accepté dans ce mode');
        }

        // 4. Clé d'idempotence : elle fait partie de ce qui est signé.
        $cle = self::entete($request, IdempotencePartners::ENTETE);
        if ($cle === null || preg_match(IdempotencePartners::MOTIF_CLE, $cle) !== 1) {
            return self::refus($request, 'clé d’idempotence absente ou hors format');
        }

        // 5. Signature v2 de « <horodatage>.<MÉTHODE> <chemin>.<clé>.<corps> ».
        $charge = HmacSignature::signedPayload($horodatage, self::chaineSignee($request, $cle, $corps));
        if (! HmacSignature::verify($secret, $charge, self::entete($request, 'X-Partners-Signature'))) {
            return self::refus($request, 'signature invalide');
        }

        // 6. Requête déjà vue. Empreinte sur la signature ATTENDUE ; jamais le corps.
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

    /**
     * « <MÉTHODE> <chemin>.<Idempotency-Key>.<corps> » — la partie qui suit
     * « <horodatage>. ». Méthode en majuscules (`getMethod()`), chemin sans
     * domaine ni paramètres de requête (`getPathInfo()`).
     */
    private static function chaineSignee(Request $request, string $cle, string $corps): string
    {
        return strtoupper($request->getMethod()) . ' ' . $request->getPathInfo() . '.' . $cle . '.' . $corps;
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
