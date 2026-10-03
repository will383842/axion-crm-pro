<?php

namespace App\Support\Partners;

use RuntimeException;

/**
 * Configuration VALIDÉE du futur canal Axion Partners (lot N11).
 *
 * Une seule porte d'entrée, `depuisConfig()`, appelée :
 *   - au démarrage par `CanalPartnersServiceProvider` (refus de démarrer sur
 *     une configuration fausse) ;
 *   - à chaque requête par `VerificateurCanalPartners` (la même validation, la
 *     même lecture : aucun chemin ne lit les secrets sans les avoir vérifiés).
 *
 * RÈGLES :
 *   - `mode` ∈ {off, essai, actif}, sinon refus (même en l'absence de secret) ;
 *   - en `off`, les secrets ne sont PAS exigés (canal fermé, valeur par défaut) ;
 *   - hors `off`, `entrant_secrets` est obligatoire ; chaque liste posée suit
 *     `kid:secret[,kid:secret]` — deux clés au plus (rotation), `kid` au format
 *     fermé `^[a-z0-9-]{1,32}$`, pas de `kid` en double, secret d'au moins
 *     32 octets et sans préfixe de développement (`dev`, `test`, `changeme`…) ;
 *   - `kid_essai`, s'il est posé, doit désigner une clé entrante ; en `actif`,
 *     il doit rester au moins une clé qui ne soit pas la clé d'essai.
 *
 * Les messages d'erreur nomment la variable et la règle, JAMAIS la valeur d'un
 * secret.
 */
final class ConfigurationCanalPartners
{
    public const MODE_OFF = 'off';

    public const MODE_ESSAI = 'essai';

    public const MODE_ACTIF = 'actif';

    public const MODES = [self::MODE_OFF, self::MODE_ESSAI, self::MODE_ACTIF];

    public const MOTIF_KID = '/^[a-z0-9-]{1,32}$/';

    public const LONGUEUR_MIN_SECRET = 32;

    /** Deux valeurs au plus : l'ancienne et la nouvelle pendant une rotation. */
    public const CLES_MAX = 2;

    /**
     * Préfixes trahissant un secret de développement ou d'exemple (comparés
     * sans tenir compte de la casse).
     */
    public const PREFIXES_INTERDITS = [
        'dev', 'test', 'changeme', 'change-me', 'change_me', 'example', 'exemple',
        'placeholder', 'dummy', 'fake', 'todo', 'xxx', 'secret', 'password',
    ];

    /**
     * @param  array<string, string>  $clesEntrantes  kid => secret
     * @param  array<string, string>  $clesSortantes  kid => secret
     * @param  array<string, string>  $jetonsApi3  kid => jeton
     */
    private function __construct(
        public readonly string $mode,
        private readonly array $clesEntrantes,
        private readonly array $clesSortantes,
        private readonly array $jetonsApi3,
        public readonly ?string $kidEssai,
    ) {}

    /**
     * @throws RuntimeException si la configuration ne peut pas servir
     */
    public static function depuisConfig(): self
    {
        $mode = config('crm.partners.mode');
        if (! is_string($mode) || ! in_array($mode, self::MODES, true)) {
            throw self::refus('CRM_PARTNERS_MODE', sprintf(
                'mode inconnu (%s) ; attendu : off, essai ou actif',
                is_scalar($mode) ? var_export($mode, true) : get_debug_type($mode),
            ));
        }

        if ($mode === self::MODE_OFF) {
            return new self($mode, [], [], [], null);
        }

        $entrantes = self::lireListe('CRM_PARTNERS_ENTRANT_SECRETS', config('crm.partners.entrant_secrets'), true);
        $sortantes = self::lireListe('CRM_PARTNERS_SORTANT_SECRETS', config('crm.partners.sortant_secrets'), false);
        $api3 = self::lireListe('CRM_PARTNERS_API3_TOKENS', config('crm.partners.api3_tokens'), false);

        $kidEssai = config('crm.partners.kid_essai');
        $kidEssai = is_string($kidEssai) ? trim($kidEssai) : $kidEssai;
        if ($kidEssai === null || $kidEssai === '') {
            $kidEssai = null;
        } elseif (! is_string($kidEssai) || preg_match(self::MOTIF_KID, $kidEssai) !== 1) {
            throw self::refus('CRM_PARTNERS_KID_ESSAI', 'identifiant hors format ^[a-z0-9-]{1,32}$');
        } elseif (! array_key_exists($kidEssai, $entrantes)) {
            throw self::refus('CRM_PARTNERS_KID_ESSAI', 'ne désigne aucune clé de CRM_PARTNERS_ENTRANT_SECRETS');
        }

        if ($mode === self::MODE_ACTIF && array_keys($entrantes) === [$kidEssai]) {
            throw self::refus(
                'CRM_PARTNERS_ENTRANT_SECRETS',
                'en mode actif, la seule clé entrante est la clé d’essai : aucune requête ne serait acceptée',
            );
        }

        return new self($mode, $entrantes, $sortantes, $api3, $kidEssai);
    }

    public function estOuvert(): bool
    {
        return $this->mode !== self::MODE_OFF;
    }

    /**
     * Secret entrant ACCEPTÉ pour ce `kid` dans le mode courant, sinon null.
     *
     * Le `kid` reçu est d'abord confronté au format fermé, puis cherché par
     * comparaison dans la liste — il n'entre jamais dans une clé de
     * configuration ni dans un chemin. La clé d'essai n'est rendue qu'en mode
     * `essai`.
     */
    public function secretEntrantPour(?string $kid): ?string
    {
        if ($kid === null || preg_match(self::MOTIF_KID, $kid) !== 1) {
            return null;
        }

        $trouve = null;
        foreach ($this->clesEntrantes as $kidConnu => $secret) {
            if (hash_equals($kidConnu, $kid)) {
                $trouve = $kidConnu;
            }
        }

        if ($trouve === null) {
            return null;
        }

        if ($trouve === $this->kidEssai && $this->mode !== self::MODE_ESSAI) {
            return null;
        }

        return $this->clesEntrantes[$trouve];
    }

    /** @return list<string> identifiants des clés sortantes posées (futur) */
    public function kidsSortants(): array
    {
        return array_keys($this->clesSortantes);
    }

    /** @return list<string> identifiants des jetons API 3 posés (futur) */
    public function kidsApi3(): array
    {
        return array_keys($this->jetonsApi3);
    }

    /**
     * @return array<string, string> kid => secret
     *
     * @throws RuntimeException
     */
    private static function lireListe(string $variable, mixed $brut, bool $obligatoire): array
    {
        if ($brut === null || (is_string($brut) && trim($brut) === '')) {
            if ($obligatoire) {
                throw self::refus($variable, 'absente ou vide alors que le canal n’est pas en mode off');
            }

            return [];
        }

        if (! is_string($brut)) {
            throw self::refus($variable, 'doit être une chaîne kid:secret[,kid:secret]');
        }

        $cles = [];
        foreach (explode(',', $brut) as $position => $entree) {
            $numero = $position + 1;
            $morceaux = explode(':', trim($entree), 2);
            if (count($morceaux) !== 2) {
                throw self::refus($variable, "entrée n°{$numero} sans séparateur « : » (attendu kid:secret)");
            }

            [$kid, $secret] = $morceaux;
            if (preg_match(self::MOTIF_KID, $kid) !== 1) {
                throw self::refus($variable, "entrée n°{$numero} : identifiant hors format ^[a-z0-9-]{1,32}$");
            }
            if (array_key_exists($kid, $cles)) {
                throw self::refus($variable, "identifiant « {$kid} » en double");
            }
            if (strlen($secret) < self::LONGUEUR_MIN_SECRET) {
                throw self::refus($variable, sprintf(
                    'secret « %s » trop court (%d octets au moins)',
                    $kid,
                    self::LONGUEUR_MIN_SECRET,
                ));
            }
            foreach (self::PREFIXES_INTERDITS as $prefixe) {
                if (str_starts_with(strtolower($secret), $prefixe)) {
                    throw self::refus($variable, "secret « {$kid} » au préfixe de développement « {$prefixe} »");
                }
            }

            $cles[$kid] = $secret;
        }

        if (count($cles) > self::CLES_MAX) {
            throw self::refus($variable, sprintf('%d clés posées ; deux au plus (rotation)', count($cles)));
        }

        return $cles;
    }

    private static function refus(string $variable, string $raison): RuntimeException
    {
        return new RuntimeException(sprintf(
            'Configuration invalide du canal Partners : %s — %s. '
            . "L'application refuse de démarrer tant que la valeur n'est pas corrigée.",
            $variable,
            $raison,
        ));
    }
}
