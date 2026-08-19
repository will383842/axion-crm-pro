<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

class LoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * 🔴 PAS DE RÈGLE DE COMPLEXITÉ ICI. `Password::min(12)` était appliqué à la
     * CONNEXION : un compte dont le mot de passe faisait moins de 12 caractères
     * — créé par un script, une reprise, un import — recevait un 422
     * « le mot de passe doit contenir au moins 12 caractères » alors qu'il
     * venait de saisir le bon. Impasse totale, et message trompeur.
     * Mesuré le 2026-08-19 (audit 360, F35-011).
     *
     * La complexité se contrôle là où un mot de passe est CHOISI (création,
     * changement, réinitialisation — cf. `PasswordResetController::reset()`),
     * jamais là où il est présenté.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'email'    => ['required', 'email', 'max:254'],
            'password' => ['required', 'string', 'max:4096'],
            'remember' => ['nullable', 'boolean'],
        ];
    }
}
