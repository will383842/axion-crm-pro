<?php

namespace App\Crm\Ingest;

use DateTimeImmutable;
use DateTimeZone;
use Exception;

/**
 * « CONTENUS ET ACCORDS » (lot PV-6) — les quatre champs ajoutés au contrat de
 * synchro (version 2) : `origine_contenu`, `guides_recus`, `consentements`,
 * `opposition`. Objet immuable, validé en schéma FERMÉ.
 *
 * Jamais de pseudo de réseau ni de texte de message : ces clés n'existent pas
 * ici, donc `SiteSyncRejection` (`unknown_field`) les refuse.
 *
 * Les fusions sont des règles PURES (sans base) pour être testables seules :
 *   - listes : union, jamais de retrait (aucune suppression ne se propage) ;
 *   - origine : la plus récente gagne, un champ absent n'efface rien ;
 *   - opposition : une fois posée, elle n'est jamais levée par la synchro.
 */
final class ContenusAccords
{
    public const NATURES = ['information', 'optin', 'retrait'];

    private const MAX_LISTE = 50;

    private const JETON = '/^[a-z0-9][a-z0-9._-]{0,63}$/';

    private const LIBRE = '/^[^\s\x00-\x1f]{1,64}$/u';

    public const CLES = ['origine_contenu', 'guides_recus', 'consentements', 'opposition'];

    // ── Contrat v3 (ADR-CONTRAT-V3-ORIGINE) ─────────────────────────────────

    /** Plafond d'entrées de `personnes.origines` ; au-delà : archive, rien n'est supprimé. */
    public const PLAFOND_PAR_PERSONNE = 50;

    /** Plafond d'entrées d'une charge du site ; au-delà : refus `too_many_origines`. */
    public const PLAFOND_PAR_CHARGE = 20;

    public const CANAUX = ['commentaire', 'message_prive'];

    /** Réseaux où le message privé automatique est refusé (ADR § 8). */
    public const RESEAUX_SANS_MESSAGE_PRIVE = ['linkedin', 'tiktok'];

    /** Liste BLANCHE des clés d'une entrée `origines`. Ni pseudo, ni nom, ni texte. */
    public const CLES_ORIGINE_V3 = [
        'mot', 'reseau', 'guide', 'date', 'post_ref', 'post_url', 'canal',
        'famille', 'detail', 'compte', 'version_texte', 'utm_campaign', 'commentaire_ref',
    ];

    private const POST_REF = '/^[A-Za-z0-9_-]{8,80}$/';

    private const VERSION_TEXTE = '/^[A-Za-z0-9._-]{3,64}$/';

    private const UTM = '/^[a-z0-9_-]{2,60}$/';

    private const COMPTE = '/^[a-z0-9_]{2,40}$/';

    private const HOTE = '/^[a-z0-9]([a-z0-9.-]*[a-z0-9])?$/';

    /**
     * @param  array{mot: ?string, reseau: ?string, guide: ?string, date: ?string}|null  $origine
     * @param  list<array{guide: string, date: string}>|null  $guides
     * @param  list<array{nature: string, date: string, source: string, version: string}>|null  $consentements
     * @param  array{actif: bool, date: ?string}|null  $opposition
     * @param  list<array<string, string>>|null  $origines  contrat v3 : historique des origines de la charge
     */
    private function __construct(
        public readonly ?array $origine,
        public readonly ?array $guides,
        public readonly ?array $consentements,
        public readonly ?array $opposition,
        public readonly ?array $origines = null,
    ) {}

    /**
     * @param  array<mixed>  $raw  la charge entière (racine)
     *
     * @throws SiteSyncRejection
     */
    public static function depuisRacine(array $raw, bool $v3 = false): ?self
    {
        $origines = $v3 ? self::origines($raw['origines'] ?? null) : null;

        if (! array_key_exists('origine_contenu', $raw) && ! array_key_exists('guides_recus', $raw)
            && ! array_key_exists('consentements', $raw) && ! array_key_exists('opposition', $raw)
            && $origines === null) {
            return null;
        }

        return new self(
            self::origine($raw['origine_contenu'] ?? null, $v3),
            self::guides($raw['guides_recus'] ?? null),
            self::consentements($raw['consentements'] ?? null),
            self::opposition($raw['opposition'] ?? null),
            $origines,
        );
    }

    public function oppose(): bool
    {
        return $this->opposition !== null && $this->opposition['actif'];
    }

    // ── Fusions pures ───────────────────────────────────────────────────────

    /**
     * Union sans retrait, dédoublonnée sur la valeur entière, triée par date
     * puis valeur : le résultat ne dépend PAS de l'ordre d'arrivée.
     *
     * @param  array<mixed>  $actuels
     * @param  array<mixed>|null  $entrants
     * @return list<array<string, mixed>>
     */
    public static function fusionnerListes(array $actuels, ?array $entrants): array
    {
        $tous = [];
        foreach (array_merge($actuels, $entrants ?? []) as $ligne) {
            if (! is_array($ligne)) {
                continue;
            }
            $cle = $ligne;
            ksort($cle);
            $tous[json_encode($cle, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)] = $ligne;
        }
        ksort($tous);
        $liste = array_values($tous);
        usort($liste, static fn (array $a, array $b): int => [$a['date'] ?? '', json_encode($a)] <=> [$b['date'] ?? '', json_encode($b)]);

        return $liste;
    }

    /**
     * Historique des origines (contrat v3) : UNION SANS RETRAIT, idempotente,
     * dédoublonnée, triée par date. Au-delà de `$plafond`, les plus anciennes
     * sortent de `garde` et vont dans `archive` : c'est un DÉPLACEMENT, jamais
     * une suppression (l'appelant écrit l'archive avant de réduire le tableau,
     * dans une seule transaction).
     *
     * Dédoublonnage : (`post_ref`, `canal`, `date` à la seconde) ; sans
     * `post_ref` (origines v1/v2) : (`mot`, `reseau`, `guide`, `date`), `null`
     * traité comme une valeur. Doublon : l'entrée déjà connue gagne.
     *
     * @param  array<mixed>  $actuelles
     * @param  array<mixed>|null  $entrantes
     * @return array{garde: list<array<string, mixed>>, archive: list<array<string, mixed>>}
     */
    public static function fusionnerOrigines(array $actuelles, ?array $entrantes, int $plafond = self::PLAFOND_PAR_PERSONNE): array
    {
        $parCle = [];
        foreach (array_merge($actuelles, $entrantes ?? []) as $ligne) {
            if (! is_array($ligne)) {
                continue;
            }
            $cle = self::cleOrigine($ligne);
            if (! array_key_exists($cle, $parCle)) {
                $parCle[$cle] = $ligne;
            }
        }
        uksort($parCle, static function (string $a, string $b) use ($parCle): int {
            return [(string) ($parCle[$a]['date'] ?? ''), $a] <=> [(string) ($parCle[$b]['date'] ?? ''), $b];
        });
        $liste = array_values($parCle);
        $trop = max(0, count($liste) - max(1, $plafond));

        return ['garde' => array_slice($liste, $trop), 'archive' => array_slice($liste, 0, $trop)];
    }

    /** Clé de dédoublonnage d'une entrée (publique : la table d'archive la stocke). */
    public static function cleOrigine(array $o): string
    {
        $postRef = $o['post_ref'] ?? null;

        return json_encode(
            $postRef !== null
                ? [$postRef, $o['canal'] ?? null, $o['date'] ?? null]
                : [$o['mot'] ?? null, $o['reseau'] ?? null, $o['guide'] ?? null, $o['date'] ?? null],
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        );
    }

    /**
     * Lecture (API) d'une entrée : liste BLANCHE de clés, relue depuis la base.
     * `commentaire_ref` (empreinte) ne sort jamais.
     *
     * @return array<string, ?string>
     */
    public static function presenterOrigine(array $o): array
    {
        $sortie = [];
        foreach (['famille', 'detail', 'canal', 'reseau', 'compte', 'mot', 'guide', 'date', 'post_ref', 'post_url', 'version_texte', 'utm_campaign'] as $cle) {
            $v = $o[$cle] ?? null;
            $sortie[$cle] = is_string($v) ? mb_substr($v, 0, 300) : null;
        }

        return $sortie;
    }

    /**
     * L'origine la plus récente gagne (à la seconde) ; absente, elle n'efface rien.
     *
     * @param  array<string, mixed>|null  $actuelle
     * @param  array<string, mixed>|null  $entrante
     * @return array<string, mixed>|null
     */
    public static function fusionnerOrigine(?array $actuelle, ?DateTimeImmutable $actuelleAt, ?array $entrante, DateTimeImmutable $entranteAt): ?array
    {
        if ($entrante === null) {
            return $actuelle;
        }
        if ($actuelle === null || $actuelleAt === null || (int) $entranteAt->format('U') > (int) $actuelleAt->format('U')) {
            return $entrante;
        }

        return $actuelle;
    }

    // ── Lecture (fiche) ─────────────────────────────────────────────────────

    /**
     * Le bloc « Contenus et accords » de la fiche : une liste BLANCHE de clés,
     * relue depuis la base. Même si une ligne ancienne portait autre chose, rien
     * d'autre ne sort (jamais de pseudo ni de texte de message).
     *
     * @return array<string, mixed>
     */
    public static function bloc(mixed $origine, mixed $guides, mixed $consentements, mixed $oppositionAt): array
    {
        $decode = static function (mixed $v): array {
            if (is_string($v)) {
                $v = json_decode($v, true);
            }

            return is_array($v) ? $v : [];
        };
        $garder = static function (array $ligne, array $cles): array {
            $sortie = [];
            foreach ($cles as $cle) {
                $valeur = $ligne[$cle] ?? null;
                $sortie[$cle] = is_string($valeur) ? mb_substr($valeur, 0, 64) : null;
            }

            return $sortie;
        };
        $listes = static function (array $lignes, array $cles) use ($garder): array {
            $sortie = [];
            foreach ($lignes as $ligne) {
                if (is_array($ligne)) {
                    $sortie[] = $garder($ligne, $cles);
                }
            }

            return $sortie;
        };

        $o = $decode($origine);

        return [
            'origine' => $o === [] ? null : $garder($o, ['mot', 'reseau', 'guide', 'date']),
            'guides' => $listes($decode($guides), ['guide', 'date']),
            'consentements' => $listes($decode($consentements), ['nature', 'date', 'source', 'version']),
            'opposition' => [
                'actif' => $oppositionAt !== null,
                'date' => is_string($oppositionAt) ? $oppositionAt : ($oppositionAt instanceof \DateTimeInterface ? $oppositionAt->format('c') : null),
            ],
        ];
    }

    // ── Validation ──────────────────────────────────────────────────────────

    /** @return array{mot: ?string, reseau: ?string, guide: ?string, date: ?string}|null */
    private static function origine(mixed $v, bool $v3 = false): ?array
    {
        if ($v === null) {
            return null;
        }
        $o = self::objet($v, 'origine_contenu', ['mot', 'reseau', 'guide', 'date']);
        if ($v3) {
            $o['mot'] = self::enMinuscules($o['mot'] ?? null);
        }

        return [
            'mot' => self::jeton($o['mot'] ?? null, 'origine_contenu.mot'),
            'reseau' => self::jeton($o['reseau'] ?? null, 'origine_contenu.reseau'),
            'guide' => self::jeton($o['guide'] ?? null, 'origine_contenu.guide'),
            'date' => self::dateOuNull($o['date'] ?? null, 'origine_contenu.date'),
        ];
    }

    /**
     * Contrat v3 : liste d'entrées FERMÉES (clés blanches, famille/détail du
     * dictionnaire, canal fermé, jetons à forme stricte). Toute clé inconnue
     * (pseudo, nom, texte…) est refusée `unknown_field`.
     *
     * @return list<array<string, string>>|null
     */
    private static function origines(mixed $v): ?array
    {
        if ($v === null) {
            return null;
        }
        if (! is_array($v) || ! array_is_list($v)) {
            throw SiteSyncRejection::invalid('invalid_type', '« origines » doit être une liste.');
        }
        if (count($v) > self::PLAFOND_PAR_CHARGE) {
            throw SiteSyncRejection::invalid('too_many_origines', '« origines » : ' . self::PLAFOND_PAR_CHARGE . ' entrées au plus par charge.');
        }

        $sortie = [];
        foreach ($v as $ligne) {
            $o = self::objet($ligne, 'origines', self::CLES_ORIGINE_V3);
            $e = [
                'mot' => self::jeton(self::enMinuscules($o['mot'] ?? null), 'origines.mot'),
                'reseau' => self::jeton($o['reseau'] ?? null, 'origines.reseau'),
                'guide' => self::jeton($o['guide'] ?? null, 'origines.guide'),
                'date' => self::dateRequise($o['date'] ?? null, 'origines.date'),
                'post_ref' => self::motif($o['post_ref'] ?? null, self::POST_REF, 'origines.post_ref'),
                'post_url' => self::urlPublique($o['post_url'] ?? null),
                'canal' => self::canal($o['canal'] ?? null),
                'compte' => self::motif($o['compte'] ?? null, self::COMPTE, 'origines.compte'),
                'version_texte' => self::motif($o['version_texte'] ?? null, self::VERSION_TEXTE, 'origines.version_texte'),
                'utm_campaign' => self::motif($o['utm_campaign'] ?? null, self::UTM, 'origines.utm_campaign'),
            ];
            [$e['famille'], $e['detail']] = self::familleDetail($o['famille'] ?? null, $o['detail'] ?? null);

            if ($e['canal'] === 'message_prive' && in_array($e['reseau'], self::RESEAUX_SANS_MESSAGE_PRIVE, true)) {
                throw SiteSyncRejection::invalid('invalid_canal', '« origines.canal » : message privé refusé pour ce réseau.');
            }

            // I6 : jamais le jeton brut du commentaire, seulement son empreinte (clé serveur).
            $ref = self::motif($o['commentaire_ref'] ?? null, self::POST_REF, 'origines.commentaire_ref');
            $e['commentaire_ref'] = $ref === null ? null : hash_hmac('sha256', $ref, self::cleEmpreinte());

            $sortie[] = array_filter($e, static fn (mixed $x): bool => $x !== null);
        }

        return $sortie;
    }

    /** Le mot-clé du site arrive en capitales (« GUIDE ») : forme unique en base. */
    private static function enMinuscules(mixed $v): mixed
    {
        return is_string($v) ? mb_strtolower($v) : $v;
    }

    private static function cleEmpreinte(): string
    {
        $cle = (string) config('origines.cle_hmac', '');

        return $cle !== '' ? $cle : (string) config('app.key', '');
    }

    private static function motif(mixed $v, string $regex, string $ou): ?string
    {
        if ($v === null) {
            return null;
        }
        if (! is_string($v) || preg_match($regex, $v) !== 1) {
            throw SiteSyncRejection::invalid('invalid_token', "« {$ou} » a une forme invalide.");
        }

        return $v;
    }

    private static function canal(mixed $v): ?string
    {
        if ($v === null) {
            return null;
        }
        if (! is_string($v) || ! in_array($v, self::CANAUX, true)) {
            throw SiteSyncRejection::invalid('invalid_canal', '« origines.canal » doit être commentaire ou message_prive.');
        }

        return $v;
    }

    /** @return array{0: ?string, 1: ?string} */
    private static function familleDetail(mixed $famille, mixed $detail): array
    {
        if ($famille === null && $detail === null) {
            return [null, null];
        }
        if (! is_string($famille) || ! is_string($detail) || ! OrigineDictionnaire::valide($famille, $detail)) {
            throw SiteSyncRejection::invalid('invalid_origine_dictionnaire', '« origines.famille » et « origines.detail » doivent former une paire du dictionnaire.');
        }

        return [$famille, $detail];
    }

    /** https public, sans port, identifiant, paramètre ni fragment ; ≤ 300 signes. La liste d'hôtes est tenue côté site. */
    private static function urlPublique(mixed $v): ?string
    {
        if ($v === null) {
            return null;
        }
        $p = is_string($v) && strlen($v) <= 300 ? parse_url($v) : false;
        if ($p === false || ($p['scheme'] ?? '') !== 'https' || ! isset($p['host'])
            || isset($p['port']) || isset($p['user']) || isset($p['pass']) || isset($p['query']) || isset($p['fragment'])
            || preg_match(self::HOTE, $p['host']) !== 1 || str_contains($p['host'], 'xn--')
            || preg_match('/[\x00-\x20\x7f]/', $v) === 1) {
            throw SiteSyncRejection::invalid('invalid_url', '« origines.post_url » doit être une URL https publique simple.');
        }

        return $v;
    }

    /** @return list<array{guide: string, date: string}>|null */
    private static function guides(mixed $v): ?array
    {
        if ($v === null) {
            return null;
        }
        $sortie = [];
        foreach (self::liste($v, 'guides_recus') as $ligne) {
            $o = self::objet($ligne, 'guides_recus', ['guide', 'date']);
            $sortie[] = [
                'guide' => self::jetonRequis($o['guide'] ?? null, 'guides_recus.guide'),
                'date' => self::dateRequise($o['date'] ?? null, 'guides_recus.date'),
            ];
        }

        return $sortie;
    }

    /** @return list<array{nature: string, date: string, source: string, version: string}>|null */
    private static function consentements(mixed $v): ?array
    {
        if ($v === null) {
            return null;
        }
        $sortie = [];
        foreach (self::liste($v, 'consentements') as $ligne) {
            $o = self::objet($ligne, 'consentements', ['nature', 'date', 'source', 'version']);
            $nature = $o['nature'] ?? null;
            if (! is_string($nature) || ! in_array($nature, self::NATURES, true)) {
                throw SiteSyncRejection::invalid('invalid_consentement', 'consentements.nature inconnue (information, optin ou retrait).');
            }
            $sortie[] = [
                'nature' => $nature,
                'date' => self::dateRequise($o['date'] ?? null, 'consentements.date'),
                'source' => self::libreRequis($o['source'] ?? null, 'consentements.source'),
                'version' => self::libreRequis($o['version'] ?? null, 'consentements.version'),
            ];
        }

        return $sortie;
    }

    /** @return array{actif: bool, date: ?string}|null */
    private static function opposition(mixed $v): ?array
    {
        if ($v === null) {
            return null;
        }
        $o = self::objet($v, 'opposition', ['actif', 'date']);
        if (! array_key_exists('actif', $o) || ! is_bool($o['actif'])) {
            throw SiteSyncRejection::invalid('invalid_opposition', 'opposition.actif est obligatoire et doit être un booléen.');
        }

        return ['actif' => $o['actif'], 'date' => self::dateOuNull($o['date'] ?? null, 'opposition.date')];
    }

    /**
     * @param  list<string>  $cles
     * @return array<string, mixed>
     */
    private static function objet(mixed $v, string $ou, array $cles): array
    {
        if (! is_array($v) || ($v !== [] && array_is_list($v))) {
            throw SiteSyncRejection::invalid('invalid_type', "« {$ou} » doit être un objet.");
        }
        $inconnues = array_diff(array_map('strval', array_keys($v)), $cles);
        if ($inconnues !== []) {
            throw SiteSyncRejection::invalid(
                'unknown_field',
                'Champ(s) inconnu(s) dans « ' . $ou . ' » : ' . implode(', ', $inconnues) . '.',
                ['unknown' => array_values($inconnues)],
            );
        }

        return $v;
    }

    /** @return list<mixed> */
    private static function liste(mixed $v, string $ou): array
    {
        if (! is_array($v) || ! array_is_list($v) || count($v) > self::MAX_LISTE) {
            throw SiteSyncRejection::invalid('invalid_type', "« {$ou} » doit être une liste de " . self::MAX_LISTE . ' éléments au plus.');
        }

        return $v;
    }

    private static function jeton(mixed $v, string $ou): ?string
    {
        return $v === null ? null : self::jetonRequis($v, $ou);
    }

    private static function jetonRequis(mixed $v, string $ou): string
    {
        if (! is_string($v) || preg_match(self::JETON, $v) !== 1) {
            throw SiteSyncRejection::invalid('invalid_token', "« {$ou} » doit être un mot en minuscules (lettres, chiffres, . _ -), 64 signes au plus.");
        }

        return $v;
    }

    private static function libreRequis(mixed $v, string $ou): string
    {
        if (! is_string($v) || preg_match(self::LIBRE, $v) !== 1) {
            throw SiteSyncRejection::invalid('invalid_token', "« {$ou} » doit tenir en 64 signes, sans espace ni saut de ligne.");
        }

        return $v;
    }

    private static function dateOuNull(mixed $v, string $ou): ?string
    {
        return $v === null ? null : self::dateRequise($v, $ou);
    }

    /** Normalisée en ISO 8601 UTC : l'instant ne bouge pas, la forme est unique. */
    private static function dateRequise(mixed $v, string $ou): string
    {
        if (! is_string($v) || trim($v) === '') {
            throw SiteSyncRejection::invalid('invalid_date', "« {$ou} » doit être une date ISO 8601.");
        }
        try {
            $d = new DateTimeImmutable($v);
        } catch (Exception) {
            throw SiteSyncRejection::invalid('invalid_date', "« {$ou} » n'est pas une date ISO 8601 valide.");
        }

        return $d->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
    }
}
