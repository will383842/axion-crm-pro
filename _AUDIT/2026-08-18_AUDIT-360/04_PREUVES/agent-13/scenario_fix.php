<?php
/** AGENT 13 — reprise des cellules polluées (event_id trop court) + tests isolés. */

use Illuminate\Support\Facades\DB;

function evt(array $over = []): array
{
    return array_replace_recursive([
        'schema_version' => 1,
        'event_id' => 'audit13-fix-00000001',
        'event_type' => 'form_submission',
        'form_type' => 'audit',
        'occurred_at' => '2026-08-17T10:00:00.000Z',
        'subject_ref' => 'site:submission:fix-1',
        'person' => [
            'person_key' => str_repeat('a', 64), 'email' => 'Jean.Dupont@Example.COM',
            'first_name' => 'Jean', 'last_name' => 'Dupont', 'phone' => '+33600000001',
        ],
        'company' => ['siren' => '123456789', 'name' => 'ACME SAS', 'postcode' => '75001', 'city' => 'Paris'],
        'consent' => ['version' => 'site-v1', 'at' => '2026-08-17T09:59:00.000Z', 'text_ref' => 'contact-v1'],
        'tags' => [], 'payload' => ['message' => 'bonjour'],
    ], $over);
}
$S = 'audit13-secret-local';

echo "\n############ R4. ÉVÉNEMENT / VOCABULAIRE INCONNU (reprise) ############\n";
show('R4.1 event_type inconnu « enrollment_created » (inscription à une session)', post('/api/internal/site-sync', evt([
    'event_id' => 'fix-r41-enrollment-001', 'subject_ref' => 'site:enrollment:r41', 'event_type' => 'enrollment_created'])));
show('R4.2 form_type inconnu « webinaire »', post('/api/internal/site-sync', evt([
    'event_id' => 'fix-r42-webinaire-001', 'subject_ref' => 'site:submission:r42', 'form_type' => 'webinaire'])));
show('R4.3 source_slug NON gouverné « qualiopi-portail »', post('/api/internal/site-sync', evt([
    'event_id' => 'fix-r43-slug-00000001', 'subject_ref' => 'site:submission:r43', 'source_slug' => 'qualiopi-portail',
    'company' => ['siren' => '323456789', 'name' => 'GAMMA SAS']])));
dump('R4.3 le tag src:qualiopi-portail a-t-il été créé ?', "select count(*) n from tags where slug='src:qualiopi-portail'");
dump('R4.3 tags réellement posés sur GAMMA', "select coalesce(string_agg(t.slug,','),'(AUCUN)') tags from company_tag ct join tags t on t.id=ct.tag_id join companies co on co.id=ct.company_id where co.siren='323456789'");
show('R4.4 tags gouvernés : « taille:micro » (hors référentiel) + « taille:pme » (dans le référentiel)', post('/api/internal/site-sync', evt([
    'event_id' => 'fix-r44-tags-00000001', 'subject_ref' => 'site:submission:r44', 'tags' => ['taille:micro', 'taille:pme'],
    'company' => ['siren' => '423456789', 'name' => 'DELTA SAS']])));
dump('R4.4 tags réellement posés sur DELTA', "select coalesce(string_agg(t.slug,','),'(AUCUN)') tags from company_tag ct join tags t on t.id=ct.tag_id join companies co on co.id=ct.company_id where co.siren='423456789'");
show('R4.5 signature préfixée « sha256=… »', post('/api/internal/site-sync', evt(['event_id' => 'fix-r45-sha256-00001', 'subject_ref' => 'site:submission:r45']), 'sha256=' . hash_hmac('sha256', 'X', 'Y')));

echo "\n############ R2. REJEU DANS LA FENÊTRE (reprise) ############\n";
$e = evt(['event_id' => 'fix-r2-fenetre-000001', 'subject_ref' => 'site:submission:r2f']);
$b = json_encode($e, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
$t = (string) (time() - 290);
show('R2.1 horodatage -290 s (DANS la fenêtre de 300 s)', post('/api/internal/site-sync', $e, hash_hmac('sha256', $t . '.' . $b, $S), $t));

echo "\n############ R3. DÉDUPLICATION (reprise, cas isolés) ############\n";
show('R3.1 MÊME e-mail, person_key DIFFÉRENT, autre subject_ref', post('/api/internal/site-sync', evt([
    'event_id' => 'fix-r31-memeemail-01', 'subject_ref' => 'site:submission:r31',
    'person' => ['person_key' => str_repeat('b', 64), 'email' => 'JEAN.DUPONT@EXAMPLE.COM', 'first_name' => 'J.', 'last_name' => 'Dupont']])));
dump('R3.1 fiches Dupont', "select id,first_name,last_name,email,person_key from contacts where last_name='Dupont'");

show('R3.2 person_key en MAJUSCULES hexadécimales', post('/api/internal/site-sync', evt([
    'event_id' => 'fix-r32-majuscule-01', 'subject_ref' => 'site:submission:r32',
    'person' => ['person_key' => strtoupper(str_repeat('a', 64))]])));

// PIÈGE 10 ISOLÉ : seule la voie E-MAIL peut rapprocher (nom et entreprise différents)
$ws = DB::table('workspaces')->where('slug', 'axion-ia')->value('id');
$idCo = DB::table('companies')->insertGetId(['workspace_id' => $ws, 'siren' => '523456789', 'denomination' => 'EPSILON',
    'discovery_source' => 'scraping', 'quality_score' => 0, 'signals' => '{}', 'metadata' => '{}',
    'relation_type' => 'prospect', 'lifecycle_stage' => 'nouveau', 'legal_basis' => 'legitimate_interest_b2b',
    'created_at' => now(), 'updated_at' => now()]);
DB::table('contacts')->insert(['workspace_id' => $ws, 'company_id' => $idCo,
    'first_name' => 'Zoé', 'last_name' => 'Ancienne', 'email' => 'ZOÉ.TEST@example.com',
    'discovery_source' => 'scraping', 'sources' => '["scraping"]', 'metadata' => '{}',
    'legal_basis' => 'legitimate_interest_b2b', 'created_at' => now(), 'updated_at' => now()]);
DB::table('contacts')->insert(['workspace_id' => $ws, 'company_id' => $idCo,
    'first_name' => 'Ana', 'last_name' => 'Temoin', 'email' => 'ANA.TEST@example.com',
    'discovery_source' => 'scraping', 'sources' => '["scraping"]', 'metadata' => '{}',
    'legal_basis' => 'legitimate_interest_b2b', 'created_at' => now(), 'updated_at' => now()]);
dump('R3.3 état AVANT', "select id,first_name,last_name,email from contacts where company_id={$idCo} order by id");
show('R3.3 PIÈGE 10 — e-mail ACCENTUÉ majuscule en base, même personne ingérée (nom DIFFÉRENT)', post('/api/internal/site-sync', evt([
    'event_id' => 'fix-r33-accent-00001', 'subject_ref' => 'site:submission:r33',
    'company' => ['siren' => '523456789', 'name' => 'EPSILON'],
    'person' => ['person_key' => str_repeat('e', 64), 'email' => 'Zoé.Test@example.com', 'first_name' => 'Zoé', 'last_name' => 'Nouveau']])));
show('R3.4 TÉMOIN — e-mail ASCII majuscule en base, même personne (nom DIFFÉRENT)', post('/api/internal/site-sync', evt([
    'event_id' => 'fix-r34-ascii-000001', 'subject_ref' => 'site:submission:r34',
    'company' => ['siren' => '523456789', 'name' => 'EPSILON'],
    'person' => ['person_key' => str_repeat('f', 64), 'email' => 'Ana.Test@example.com', 'first_name' => 'Ana', 'last_name' => 'Nouveau']])));
dump('R3.3/R3.4 état APRÈS (accentué = doublon ? ASCII = fusion ?)', "select id,first_name,last_name,email,person_key,discovery_source from contacts where company_id={$idCo} order by id");

echo "\n############ R6. PERTE DE LA PERSONNE SANS NOM ############\n";
show('R6.1 SIREN présent, e-mail présent, nom de famille ABSENT', post('/api/internal/site-sync', evt([
    'event_id' => 'fix-r61-sansnom-0001', 'subject_ref' => 'site:submission:r61',
    'company' => ['siren' => '623456789', 'name' => 'ZETA SAS'],
    'person' => ['person_key' => str_repeat('9', 64), 'email' => 'perdu@example.com', 'phone' => '+33611111111', 'first_name' => 'Marie', 'last_name' => null]])));
dump('R6.1 contacts de ZETA', "select coalesce(string_agg(c.last_name,','),'(AUCUN CONTACT)') as contacts from contacts c join companies co on co.id=c.company_id where co.siren='623456789'");
dump('R6.1 l’e-mail survit-il quelque part dans l’activité ?', "select external_ref, payload::text from activities where external_ref='site:event:fix-r61-sansnom-0001'");
dump('R6.1 « perdu@example.com » existe-t-il ENCORE dans la base ?', "select (select count(*) from contacts where email::text ilike '%perdu@example.com%') c, (select count(*) from activities where payload::text ilike '%perdu@example.com%') a");

echo "\n############ R8. WORKSPACE DE DESTINATION ABSENT ############\n";
DB::table('workspaces')->where('slug', 'axion-ia')->update(['slug' => 'axion-ia-masque']);
show('R8.1 événement business, workspace « axion-ia » introuvable', post('/api/internal/site-sync', evt([
    'event_id' => 'fix-r81-nows-0000001', 'subject_ref' => 'site:submission:r81',
    'company' => ['siren' => '723456789', 'name' => 'OMEGA SAS']])));
dump('R8.1 quelque chose a-t-il été écrit ?', "select (select count(*) from companies where siren='723456789') c, (select count(*) from activities where external_ref='site:event:fix-r81-nows-0000001') a");
DB::table('workspaces')->where('slug', 'axion-ia-masque')->update(['slug' => 'axion-ia']);
echo "(workspace restauré)\n";

echo "\n############ R7. JOURNAL — que reste-t-il d’un REFUS ? ############\n";
dump('R7 audit_logs des appels site-sync', "select method, path, status, count(*) n from audit_logs where path like '%site-sync%' group by 1,2,3 order by 3");
dump('R7 le CONTENU d’un événement refusé est-il stocké ?', "select count(*) n from audit_logs where path like '%site-sync%' and status >= 400");
dump('R7 colonnes d’audit_logs', "select string_agg(column_name, ', ' order by ordinal_position) cols from information_schema.columns where table_name='audit_logs'");
dump('R7 activités par kind', "select kind, count(*) n from activities group by 1 order by 2 desc");
dump('R7 arbitrage en attente (pending_match)', "select count(*) n from activities where payload -> 'pending_match' is not null");
