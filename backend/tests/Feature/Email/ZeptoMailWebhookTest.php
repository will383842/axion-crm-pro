<?php

/**
 * Étape 0, ligne 3 ter (F18) — le webhook du service d'envoi alimente la
 * liste de suppression, sans jamais rien envoyer.
 *
 * Ce que ces tests garantissent, et qui a été vu ROUGE avant d'être vu vert :
 *  - sans jeton configuré → 503 et rien d'écrit (inertie) ;
 *  - mauvais jeton → 401 et rien d'écrit ;
 *  - rebond dur → ligne `hard_bounce`, PAR EMPREINTE, jamais l'adresse en clair ;
 *  - plainte → ligne `complaint` ;
 *  - rebond mou → rien avant le seuil, une ligne au seuil ;
 *  - rejouer le même webhook → une seule ligne, deux occurrences ;
 *  - un corps sans événement reconnu → 200, compteurs à zéro (pas de retry infini).
 */

use App\Support\ListeSuppression;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

const JETON = 'jeton-de-test-0123456789abcdef0123456789abcdef';

/** Corps au format documenté du fournisseur (event_message → event_data → details). */
function corpsZepto(string $evenement, string $adresse, array $details = []): array
{
    return [
        'event_name' => [$evenement],
        'event_message' => [[
            'email_info' => [
                'subject' => 'Sujet — jamais conservé',
                'to' => [['email_address' => ['address' => $adresse, 'name' => '']]],
            ],
            'event_data' => [[
                'event_name' => $evenement,
                'details' => [array_merge(['bounced_recipient' => $adresse, 'time' => '2026-08-18T12:00:00Z'], $details)],
            ]],
        ]],
        'mailagent_key' => 'ma-test',
    ];
}

function urlWebhook(): string
{
    return '/api/internal/email/zeptomail?t=' . JETON;
}

it('répond 503 et n’écrit rien tant que le jeton n’est pas configuré (inertie)', function () {
    config(['mail.webhooks.zeptomail_token' => null]);

    $this->postJson(urlWebhook(), corpsZepto('hardbounce', 'mort@acme.fr'))->assertStatus(503);

    expect(DB::table('email_suppressions')->count())->toBe(0);
});

it('rejette un jeton absent ou faux (401) sans rien écrire', function () {
    config(['mail.webhooks.zeptomail_token' => JETON]);

    $this->postJson('/api/internal/email/zeptomail', corpsZepto('hardbounce', 'mort@acme.fr'))->assertStatus(401);
    $this->postJson('/api/internal/email/zeptomail?t=faux', corpsZepto('hardbounce', 'mort@acme.fr'))->assertStatus(401);

    expect(DB::table('email_suppressions')->count())->toBe(0);
});

it('inscrit un rebond dur par empreinte, jamais l’adresse en clair', function () {
    config(['mail.webhooks.zeptomail_token' => JETON]);

    $this->postJson(urlWebhook(), corpsZepto('hardbounce', 'Mort@ACME.fr', ['reason' => 'Mailbox does not exist']))
        ->assertOk()
        ->assertJsonPath('counts.hard_bounce', 1);

    $ligne = DB::table('email_suppressions')->first();
    expect($ligne)->not->toBeNull()
        ->and($ligne->reason)->toBe(ListeSuppression::REBOND_DUR)
        ->and($ligne->source)->toBe('zeptomail')
        ->and($ligne->email)->toBeNull()
        ->and($ligne->email_hash)->toBe(ListeSuppression::empreinte('mort@acme.fr'));

    // La liste est LUE par empreinte, insensible à la casse : l'adresse est bien supprimée.
    expect(ListeSuppression::estSupprimee('mort@acme.fr'))->toBeTrue();
});

it('inscrit une plainte', function () {
    config(['mail.webhooks.zeptomail_token' => JETON]);

    $this->postJson(urlWebhook(), corpsZepto('complaint', 'fache@acme.fr'))->assertOk()->assertJsonPath('counts.complaint', 1);

    expect(DB::table('email_suppressions')->where('reason', ListeSuppression::PLAINTE)->count())->toBe(1);
});

it('un rebond mou ne supprime qu’au seuil', function () {
    config(['mail.webhooks.zeptomail_token' => JETON]);

    for ($i = 1; $i < ListeSuppression::SEUIL_REBONDS_TEMPORAIRES; $i++) {
        $this->postJson(urlWebhook(), corpsZepto('softbounce', 'plein@acme.fr'))->assertOk();
        expect(DB::table('email_suppressions')->count())->toBe(0);
    }

    $this->postJson(urlWebhook(), corpsZepto('softbounce', 'plein@acme.fr'))->assertOk();
    expect(DB::table('email_suppressions')->where('reason', ListeSuppression::REBOND_TEMPORAIRE)->count())->toBe(1);
});

it('rejouer le même webhook ne crée pas de doublon', function () {
    config(['mail.webhooks.zeptomail_token' => JETON]);

    $this->postJson(urlWebhook(), corpsZepto('hardbounce', 'mort@acme.fr'))->assertOk();
    $this->postJson(urlWebhook(), corpsZepto('hardbounce', 'mort@acme.fr'))->assertOk();

    expect(DB::table('email_suppressions')->count())->toBe(1)
        ->and((int) DB::table('email_suppressions')->value('occurrences'))->toBe(2);
});

it('un corps sans événement reconnu rend 200 avec des compteurs à zéro', function () {
    config(['mail.webhooks.zeptomail_token' => JETON]);

    $this->postJson(urlWebhook(), ['event_name' => ['delivered'], 'event_message' => [['event_data' => [['event_name' => 'delivered', 'details' => [['bounced_recipient' => 'ok@acme.fr']]]]]]])
        ->assertOk()
        ->assertJsonPath('counts.hard_bounce', 0)
        ->assertJsonPath('counts.ignored', 1);

    expect(DB::table('email_suppressions')->count())->toBe(0);
});

it('retrouve le destinataire dans email_info.to quand les détails ne le portent pas', function () {
    config(['mail.webhooks.zeptomail_token' => JETON]);

    $corps = corpsZepto('hardbounce', 'sans-details@acme.fr');
    $corps['event_message'][0]['event_data'][0]['details'] = [['reason' => 'unknown user']];

    $this->postJson(urlWebhook(), $corps)->assertOk()->assertJsonPath('counts.hard_bounce', 1);
    expect(ListeSuppression::estSupprimee('sans-details@acme.fr'))->toBeTrue();
});
