// Construit un JSONL pivot qui BACKFILL le site web des 100 maisons-mères
// déjà en base, depuis l'annuaire CCIFER (le funnel est backfill-only : un
// site déjà renseigné n'est jamais écrasé).
import { readFileSync, writeFileSync } from 'node:fs';

const report = JSON.parse(readFileSync('resolution-report.json', 'utf8'));

const norm = (s) => s.toUpperCase()
  .normalize('NFD').replace(/[̀-ͯ]/g, '')
  .replace(/[^A-Z0-9]/g, '');

// nom CCIFER normalisé → { website, sector, city }
const byName = new Map();
for (const line of readFileSync('ccifer-full.txt', 'utf8').split('\n')) {
  const t = line.trim();
  if (!t) continue;
  const [name, website, sector, city] = t.split('|');
  byName.set(norm(name), {
    website: website && website !== '-' ? website.trim() : null,
    sector: (sector || '').trim(),
    city: (city || '').trim(),
  });
}

const out = [];
let withSite = 0;
for (const m of report.matched_list) {
  // Une maison-mère peut porter PLUSIEURS entités roumaines (Renault ←
  // Dacia + Renault Commercial) : on prend le premier site trouvé.
  let picked = null;
  for (const roName of m.ro) {
    const hit = byName.get(norm(roName));
    if (hit?.website) { picked = { ...hit, roName }; break; }
  }
  if (!picked) continue;
  withSite++;

  out.push(JSON.stringify({
    schema_version: 1,
    source: 'implantations-fr-etranger',
    run_id: 'ro-ccifer-web-2026-08-15-' + m.siren,
    fetched_at: '2026-08-15T14:00:00+02:00',
    status: 'success',
    company: {
      siren: m.siren,
      fields: { website: picked.website },
      implantations: [{
        country: 'RO',
        name_local: picked.roName,
        ...(picked.city ? { city: picked.city } : {}),
      }],
    },
    evidence: { url: 'https://www.ccifer.ro/adhesion/annuaire-des-membres.html' },
    confidence: 85,
  }));
}

writeFileSync('backfill-websites-ro.jsonl', out.join('\n') + '\n');
console.log(`${withSite}/${report.matched_list.length} maisons-mères avec un site CCIFER.`);
