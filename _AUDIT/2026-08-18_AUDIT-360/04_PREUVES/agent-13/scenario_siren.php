<?php
/** AGENT 13 — ce que produit un événement TEL QUE LE SITE L'ÉMET RÉELLEMENT (sans SIREN). */

use Illuminate\Support\Facades\DB;

// Payload calqué sur src/features/unified-contact/actions.ts:250-274 du site :
// person{email,fullName,phone} + company{name,city,sizeCategory,sector} — PAS de siren.
function reel(array $over = []): array
{
    return array_replace_recursive([
        'schema_version' => 1,
        'event_id' => 'reel-unified-contact-01',
        'event_type' => 'form_submission',
        'form_type' => 'audit',
        'occurred_at' => '2026-08-19T08:00:00.000Z',
        'subject_ref' => 'site:submission:reel-01',
        'person' => [
            'person_key' => str_repeat('7', 64),
            'email' => 'directrice@vraie-entreprise.fr',
            'first_name' => 'Claire', 'last_name' => 'Moreau', 'phone' => '+33612345678',
        ],
        // Exactement ce que le site sait remplir : ni siren, ni postcode.
        'company' => ['name' => 'VRAIE ENTREPRISE SAS', 'city' => 'Lyon', 'size_category' => 'pme', 'sector' => 'services'],
        'consent' => ['version' => 'unified-contact-v1', 'at' => '2026-08-19T08:00:00.000Z', 'text_ref' => 'unified-contact-form'],
        'tags' => [], 'payload' => ['subType' => 'audit-complet'],
    ], $over);
}

echo "\n############ S1. UN LEAD TEL QUE LE SITE L’ÉMET (sans SIREN) ############\n";
show('S1.1 formulaire d’audit, contrat réel du site', post('/api/internal/site-sync', reel()));
dump('S1.1 une société a-t-elle été créée ?', "select count(*) n from companies where denomination='VRAIE ENTREPRISE SAS'");
dump('S1.1 une personne a-t-elle été créée ?', "select count(*) n from contacts where email::text like '%vraie-entreprise%'");
dump('S1.1 l’activité produite', "select id,kind,subject_type,subject_id,title,external_ref from activities where external_ref='site:event:reel-unified-contact-01'");
dump('S1.1 le lead est-il conservé dans pending_match ?', "select jsonb_pretty(payload -> 'pending_match') p from activities where external_ref='site:event:reel-unified-contact-01'");

echo "\n############ S2. VOLUME : 5 leads réels consécutifs ############\n";
foreach (['audit', 'devis', 'formation', 'partenariat', 'support_client'] as $i => $ft) {
    $r = post('/api/internal/site-sync', reel([
        'event_id' => 'reel-lead-' . str_pad((string) $i, 10, '0', STR_PAD_LEFT),
        'subject_ref' => 'site:submission:reel-' . $i,
        'form_type' => $ft,
        'person' => ['person_key' => hash('sha256', 'lead' . $i), 'email' => "lead{$i}@societe{$i}.fr", 'first_name' => 'P' . $i, 'last_name' => 'Nom' . $i],
        'company' => ['name' => "SOCIETE {$i} SAS"],
    ]));
    echo "  {$ft} → HTTP {$r['status']} " . $r['body'] . "\n";
}
dump('S2 sociétés créées par ces 6 leads', "select count(*) n from companies where denomination like 'SOCIETE %' or denomination='VRAIE ENTREPRISE SAS'");
dump('S2 personnes créées', "select count(*) n from contacts where email::text like '%societe%' or email::text like '%vraie-entreprise%'");
dump('S2 événements en attente d’arbitrage HUMAIN', "select count(*) n from activities where payload -> 'pending_match' is not null");
dump('S2 statut renvoyé au site', "select payload->>'subject_ref' ref, subject_type, subject_id from activities where payload -> 'pending_match' is not null order by id");

echo "\n############ S3. L’ÉCRAN D’ARBITRAGE EST-IL ACCESSIBLE ? ############\n";
echo "crm.console_v2 (drapeau) = " . var_export(config('crm.console_v2'), true) . "\n";
echo "CRM_CONSOLE_V2_ENABLED (env conteneur) = " . var_export(getenv('CRM_CONSOLE_V2_ENABLED'), true) . "\n";
$req = Illuminate\Http\Request::create('/api/v1/crm/arbitrage', 'GET', [], [], [], ['HTTP_ACCEPT' => 'application/json']);
$resp = $kernel->handle($req);
echo "GET /api/v1/crm/arbitrage (sans authentification) → HTTP " . $resp->getStatusCode() . "\n";
echo substr((string) $resp->getContent(), 0, 300) . "\n";
