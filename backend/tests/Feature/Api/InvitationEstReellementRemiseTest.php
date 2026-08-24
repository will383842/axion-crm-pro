<?php

/**
 * GARDE : « INVITER » DOIT REMETTRE QUELQUE CHOSE, OU LE DIRE — constat X39-027.
 *
 * ═══ CE QUI ETAIT MESURE ═══════════════════════════════════════════════════
 *
 * `POST /users` creait le compte et s'arretait la. Le commentaire de la methode
 * l'ecrivait sans detour : « aucun courriel ne part [...] sa remise est un
 * chantier distinct, et il reste ouvert ». Pendant ce temps, l'ecran annoncait
 * « Invitation envoyee ».
 *
 * Le compte naît SANS mot de passe — c'est voulu, un administrateur qui choisit
 * le secret d'autrui le connaît. La personne doit donc recevoir un lien pour
 * s'en donner un. Sans remise, elle ne peut pas se connecter DU TOUT : le
 * compte existe, occupe l'adresse, et reste inaccessible.
 *
 * ═══ CE QUE CETTE GARDE MESURE ═════════════════════════════════════════════
 *
 *   A. Un jeton de definition de mot de passe est ECRIT, dans tous les cas —
 *      c'est lui qui rend le compte recuperable meme si le courriel se perd.
 *   B. La base ne garde que son CONDENSAT, jamais le jeton en clair : un
 *      administrateur qui lit la table ne doit pas pouvoir prendre le compte
 *      qu'il vient de creer.
 *   C. Hors mode maquette, un courriel part REELLEMENT, a la bonne adresse.
 *   D. En mode maquette, il ne part rien — et la reponse le DIT
 *      (`invitation_envoyee: false`) au lieu de laisser l'ecran supposer.
 *   E. Un transport en echec ne defait pas la creation : le compte reste, et le
 *      drapeau passe a `false`. C'est la face qui compte le plus : un 500 ici
 *      rendrait la creation invisible alors qu'elle a eu lieu, et l'adresse
 *      serait ensuite « deja utilisee » — un compte fantome, impossible a
 *      recreer.
 *
 * ⚠️ CE QU'ELLE NE MESURE PAS : que le courriel ARRIVE. L'evenement `MessageSent`
 * prouve que Laravel l'a remis a son transport, rien de plus. La reception depend du
 * jeton ZeptoMail, du domaine verifie sur l'agent et de la reputation d'envoi —
 * cela se constate dans « E-mails traites » de l'agent, pas dans un banc.
 */

use App\Models\User;
use App\Models\Workspace;
use Database\Seeders\PermissionsAndRolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\Events\MessageSent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    $this->seed(PermissionsAndRolesSeeder::class);
});

/**
 * Un compte qui a le droit d'inviter (`users.manage`), dans un espace neuf.
 *
 * ⚠️ Le nom porte celui de CE fichier : Pest charge tous les tests dans le meme
 * espace global, et deux assistants homonymes se percutent en
 * `Cannot redeclare`. Le piege est deja documente dans
 * `ImagesConstruitesSontDeployeesTest`.
 *
 * @return array{0: User, 1: string}
 */
function invitantX39027(): array
{
    $espace = Workspace::create([
        'id' => (string) Str::uuid(),
        'slug' => 'invit-x39027-' . Str::random(8),
        'name' => 'Espace invitation',
    ]);

    $compte = User::create([
        'id' => (string) Str::uuid(),
        'email' => 'admin-' . Str::random(6) . '@invitation-x39027.test',
        'name' => 'Admin invitant',
        'password_hash' => password_hash('MotDePasseDeTest2026!', PASSWORD_BCRYPT, ['cost' => 4]),
        'current_workspace_id' => $espace->id,
        'first_login_completed_at' => now(),
    ]);

    setPermissionsTeamId($espace->id);
    $compte->assignRole('admin');

    return [$compte, (string) $espace->id];
}

/** Le corps d'invitation minimal accepte par la route. */
function corpsInvitationX39027(string $email): array
{
    return ['email' => $email, 'name' => 'Nouvelle Recrue', 'role' => 'operator'];
}

it('ecrit un jeton de definition de mot de passe, et n en garde que le CONDENSAT', function () {
    config()->set('crm.mock_mail', true);
    [$admin] = invitantX39027();
    $email = 'recrue-' . Str::random(6) . '@invitation-x39027.test';

    $this->actingAs($admin)
        ->postJson('/api/v1/users', corpsInvitationX39027($email))
        ->assertCreated();

    $ligne = DB::table('password_reset_tokens')->where('email', $email)->first();

    expect($ligne)->not->toBeNull(
        'Sans jeton, le compte cree est INACCESSIBLE : il naît sans mot de passe, '
        . 'et rien ne permet a la personne de s en donner un.',
    );

    // 64 caracteres aleatoires condenses en SHA-256 : 64 caracteres hexadecimaux.
    expect($ligne->token)->toMatch(
        '/^[0-9a-f]{64}$/',
        'Le jeton doit etre stocke CONDENSE. En clair, un administrateur qui lit '
        . 'la table peut prendre le compte qu il vient de creer.',
    );
});

it('en mode maquette, ne remet RIEN et le dit — pas de courriel, drapeau a false', function () {
    // ⚠️ PAS `Mail::assertNothingSent()`, ET C'EST LE PIEGE DE CETTE GARDE.
    // Elle n'inspecte que les MAILABLES ; un `Mail::raw()` lui echappe. Elle
    // serait donc VERTE meme si un courriel partait — precisement le vert
    // menteur que ce depot traque. On observe l'evenement d'envoi, qui ne fait
    // pas cette distinction.
    Event::fake([MessageSent::class]);
    config()->set('crm.mock_mail', true);
    [$admin] = invitantX39027();
    $email = 'recrue-' . Str::random(6) . '@invitation-x39027.test';

    $reponse = $this->actingAs($admin)
        ->postJson('/api/v1/users', corpsInvitationX39027($email))
        ->assertCreated();

    $reponse->assertJsonPath('invitation_envoyee', false);
    Event::assertNotDispatched(MessageSent::class);
});

it('hors mode maquette, remet REELLEMENT un courriel a la personne invitee', function () {
    // ⚠️ PAS `Mail::fake()` + `Mail::assertSent()` ICI, et la premiere version de
    // ce test s'y est cassee (RuntimeException). Ces deux-la travaillent sur des
    // MAILABLES ; `remettreInvitation()` envoie un message BRUT (`Mail::raw`),
    // que le faux mailer ne range pas dans sa liste de mailables. L'assertion
    // n'avait donc rien a inspecter.
    //
    // On observe l'evenement que TOUT envoi emet, brut ou non.
    Event::fake([MessageSent::class]);
    config()->set('crm.mock_mail', false);
    [$admin] = invitantX39027();
    $email = 'recrue-' . Str::random(6) . '@invitation-x39027.test';

    $reponse = $this->actingAs($admin)
        ->postJson('/api/v1/users', corpsInvitationX39027($email))
        ->assertCreated();

    $reponse->assertJsonPath('invitation_envoyee', true);

    Event::assertDispatched(MessageSent::class, function (MessageSent $e) use ($email) {
        $destinataires = array_map(
            static fn ($adresse) => $adresse->getAddress(),
            $e->message->getTo(),
        );

        return in_array($email, $destinataires, true);
    });
});

it('un transport en ECHEC ne defait pas la creation — le compte reste, le drapeau tombe', function () {
    config()->set('crm.mock_mail', false);
    // ⚠️ UN TRANSPORT INEXISTANT, ET NON UN SMTP INJOIGNABLE. La premiere
    // version pointait `127.0.0.1:1` en esperant un refus de connexion : cela
    // depend du reseau du banc, du pare-feu et d'un delai d'attente — trois
    // facons de rendre ce test lent puis capricieux. Un nom de mailer non
    // declare fait lever le gestionnaire immediatement, sans toucher au reseau,
    // et c'est exactement le chemin qu'on veut mesurer : ce qui se passe quand
    // l'envoi jette.
    config()->set('mail.auth_mailer', 'transport-absent-x39027');

    [$admin] = invitantX39027();
    $email = 'recrue-' . Str::random(6) . '@invitation-x39027.test';

    $reponse = $this->actingAs($admin)
        ->postJson('/api/v1/users', corpsInvitationX39027($email));

    $reponse->assertCreated();
    $reponse->assertJsonPath('invitation_envoyee', false);

    expect(User::where('email', $email)->exists())->toBeTrue(
        'Le compte doit SURVIVRE a l echec d envoi. Sinon l adresse reste prise '
        . 'par une creation invisible, et personne ne peut la recreer.',
    );
});
