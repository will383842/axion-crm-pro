<?php

namespace App\Rules;

use App\Services\Auth\HibpChecker;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

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
 */
class NotPwnedPassword implements ValidationRule
{
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
            $fail(
                "La vérification des mots de passe compromis est momentanément indisponible. "
                . "Par précaution, le mot de passe n'a pas été changé — réessayez dans quelques minutes."
            );

            return;
        }

        if ($count > $this->threshold) {
            $fail("Le mot de passe figure dans {$count} fuites de données connues. Choisissez-en un autre.");
        }
    }
}
