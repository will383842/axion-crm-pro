<?php

/**
 * COURRIER D'AUTHENTIFICATION ISOLÉ (S2). Aucun courriel réel : transports
 * `array`. Aucune vraie adresse : domaine `.test`.
 */

use App\Models\User;
use App\Models\Workspace;
use App\Services\Auth\MagicLinkService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

function compteS2(string $email): User
{
    $espace = Workspace::create([
        'id' => (string) Str::uuid(),
        'slug' => 's2-' . Str::random(8),
        'name' => 'Espace S2',
    ]);

    return User::create([
        'id' => (string) Str::uuid(),
        'email' => $email,
        'name' => 'Destinataire',
        'password_hash' => password_hash('MotDePasseDeTest2026!', PASSWORD_BCRYPT, ['cost' => 4]),
        'current_workspace_id' => $espace->id,
        'first_login_completed_at' => now(),
    ]);
}

function reglerDeuxTransports(): void
{
    // `mail.mailers.autre` : second transport `array` distinct du transport par défaut.
    config([
        'crm.mock_mode' => false,
        'crm.mock_mail' => false,
        'mail.default' => 'array',
        'mail.mailers.authentification' => ['transport' => 'array'],
        'mail.auth_mailer' => 'authentification',
    ]);
    Mail::forgetMailers();
}

test('le lien de connexion part par le mailer d authentification, jamais par le mailer par défaut', function () {
    reglerDeuxTransports();
    compteS2('destinataire@s2.test');

    app(MagicLinkService::class)->issue('destinataire@s2.test', '203.0.113.7');

    expect(count(Mail::mailer('authentification')->getSymfonyTransport()->messages()))->toBe(1)
        ->and(count(Mail::mailer('array')->getSymfonyTransport()->messages()))->toBe(0);
});

test('la réinitialisation part par le mailer d authentification, jamais par le mailer par défaut', function () {
    reglerDeuxTransports();
    $compte = compteS2('reset@s2.test');

    $this->postJson('/api/v1/auth/password/forgot', ['email' => $compte->email])->assertOk();

    expect(count(Mail::mailer('authentification')->getSymfonyTransport()->messages()))->toBe(1)
        ->and(count(Mail::mailer('array')->getSymfonyTransport()->messages()))->toBe(0);
});

test('le journal ne contient ni le lien ni l adresse en clair', function () {
    reglerDeuxTransports();
    $lignes = [];
    Log::listen(function ($message) use (&$lignes) {
        $lignes[] = $message->message . json_encode($message->context);
    });
    compteS2('discret@s2.test');

    app(MagicLinkService::class)->issue('discret@s2.test', '203.0.113.7');
    $this->postJson('/api/v1/auth/password/forgot', ['email' => 'discret@s2.test'])->assertOk();

    $journal = implode("\n", $lignes);
    expect($journal)->not->toContain('discret@s2.test')
        ->and($journal)->not->toContain('magic-link/verify')
        ->and($journal)->not->toContain('password-reset?token');
});
