<?php

namespace App\Crm\Annuaire;

/**
 * UN ORGANISME DE L'ANNUAIRE OFFICIEL, lu d'une ligne de l'export JSONL.
 *
 * Le format de l'API (`api-lannuaire-administration`) range plusieurs
 * colonnes en TEXTE JSON : `pivot` (`[{"type_service_local": "mairie",
 * "code_insee_commune": ["01001"]}]`), `telephone` (`[{"valeur": "…",
 * "description": "…"}]`), `site_internet` (`[{"libelle": "…", "valeur":
 * "https://…"}]`). Chacune est lue sous ses TROIS formes possibles — texte
 * JSON, tableau déjà décodé, texte simple — et toute valeur au format
 * inattendu est ignorée (jamais « réparée » par une devinette).
 *
 * L'organisme ne garde que ce qui sert au rapprochement certain et les
 * coordonnées GÉNÉRIQUES de l'organisme : la PREMIÈRE adresse courriel,
 * le PREMIER téléphone et le PREMIER site valides, dans l'ordre publié.
 */
final class OrganismeAnnuaire
{
    /** Le code pivot des mairies (type de service local). */
    public const PIVOT_MAIRIE = 'mairie';

    private const EMAIL_MAX = 254;

    private const TELEPHONE_MAX = 40;

    private const SITE_MAX = 500;

    private const IDENTIFIANT_MAX = 200;

    /**
     * @param  ?string  $codeMairie  le code INSEE de commune d'une MAIRIE, s'il est UNIQUE dans la ligne
     */
    public function __construct(
        public readonly ?string $identifiant,
        public readonly ?string $siret,
        public readonly ?string $siren,
        public readonly bool $estMairie,
        public readonly ?string $codeMairie,
        public readonly ?string $email,
        public readonly ?string $telephone,
        public readonly ?string $site,
    ) {}

    /** Une ligne JSONL, ou null si elle n'est pas un objet JSON. */
    public static function depuisLigne(string $ligne): ?self
    {
        try {
            $donnees = json_decode($ligne, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }
        if (! is_array($donnees) || array_is_list($donnees)) {
            return null;
        }

        return self::depuis($donnees);
    }

    /** @param  array<string, mixed>  $d */
    public static function depuis(array $d): self
    {
        $siret = self::chiffres($d['siret'] ?? null, 14);
        $siren = self::chiffres($d['siren'] ?? null, 9);
        // Un SIREN contradictoire avec le SIRET : la ligne n'est pas sûre,
        // aucun des deux n'est retenu.
        if ($siret !== null && $siren !== null && ! str_starts_with($siret, $siren)) {
            $siret = null;
            $siren = null;
        }
        $siren ??= $siret === null ? null : substr($siret, 0, 9);

        [$estMairie, $codes] = self::pivotMairie($d['pivot'] ?? null);
        if ($estMairie && $codes === []) {
            $code = self::codeCommune($d['code_insee_commune'] ?? null);
            $codes = $code === null ? [] : [$code];
        }

        $identifiant = is_scalar($d['id'] ?? null) ? trim((string) $d['id']) : '';

        return new self(
            $identifiant === '' ? null : mb_substr($identifiant, 0, self::IDENTIFIANT_MAX),
            $siret,
            $siren,
            $estMairie,
            $estMairie && count($codes) === 1 ? $codes[0] : null,
            self::email($d['adresse_courriel'] ?? null),
            self::telephone($d['telephone'] ?? null),
            self::site($d['site_internet'] ?? null),
        );
    }

    public function aDesCoordonnees(): bool
    {
        return $this->email !== null || $this->telephone !== null || $this->site !== null;
    }

    /** SIRET (int) : clé compacte des index en mémoire. */
    public function cleSiret(): ?int
    {
        return $this->siret === null ? null : (int) $this->siret;
    }

    public function cleSiren(): ?int
    {
        return $this->siren === null ? null : (int) $this->siren;
    }

    // ── Lecture des colonnes ─────────────────────────────────────────────

    private static function chiffres(mixed $valeur, int $longueur): ?string
    {
        if (! is_scalar($valeur)) {
            return null;
        }
        $v = str_replace([' ', "\u{00A0}", '.'], '', trim((string) $valeur));

        return preg_match('/^[0-9]{' . $longueur . '}$/', $v) === 1 ? $v : null;
    }

    private static function codeCommune(mixed $valeur): ?string
    {
        if (! is_scalar($valeur)) {
            return null;
        }
        $v = strtoupper(trim((string) $valeur));

        return preg_match('/^(\d{2}|2A|2B)[0-9]{3}$/', $v) === 1 ? $v : null;
    }

    /**
     * La ligne est-elle une MAIRIE (pivot `mairie`), et ses codes INSEE de
     * commune (distincts) ?
     *
     * @return array{0: bool, 1: list<string>}
     */
    private static function pivotMairie(mixed $pivot): array
    {
        $pivot = self::json($pivot);
        if (! is_array($pivot)) {
            return [false, []];
        }
        if (! array_is_list($pivot)) {
            $pivot = [$pivot];
        }
        $mairie = false;
        $codes = [];
        foreach ($pivot as $p) {
            if (! is_array($p) || ! is_string($p['type_service_local'] ?? null)
                || strtolower(trim($p['type_service_local'])) !== self::PIVOT_MAIRIE) {
                continue;
            }
            $mairie = true;
            $liste = $p['code_insee_commune'] ?? [];
            foreach (is_array($liste) ? $liste : [$liste] as $c) {
                $code = self::codeCommune($c);
                if ($code !== null) {
                    $codes[$code] = true;
                }
            }
        }

        return [$mairie, array_keys($codes)];
    }

    private static function email(mixed $valeur): ?string
    {
        foreach (self::valeurs($valeur, ['valeur', 'adresse_courriel', 'email']) as $v) {
            foreach (preg_split('/[;,\s]+/', $v) ?: [] as $morceau) {
                $email = mb_strtolower(trim($morceau, " \t\n\r\0\x0B<>\"'"));
                if ($email !== '' && strlen($email) <= self::EMAIL_MAX
                    && filter_var($email, FILTER_VALIDATE_EMAIL) !== false) {
                    return $email;
                }
            }
        }

        return null;
    }

    private static function telephone(mixed $valeur): ?string
    {
        foreach (self::valeurs($valeur, ['valeur', 'numero', 'telephone']) as $v) {
            $v = trim((string) preg_replace('/\s+/u', ' ', $v));
            $chiffres = (string) preg_replace('/\D/', '', $v);
            // Seuls chiffres, espaces, points, tirets, parenthèses et « + » ;
            // 10 chiffres en France, 8 à 15 pour un numéro international.
            if ($v === '' || mb_strlen($v) > self::TELEPHONE_MAX
                || preg_match('/^\+?[0-9 .()\-]+$/', $v) !== 1
                || strlen($chiffres) < 8 || strlen($chiffres) > 15) {
                continue;
            }
            if (! str_starts_with($v, '+') && ! str_starts_with($chiffres, '00') && strlen($chiffres) !== 10) {
                continue;
            }

            return $v;
        }

        return null;
    }

    private static function site(mixed $valeur): ?string
    {
        foreach (self::valeurs($valeur, ['valeur', 'url', 'site_internet']) as $v) {
            $v = trim($v);
            if ($v === '' || strlen($v) > self::SITE_MAX || preg_match('/[\s<>"\'\\\\]/', $v) === 1) {
                continue;
            }
            if (preg_match('#^https?://#i', $v) !== 1) {
                continue; // jamais de schéma deviné
            }
            $parties = parse_url($v);
            $hote = is_array($parties) ? strtolower((string) ($parties['host'] ?? '')) : '';
            if ($hote === '' || ! str_contains($hote, '.') || isset($parties['user']) || isset($parties['pass'])
                || filter_var($hote, FILTER_VALIDATE_IP) !== false) {
                continue;
            }

            return $v;
        }

        return null;
    }

    /**
     * Les valeurs texte d'une colonne, dans l'ordre publié : texte simple,
     * texte JSON (liste d'objets `{valeur: …}` ou de textes), tableau.
     *
     * @param  list<string>  $cles
     * @return list<string>
     */
    private static function valeurs(mixed $brut, array $cles): array
    {
        $donnee = self::json($brut);
        if (is_string($donnee)) {
            return [$donnee];
        }
        if (! is_array($donnee)) {
            return [];
        }
        if (! array_is_list($donnee)) {
            $donnee = [$donnee];
        }
        $sortie = [];
        foreach ($donnee as $element) {
            if (is_string($element)) {
                $sortie[] = $element;

                continue;
            }
            if (is_array($element)) {
                foreach ($cles as $cle) {
                    if (is_string($element[$cle] ?? null)) {
                        $sortie[] = $element[$cle];

                        break;
                    }
                }
            }
        }

        return $sortie;
    }

    /** Un texte JSON décodé (tableau), sinon la valeur telle quelle. */
    private static function json(mixed $brut): mixed
    {
        if (! is_string($brut)) {
            return $brut;
        }
        $t = trim($brut);
        if ($t === '' || ($t[0] !== '[' && $t[0] !== '{')) {
            return $t === '' ? null : $t;
        }
        $d = json_decode($t, true, 16);

        return is_array($d) ? $d : null;
    }
}
