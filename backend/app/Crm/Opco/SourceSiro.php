<?php

namespace App\Crm\Opco;

use App\Services\Http\SsrfGuard;
use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\UriResolver;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Psr\Http\Message\StreamInterface;
use RuntimeException;

/**
 * LA TABLE SIRET → OPCO DE FRANCE COMPÉTENCES (« table SIRO », lot O14),
 * publiée sur data.gouv.fr sous licence ouverte 2.0 :
 * https://www.data.gouv.fr/datasets/table-siret-opco
 *
 *  - la ressource est RÉSOLUE à chaque passage par l'API du jeu de données
 *    (`API_JEU`) : la DERNIÈRE ressource CSV publiée — jamais un identifiant
 *    figé dans le code ;
 *  - le mois de la DSN dont la table est issue est lu dans le titre, la
 *    description ou le nom du fichier de la ressource (« DSN de juillet
 *    2026 », « 2026-07 », « 202607 »…) ; introuvable : null, et
 *    `--releve-le=AAAA-MM` de la commande l'impose ;
 *  - le fichier (≈ 100 Mo) est téléchargé EN FLUX vers un fichier, par
 *    morceaux de 1 Mo : son corps ne passe jamais par la mémoire de PHP, et
 *    le volume RÉELLEMENT reçu est borné (`TAILLE_MAX`), annoncé ou non ;
 *  - LISTE FERMÉE D'HÔTES (`HOTES_AUTORISES`) : l'URL vient de la réponse de
 *    l'API, pas du code — tout autre hôte est refusé, y compris après une
 *    redirection. Les redirections sont suivies À LA MAIN (au plus
 *    `MAX_REDIRECTIONS`) : chaque saut est revérifié (https seul, port 443,
 *    hôte de la liste, garde SSRF) et sa connexion épinglée sur l'IP
 *    vérifiée.
 *
 * Classe non finale : les tests la remplacent par un double qui écrit un
 * petit fichier fictif au lieu d'appeler le réseau.
 */
class SourceSiro
{
    public const API_JEU = 'https://www.data.gouv.fr/api/1/datasets/table-siret-opco/';

    /**
     * Les SEULS hôtes d'où la table est téléchargée (URL initiale et chaque
     * redirection). Aucun domaine de France compétences n'a été constaté dans
     * les URL de ressource : aucun n'est ajouté.
     */
    public const HOTES_AUTORISES = ['static.data.gouv.fr', 'www.data.gouv.fr'];

    /** Redirections suivies au plus (chacune revérifiée). */
    public const MAX_REDIRECTIONS = 3;

    /** Taille maximale du fichier (≈ 100 Mo attendus), annoncée OU reçue. */
    public const TAILLE_MAX = 300 * 1024 * 1024;

    /** Taille maximale de la réponse JSON de l'API du jeu de données. */
    public const TAILLE_MAX_JSON = 5 * 1024 * 1024;

    /** Taille des morceaux recopiés du réseau vers le fichier. */
    private const MORCEAU = 1024 * 1024;

    private const MOIS = [
        'janvier' => 1, 'fevrier' => 2, 'février' => 2, 'mars' => 3, 'avril' => 4,
        'mai' => 5, 'juin' => 6, 'juillet' => 7, 'aout' => 8, 'août' => 8,
        'septembre' => 9, 'octobre' => 10, 'novembre' => 11, 'decembre' => 12, 'décembre' => 12,
    ];

    /**
     * La dernière ressource CSV du jeu de données.
     *
     * L'hôte de l'API est une constante : aucune redirection n'est suivie,
     * et la réponse est lue par morceaux, bornée à `TAILLE_MAX_JSON`.
     *
     * @return array{id: string, url: string, titre: string, releve_le: ?string, version: ?string}
     */
    public function ressourceCourante(): array
    {
        $reponse = Http::acceptJson()->timeout(30)->connectTimeout(10)
            ->withOptions(['allow_redirects' => false, 'stream' => true])
            ->get(self::API_JEU);
        if (! $reponse->successful()) {
            throw new RuntimeException('API data.gouv : statut HTTP ' . $reponse->status() . ' sur le jeu table-siret-opco.');
        }
        if ((int) $reponse->header('Content-Length') > self::TAILLE_MAX_JSON) {
            throw new RuntimeException('API data.gouv : réponse trop volumineuse.');
        }
        $corps = $reponse->toPsrResponse()->getBody();
        if ($corps->isSeekable()) {
            $corps->rewind();
        }
        $texte = '';
        try {
            while (! $corps->eof()) {
                $morceau = $corps->read(65536);
                if ($morceau === '') {
                    break;
                }
                $texte .= $morceau;
                if (strlen($texte) > self::TAILLE_MAX_JSON) {
                    throw new RuntimeException('API data.gouv : réponse trop volumineuse.');
                }
            }
        } finally {
            $corps->close();
        }
        $jeu = json_decode($texte, true);

        return self::choisirRessource(is_array($jeu) ? $jeu : []);
    }

    /**
     * Choisit la dernière ressource CSV (la plus récente par `last_modified`,
     * puis `created_at`) parmi celles du jeu ; les ressources principales
     * (`type = main`) passent avant les annexes.
     *
     * La `version` (somme de contrôle publiée, sinon `last_modified`) permet
     * de reconnaître un fichier REMPLACÉ sous le même identifiant.
     *
     * @param  array<string, mixed>  $jeu  la réponse de l'API data.gouv
     * @return array{id: string, url: string, titre: string, releve_le: ?string, version: ?string}
     */
    public static function choisirRessource(array $jeu): array
    {
        $candidates = [];
        foreach (is_array($jeu['resources'] ?? null) ? $jeu['resources'] : [] as $r) {
            if (! is_array($r) || ! is_string($r['url'] ?? null) || ! is_string($r['id'] ?? null)) {
                continue;
            }
            $format = strtolower(trim((string) ($r['format'] ?? '')));
            $chemin = strtolower((string) parse_url($r['url'], PHP_URL_PATH));
            if ($format !== 'csv' && ! str_ends_with($chemin, '.csv')) {
                continue;
            }
            if (strtolower((string) parse_url($r['url'], PHP_URL_SCHEME)) !== 'https') {
                continue;
            }
            $candidates[] = $r;
        }
        if ($candidates === []) {
            throw new RuntimeException('API data.gouv : aucune ressource CSV en https dans le jeu table-siret-opco.');
        }

        usort($candidates, static function (array $a, array $b): int {
            $principaleA = ($a['type'] ?? 'main') === 'main' ? 1 : 0;
            $principaleB = ($b['type'] ?? 'main') === 'main' ? 1 : 0;

            return [$principaleB, (string) ($b['last_modified'] ?? ''), (string) ($b['created_at'] ?? '')]
                <=> [$principaleA, (string) ($a['last_modified'] ?? ''), (string) ($a['created_at'] ?? '')];
        });
        $r = $candidates[0];
        $titre = trim((string) ($r['title'] ?? ''));
        $somme = is_array($r['checksum'] ?? null) && is_string($r['checksum']['value'] ?? null) && $r['checksum']['value'] !== ''
            ? (string) ($r['checksum']['type'] ?? 'somme') . ':' . $r['checksum']['value']
            : null;
        $modifie = is_string($r['last_modified'] ?? null) && $r['last_modified'] !== '' ? $r['last_modified'] : null;

        return [
            'id' => (string) $r['id'],
            'url' => (string) $r['url'],
            'titre' => $titre,
            'releve_le' => self::moisDsn($titre)
                ?? self::moisDsn((string) ($r['description'] ?? ''))
                ?? self::moisDsn(basename((string) parse_url((string) $r['url'], PHP_URL_PATH))),
            'version' => $somme ?? $modifie,
        ];
    }

    /**
     * Le mois de DSN (premier jour, AAAA-MM-01) lu dans un texte, ou null.
     */
    public static function moisDsn(string $texte): ?string
    {
        $texte = mb_strtolower($texte, 'UTF-8');
        $noms = implode('|', array_map(static fn (string $m): string => preg_quote($m, '/'), array_keys(self::MOIS)));
        if (preg_match('/(?<![\p{L}])(' . $noms . ')\s+(20\d{2})(?!\d)/u', $texte, $m) === 1) {
            return sprintf('%04d-%02d-01', (int) $m[2], self::MOIS[$m[1]]);
        }
        if (preg_match('/(?<!\d)(20\d{2})[-_ \/.]?(0[1-9]|1[0-2])(?!\d)/', $texte, $m) === 1) {
            return sprintf('%04d-%02d-01', (int) $m[1], (int) $m[2]);
        }
        if (preg_match('/(?<!\d)(0[1-9]|1[0-2])[-_ \/.](20\d{2})(?!\d)/', $texte, $m) === 1) {
            return sprintf('%04d-%02d-01', (int) $m[2], (int) $m[1]);
        }

        return null;
    }

    /**
     * Télécharge la ressource EN FLUX dans `$chemin` (jamais en mémoire).
     *
     * L'URL vient de l'API data.gouv, pas du code. Chaque requête (l'URL
     * initiale puis chaque redirection, suivie à la main) passe `verifierUrl`
     * — https seul, port 443, hôte de `HOTES_AUTORISES`, garde SSRF — et sa
     * connexion est épinglée sur l'IP vérifiée (pas de rebinding DNS). Le
     * volume reçu est compté pendant la copie : au-delà de `tailleMax()`, le
     * téléchargement est coupé, même sans `Content-Length`.
     */
    public function telecharger(string $url, string $chemin): void
    {
        $courante = $url;
        for ($saut = 0; ; $saut++) {
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
        }

        if (! $reponse->successful()) {
            throw new RuntimeException('Téléchargement de la table SIRO : statut HTTP ' . $reponse->status() . '.');
        }
        $annoncee = (int) $reponse->header('Content-Length');
        if ($annoncee > $this->tailleMax()) {
            throw new RuntimeException('Ressource SIRO trop volumineuse (' . $annoncee . ' octets annoncés).');
        }

        $this->recopier($reponse, $chemin);

        clearstatcache(true, $chemin);
        $taille = is_file($chemin) ? (int) filesize($chemin) : 0;
        if ($taille === 0) {
            throw new RuntimeException('Téléchargement de la table SIRO : fichier vide.');
        }
        if ($taille > $this->tailleMax()) {
            throw new RuntimeException('Ressource SIRO trop volumineuse (' . $taille . ' octets reçus).');
        }
    }

    /**
     * Vérifie une URL de téléchargement (initiale ou redirigée) et rend l'IP
     * à épingler (null : rien à épingler). Lève si elle est refusée.
     */
    public static function verifierUrl(string $url): ?string
    {
        $parties = parse_url($url);
        if (! is_array($parties) || strtolower((string) ($parties['scheme'] ?? '')) !== 'https') {
            throw new RuntimeException('Téléchargement refusé : la ressource SIRO doit être en https.');
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
     * Recopie le corps de la réponse dans `$chemin`, par morceaux, en
     * comptant les octets REÇUS : coupe au-delà de `tailleMax()`.
     */
    private function recopier(Response $reponse, string $chemin): void
    {
        $corps = $reponse->toPsrResponse()->getBody();
        if ($corps->isSeekable()) {
            $corps->rewind();
        }
        $fichier = fopen($chemin, 'wb');
        if ($fichier === false) {
            $corps->close();

            throw new RuntimeException('Fichier temporaire SIRO impossible à ouvrir en écriture.');
        }

        try {
            self::copierBorne($corps, $fichier, $this->tailleMax());
        } finally {
            fclose($fichier);
            $corps->close();
        }
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
                break; // flux bloquant : rien à lire = fin du corps
            }
            $total += strlen($morceau);
            if ($total > $max) {
                throw new RuntimeException('Ressource SIRO trop volumineuse (plus de ' . $max . ' octets reçus).');
            }
            if (fwrite($fichier, $morceau) !== strlen($morceau)) {
                throw new RuntimeException('Écriture du fichier temporaire SIRO impossible (disque plein ?).');
            }
        }

        return $total;
    }
}
