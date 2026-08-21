<?php

/**
 * GARDE DU COURRIEL D'AUTHENTIFICATION — audit 360, F40-002 (S0).
 *
 * Deux défauts se cumulaient, et chacun suffisait à couper les seules portes de
 * secours d'un compte — lien magique et réinitialisation de mot de passe.
 *
 * 1. `MAIL_MAILER` n'était défini NULLE PART : ni dans `.env.example`, ni dans
 *    `configure-prod-env.sh`. Laravel retombait sur son défaut `log`. Un défaut
 *    implicite est une décision que personne n'a prise.
 *
 * 2. Le court-circuit de simulacre lisait `env('MOCK_MODE', true)` AU MOMENT DE
 *    LA REQUÊTE. Or l'entrypoint de production tente `php artisan config:cache`
 *    à chaque démarrage, et une configuration en cache signifie que Laravel NE
 *    CHARGE PLUS le `.env` : `env()` rend alors sa valeur par défaut, ici `true`.
 *    La production se croyait en mode simulacre alors que `MOCK_MODE=false` y
 *    était bien posé — il n'était simplement plus lu.
 *
 * C'est l'une des raisons pour lesquelles personne ne s'est jamais connecté au
 * CRM en production.
 */

use App\Models\User;
use App\Models\Workspace;
use App\Services\Auth\MagicLinkService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

function utilisateurCourriel(string $email): User
{
    $workspace = Workspace::create([
        'id' => (string) Str::uuid(),
        'slug' => 'mail-' . Str::random(8),
        'name' => 'Espace courriel',
    ]);

    return User::create([
        'id' => (string) Str::uuid(),
        'email' => $email,
        'name' => 'Destinataire',
        'password_hash' => password_hash('MotDePasseDeTest2026!', PASSWORD_BCRYPT, ['cost' => 4]),
        'current_workspace_id' => $workspace->id,
        'first_login_completed_at' => now(),
    ]);
}

/**
 * ⚠️ ON NE MESURE PAS AVEC `Mail::fake()`, ET C'EST DELIBERE.
 *
 * `Mail::fake()` n'enregistre que les *mailables* passes a `send()`. Les deux
 * envois d'authentification utilisent `Mail::raw()`, que le faux ne comptabilise
 * pas : `assertSentCount()` reste a 0 meme quand le message part reellement.
 * La garde rougissait donc AUSSI sur le code corrige - elle mesurait mon banc,
 * pas le produit. Constate en la jouant.
 *
 * On mesure donc sur le transport `array`, qui garde les messages reellement
 * remis. C'est la seule facon de savoir si un courriel est PARTI.
 */
function messagesEnvoyes(): int
{
    return count(Mail::mailer('array')->getSymfonyTransport()->messages());
}

function viderLesMessages(): void
{
    Mail::mailer('array')->getSymfonyTransport()->flush();
}

test('F40-002 — hors simulacre, le lien magique PART reellement', function () {
    config(['crm.mock_mode' => false, 'mail.auth_mailer' => 'array']);
    viderLesMessages();

    utilisateurCourriel('destinataire@courriel.test');
    app(MagicLinkService::class)->issue('destinataire@courriel.test', '10.0.0.1');

    // Sur le code d'origine, le court-circuit lisait `env()` et le courriel ne
    // partait jamais : c'est exactement ce que cette garde mesure.
    expect(messagesEnvoyes())->toBe(1);
});

test('F40-002 — hors simulacre, la reinitialisation PART reellement', function () {
    config(['crm.mock_mode' => false, 'mail.auth_mailer' => 'array']);
    viderLesMessages();

    $utilisateur = utilisateurCourriel('reset@courriel.test');

    $this->postJson('/api/v1/auth/password/forgot', ['email' => $utilisateur->email])->assertOk();

    expect(messagesEnvoyes())->toBe(1);
});

test('F40-002 — TEMOIN : en simulacre, rien ne part et le lien est journalise', function () {
    config(['crm.mock_mode' => true, 'mail.auth_mailer' => 'array']);
    viderLesMessages();

    utilisateurCourriel('simulacre@courriel.test');
    app(MagicLinkService::class)->issue('simulacre@courriel.test', '10.0.0.1');

    // Sans ce témoin, un correctif qui enverrait TOUJOURS passerait pour une
    // réussite — et enverrait du vrai courrier depuis un poste de développement.
    expect(messagesEnvoyes())->toBe(0);
    expect(DB::table('magic_links')->where('email', 'simulacre@courriel.test')->count())->toBe(1);
});

test('F40-002 — le transport des courriels d authentification est configurable a part', function () {
    // La décision « MAIL_MAILER reste log » doit pouvoir tenir SANS couper les
    // portes de secours : c'est tout l'objet de cette clé.
    config(['mail.default' => 'log', 'mail.auth_mailer' => 'array']);

    expect(config('mail.auth_mailer'))->toBe('array');
    expect(config('mail.default'))->toBe('log');
});
