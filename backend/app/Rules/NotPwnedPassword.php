<?php

namespace App\Rules;

use App\Services\Auth\HibpChecker;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Facades\Log;

/**
 * Sprint 18.1 — règle de validation adossée à HibpChecker.
 *
 * Usage :
 *   'password' => ['required', 'string', 'min:12', new NotPwnedPassword()]
 *
 * `threshold` = nombre maximum d'apparitions tolérées dans les fuites connues.
 * Défaut 5 (spec sécurité). Au-delà, le mot de passe est jugé compromis.
 *
 * 🔴 SERVICE INDISPONIBLE ⇒ REFUS, PAS ACCEPTATION. Tant que `HibpChecker`
 * renvoyait `0` sur erreur réseau, cette règle concluait « mot de passe sain » et
 * laissait passer `password`. Un contrôle de sécurité qui s'efface quand il
 * tombe en panne ne protège que les jours où l'on n'en a pas besoin.
 * L'utilisateur reçoit un message qui dit quoi faire : réessayer.
 *
 * 🟠 P5-35-010 (2026-08-22) — CE REFUS A UNE PORTE DE SORTIE, ET UNE SEULE.
 * Cette règle n'est branchée qu'à un endroit, `PasswordResetController`, qui
 * est aussi le seul point de choix d'un mot de passe du produit. Le refus
 * fail-closed suspend donc, à lui seul, toute reprise en main d'un compte tant
 * qu'un service TIERS est injoignable — et les délais sont courts
 * (`HibpChecker` : 5 s / 3 s), donc un simple pare-feu sortant suffit.
 *
 * `config('auth.hibp.fail_mode')` vaut `closed` par défaut : RIEN ne change
 * sans geste explicite d'exploitation. Passé à `open-audited`, le mot de passe
 * est accepté **et** une ligne `auth.hibp.fail_open` part au niveau `alert`.
 * C'est la différence exacte avec le défaut F35-004 qu'on a fermé : celui-ci
 * était SILENCIEUX et permanent, celui-là est bruyant et décidé.
 *
 * ⚠️ Toute valeur inconnue retombe sur `closed`. Un drapeau mal orthographié
 * ne doit jamais rouvrir un contrôle de sécurité.
 */
class NotPwnedPassword implements ValidationRule
{
    /** Service indisponible ⇒ refus. Le défaut, et le bon. */
    public const FAIL_MODE_CLOSED = 'closed';

    /** Service indisponible ⇒ acceptation, contre une ligne de journal `alert`. */
    public const FAIL_MODE_OPEN_AUDITED = 'open-audited';

    public function __construct(
        private int $threshold = HibpChecker::DEFAULT_THRESHOLD,
        private ?HibpChecker $checker = null,
    ) {
        $this->checker ??= app(HibpChecker::class);
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || $value === '') {
            // Laisse `required` / `string` faire leur travail.
            return;
        }

        $count = $this->checker->getBreachCount($value);

        if ($count === null) {
            // P5-35-010 — mode dégradé, jamais silencieux. On ne journalise ni
            // le mot de passe ni son empreinte : le message doit dire QUE le
            // contrôle a été contourné et QUAND, pas sur quoi.
            if ($this->failMode() === self::FAIL_MODE_OPEN_AUDITED) {
                Log::alert('auth.hibp.fail_open', [
                    'attribute' => $attribute,
                    'reason' => 'hibp_unreachable',
                    'fail_mode' => self::FAIL_MODE_OPEN_AUDITED,
                    'note' => "Mot de passe accepte SANS verification HIBP (HIBP_FAIL_MODE=open-audited). Repasser a 'closed' des que l'API repond.",
                ]);

                return;
            }

            $fail(
                'La vérification des mots de passe compromis est momentanément indisponible. '
                . "Par précaution, le mot de passe n'a pas été changé — réessayez dans quelques minutes.",
            );

            return;
        }

        if ($count > $this->threshold) {
            $fail("Le mot de passe figure dans {$count} fuites de données connues. Choisissez-en un autre.");
        }
    }

    /**
     * Mode de repli effectif.
     *
     * Liste BLANCHE, pas liste noire : seule la chaîne exacte `open-audited`
     * ouvre la porte. `open`, `OPEN-AUDITED`, `true`, `1` ou une faute de frappe
     * retombent sur `closed` — c'est-à-dire sur le comportement sûr. Un drapeau
     * d'exploitation qui échoue vers l'ouverture reproduirait mot pour mot le
     * défaut F35-004.
     */
    private function failMode(): string
    {
        $mode = config('auth.hibp.fail_mode', self::FAIL_MODE_CLOSED);

        return $mode === self::FAIL_MODE_OPEN_AUDITED
            ? self::FAIL_MODE_OPEN_AUDITED
            : self::FAIL_MODE_CLOSED;
    }
}
