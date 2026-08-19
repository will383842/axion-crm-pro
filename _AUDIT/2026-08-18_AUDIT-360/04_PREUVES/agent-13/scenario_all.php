<?php
/** AGENT 13 — scénario complet du canal entrant. Base jetable axion_crm_audit13. */

use Illuminate\Support\Facades\DB;

function evt(array $over = []): array
{
    return array_replace_recursive([
        'schema_version' => 1,
        'event_id' => 'audit13-0001',
        'event_type' => 'form_submission',
        'form_type' => 'audit',
        'occurred_at' => '2026-08-17T10:00:00.000Z',
        'subject_ref' => 'site:submission:audit13-0001',
        'person' => [
            'person_key' => str_repeat('a', 64),
            'email' => 'Jean.Dupont@Example.COM',
            'first_name' => 'Jean',
            'last_name' => 'Dupont',
            'phone' => '+33600000001',
        ],
        'company' => ['siren' => '123456789', 'name' => 'ACME SAS', 'postcode' => '75001', 'city' => 'Paris'],
        'consent' => ['version' => 'site-v1', 'at' => '2026-08-17T09:59:00.000Z', 'text_ref' => 'contact-v1'],
        'tags' => [],
        'payload' => ['message' => 'bonjour'],
    ], $over);
}

$S = 'audit13-secret-local';

// ═══════════ POINT 8 — workspace de destination absent ═══════════
echo "\n############ P8. WORKSPACE BUSINESS ABSENT ############\n";
dump('workspaces', "select slug from workspaces order by 1");
show('P8.1 événement business, workspace « axion-ia » absent', post('/api/internal/site-sync', evt(['event_id' => 'p8-0001', 'subject_ref' => 'site:submission:p8-0001'])));
dump('P8.1 écritures', "select (select count(*) from companies) c, (select count(*) from activities) a, (select count(*) from contacts) k");
DB::table('workspaces')->insertOrIgnore(['slug' => 'axion-ia', 'name' => 'Axion IA']);
echo "\n(workspace axion-ia créé)\n";

// ═══════════ POINT 1 — SIGNATURE ═══════════
echo "\n############ P1. SIGNATURE ############\n";
show('P1.1 TÉMOIN POSITIF — signature correcte', post('/api/internal/site-sync', evt()));
dump('P1.1 société', "select id,siren,denomination,relation_type,lifecycle_stage,legal_basis,consent_version from companies");
dump('P1.1 contact', "select id,first_name,last_name,email,person_key,legal_basis from contacts");
dump('P1.1 activité', "select id,kind,type,occurred_at,external_ref,person_key,title,subject_type,subject_id from activities");
dump('P1.1 tags posés', "select t.slug from company_tag ct join tags t on t.id=ct.tag_id");

show('P1.2 TÉMOIN NÉGATIF — signature falsifiée', post('/api/internal/site-sync', evt(['event_id' => 'p1-bad-1', 'subject_ref' => 'site:submission:p1-bad-1']), str_repeat('0', 64)));
show('P1.3 TÉMOIN NÉGATIF — aucun en-tête de signature', post('/api/internal/site-sync', evt(['event_id' => 'p1-bad-2', 'subject_ref' => 'site:submission:p1-bad-2']), null, null, true));

$b = json_encode(evt(['event_id' => 'p1-alt', 'subject_ref' => 'site:submission:p1-alt']), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
$t = (string) time();
$sig = hash_hmac('sha256', $t . '.' . $b, $S);
show('P1.4 corps ALTÉRÉ après signature (le corps est-il signé ?)',
    post('/api/internal/site-sync', evt(['event_id' => 'p1-alt', 'subject_ref' => 'site:submission:p1-alt', 'company' => ['siren' => '999999999']]), $sig, $t));
show('P1.5 horodatage ALTÉRÉ après signature (l’horodatage est-il signé ?)',
    post('/api/internal/site-sync', evt(['event_id' => 'p1-alt', 'subject_ref' => 'site:submission:p1-alt']), $sig, (string) ((int) $t - 1)));
show('P1.6 préfixe « sha256= » accepté ?',
    post('/api/internal/site-sync', evt(['event_id' => 'p1-pfx', 'subject_ref' => 'site:submission:p1-pfx']), null, null, false));
dump('P1 état après tentatives invalides', "select count(*) n from activities where external_ref like 'site:event:p1-bad%' or external_ref='site:event:p1-alt'");

// ═══════════ POINT 2 — REJEU / FENÊTRE / NONCE ═══════════
echo "\n############ P2. REJEU ############\n";
$r1 = post('/api/internal/site-sync', evt(['event_id' => 'p2-rejeu', 'subject_ref' => 'site:submission:p2-rejeu']));
show('P2.1 première ingestion', $r1);
global $kernel;
$req = Illuminate\Http\Request::create('/api/internal/site-sync', 'POST', [], [], [], [
    'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
    'HTTP_X_SITE_TIMESTAMP' => $r1['ts'],
    'HTTP_X_SITE_SIGNATURE' => hash_hmac('sha256', $r1['ts'] . '.' . $r1['sent_json'], $S),
], $r1['sent_json']);
$resp = $kernel->handle($req);
show('P2.2 REJEU OCTET POUR OCTET (mêmes en-têtes, même signature)', ['status' => $resp->getStatusCode(), 'body' => $resp->getContent(), 'sent_json' => '', 'ts' => '']);
dump('P2 activités pour cet event_id', "select count(*) n from activities where external_ref='site:event:p2-rejeu'");
dump('P2 sociétés', "select count(*) n from companies");

$old = (string) (time() - 400);
$bo = json_encode(evt(['event_id' => 'p2-stale', 'subject_ref' => 'site:submission:p2-stale']), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
show('P2.3 horodatage -400 s, signature correcte', post('/api/internal/site-sync', evt(['event_id' => 'p2-stale', 'subject_ref' => 'site:submission:p2-stale']), hash_hmac('sha256', $old . '.' . $bo, $S), $old));
$fu = (string) (time() + 400);
$bf = json_encode(evt(['event_id' => 'p2-fut', 'subject_ref' => 'site:submission:p2-fut']), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
show('P2.4 horodatage +400 s, signature correcte', post('/api/internal/site-sync', evt(['event_id' => 'p2-fut', 'subject_ref' => 'site:submission:p2-fut']), hash_hmac('sha256', $fu . '.' . $bf, $S), $fu));
$lim = (string) (time() - 290);
$bl = json_encode(evt(['event_id' => 'p2-lim', 'subject_ref' => 'site:submission:p2-lim']), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
show('P2.5 horodatage -290 s (DANS la fenêtre) : rejeu toléré ?', post('/api/internal/site-sync', evt(['event_id' => 'p2-lim', 'subject_ref' => 'site:submission:p2-lim']), hash_hmac('sha256', $lim . '.' . $bl, $S), $lim));
$rc = new ReflectionClass(App\Crm\Ingest\SiteSyncEvent::class);
echo "\nNONCE — clés acceptées à la racine du contrat : " . json_encode($rc->getConstant('TOP_LEVEL_KEYS') ?: 'privée') . "\n";
echo "EVENT_TYPES acceptés (" . count($rc->getConstant('EVENT_TYPES')) . ") : " . json_encode($rc->getConstant('EVENT_TYPES')) . "\n";
echo "ACTIVITY_KINDS du vocabulaire fermé (" . count(App\Crm\Taxonomy::ACTIVITY_KINDS) . ") : " . json_encode(App\Crm\Taxonomy::ACTIVITY_KINDS) . "\n";
echo "EVENT_TYPES absents de ACTIVITY_KINDS : " . json_encode(array_values(array_diff($rc->getConstant('EVENT_TYPES'), App\Crm\Taxonomy::ACTIVITY_KINDS))) . "\n";
dump('table de nonce / anti-rejeu en base ?', "select table_name from information_schema.tables where table_schema='public' and (table_name ilike '%nonce%' or table_name ilike '%replay%' or table_name ilike '%rejet%' or table_name ilike '%reject%' or table_name ilike '%dead%' or table_name ilike '%outbox%')");

// ═══════════ POINT 3 — IDEMPOTENCE / DÉDUPLICATION ═══════════
echo "\n############ P3. DÉDUPLICATION PERSONNE ############\n";
show('P3.1 MÊME personne, AUTRE événement, AUTRE source (calendly)', post('/api/internal/site-sync', evt([
    'event_id' => 'p3-autre', 'event_type' => 'calendly_booked', 'form_type' => null,
    'subject_ref' => 'site:calendly_event:p3-autre', 'source_slug' => 'calendly',
])));
dump('P3.1 contacts', "select id,first_name,last_name,email,person_key,company_id from contacts");

show('P3.2 MÊME e-mail, person_key DIFFÉRENT, subject_ref différent', post('/api/internal/site-sync', evt([
    'event_id' => 'p3-key2', 'subject_ref' => 'site:submission:p3-key2',
    'person' => ['person_key' => str_repeat('b', 64), 'email' => 'JEAN.DUPONT@EXAMPLE.COM'],
])));
dump('P3.2 contacts', "select id,first_name,last_name,email,person_key from contacts");

show('P3.3 person_key en MAJUSCULES hexadécimales (casse de la clé)', post('/api/internal/site-sync', evt([
    'event_id' => 'p3-maj', 'subject_ref' => 'site:submission:p3-maj',
    'person' => ['person_key' => strtoupper(str_repeat('a', 64))],
])));

show('P3.4 person_key ABSENT', post('/api/internal/site-sync', evt([
    'event_id' => 'p3-nokey', 'subject_ref' => 'site:submission:p3-nokey', 'person' => ['person_key' => null],
])));

// PIÈGE 10 — une fiche préexistante dont l'e-mail porte une MAJUSCULE ACCENTUÉE
DB::table('contacts')->insert([
    'workspace_id' => DB::table('workspaces')->where('slug', 'axion-ia')->value('id'),
    'company_id' => DB::table('companies')->value('id'),
    'first_name' => 'José', 'last_name' => 'Martin',
    'email' => 'JOSÉ.MARTIN@example.com',
    'discovery_source' => 'scraping', 'sources' => '["scraping"]', 'metadata' => '{}',
    'legal_basis' => 'legitimate_interest_b2b', 'created_at' => now(), 'updated_at' => now(),
]);
show('P3.5 PIÈGE 10 — même personne, e-mail à MAJUSCULE ACCENTUÉE déjà en base', post('/api/internal/site-sync', evt([
    'event_id' => 'p3-accent', 'subject_ref' => 'site:submission:p3-accent',
    'person' => ['person_key' => str_repeat('c', 64), 'email' => 'José.Martin@example.com', 'first_name' => 'José', 'last_name' => 'Martin'],
])));
dump('P3.5 combien de fiches « Martin » ?', "select id,first_name,last_name,email,person_key,discovery_source from contacts where last_name='Martin'");

show('P3.6 personne SANS nom de famille', post('/api/internal/site-sync', evt([
    'event_id' => 'p3-noname', 'subject_ref' => 'site:submission:p3-noname',
    'company' => ['siren' => '223456789', 'name' => 'BETA SAS'],
    'person' => ['person_key' => str_repeat('d', 64), 'email' => 'anonyme@example.com', 'first_name' => null, 'last_name' => null],
])));
dump('P3.6 contacts de BETA', "select c.id,c.last_name from contacts c join companies co on co.id=c.company_id where co.siren='223456789'");

// ═══════════ POINT 4 — CLASSEMENT / ÉVÉNEMENT INCONNU ═══════════
echo "\n############ P4. ÉVÉNEMENT INCONNU ############\n";
show('P4.1 event_type inconnu « enrollment_created »', post('/api/internal/site-sync', evt(['event_id' => 'p4-unk', 'subject_ref' => 'site:enrollment:p4-unk', 'event_type' => 'enrollment_created'])));
show('P4.2 form_type inconnu « webinaire »', post('/api/internal/site-sync', evt(['event_id' => 'p4-unkf', 'subject_ref' => 'site:submission:p4-unkf', 'form_type' => 'webinaire'])));
show('P4.3 champ racine inconnu « session_id »', post('/api/internal/site-sync', array_merge(evt(['event_id' => 'p4-unkk', 'subject_ref' => 'site:submission:p4-unkk']), ['session_id' => 42])));
show('P4.4 schema_version absent', post('/api/internal/site-sync', array_diff_key(evt(['event_id' => 'p4-nov', 'subject_ref' => 'site:submission:p4-nov']), ['schema_version' => 1])));
show('P4.5 schema_version = 2', post('/api/internal/site-sync', evt(['event_id' => 'p4-v2', 'subject_ref' => 'site:submission:p4-v2', 'schema_version' => 2])));
show('P4.6 source_slug non gouverné « qualiopi-portail »', post('/api/internal/site-sync', evt([
    'event_id' => 'p4-slug', 'subject_ref' => 'site:submission:p4-slug', 'source_slug' => 'qualiopi-portail',
    'company' => ['siren' => '323456789', 'name' => 'GAMMA SAS'],
])));
dump('P4.6 le tag src:qualiopi-portail existe-t-il ?', "select count(*) n from tags where slug='src:qualiopi-portail'");
dump('P4.6 tags de GAMMA', "select t.slug from company_tag ct join tags t on t.id=ct.tag_id join companies co on co.id=ct.company_id where co.siren='323456789'");
show('P4.7 tag gouverné MAIS hors référentiel « taille:micro »', post('/api/internal/site-sync', evt([
    'event_id' => 'p4-tag', 'subject_ref' => 'site:submission:p4-tag', 'tags' => ['taille:micro', 'taille:pme'],
    'company' => ['siren' => '423456789', 'name' => 'DELTA SAS'],
])));
dump('P4.7 tags de DELTA', "select t.slug from company_tag ct join tags t on t.id=ct.tag_id join companies co on co.id=ct.company_id where co.siren='423456789'");

// ═══════════ POINT 5 — HORODATAGE UTC ═══════════
echo "\n############ P5. HORODATAGE UTC ############\n";
dump('P5 occurred_at émis « 2026-08-17T10:00:00.000Z »', "select external_ref, occurred_at, occurred_at at time zone 'UTC' as en_utc, extract(epoch from (occurred_at - timestamptz '2026-08-17T10:00:00Z')) as ecart_secondes from activities where external_ref='site:event:audit13-0001'");
dump('P5 consent_at émis « 2026-08-17T09:59:00.000Z »', "select siren, consent_at, extract(epoch from (consent_at - timestamptz '2026-08-17T09:59:00Z')) as ecart_secondes from companies where siren='123456789'");
echo "app.timezone = " . config('app.timezone') . "\n";

// ═══════════ POINT 9 — CONSENTEMENT CANDIDATS ═══════════
echo "\n############ P9. CONSENTEMENT CANDIDATS ############\n";
foreach ([
    'careers-v1-2026-01-01' => 'v1 (ancien texte)',
    'careers-v2-2026-08-13' => 'v2 attendu',
    'vivier-stock-2026-08-14' => 'stock J+30',
    null => 'aucun consentement',
] as $v => $label) {
    $id = 'p9-' . substr(md5((string) $v), 0, 8);
    show("P9 candidature — consent.version = " . var_export($v, true) . " ({$label})", post('/api/internal/site-sync', evt([
        'event_id' => $id, 'event_type' => 'application_submitted', 'form_type' => null,
        'subject_ref' => 'site:job_application:' . $id,
        'person' => ['person_key' => hash('sha256', $id), 'email' => $id . '@example.com', 'first_name' => 'Cand', 'last_name' => 'Idat'],
        'company' => ['siren' => null, 'name' => null, 'postcode' => null, 'city' => null],
        'candidate' => ['family' => 'candidat_tech', 'offer_slug' => 'dev-php'],
        'consent' => ['version' => $v, 'at' => '2026-08-17T09:00:00.000Z', 'vivier_at' => '2026-08-17T09:00:00.000Z', 'text_ref' => 'careers'],
    ])));
}
dump('P9 candidats réellement créés', "select id,last_name,email,relation_type,legal_basis,consent_version from candidates");

// ═══════════ POINT 6 & 7 — REJETS & JOURNAL ═══════════
echo "\n############ P6/P7. REJETS ET JOURNAL ############\n";
dump('P6 toute trace persistée d’un événement REFUSÉ ?', "select count(*) n from activities where payload::text ilike '%p4-unk%' or external_ref ilike '%p4-%'");
dump('P7 journal d’audit (audit_logs) alimenté par l’ingestion ?', "select count(*) n from audit_logs");
dump('P7 activités totales', "select count(*) n, count(distinct kind) kinds from activities");
dump('P7 arbitrage (pending_match)', "select id,external_ref,subject_type,subject_id, payload->'pending_match'->>'denomination' as denom from activities where payload ? 'pending_match'");
