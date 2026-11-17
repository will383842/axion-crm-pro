<?php

namespace App\Services\Auth\CodeCourriel;

use App\Models\User;
use Illuminate\Support\Facades\Log;

/** Faux canal : n'envoie RIEN. Garde le dernier code en mémoire du processus (tests) ; ne le journalise jamais. */
final class FauxCanalCodeConnexion implements CanalCodeConnexion
{
    /** @var array<string, string> */
    private static array $derniers = [];

    public function envoyer(User $u, string $code, string $objet): void
    {
        self::$derniers[(string) $u->id] = $code;
        // Un code dans un journal vaut un mot de passe pendant 10 minutes : ni code, ni adresse.
        Log::info('auth.code_courriel.faux_canal', ['user_id' => (string) $u->id, 'objet' => $objet]);
    }

    public function estReel(): bool
    {
        return false;
    }

    public static function dernierCode(string $userId): ?string
    {
        return self::$derniers[$userId] ?? null;
    }

    public static function reinitialiser(): void
    {
        self::$derniers = [];
    }
}
