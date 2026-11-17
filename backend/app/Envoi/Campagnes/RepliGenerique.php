<?php

namespace App\Envoi\Campagnes;

use App\Crm\Emails\QualificationEmail;
use App\Support\WorkspaceContext;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Repli sur l'adresse générale (PLAN ADRESSES GÉNÉRALES, cahier #680) — règle PURE, lot L1. Rien ne l'appelle encore : inerte.
 *
 *  - R1 `admise` : liste blanche étroite (plus étroite que `QualificationEmail::type`). L'adresse doit être générique ET sa partie locale
 *    doit être exactement l'un des mots, ou commencer par l'un d'eux suivi d'un chiffre ou de `.-_`. Une étiquette `+` est refusée.
 *  - `choisir` : l'ordre de la liste R1, puis le plus petit `contact_id`.
 *  - R0 `ouvert` : interrupteur `envoi.repli_generique` de l'espace ; absent, différent de « true », illisible ou en erreur = ÉTEINT.
 *  - R4 `plafondJour` : `envoi.repli_generique_plafond_jour`, défaut 3, au plus 5, jamais au-dessus du plafond de l'espace ; illisible = 0.
 */
final class RepliGenerique
{
    public const CLE_INTERRUPTEUR = 'envoi.repli_generique';

    public const CLE_PLAFOND = 'envoi.repli_generique_plafond_jour';

    public const PLAFOND_DEFAUT = 3;

    public const PLAFOND_MAX = 5;

    /** Liste blanche R1, dans l'ordre de préférence. */
    public const MOTS = ['contact', 'info', 'infos', 'accueil', 'bonjour', 'hello', 'secretariat', 'office', 'courrier', 'standard'];

    /** Termes exclus par R1 : refusés même quand ils suivent un mot de la liste blanche (`contact.presse`, `info-rh`…). */
    public const EXCLUS = ['presse', 'press', 'media', 'juridique', 'legal', 'rh', 'hr', 'jobs', 'careers', 'compta', 'finance', 'billing', 'support', 'help', 'feedback', 'complaints', 'sales', 'marketing', 'newsletter', 'admin', 'direction', 'adherent', 'adherents', 'formation'];

    /** @return array{0: int, 1: string}|null [rang du mot dans MOTS, mot], null si refusée */
    private static function rang(string $email): ?array
    {
        $email = QualificationEmail::normaliser($email);
        $at = strrpos($email, '@');
        if ($at === false || $at === 0 || QualificationEmail::type($email) !== 'generique') {
            return null;
        }
        $local = substr($email, 0, $at);
        if (str_contains($local, '+')) {
            return null;
        }
        foreach (self::MOTS as $rang => $mot) {
            if ($local === $mot) {
                return [$rang, $mot];
            }
            if (str_starts_with($local, $mot) && preg_match('/^[0-9._-]/', substr($local, strlen($mot))) === 1) {
                $suffixe = preg_split('/[0-9._-]+/', substr($local, strlen($mot)), -1, PREG_SPLIT_NO_EMPTY) ?: [];
                if (array_intersect($suffixe, self::EXCLUS) !== []) {
                    return null;
                }

                return [$rang, $mot];
            }
        }

        return null;
    }

    public static function admise(string $email): bool
    {
        return self::rang($email) !== null;
    }

    /**
     * @param  list<array<string, mixed>>  $contacts  chaque élément porte `contact_id` et `email`
     * @return array<string, mixed>|null le contact retenu, ou null
     */
    public static function choisir(array $contacts): ?array
    {
        $choisi = null;
        $clef = null;
        foreach ($contacts as $c) {
            if (! isset($c['email'], $c['contact_id']) || ! is_string($c['email'])) {
                continue;
            }
            $r = self::rang($c['email']);
            if ($r === null) {
                continue;
            }
            $k = [$r[0], (int) $c['contact_id']];
            if ($clef === null || $k < $clef) {
                $clef = $k;
                $choisi = $c;
            }
        }

        return $choisi;
    }

    /** R0 : échec fermé — toute exception, valeur absente ou autre que « true » = éteint. */
    public static function ouvert(string $ws): bool
    {
        try {
            return self::lire($ws, self::CLE_INTERRUPTEUR) === 'true';
        } catch (Throwable) {
            return false;
        }
    }

    /** R4 : plafond effectif des replis par jour, compris dans le plafond général de l'espace. */
    public static function plafondJour(string $ws, int $plafondEspace): int
    {
        try {
            $v = self::lire($ws, self::CLE_PLAFOND);
        } catch (Throwable) {
            return 0;
        }
        if ($v === null) {
            $reglage = self::PLAFOND_DEFAUT;
        } elseif (preg_match('/^-?[0-9]{1,9}$/', $v) === 1) {
            $reglage = max(0, min(self::PLAFOND_MAX, (int) $v));
        } else {
            return 0;
        }

        return max(0, min($reglage, self::PLAFOND_MAX, $plafondEspace));
    }

    private static function lire(string $ws, string $cle): ?string
    {
        $v = WorkspaceContext::run($ws, fn () => DB::table('envoi_reglages')->where('workspace_id', $ws)->where('cle', $cle)->value('valeur'));

        return $v === null ? null : (string) $v;
    }
}
