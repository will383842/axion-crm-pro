<?php

namespace App\Rules;

use App\Services\Auth\HibpChecker;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Sprint 18.1 — règle de validation Laravel utilisant HibpChecker.
 *
 * Usage :
 *   'password' => ['required', 'string', 'min:12', new NotPwnedPassword()]
 *
 * Param threshold = nombre maximum d'apparitions dans les breaches HIBP avant refus.
 * Par défaut 5 (cf. spec sécurité). Au-delà, le password est jugé compromis.
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
            // Laisse les autres rules (required, string) gérer.
            return;
        }

        $count = $this->checker->getBreachCount($value);
        if ($count > $this->threshold) {
            $fail("Le mot de passe figure dans {$count} fuites de données connues. Choisissez-en un autre.");
        }
    }
}
