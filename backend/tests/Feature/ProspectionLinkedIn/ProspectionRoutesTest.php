<?php

namespace Tests\Feature\ProspectionLinkedIn;

use App\Envoi\Conformite\RegistreOppositionLinkedin;
use App\Support\ListeSuppression;
use App\Support\WorkspaceContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Feature\Envoi\Routes\BaseRoutesEnvoi;

/*
 * Lot PONT-LINKEDIN LI-C — API de la file (HTTP réel). Les écritures sont rejouées SOUS LE PROPRIÉTAIRE ET SOUS LE RÔLE
 * APPLICATIF (`SET LOCAL ROLE`, posé après la préparation des données). Profils et adresses fictifs. Aucune requête sortante.
 */
uses(BaseRoutesEnvoi::class)->group('envoi');

const LIC_BASE = '/api/v1/prospection-linkedin';
const LIC_MODES = ['proprio', 'app'];

beforeEach(function () {
    config(['prospection_linkedin.active' => true]);
    Http::preventStrayRequests();
    $this->espace = $this->espace(true);
    $this->proprio = $this->compte($this->espace, 'owner');
});

function licProspect(string $ws, string $exp, array $col = []): string
{
    static $n = 0;
    $n++;
    $id = (string) Str::uuid();
    WorkspaceContext::run($ws, static fn () => DB::table('pl_prospects')->insert(array_merge([
        'id' => $id, 'workspace_id' => $ws, 'expediteur_user_id' => $exp, 'linkedin_slug' => 'exemple-lic-' . $n . substr($id, 0, 4),
        'source' => 'recherche', 'source_le' => '2026-11-01', 'score' => 80, 'etat' => 'en_file',
    ], $col)));

    return $id;
}

function licSous(string $mode): void
{
    if ($mode === 'app') {
        DB::statement('SET LOCAL ROLE "' . str_replace('"', '""', (string) config('database.connections.pgsql_app.username')) . '"');
    }
}

function licVoir(string $ws, string $table, array $where = []): int
{
    DB::statement('RESET ROLE');

    return DB::table($table)->where('workspace_id', $ws)->where($where)->count();
}

function licCle(): string
{
    return 'cle-' . Str::random(20);
}

test('drapeau éteint ⇒ 404 partout, même pour le propriétaire', function () {
    config(['prospection_linkedin.active' => false]);
    $p = licProspect($this->espace->id, $this->proprio->id);
    $h = ['Idempotency-Key' => licCle()];
    $u = $this->actingAs($this->proprio);
    $u->getJson(LIC_BASE . '/file')->assertNotFound();
    $u->postJson(LIC_BASE . '/prospects', [])->assertNotFound();
    $u->postJson(LIC_BASE . "/prospects/{$p}/gestes", ['geste' => 'invitation_faite'], $h)->assertNotFound();
    $u->postJson(LIC_BASE . '/arret', [])->assertNotFound();
    $u->postJson(LIC_BASE . '/reprise', [])->assertNotFound();
    $u->postJson(LIC_BASE . '/plafonds', [])->assertNotFound();
});

test('non propriétaire ⇒ 403 ; sans session ⇒ 401', function () {
    $this->getJson(LIC_BASE . '/file')->assertUnauthorized();
    foreach (['admin', 'operator', 'viewer'] as $role) {
        $this->actingAs($this->compte($this->espace, $role))->getJson(LIC_BASE . '/file')->assertForbidden();
        $this->actingAs($this->compte($this->espace, $role))->postJson(LIC_BASE . '/arret', ['motif' => 'arret de test fictif'])->assertForbidden();
    }
});

test('geste : sans clé 422, clé trop courte 422, double clic même clé ⇒ une ligne de journal', function () {
    foreach (LIC_MODES as $mode) {
        $p = licProspect($this->espace->id, $this->proprio->id);
        $this->actingAs($this->proprio)->postJson(LIC_BASE . "/prospects/{$p}/gestes", ['geste' => 'invitation_faite'])->assertStatus(422);
        $this->actingAs($this->proprio)->postJson(LIC_BASE . "/prospects/{$p}/gestes", ['geste' => 'invitation_faite'], ['Idempotency-Key' => 'court'])->assertStatus(422);
        $this->actingAs($this->proprio)->postJson(LIC_BASE . "/prospects/{$p}/gestes", ['geste' => 'inconnu'], ['Idempotency-Key' => licCle()])->assertStatus(422);
        $cle = licCle();
        licSous($mode);
        $this->actingAs($this->proprio)->postJson(LIC_BASE . "/prospects/{$p}/gestes", ['geste' => 'invitation_faite'], ['Idempotency-Key' => $cle])->assertOk()->assertJsonPath('etat', 'invite');
        $this->actingAs($this->proprio)->postJson(LIC_BASE . "/prospects/{$p}/gestes", ['geste' => 'invitation_faite'], ['Idempotency-Key' => $cle])->assertOk()->assertJsonPath('rejeu', true);
        expect(licVoir($this->espace->id, 'pl_journal', ['prospect_id' => $p, 'evenement' => 'invitation_faite']))->toBe(1, $mode);
    }
});

test('opposé entre la file et le clic ⇒ 409, prospect écarté, arrêt posé', function () {
    foreach (LIC_MODES as $mode) {
        $this->espace = $this->espace(true); // un espace par mode : l'arrêt posé (ajout seul) ne fuit pas
        $this->proprio = $this->compte($this->espace, 'owner');
        $p = licProspect($this->espace->id, $this->proprio->id, ['linkedin_slug' => 'exemple-oppose-' . $mode]);
        (new RegistreOppositionLinkedin)->inscrire($this->espace->id, 'exemple-oppose-' . $mode, 'opposition fictive', 'le fondateur', 'manuel');
        licSous($mode);
        $this->actingAs($this->proprio)->postJson(LIC_BASE . "/prospects/{$p}/gestes", ['geste' => 'invitation_faite'], ['Idempotency-Key' => licCle()])
            ->assertStatus(409)->assertJsonPath('refus', 'oppose');
        expect(licVoir($this->espace->id, 'pl_prospects', ['id' => $p, 'etat' => 'ecarte']))->toBe(1, $mode)
            ->and(licVoir($this->espace->id, 'pl_arret', ['geste' => 'arret', 'declencheur' => 'oppose_dans_file']))->toBe(1, $mode)
            ->and(licVoir($this->espace->id, 'pl_journal', ['prospect_id' => $p, 'evenement' => 'invitation_faite']))->toBe(0, $mode);
        // l'arrêt posé : le geste suivant d'un autre prospect est refusé 409 arret
        $q = licProspect($this->espace->id, $this->proprio->id);
        $this->actingAs($this->proprio)->postJson(LIC_BASE . "/prospects/{$q}/gestes", ['geste' => 'message_fait'], ['Idempotency-Key' => licCle()])
            ->assertStatus(409)->assertJsonPath('refus', 'arret');
        DB::statement('RESET ROLE');
    }
});

test('opposition avec fiche rattachée ⇒ registre LinkedIn + registre adresse + 1 événement consent_optout', function () {
    foreach (LIC_MODES as $mode) {
        $ws = $this->espace->id;
        $email = 'rattache-' . $mode . '@zz-client.example.invalid';
        $contact = WorkspaceContext::run($ws, static function () use ($ws, $email): int {
            $societe = (int) DB::table('companies')->insertGetId(['workspace_id' => $ws, 'siren' => (string) random_int(100000000, 999999999), 'denomination' => 'Societe lic', 'created_at' => now(), 'updated_at' => now()]);

            return (int) DB::table('contacts')->insertGetId(['workspace_id' => $ws, 'company_id' => $societe, 'first_name' => 'Prenom', 'last_name' => 'Nom', 'email' => $email, 'created_at' => now(), 'updated_at' => now()]);
        });
        $p = licProspect($ws, $this->proprio->id, ['contact_id' => $contact, 'linkedin_slug' => 'exemple-rattache-' . $mode]);
        licSous($mode);
        $this->actingAs($this->proprio)->postJson(LIC_BASE . "/prospects/{$p}/gestes", ['geste' => 'opposition'], ['Idempotency-Key' => licCle()])->assertOk()->assertJsonPath('etat', 'ecarte');
        DB::statement('RESET ROLE');
        expect(DB::table('envoi_oppositions_linkedin')->where('empreinte', RegistreOppositionLinkedin::empreinte('exemple-rattache-' . $mode))->count())->toBe(1, $mode)
            ->and(DB::table('envoi_oppositions_registre')->where('portee', 'adresse')->where('empreinte', ListeSuppression::empreinte($email))->count())->toBe(1, $mode)
            ->and(DB::table('crm_outbound_events')->where('email_hash', ListeSuppression::empreinte($email))->where('event_type', 'consent_optout')->count())->toBe(1, $mode)
            ->and(DB::table('pl_prospects')->where('id', $p)->count())->toBe(1);
    }
});

test('opposition sans adresse connue ⇒ registre LinkedIn seul, 0 événement', function () {
    foreach (LIC_MODES as $mode) {
        $p = licProspect($this->espace->id, $this->proprio->id, ['linkedin_slug' => 'exemple-sans-adresse-' . $mode]);
        licSous($mode);
        $this->actingAs($this->proprio)->postJson(LIC_BASE . "/prospects/{$p}/gestes", ['geste' => 'opposition'], ['Idempotency-Key' => licCle()])->assertOk();
        DB::statement('RESET ROLE');
        expect(DB::table('envoi_oppositions_linkedin')->where('empreinte', RegistreOppositionLinkedin::empreinte('exemple-sans-adresse-' . $mode))->count())->toBe(1, $mode)
            ->and(DB::table('crm_outbound_events')->count())->toBe(0);
    }
});

test('panne du registre adresse ⇒ rien d\'écrit, 503', function () {
    $ws = $this->espace->id;
    $email = 'panne@zz-client.example.invalid';
    $contact = WorkspaceContext::run($ws, static function () use ($ws, $email): int {
        $societe = (int) DB::table('companies')->insertGetId(['workspace_id' => $ws, 'siren' => '123456782', 'denomination' => 'Societe panne', 'created_at' => now(), 'updated_at' => now()]);

        return (int) DB::table('contacts')->insertGetId(['workspace_id' => $ws, 'company_id' => $societe, 'first_name' => 'Prenom', 'last_name' => 'Nom', 'email' => $email, 'created_at' => now(), 'updated_at' => now()]);
    });
    $p = licProspect($ws, $this->proprio->id, ['contact_id' => $contact, 'linkedin_slug' => 'exemple-panne']);
    DB::statement("CREATE FUNCTION lic_panne() RETURNS trigger AS \$f\$ BEGIN RAISE EXCEPTION 'panne simulee'; END \$f\$ LANGUAGE plpgsql");
    DB::statement('CREATE TRIGGER lic_panne BEFORE INSERT ON envoi_oppositions_registre FOR EACH ROW EXECUTE FUNCTION lic_panne()');
    $this->actingAs($this->proprio)->postJson(LIC_BASE . "/prospects/{$p}/gestes", ['geste' => 'opposition'], ['Idempotency-Key' => licCle()])->assertStatus(503);
    expect(DB::table('envoi_oppositions_linkedin')->count())->toBe(0)
        ->and(DB::table('pl_prospects')->where('id', $p)->value('etat'))->toBe('en_file')
        ->and(DB::table('crm_outbound_events')->count())->toBe(0);
});

test('IDOR : prospect d\'un autre espace ⇒ 404 ; ajout, arrêt, reprise, plafonds ; file de l\'utilisateur', function () {
    $autre = $this->espace(true);
    $autreProprio = $this->compte($autre, 'owner');
    $pAutre = licProspect($autre->id, $autreProprio->id);
    $this->actingAs($this->proprio)->postJson(LIC_BASE . "/prospects/{$pAutre}/gestes", ['geste' => 'invitation_faite'], ['Idempotency-Key' => licCle()])->assertNotFound();
    expect(DB::table('pl_prospects')->where('id', $pAutre)->value('etat'))->toBe('en_file');

    foreach (LIC_MODES as $mode) {
        licSous($mode);
        $this->actingAs($this->proprio)->postJson(LIC_BASE . '/prospects', ['profil' => 'https://www.linkedin.com/in/exemple-ajout-' . $mode . '/', 'source' => 'recherche'])->assertCreated();
        $this->actingAs($this->proprio)->postJson(LIC_BASE . '/prospects', ['profil' => 'exemple-ajout-' . $mode, 'source' => 'recherche'])->assertOk();
        $this->actingAs($this->proprio)->postJson(LIC_BASE . '/prospects', ['profil' => 'https://www.linkedin.com/company/x/', 'source' => 'recherche'])->assertStatus(422);
        $this->actingAs($this->proprio)->postJson(LIC_BASE . '/arret', ['motif' => 'arret volontaire de test'])->assertOk();
        $this->actingAs($this->proprio)->getJson(LIC_BASE . '/file')->assertOk()->assertJsonPath('arret', true)->assertJsonPath('prospects', []);
        $this->actingAs($this->proprio)->postJson(LIC_BASE . '/reprise', [])->assertStatus(422);
        $this->actingAs($this->proprio)->postJson(LIC_BASE . '/reprise', ['motif' => 'reprise apres verification'])->assertOk();
        $this->actingAs($this->proprio)->getJson(LIC_BASE . '/file')->assertOk()->assertJsonPath('arret', false);
        DB::statement('RESET ROLE');
    }
});

test('plafonds : bornes du CHECK, au-dessus des défauts seulement avec confirme', function () {
    $ok = ['invitations_jour' => 8, 'invitations_semaine' => 40, 'messages_jour' => 10, 'notes_mois' => 3, 'jours_ouvres_seulement' => true];
    $u = $this->actingAs($this->proprio);
    $u->postJson(LIC_BASE . '/plafonds', $ok)->assertOk();
    $u->postJson(LIC_BASE . '/plafonds', array_merge($ok, ['invitations_jour' => 26, 'confirme' => true]))->assertStatus(422);
    $u->postJson(LIC_BASE . '/plafonds', array_merge($ok, ['invitations_jour' => 20]))->assertStatus(422)->assertJsonPath('refus', 'confirmation_requise');
    $u->postJson(LIC_BASE . '/plafonds', array_merge($ok, ['invitations_jour' => 20, 'confirme' => true]))->assertOk();
    expect(DB::table('pl_plafonds')->count())->toBe(2);
});

test('file : un opposé n\'est jamais proposé ; registre illisible ⇒ rien proposé', function () {
    $ws = $this->espace->id;
    $pOk = licProspect($ws, $this->proprio->id, ['linkedin_slug' => 'exemple-file-ok']);
    $pOppose = licProspect($ws, $this->proprio->id, ['linkedin_slug' => 'exemple-file-oppose']);
    (new RegistreOppositionLinkedin)->inscrire($ws, 'exemple-file-oppose', 'opposition fictive', 'le fondateur', 'manuel');
    $r = $this->actingAs($this->proprio)->getJson(LIC_BASE . '/file')->assertOk();
    expect(array_column($r->json('prospects'), 'id'))->toBe([$pOk]);
    expect($r->json('prospects.0.profil'))->toBe('https://www.linkedin.com/in/exemple-file-ok/');

    DB::statement('ALTER TABLE envoi_oppositions_linkedin RENAME TO envoi_oppositions_linkedin_x');
    $r = $this->actingAs($this->proprio)->getJson(LIC_BASE . '/file')->assertOk();
    expect($r->json('prospects'))->toBe([]);
});

test('aucune route ne supprime ; aucune requête sortante', function () {
    $routes = collect(app('router')->getRoutes())->filter(fn ($r) => str_starts_with($r->uri(), 'api/v1/prospection-linkedin'));
    expect($routes->count())->toBe(6);
    foreach ($routes as $r) {
        expect(array_intersect($r->methods(), ['DELETE', 'PUT', 'PATCH']))->toBe([]);
    }
    Http::assertNothingSent();
});
