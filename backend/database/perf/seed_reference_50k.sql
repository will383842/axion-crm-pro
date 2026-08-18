-- Jeu de données de RÉFÉRENCE pour la mesure de performance (étape 0, ligne 11 / F5).
-- Volume de référence du cahier des charges §0.9 : 50 000 fiches actives, 5 ans d'historique.
-- + 250 000 fiches froides (collecte) pour que le filtre de température travaille.
-- Synthétique, reproductible (setseed), workspace business local uniquement.
\set ws '20cd81e4-de5d-4875-a759-07d64fe1f168'
BEGIN;
SELECT setseed(0.42);

-- 1) 250 000 fiches FROIDES : lifecycle 'nouveau', une seule source src:scraping-*
INSERT INTO companies (workspace_id, siren, denomination, postcode, city, department_code, region_code,
                       relation_type, lifecycle_stage, created_at, updated_at, prospection_status)
SELECT :'ws'::uuid,
       lpad((100000000 + g)::text, 9, '0'),
       'Société froide ' || g,
       lpad(((g % 95) + 1)::text, 2, '0') || lpad((g % 900)::text, 3, '0'),
       'Ville ' || (g % 5000),
       lpad(((g % 95) + 1)::text, 2, '0'),
       lpad(((g % 13) + 1)::text, 2, '0'),
       'prospect', 'nouveau',
       now() - (random() * interval '1825 days'),
       now() - (random() * interval '365 days'),
       'pending'
FROM generate_series(1, 250000) g;

INSERT INTO company_tag (company_id, tag_id)
SELECT c.id, (ARRAY[41,42,43,44,45,46,47])[1 + (c.id % 7)::int]
FROM companies c WHERE c.workspace_id = :'ws'::uuid AND c.denomination LIKE 'Société froide %';

-- 2) 50 000 fiches ACTIVES : types et étapes variés, source humaine (site / calendly / newsletter / avis)
INSERT INTO companies (workspace_id, siren, denomination, postcode, city, department_code, region_code,
                       relation_type, lifecycle_stage, created_at, updated_at, prospection_status, website, phone)
SELECT :'ws'::uuid,
       lpad((500000000 + g)::text, 9, '0'),
       (ARRAY['Atelier','Cabinet','Groupe','Studio','Clinique','Maison','Agence','Institut'])[1 + (g % 8)] || ' ' ||
         (ARRAY['Durand','Martin','Bernard','Petit','Robert','Richard','Moreau','Simon','Laurent','Lefebvre','Michel','Garcia','David','Bertrand','Roux','Vincent'])[1 + (g % 16)] || ' ' || g,
       lpad(((g % 95) + 1)::text, 2, '0') || lpad((g % 900)::text, 3, '0'),
       'Ville ' || (g % 5000),
       lpad(((g % 95) + 1)::text, 2, '0'),
       lpad(((g % 13) + 1)::text, 2, '0'),
       (ARRAY['prospect','prospect','prospect','client','client','presse_media','partenaire','investisseur','conference','newsletter','fournisseur'])[1 + (g % 11)],
       (ARRAY['nouveau','qualifie','qualifie','opportunite','client','client','dormant','perdu'])[1 + (g % 8)],
       now() - (random() * interval '1825 days'),
       now() - (random() * interval '180 days'),
       'pending',
       'https://exemple-' || g || '.fr',
       '0' || (600000000 + g)::text
FROM generate_series(1, 50000) g;

-- source humaine : formulaire (15..22 site), calendly 29, newsletter 30, avis 32, chatbot 31
INSERT INTO company_tag (company_id, tag_id)
SELECT c.id, (ARRAY[15,16,17,18,19,20,21,22,29,29,30,32,31])[1 + (c.id % 13)::int]
FROM companies c WHERE c.workspace_id = :'ws'::uuid AND c.siren >= '500000000';

-- 3) 1 contact par fiche active (50 000), person_key = sha256 d'un e-mail synthétique
INSERT INTO contacts (workspace_id, company_id, first_name, last_name, email, role, person_key, created_at, updated_at)
SELECT :'ws'::uuid, c.id,
       (ARRAY['Anne','Paul','Julie','Marc','Léa','Hugo','Chloé','Louis','Emma','Nathan'])[1 + (c.id % 10)::int],
       (ARRAY['Durand','Martin','Bernard','Petit','Robert','Richard','Moreau','Simon','Laurent','Lefebvre'])[1 + (c.id % 10)::int],
       ('contact' || c.id || '@exemple-' || c.id || '.fr')::citext,
       (ARRAY['Dirigeant','DRH','DAF','Responsable formation','Chef de projet'])[1 + (c.id % 5)::int],
       encode(sha256(('contact' || c.id || '@exemple-' || c.id || '.fr')::bytea), 'hex'),
       c.created_at, c.updated_at
FROM companies c WHERE c.workspace_id = :'ws'::uuid AND c.siren >= '500000000';

-- 4) ~500 000 activités sur 5 ans (10 par fiche active en moyenne), timeline unifiée
INSERT INTO activities (workspace_id, contact_id, type, kind, person_key, occurred_at, created_at, title, subject_type, subject_id, payload)
SELECT :'ws'::uuid, ct.id,
       'timeline',
       (ARRAY['form_submission','calendly_booked','calendly_completed','calendly_no_show','review_posted','newsletter_optin','stage_changed','form_submission','calendly_booked','stage_changed'])[1 + (s % 10)::int],
       ct.person_key,
       ct.created_at + (random() * (now() - ct.created_at)),
       now(),
       'Événement ' || s,
       'contact', ct.id,
       '{"source":"seed-perf"}'::jsonb
FROM contacts ct
CROSS JOIN generate_series(1, 10) s
WHERE ct.workspace_id = :'ws'::uuid;

COMMIT;
ANALYZE companies; ANALYZE contacts; ANALYZE activities; ANALYZE company_tag;
SELECT (SELECT count(*) FROM companies) AS companies, (SELECT count(*) FROM contacts) AS contacts, (SELECT count(*) FROM activities) AS activities, (SELECT count(*) FROM company_tag) AS company_tags;
