<?php

namespace App\Crm\Annuaire;

use App\Services\Http\SsrfGuard;
use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\UriResolver;
use Illuminate\Support\Facades\Http;
use Psr\Http\Message\StreamInterface;
use RuntimeException;

/**
 * L'ANNUAIRE OFFICIEL DE L'ADMINISTRATION (Service-public / DILA, licence
 * ouverte) — l'export du jeu `api-lannuaire-administration` de l'API ouverte
 * de l'annuaire, au format JSONL (un organisme par ligne).
 *
 *  - SEULES les colonnes utiles sont demandées (`select`) : identifiant, nom,
 *    SIRET, SIREN, pivot (type de service local et codes INSEE de commune),
 *    code INSEE de commune, type d'organisme, adresse courriel, téléphone,
 *    site internet. Le fichier reste petit ;
 *  - le fichier est téléchargé EN FLUX vers un fichier, par morceaux de
 *    1 Mo : son corps ne passe jamais par la mémoire de PHP, et le volume
 *    RÉELLEMENT reçu est borné (`tailleMax()`), annoncé ou non ;
 *  - LISTE FERMÉE D'HÔTES (`HOTES_AUTORISES`), revérifiée à CHAQUE
 *    redirection (suivies à la main, au plus `MAX_REDIRECTIONS`) : https
 *    seul, port 443, pas d'identifiants, hôte de la liste, garde SSRF
 *    (`SsrfGuard::verifier`), connexion épinglée sur l'IP vérifiée.
 *
 * L'API a quitté `service-public.fr` pour `service-public.gouv.fr` le
 * 01/10/2025 (l'ancien nom n'était garanti que jusqu'au 30/04/2026) : les
 * DEUX noms de l'API officielle sont dans la liste, l'URL par défaut est le
 * nouveau. `www.data.gouv.fr` et `static.data.gouv.fr` servent le même jeu
 * (« Annuaire de l'administration — Base de données locales »), pour
 * `--url`.
 *
 * Classe non finale : les tests la remplacent par un double qui écrit un
 * petit fichier fictif au lieu d'appeler le réseau.
 */
class SourceAnnuaire
{
    /** Les colonnes lues (et seules demandées à l'export). */
    public const COLONNES = [
        'id', 'nom', 'siret', 'siren', 'pivot', 'code_insee_commune',
        'type_organisme', 'adresse_courriel', 'telephone', 'site_internet',
    ];

    public const URL_EXPORT = 'https://api-lannuaire.service-public.gouv.fr/api/explore/v2.1/catalog/datasets/api-lannuaire-administration/exports/jsonl';

    /** Les SEULS hôtes d'où l'annuaire est téléchargé (URL initiale et chaque redirection). */
    public const HOTES_AUTORISES = [
        'api-lannuaire.service-public.gouv.fr',
        'api-lannuaire.service-public.fr',
        'www.data.gouv.fr',
        'static.data.gouv.fr',
    ];

    public const MAX_REDIRECTIONS = 3;

    /** Taille maximale du fichier, annoncée OU reçue (quelques dizaines de Mo attendus). */
    public const TAILLE_MAX = 400 * 1024 * 1024;

    private const MORCEAU = 1024 * 1024;

    /** L'URL d'export par défaut, colonnes utiles seulement. */
    public static function urlParDefaut(): string
    {
        return self::URL_EXPORT . '?' . http_build_query(['select' => implode(',', self::COLONNES)], '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * Télécharge `$url` EN FLUX dans `$chemin` (jamais en mémoire). Chaque
     * requête (l'URL initiale puis chaque redirection) passe `verifierUrl`.
     */
    public function telecharger(string $url, string $chemin): void
    {
        $courante = $url;
        $saut = 0;
        while (true) {
            $ip = self::verifierUrl($courante);
            $reponse = Http::timeout(1800)->connectTimeout(15)
                ->withOptions(['allow_redirects' => false, 'stream' => true] + SsrfGuard::optionsEpinglage($courante, $ip))
                ->get($courante);

            if (! $reponse->redirect()) {
                break;
            }
            $reponse->toPsrResponse()->getBody()->close();
            if ($saut >= self::MAX_REDIRECTIONS) {
                throw new RuntimeException('Téléchargement refusé : plus de ' . self::MAX_REDIRECTIONS . ' redirections.');
            }
            $cible = trim($reponse->header('Location'));
            if ($cible === '') {
                throw new RuntimeException('Téléchargement refusé : redirection sans destination.');
            }
            $courante = (string) UriResolver::resolve(new Uri($courante), new Uri($cible));
            $saut++;
        }

        if (! $reponse->successful()) {
            throw new RuntimeException('Téléchargement de l\'annuaire : statut HTTP ' . $reponse->status() . '.');
        }
        $annoncee = (int) $reponse->header('Content-Length');
        if ($annoncee > $this->tailleMax()) {
            throw new RuntimeException('Annuaire trop volumineux (' . $annoncee . ' octets annoncés).');
        }

        $corps = $reponse->toPsrResponse()->getBody();
        if ($corps->isSeekable()) {
            $corps->rewind();
        }
        $fichier = fopen($chemin, 'wb');
        if ($fichier === false) {
            $corps->close();

            throw new RuntimeException('Fichier temporaire de l\'annuaire impossible à ouvrir en écriture.');
        }
        try {
            self::copierBorne($corps, $fichier, $this->tailleMax());
        } finally {
            fclose($fichier);
            $corps->close();
        }

        clearstatcache(true, $chemin);
        if (! is_file($chemin) || (int) filesize($chemin) === 0) {
            throw new RuntimeException('Téléchargement de l\'annuaire : fichier vide.');
        }
    }

    /**
     * Vérifie une URL (initiale ou redirigée) et rend l'IP à épingler (null :
     * rien à épingler). Lève si elle est refusée — AVANT toute requête.
     */
    public static function verifierUrl(string $url): ?string
    {
        $parties = parse_url($url);
        if (! is_array($parties) || strtolower((string) ($parties['scheme'] ?? '')) !== 'https') {
            throw new RuntimeException('Téléchargement refusé : l\'annuaire doit être lu en https.');
        }
        if (isset($parties['user']) || isset($parties['pass'])) {
            throw new RuntimeException('Téléchargement refusé : identifiants dans l\'URL.');
        }
        if (isset($parties['port']) && (int) $parties['port'] !== 443) {
            throw new RuntimeException('Téléchargement refusé : seul le port 443 est permis.');
        }
        $hote = strtolower(rtrim((string) ($parties['host'] ?? ''), '.'));
        if (! in_array($hote, self::HOTES_AUTORISES, true)) {
            throw new RuntimeException('Téléchargement refusé : hôte hors de la liste autorisée (' . mb_substr($hote, 0, 60) . ').');
        }

        $verification = SsrfGuard::verifier($url, [443]);
        if (! $verification['ok']) {
            throw new RuntimeException('Téléchargement refusé par la garde SSRF : ' . $verification['reason'] . '.');
        }

        return $verification['ip'];
    }

    /** La taille maximale du fichier (méthode : un test peut la réduire). */
    protected function tailleMax(): int
    {
        return self::TAILLE_MAX;
    }

    /**
     * Copie un flux vers un fichier ouvert, par morceaux de 1 Mo, et lève dès
     * que le volume copié dépasse `$max` octets.
     *
     * @param  resource  $fichier
     */
    public static function copierBorne(StreamInterface $corps, $fichier, int $max): int
    {
        $total = 0;
        while (! $corps->eof()) {
            $morceau = $corps->read(self::MORCEAU);
            if ($morceau === '') {
                break;
            }
            $total += strlen($morceau);
            if ($total > $max) {
                throw new RuntimeException('Annuaire trop volumineux (plus de ' . $max . ' octets reçus).');
            }
            if (fwrite($fichier, $morceau) !== strlen($morceau)) {
                throw new RuntimeException('Écriture du fichier temporaire de l\'annuaire impossible (disque plein ?).');
            }
        }

        return $total;
    }
}
