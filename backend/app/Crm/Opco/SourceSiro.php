<?php

namespace App\Crm\Opco;

use Illuminate\Support\Facades\Http;
use Psr\Http\Message\ResponseInterface;
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
 *  - le fichier (≈ 100 Mo) est téléchargé EN FLUX vers un fichier
 *    (`sink`) : son corps ne passe jamais par la mémoire de PHP.
 *
 * Classe non finale : les tests la remplacent par un double qui écrit un
 * petit fichier fictif au lieu d'appeler le réseau.
 */
class SourceSiro
{
    public const API_JEU = 'https://www.data.gouv.fr/api/1/datasets/table-siret-opco/';

    /** Taille maximale acceptée du fichier (annoncée par `Content-Length`). */
    public const TAILLE_MAX = 1024 * 1024 * 1024;

    private const MOIS = [
        'janvier' => 1, 'fevrier' => 2, 'février' => 2, 'mars' => 3, 'avril' => 4,
        'mai' => 5, 'juin' => 6, 'juillet' => 7, 'aout' => 8, 'août' => 8,
        'septembre' => 9, 'octobre' => 10, 'novembre' => 11, 'decembre' => 12, 'décembre' => 12,
    ];

    /**
     * La dernière ressource CSV du jeu de données.
     *
     * @return array{id: string, url: string, titre: string, releve_le: ?string}
     */
    public function ressourceCourante(): array
    {
        $reponse = Http::acceptJson()->timeout(30)->connectTimeout(10)->get(self::API_JEU);
        if (! $reponse->successful()) {
            throw new RuntimeException('API data.gouv : statut HTTP ' . $reponse->status() . ' sur le jeu table-siret-opco.');
        }
        $jeu = $reponse->json();

        return self::choisirRessource(is_array($jeu) ? $jeu : []);
    }

    /**
     * Choisit la dernière ressource CSV (la plus récente par `last_modified`,
     * puis `created_at`) parmi celles du jeu ; les ressources principales
     * (`type = main`) passent avant les annexes.
     *
     * @param  array<string, mixed>  $jeu  la réponse de l'API data.gouv
     * @return array{id: string, url: string, titre: string, releve_le: ?string}
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

        return [
            'id' => (string) $r['id'],
            'url' => (string) $r['url'],
            'titre' => $titre,
            'releve_le' => self::moisDsn($titre)
                ?? self::moisDsn((string) ($r['description'] ?? ''))
                ?? self::moisDsn(basename((string) parse_url((string) $r['url'], PHP_URL_PATH))),
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
     */
    public function telecharger(string $url, string $chemin): void
    {
        if (strtolower((string) parse_url($url, PHP_URL_SCHEME)) !== 'https') {
            throw new RuntimeException('Téléchargement refusé : la ressource SIRO doit être en https.');
        }

        $reponse = Http::timeout(1800)->connectTimeout(15)
            ->withOptions([
                'sink' => $chemin,
                'on_headers' => static function (ResponseInterface $r): void {
                    $taille = (int) $r->getHeaderLine('Content-Length');
                    if ($taille > self::TAILLE_MAX) {
                        throw new RuntimeException('Ressource SIRO trop volumineuse (' . $taille . ' octets).');
                    }
                },
            ])
            ->get($url);

        if (! $reponse->successful()) {
            throw new RuntimeException('Téléchargement de la table SIRO : statut HTTP ' . $reponse->status() . '.');
        }
        clearstatcache(true, $chemin);
        if (! is_file($chemin) || (int) filesize($chemin) === 0) {
            throw new RuntimeException('Téléchargement de la table SIRO : fichier vide.');
        }
    }
}
