<?php
/** AGENT 13 — les deux témoins négatifs qu'il me manquait, joués. */

echo "\n############ T1. TÉMOIN — un namespace de tag NON gouverné est-il refusé ? ############\n";
$base = [
    'schema_version' => 1, 'event_id' => 'temoin-tag-000000001', 'event_type' => 'form_submission',
    'form_type' => 'audit', 'occurred_at' => '2026-08-17T10:00:00.000Z',
    'subject_ref' => 'site:submission:temoin-tag',
    'person' => ['person_key' => str_repeat('1', 64), 'email' => 't@example.com', 'first_name' => 'T', 'last_name' => 'Emoin'],
    'company' => ['siren' => '823456789', 'name' => 'TEMOIN SAS'],
    'consent' => ['version' => 'site-v1', 'at' => '2026-08-17T09:59:00.000Z'],
    'tags' => ['toto:inconnu'], 'payload' => [],
];
show('T1.1 tag « toto:inconnu » — namespace HORS référentiel gouverné', post('/api/internal/site-sync', $base));
$base['tags'] = ['taille:micro'];
$base['event_id'] = 'temoin-tag-000000002';
$base['subject_ref'] = 'site:submission:temoin-tag2';
show('T1.2 tag « taille:micro » — namespace gouverné, valeur hors référentiel', post('/api/internal/site-sync', $base));

echo "\n############ T2. TÉMOIN — la sonde d’écart sait-elle rendre ZÉRO ? ############\n";
dump('T2.1 colonnes écrites par POSTGRES lui-même (created_at DEFAULT now())',
    "select external_ref,
            extract(epoch from (created_at - now())) as ecart_created_at_vs_now,
            extract(epoch from (occurred_at - timestamptz '2026-08-17T10:00:00Z')) as ecart_occurred_at
     from activities where external_ref='site:event:audit13-0001'");
dump('T2.2 la même sonde sur une valeur volontairement JUSTE',
    "select extract(epoch from (timestamptz '2026-08-17T10:00:00Z' - timestamptz '2026-08-17T10:00:00Z')) as doit_valoir_zero");
dump('T2.3 fuseau de la session Postgres vue par l’application', "show TimeZone");
