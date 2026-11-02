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

    /**
     * @param  array{mot: ?string, reseau: ?string, guide: ?string, date: ?string}|null  $origine
     * @param  list<array{guide: string, date: string}>|null  $guides
     * @param  list<array{nature: string, date: string, source: string, version: string}>|null  $consentements
     * @param  array{actif: bool, date: ?string}|null  $opposition
     */
    private function __construct(
        public readonly ?array $origine,
        public readonly ?array $guides,
        public readonly ?array $consentements,
        public readonly ?array $opposition,
    ) {}

    /**
     * @param  array<mixed>  $raw  la charge entière (racine)
     *
     * @throws SiteSyncRejection
     */
    public static function depuisRacine(array $raw): ?self
    {
        if (! array_key_exists('origine_contenu', $raw) && ! array_key_exists('guides_recus', $raw)
            && ! array_key_exists('consentements', $raw) && ! array_key_exists('opposition', $raw)) {
            return null;
        }

        return new self(
            self::origine($raw['origine_contenu'] ?? null),
            self::guides($raw['guides_recus'] ?? null),
            self::consentements($raw['consentements'] ?? null),
            self::opposition($raw['opposition'] ?? null),
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
     * @param  list<array<string, string>>  $actuels
     * @param  list<array<string, string>>|null  $entrants
     * @return list<array<string, string>>
     */
    public static function fusionnerListes(array $actuels, ?array $entrants): array
    {
        $tous = [];
        foreach (array_merge($actuels, $entrants ?? []) as $ligne) {
            ksort($ligne);
            $tous[json_encode($ligne, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)] = $ligne;
        }
        ksort($tous);
        $liste = array_values($tous);
        usort($liste, static fn (array $a, array $b): int => [$a['date'] ?? '', json_encode($a)] <=> [$b['date'] ?? '', json_encode($b)]);

        return $liste;
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
     * @return array{origine: array<string, ?string>|null, guides: list<array<string, string>>, consentements: list<array<string, string>>, opposition: array{actif: bool, date: ?string}}
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
    private static function origine(mixed $v): ?array
    {
        if ($v === null) {
            return null;
        }
        $o = self::objet($v, 'origine_contenu', ['mot', 'reseau', 'guide', 'date']);

        return [
            'mot' => self::jeton($o['mot'] ?? null, 'origine_contenu.mot'),
            'reseau' => self::jeton($o['reseau'] ?? null, 'origine_contenu.reseau'),
            'guide' => self::jeton($o['guide'] ?? null, 'origine_contenu.guide'),
            'date' => self::dateOuNull($o['date'] ?? null, 'origine_contenu.date'),
        ];
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
