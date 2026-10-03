<?php

/**
 * N14 réduit — `contacts.dernier_echange_initiative_at` sous le RÔLE
 * APPLICATIF (`axion_app`, RLS forcée), sur le VRAI chemin : l'ingestion d'un
 * formulaire du site (relecture architecte de #312).
 *
 * Le déclencheur `activites_echange_initiative` s'exécute avec les droits de
 * l'appelant. Sous `axion_app` sans contexte d'espace, son UPDATE toucherait
 * 0 ligne sans rien dire ; ce test prouve que l'ingestion, jouée hors requête
 * HTTP après le crochet `Looping` qui efface le contexte, pose bien la date.
 *
 * Pas de `RefreshDatabase` : `pgsql_app` est une AUTRE session Postgres. Le jeu
 * d'essai est validé (auto-commit) et nettoyé par le propriétaire dans un
 * `finally`. Fixtures FICTIVES (dépôt public).
 */

use App\Crm\Ingest\SiteSyncEvent;
use App\Crm\Ingest\SiteSyncIngestService;
use Illuminate\Queue\Events\Looping;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\SemeurTablesScopees;
use Tests\TestCase;

uses(TestCase::class);

function ptraNettoyer(string $ws, string $emailHash): void
{
    $o = DB::connection('pgsql_owner');
    $o->select('SELECT set_config(?, ?, false)', ['app.current_workspace_id', '']);
    $o->transaction(function () use ($o, $ws): void {
        $o->statement("SET LOCAL app.autoriser_suppression_absorbee = 'on'");
        $o->statement("SET LOCAL app.autoriser_suppression_protegee = 'on'");
        foreach (array_reverse(SemeurTablesScopees::ORDRE) as $table) {
            $o->table($table)->where('workspace_id', $ws)->delete();
        }
        $o->table('workspaces')->where('id', $ws)->delete();
    });
    $o->table('opt_out')->where('email_hash', $emailHash)->delete();
    $o->disconnect();
    DB::connection('pgsql_app')->disconnect();
}

test('sous axion_app, un formulaire reçu du site date dernier_echange_initiative_at', function () {
    $o = DB::connection('pgsql_owner');
    $ws = (string) Str::uuid();
    $slug = 'zz-pt-role-' . substr($ws, 0, 8);
    $o->table('workspaces')->insert([
        'id' => $ws, 'slug' => $slug, 'name' => 'ZZ provenance rôle applicatif', 'settings' => '{}',
        'cost_cap_eur' => 0, 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
    ]);
    $email = 'zz.pt-role@example.invalid';
    $hash = hash('sha256', $email);
    config(['crm.ingest.business_workspace' => $slug]);

    try {
        config(['database.default' => 'pgsql_app']);
        DB::purge('pgsql_app');
        try {
            $role = DB::selectOne('SELECT (SELECT rolbypassrls FROM pg_roles WHERE rolname = current_user) AS b');
            expect($role->b)->toBeFalse();

            // Le vrai crochet de la file : le contexte d'espace est effacé.
            event(new Looping('sync', 'default'));
            app(SiteSyncIngestService::class)->ingest(SiteSyncEvent::fromArray([
                'schema_version' => 1,
                'event_id' => (string) Str::uuid(),
                'event_type' => 'form_submission',
                'form_type' => 'audit',
                'occurred_at' => '2026-09-14T09:30:00+02:00',
                'subject_ref' => 'site:submission:' . Str::uuid(),
                'person' => [
                    'person_key' => hash('sha256', $email), 'email' => $email,
                    'first_name' => 'Zoe', 'last_name' => 'ZZ ROLE',
                ],
                'company' => ['siren' => '900000202', 'name' => 'ZZ ROLE SAS', 'postcode' => '38000', 'city' => 'Grenoble'],
                'consent' => ['version' => 'v1-2026-05-24', 'at' => '2026-09-14T09:29:00+02:00', 'text_ref' => 'unified-contact-form'],
                'tags' => [],
                'payload' => ['page' => '/fr/contact'],
            ]));
        } finally {
            config(['database.default' => 'pgsql']);
            DB::purge('pgsql_app');
        }

        // Relu par le PROPRIÉTAIRE : ce que le rôle applicatif a réellement écrit.
        $contact = $o->table('contacts')->where('workspace_id', $ws)->where('email', $email)->first();
        expect($contact)->not->toBeNull()
            ->and($o->table('activities')->where('workspace_id', $ws)->where('kind', 'form_submission')->count())->toBe(1)
            ->and(strtotime((string) $contact->dernier_echange_initiative_at))->toBe(strtotime('2026-09-14T09:30:00+02:00'));
    } finally {
        ptraNettoyer($ws, $hash);
    }
});
