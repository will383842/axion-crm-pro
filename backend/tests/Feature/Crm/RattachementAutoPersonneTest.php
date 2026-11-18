<?php

use App\Crm\Ingest\IngestOutcome;
use App\Crm\Ingest\SiteSyncEvent;
use App\Crm\Ingest\SiteSyncIngestService;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/**
 * C1 (demande #784) : le dossier client de la console lie la personne née
 * d'une demande sans SIREN, et solde ses demandes en attente. Adresses en
 * `@example.invalid`, SIREN en 9xxxxxxxx, jamais de donnée réelle.
 */
beforeEach(function () {
    config([
        'crm.ingest.enabled' => true,
        'crm.ingest.dossier_enabled' => true,
        'crm.ingest.personnes_enabled' => false,
        'crm.ingest.demandes_personne_enabled' => true,
        'crm.ingest.dossier_client_stage' => false,
        'crm.ingest.business_workspace' => 'ws-rap',
    ]);

    $this->workspace = Workspace::create(['id' => (string) Str::uuid(), 'slug' => 'ws-rap', 'name' => 'Rattachement auto', 'settings' => []]);
});

const RAP_EMAIL = 'zz.rap@example.invalid';
const RAP_CLIENT = '5d1e7c2a-3b4f-4a6d-9c8e-1f2a3b4c5d6e';

function rapCle(string $email = RAP_EMAIL): string
{
    return hash('sha256', 'zz-c1|' . $email);
}

/** @return array<string, mixed> */
function rapDemande(string $email = RAP_EMAIL, ?string $siren = null): array
{
    return [
        'schema_version' => 1,
        'event_id' => (string) Str::uuid(),
        'event_type' => 'form_submission',
        'form_type' => 'audit',
        'occurred_at' => '2026-11-04T09:30:00+01:00',
        'subject_ref' => 'site:submission:' . Str::uuid(),
        'person' => ['person_key' => rapCle($email), 'email' => $email, 'first_name' => 'Jeanne', 'last_name' => 'ZZ RAP'],
        'company' => array_filter(['name' => 'ZZ DECLAREE', 'siren' => $siren]),
        'consent' => ['version' => 'v1-2026-05-24', 'at' => '2026-11-04T09:29:00+01:00', 'text_ref' => 'unified-contact-form'],
        'tags' => [],
        'payload' => ['page' => '/fr/contact'],
    ];
}

/** @return array<string, mixed> */
function rapDossier(string $siren = '900000801', int $version = 1, ?string $cle = null, string $email = RAP_EMAIL, string $client = RAP_CLIENT): array
{
    return [
        'schema_version' => 1,
        'event_id' => (string) Str::uuid(),
        'event_type' => 'dossier_client_synchro',
        'occurred_at' => '2026-11-05T10:00:00+01:00',
        'subject_ref' => 'site:client:' . $client,
        'company' => ['siren' => $siren, 'name' => 'ZZ CLIENT RAP SAS', 'postcode' => '75011', 'city' => 'Paris'],
        'payload' => ['dossier' => [
            'version' => $version, 'statut' => 'prospect', 'facture_emise' => false,
            'contacts' => [['ref' => 'c-1', 'prenom' => 'Jeanne', 'nom' => 'ZZ RAP', 'email' => $email, 'person_key' => $cle ?? rapCle($email)]],
        ]],
    ];
}

/** @param  array<string, mixed>  $event */
function rapIngerer(array $event): IngestOutcome
{
    return app(SiteSyncIngestService::class)->ingest(SiteSyncEvent::fromArray($event));
}

function rapPersonne(string $email = RAP_EMAIL): ?stdClass
{
    return DB::table('personnes')->where('person_key', rapCle($email))->first();
}

function rapDemandeLigne(int $id): stdClass
{
    return DB::table('activities')->where('id', $id)->first();
}

/** @param  list<string>  $requetes */
function rapPosition(array $requetes, string $morceau): ?int
{
    foreach ($requetes as $i => $sql) {
        if (stripos($sql, $morceau) !== false) {
            return $i;
        }
    }

    return null;
}

test('1. demande sans SIREN puis dossier : personne liée, demande soldée, rien supprimé', function () {
    $demande = rapIngerer(rapDemande());
    expect($demande->status)->toBe(IngestOutcome::PENDING_MATCH);
    $avant = [DB::table('activities')->count(), DB::table('contacts')->count(), DB::table('personnes')->count()];

    DB::enableQueryLog();
    $issue = rapIngerer(rapDossier());
    $requetes = array_column(DB::getQueryLog(), 'query');
    DB::disableQueryLog();

    $fiche = DB::table('companies')->where('siren', '900000801')->first();
    $contact = DB::table('contacts')->where('company_id', $fiche->id)->first();
    $personne = rapPersonne();
    $ligne = rapDemandeLigne($demande->activityId);
    $payload = json_decode($ligne->payload, true);

    expect($issue->status)->toBe(IngestOutcome::CREATED)
        ->and($personne->contact_id)->toBe($contact->id)
        ->and($personne->company_id)->toBe($fiche->id)
        ->and($personne->rattachee_at)->not->toBeNull()
        ->and($ligne->subject_type)->toBe('company')
        ->and($ligne->subject_id)->toBe($fiche->id)
        ->and($ligne->contact_id)->toBe($contact->id)
        ->and($payload['arbitrage']['attached_by'])->toBe('automatique:dossier_client')
        ->and($payload)->toHaveKey('pending_match')
        ->and(DB::table('activities')->count())->toBeGreaterThanOrEqual($avant[0])
        ->and(DB::table('contacts')->count())->toBeGreaterThanOrEqual($avant[1] + 1)
        ->and(DB::table('personnes')->count())->toBe($avant[2])
        ->and(array_filter($requetes, static fn (string $q): bool => stripos(ltrim($q), 'delete') === 0))->toBe([]);
});

test('2. rejeu du même event_id : noop_idempotent, rien ne bouge', function () {
    rapIngerer(rapDemande());
    $dossier = rapDossier();
    rapIngerer($dossier);
    $personne = rapPersonne();
    $payloads = DB::table('activities')->orderBy('id')->pluck('payload', 'id')->all();

    $rejeu = rapIngerer($dossier);

    expect($rejeu->status)->toBe(IngestOutcome::IDEMPOTENT)
        ->and((string) rapPersonne()->rattachee_at)->toBe((string) $personne->rattachee_at)
        ->and(DB::table('activities')->orderBy('id')->pluck('payload', 'id')->all())->toBe($payloads);
});

test('3. dossier périmé : rien n est lié, la demande reste en attente', function () {
    rapIngerer(rapDossier(version: 2, cle: hash('sha256', 'autre')));
    $demande = rapIngerer(rapDemande());

    $issue = rapIngerer(rapDossier(version: 1));

    expect($issue->status)->toBe(IngestOutcome::IDEMPOTENT)
        ->and(rapPersonne()->contact_id)->toBeNull()
        ->and(rapDemandeLigne($demande->activityId)->subject_id)->toBeNull();
});

test('4. personne déjà liée à une autre fiche : rien ne bouge', function () {
    $demande = rapIngerer(rapDemande());
    rapIngerer(rapDemande(siren: '900000802'));
    $ailleurs = rapPersonne()->contact_id;
    expect($ailleurs)->not->toBeNull();

    $issue = rapIngerer(rapDossier('900000803'));

    $ignores = json_decode(rapDemandeLigne($issue->activityId)->payload, true)['contacts']['ignores'];
    expect(rapPersonne()->contact_id)->toBe($ailleurs)
        ->and(rapDemandeLigne($demande->activityId)->subject_id)->toBeNull()
        ->and(array_column($ignores, 'raison'))->toContain('personne_liee_ailleurs');
});

test('4b. un contact actif d une autre fiche porte la clé alors que la personne n est pas liée : rien ne bouge', function () {
    $demande = rapIngerer(rapDemande());
    rapIngerer(rapDemande(siren: '900000802'));
    DB::table('personnes')->where('person_key', rapCle())->update(['contact_id' => null, 'company_id' => null]);

    $issue = rapIngerer(rapDossier('900000803'));

    $ignores = json_decode(rapDemandeLigne($issue->activityId)->payload, true)['contacts']['ignores'];
    expect(rapPersonne()->contact_id)->toBeNull()
        ->and(rapDemandeLigne($demande->activityId)->subject_id)->toBeNull()
        ->and(array_column($ignores, 'raison'))->toContain('personne_liee_ailleurs');
});

test('4c. personne liée à un contact d une autre fiche (à la corbeille) : rien ne bouge', function () {
    $demande = rapIngerer(rapDemande());
    rapIngerer(rapDemande(siren: '900000802'));
    $ailleurs = rapPersonne()->contact_id;
    DB::table('contacts')->where('id', $ailleurs)->update(['deleted_at' => now()]);

    $issue = rapIngerer(rapDossier('900000803'));

    $ignores = json_decode(rapDemandeLigne($issue->activityId)->payload, true)['contacts']['ignores'];
    expect(rapPersonne()->contact_id)->toBe($ailleurs)
        ->and(rapDemandeLigne($demande->activityId)->subject_id)->toBeNull()
        ->and(array_column($ignores, 'raison'))->toContain('personne_liee_ailleurs');
});

test('5. personne opposée (opposition_at) : ni lien ni solde', function () {
    $demande = rapIngerer(rapDemande());
    DB::table('personnes')->where('person_key', rapCle())->update(['opposition_at' => now()]);

    $issue = rapIngerer(rapDossier());

    $ignores = json_decode(rapDemandeLigne($issue->activityId)->payload, true)['contacts']['ignores'];
    expect(rapPersonne()->contact_id)->toBeNull()
        ->and(rapDemandeLigne($demande->activityId)->subject_id)->toBeNull()
        ->and(array_column($ignores, 'raison'))->toContain('personne_opposee');
});

test('5b. opposition générale opt_out sur l empreinte : ni lien ni solde', function () {
    $demande = rapIngerer(rapDemande());
    $hash = rapPersonne()->email_hash;
    expect($hash)->not->toBeNull();
    // Posée APRÈS la personne : le dossier n'est pas bloqué en amont par `estOppose`
    // sur une autre adresse ; l'empreinte de la personne est la seule en cause.
    DB::table('opt_out')->insert(['email' => null, 'email_hash' => $hash, 'scope' => 'business', 'source' => 'test', 'reason' => 'test', 'created_at' => now()]);
    $dossier = rapDossier();
    $dossier['payload']['dossier']['contacts'][0]['email'] = 'zz.autre.adresse@example.invalid';

    $issue = rapIngerer($dossier);

    $ignores = json_decode(rapDemandeLigne($issue->activityId)->payload, true)['contacts']['ignores'];
    expect(rapPersonne()->contact_id)->toBeNull()
        ->and(rapDemandeLigne($demande->activityId)->subject_id)->toBeNull()
        ->and(array_column($ignores, 'raison'))->toContain('personne_opposee');
});

test('6. drapeaux fermés : aucune requête sur personnes ni verrou, demande en attente', function () {
    config(['crm.ingest.personnes_enabled' => false, 'crm.ingest.demandes_personne_enabled' => false]);
    $id = DB::table('activities')->insertGetId([
        'workspace_id' => $this->workspace->id, 'contact_id' => null, 'type' => 'form_submission', 'kind' => 'form_submission',
        'occurred_at' => now(), 'person_key' => rapCle(), 'external_ref' => 'site:event:' . Str::uuid(),
        'subject_type' => null, 'subject_id' => null, 'title' => 'ZZ demande',
        'payload' => json_encode(['pending_match' => ['email' => RAP_EMAIL]]), 'created_at' => now(),
    ]);

    DB::enableQueryLog();
    rapIngerer(rapDossier());
    $requetes = array_column(DB::getQueryLog(), 'query');
    DB::disableQueryLog();

    expect(rapPosition($requetes, 'personnes'))->toBeNull()
        ->and(rapPosition($requetes, 'pg_advisory_xact_lock'))->toBeNull()
        ->and(rapDemandeLigne($id)->subject_id)->toBeNull();
});

test('6b. seul le drapeau lettre est ouvert : personne liée, demande toujours en attente', function () {
    config(['crm.ingest.personnes_enabled' => true, 'crm.ingest.demandes_personne_enabled' => false]);
    $personne = DB::table('personnes')->insertGetId([
        'workspace_id' => $this->workspace->id, 'person_key' => rapCle(), 'email' => RAP_EMAIL, 'email_hash' => hash('sha256', RAP_EMAIL),
        'first_name' => 'Jeanne', 'last_name' => 'ZZ RAP', 'legal_basis' => 'precontractual', 'premiere_source' => 'formulaire', 'premiere_source_at' => now(), 'created_at' => now(), 'updated_at' => now(),
    ]);
    $id = DB::table('activities')->insertGetId([
        'workspace_id' => $this->workspace->id, 'contact_id' => null, 'type' => 'form_submission', 'kind' => 'form_submission',
        'occurred_at' => now(), 'person_key' => rapCle(), 'external_ref' => 'site:event:' . Str::uuid(),
        'subject_type' => null, 'subject_id' => null, 'title' => 'ZZ demande',
        'payload' => json_encode(['pending_match' => ['email' => RAP_EMAIL]]), 'created_at' => now(),
    ]);

    rapIngerer(rapDossier());

    expect(DB::table('personnes')->where('id', $personne)->value('contact_id'))->not->toBeNull()
        ->and(rapDemandeLigne($id)->subject_id)->toBeNull();
});

test('7. le verrou de personne est pris avant le premier verrou de ligne sur companies', function () {
    DB::enableQueryLog();
    rapIngerer(rapDossier());
    $requetes = array_column(DB::getQueryLog(), 'query');
    DB::disableQueryLog();

    $verrou = rapPosition($requetes, 'pg_advisory_xact_lock');
    $ligne = null;
    foreach ($requetes as $i => $sql) {
        if (stripos($sql, 'from "companies"') !== false && stripos($sql, 'for update') !== false) {
            $ligne = $i;
            break;
        }
    }

    expect($verrou)->not->toBeNull()
        ->and($ligne)->not->toBeNull()
        ->and($verrou)->toBeLessThan($ligne);
});

test('8. contact existant d une autre clé : rien n est lié', function () {
    $v1 = rapDossier(version: 1, cle: hash('sha256', 'autre-cle'));
    rapIngerer($v1);
    $demande = rapIngerer(rapDemande());

    $issue = rapIngerer(rapDossier(version: 2));

    $ignores = json_decode(rapDemandeLigne($issue->activityId)->payload, true)['contacts']['ignores'];
    expect(array_column($ignores, 'raison'))->toContain('person_key_divergente')
        ->and(rapPersonne()->contact_id)->toBeNull()
        ->and(rapDemandeLigne($demande->activityId)->subject_id)->toBeNull();
});

// ── C2 (#784) : demande sans SIREN, personne déjà contact actif d'UNE entreprise ──

/** @return array<string, mixed> demande sans SIREN qui déclare AUTRE CHOSE que la fiche. */
function rapDemandeDeclarative(): array
{
    $e = rapDemande();
    $e['company'] = ['name' => 'ZZ AUTRE NOM', 'postcode' => '99999', 'city' => 'ZZVille', 'website' => 'https://zz.example.invalid'];
    $e['person'] = ['person_key' => rapCle(), 'email' => RAP_EMAIL, 'first_name' => 'Zoe', 'last_name' => 'ZZ AUTRE', 'phone' => '0102030405'];

    return $e;
}

/** Ce que la demande n'a pas le droit de changer : les valeurs de la fiche et du contact. */
function rapValeursFiche(int $companyId): array
{
    return [
        (array) DB::table('companies')->where('id', $companyId)->first(['denomination', 'postcode', 'city', 'website', 'relation_type', 'lifecycle_stage', 'legal_basis']),
        DB::table('contacts')->where('company_id', $companyId)->orderBy('id')->get(['id', 'first_name', 'last_name', 'phone', 'email', 'person_key'])->map(fn ($c) => (array) $c)->all(),
    ];
}

test('10. dossier puis demande sans SIREN : rattachée, aucune valeur déclarée écrite sur la fiche', function () {
    rapIngerer(rapDossier());
    $fiche = DB::table('companies')->where('siren', '900000801')->first();
    $contact = DB::table('contacts')->where('company_id', $fiche->id)->first();
    $avant = rapValeursFiche((int) $fiche->id);
    $contactsAvant = DB::table('contacts')->count();

    $issue = rapIngerer(rapDemandeDeclarative());

    $ligne = rapDemandeLigne($issue->activityId);
    $payload = json_decode($ligne->payload, true);
    $personne = rapPersonne();
    expect($issue->status)->toBe(IngestOutcome::UPDATED)
        ->and($issue->subjectType)->toBe('company')
        ->and($issue->subjectId)->toBe($fiche->id)
        ->and($ligne->subject_type)->toBe('company')
        ->and($ligne->subject_id)->toBe($fiche->id)
        ->and($ligne->contact_id)->toBe($contact->id)
        ->and($payload['arbitrage']['attached_by'])->toBe('automatique:personne_connue')
        ->and($payload['arbitrage']['company_id'])->toBe($fiche->id)
        ->and($payload['arbitrage']['contact_id'])->toBe($contact->id)
        ->and($payload['pending_match']['denomination'])->toBe('ZZ AUTRE NOM')
        ->and($personne)->not->toBeNull()
        ->and($personne->contact_id)->toBe($contact->id)
        ->and($personne->company_id)->toBe($fiche->id)
        ->and(DB::table('contacts')->count())->toBe($contactsAvant)
        ->and(rapValeursFiche((int) $fiche->id))->toBe($avant);
});

test('10b. le rattachement ne passe jamais par upsertCompany ni upsertContact', function () {
    rapIngerer(rapDossier());

    DB::enableQueryLog();
    rapIngerer(rapDemandeDeclarative());
    $requetes = array_column(DB::getQueryLog(), 'query');
    DB::disableQueryLog();

    $ecrituresSurFiche = array_filter($requetes, static fn (string $q): bool => preg_match('/^\s*(update|insert into)\s+"(companies|contacts)"/i', $q) === 1);
    expect($ecrituresSurFiche)->toBe([]);
});

test('11. plusieurs entreprises possibles : la demande reste en attente, aucune étiquette ajoutée', function () {
    rapIngerer(rapDossier('900000801', client: '5d1e7c2a-3b4f-4a6d-9c8e-1f2a3b4c5d01'));
    rapIngerer(rapDossier('900000802', client: '5d1e7c2a-3b4f-4a6d-9c8e-1f2a3b4c5d02'));
    expect(DB::table('contacts')->where('person_key', rapCle())->count())->toBe(2);
    $etiquettes = DB::table('company_tag')->count();

    $issue = rapIngerer(rapDemandeDeclarative());

    $ligne = rapDemandeLigne($issue->activityId);
    expect($issue->status)->toBe(IngestOutcome::PENDING_MATCH)
        ->and($issue->subjectId)->toBeNull()
        ->and($ligne->subject_id)->toBeNull()
        ->and($ligne->contact_id)->toBeNull()
        ->and(json_decode($ligne->payload, true))->not->toHaveKey('arbitrage')
        ->and(DB::table('company_tag')->count())->toBe($etiquettes);
});

test('12. contact ou fiche à la corbeille ne compte pas', function () {
    rapIngerer(rapDossier('900000801', client: '5d1e7c2a-3b4f-4a6d-9c8e-1f2a3b4c5d01'));
    rapIngerer(rapDossier('900000802', client: '5d1e7c2a-3b4f-4a6d-9c8e-1f2a3b4c5d02'));
    $ficheA = DB::table('companies')->where('siren', '900000801')->first();
    $ficheB = DB::table('companies')->where('siren', '900000802')->first();
    DB::table('contacts')->where('company_id', $ficheB->id)->update(['deleted_at' => now()]);

    $issue = rapIngerer(rapDemandeDeclarative());
    expect($issue->status)->toBe(IngestOutcome::UPDATED)
        ->and(rapDemandeLigne($issue->activityId)->subject_id)->toBe($ficheA->id);

    DB::table('personnes')->where('person_key', rapCle())->update(['contact_id' => null, 'company_id' => null]);
    DB::table('companies')->where('id', $ficheA->id)->update(['deleted_at' => now()]);
    $suivante = rapIngerer(rapDemandeDeclarative());

    expect($suivante->status)->toBe(IngestOutcome::PENDING_MATCH)
        ->and(rapDemandeLigne($suivante->activityId)->subject_id)->toBeNull();
});

test('14. drapeau des demandes fermé : comportement d avant, aucun verrou ni requête sur personnes', function () {
    config(['crm.ingest.personnes_enabled' => false, 'crm.ingest.demandes_personne_enabled' => false]);
    rapIngerer(rapDossier());

    DB::enableQueryLog();
    $issue = rapIngerer(rapDemandeDeclarative());
    $requetes = array_column(DB::getQueryLog(), 'query');
    DB::disableQueryLog();

    expect($issue->status)->toBe(IngestOutcome::PENDING_MATCH)
        ->and(rapDemandeLigne($issue->activityId)->subject_id)->toBeNull()
        ->and(rapPosition($requetes, 'pg_advisory_xact_lock'))->toBeNull()
        ->and(rapPosition($requetes, '"personnes"'))->toBeNull();
});

test('13. personne mise à l écart : la demande reste en attente', function () {
    rapIngerer(rapDossier());
    rapIngerer(rapDemande());
    DB::table('personnes')->where('person_key', rapCle())->update(['opposition_at' => now(), 'contact_id' => null, 'company_id' => null]);

    $issue = rapIngerer(rapDemandeDeclarative());

    expect($issue->status)->toBe(IngestOutcome::PENDING_MATCH)
        ->and(rapDemandeLigne($issue->activityId)->subject_id)->toBeNull()
        ->and(rapPersonne()->contact_id)->toBeNull();
});
