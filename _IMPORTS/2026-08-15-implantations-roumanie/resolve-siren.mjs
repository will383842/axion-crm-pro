// Résolution SIREN des membres CCIFER → JSONL pivot ScrapedRecord
// API publique recherche-entreprises.api.gouv.fr (open data, sans clé).
import { readFileSync, writeFileSync } from 'node:fs';

const RAW = 'ccifer-raw.txt';
const EVIDENCE_BASE = 'https://www.ccifer.ro/adhesion/annuaire-des-membres/directory-page/';
const TRESOR = 'https://www.tresor.economie.gouv.fr/Pays/RO/relations-bilaterales';

// ── Règles d'exclusion (motifs structurels : pas des entreprises cibles) ────
const DENY_PATTERNS = [
  /^ASOCIATIA/, /^FUNDATIA/, /^UNIVERSITATEA/, /^ACADEMIA/, /^GRADINITA/,
  /AVOCAT/, /AVOCATURA/, /SOCIETATE CIVILA/, /CABINET INDIVIDUAL/,
  /^MONSIEUR /, /PERSOANA FIZICA/, /^INSTITUT FRANCAIS/, /CRUCE ROSIE/,
  /^ECOLE /, /LYCEE/, /^JOY LEARNING/, /^LA PETITE MATERNELLE/, /^SCOALA/,
];

// Groupes connus NON français (leur filiale FR existe : ne pas la confondre
// avec une maison-mère) ou entreprises roumaines/étrangères notoires.
const DENY_KNOWN = [
  'BANCA TRANSILVANIA', 'BITDEFENDER', 'BAYER', 'NESTLE', 'NOKIA', 'OMV PETROM',
  'RAIFFEISEN', 'UNICREDIT', 'KPMG', 'PRICEWATERHOUSECOOPERS', 'DELOITTE',
  'ERNST & YOUNG', 'GRANT THORNTON', 'RSM ROMANIA', 'CBRE', 'MARSH', 'AON',
  'WILLIS', 'SANTA FE', 'GOSSELIN', 'SD WORX', 'ICAP', 'PORR', 'CTP INVEST',
  'DR.MAX', 'MEDICOVER', 'MED LIFE', 'OLX', 'KINSTELLAR', 'DLA PIPER',
  'SCHWARZ', 'BOLLHOFF', 'PFEIFFER', 'IFM ELECTRONIC', 'BIO-CIRCLE',
  'DR. PENDL', 'WAREHOUSES DE PAUW', 'VISTA BANK', 'MET ROMANIA', 'JUST ONE',
  'DANTE INTERNATIONAL', 'TOTAL SOFT', 'NET BRINEL', 'ETA2U', 'AUTONOM',
  'ANA HOTELS', 'POPECI', 'WORLD TRADE CENTER', 'TURISM LOTUS', 'EBS INTEGRATOR',
  'TREMEND', 'CONNECTIONS CONSULT', 'DOCPROCESS', 'SMARTREE', 'HUMANGEST',
  'ADECCO', 'AKKODIS', 'SOGEFI', 'ACT LEGAL', 'HAPPY TOUR', 'UIPATH',
  'WORLD VISION', 'HABITAT FOR', 'NOKIA', 'PETROLEUM EQUIPMENT', 'CCIFER',
  'DYNAMIC PARCEL', 'GOODWILL CONSULTING', 'SGS ROMANIA', 'EUROFINS FOOD',
];

// Redirections vérifiées : entité roumaine → nom de la maison-mère française.
const OVERRIDES = {
  'AUTOMOBILE-DACIA': 'RENAULT',
  'RENAULT COMMERCIAL ROUMANIE': 'RENAULT',
  'BRD - GROUPE SOCIETE GENERALE': 'SOCIETE GENERALE',
  'SOCIETE GENERALE GLOBAL SOLUTION CENTRE': 'SOCIETE GENERALE',
  'APA NOVA BUCURESTI': 'VEOLIA ENVIRONNEMENT',
  'APA NOVA PLOIESTI': 'VEOLIA ENVIRONNEMENT',
  'VEOLIA ROMANIA SOLUTII INTEGRATE': 'VEOLIA ENVIRONNEMENT',
  'BULL ROMANIA': 'BULL SAS',
  'SERVIER PHARMA': 'LES LABORATOIRES SERVIER',
  'RCI LEASING ROMANIA IFN': 'RCI BANQUE',
  'SBR SOLETANCHE BACHY FUNDATII': 'SOLETANCHE BACHY',
  'LPR - LA PALETTE ROUGE': 'LA PALETTE ROUGE',
  'EUROMASTER TYRE & SERVICES': 'EUROMASTER FRANCE',
  'ATOS GLOBAL DELIVERY CENTER': 'ATOS',
  'DANONE - PRODUCTIE SI DISTRIBUTIE DE PRODUSE ALIMENTARE': 'DANONE',
  'LION COMMUNICATION SERVICES': 'PUBLICIS GROUPE',
  'MEDIAPOSTE HIT MAIL': 'MEDIAPOSTE',
  'CAT AUTOLOGISTICS ROMANIA': 'GROUPE CAT',
  'NTN-SNR RULMENTI': 'NTN EUROPE',
  'SOUFFLET AGRO ROMANIA': 'SOUFFLET AGRICULTURE',
  'SOUFFLET MALT ROMANIA': 'MALTERIES SOUFFLET',
  'AS24 TANKSERVICE': 'AS 24',
  'FM ROMANIA': 'FM LOGISTIC CORPORATE',
  'ALD AUTOMOTIVE': 'AYVENS',
  'PLUXEE ROMANIA': 'PLUXEE FRANCE',
  'THALES DIS ROMANIA': 'THALES DIS FRANCE',
  'LOREAL ROMANIA': "L'OREAL",
  'PLASTIC OMNIUM AUTO INERGY ROMANIA': 'COMPAGNIE PLASTIC OMNIUM',
  'SAINT-GOBAIN GLASS ROMANIA': 'SAINT-GOBAIN GLASS FRANCE',
  'AIR FRANCE BUCURESTI': 'SOCIETE AIR FRANCE',
  'CAPGEMINI SERVICES ROMANIA': 'CAPGEMINI',
  'CEGEDIM RX': 'CEGEDIM',
  'COFACE ROMANIA SERVICES': 'COFACE',
  'GROUPAMA ASIGURARI': 'GROUPAMA ASSURANCES MUTUELLES',
  'HAULOTTE ARGES': 'HAULOTTE GROUP',
  'PIRIOU ATG ROMANIA': 'PIRIOU',
  'POTEZ AERONAUTIC ROMANIA': 'POTEZ AERONAUTIQUE',
  'QAIR RENEWABLES': 'QAIR INTERNATIONAL',
};

// Noms trop génériques : une correspondance API serait invérifiable sans
// humain → file d'arbitrage, jamais d'auto-match.
const AMBIGUOUS = [
  '3D CONSEIL', 'APERIO INTELLIGENCE', 'ARTA GRAFICA', 'BLACK SQUARE',
  'COACHING PARTNERS', 'CODE PRODUCTION', 'CREATIVE MAKER', 'EXACT FORESTALL',
  'FEEL IT', 'GAZ INTEGRAL', 'OXYLIFE', 'URBAN CONNECT', 'VECTOR INTERNATIONAL',
  'JUICE MARKET', 'IO PARTNERS', 'ROA3', 'SADE INGENIERIE', 'TRANSPARENT DESIGN',
  'SMART WOOD',
];

// SIREN ÉPINGLÉS, vérifiés à la main via l'API : les têtes de groupe ont
// souvent un effectif « holding » minuscule et perdent face à un homonyme
// récent (un « RENAULT » créé en 2023 à Vittefleur…). On ne cherche pas, on SAIT.
const PINNED = {
  'RENAULT': { siren: '441639465', nom: 'RENAULT' },
  'VALEO': { siren: '552030967', nom: 'VALEO' },
  'ATOS': { siren: '323623603', nom: 'ATOS GROUP' },
  'MICHELIN': { siren: '855200507', nom: 'MANUFACTURE FRANCAISE DES PNEUMATIQUES MICHELIN' },
};

// Marques mono-token françaises connues (auto-accept autorisé).
const KNOWN_FRENCH_SINGLE = new Set([
  'MICHELIN', 'DANONE', 'ENGIE', 'UBISOFT', 'ALSTOM', 'THALES', 'FRAMATOME',
  'CARREFOUR', 'AUCHAN', 'ORANGE', 'SANOFI', 'LESAFFRE', 'LIMAGRAIN',
  'HUTCHINSON', 'FAURECIA', 'EXPLEO', 'EGIS', 'COFACE', 'GROUPAMA', 'AMUNDI',
  'EDENRED', 'WEBHELP', 'AXWAY', 'BOCCARD', 'COTHERM', 'ECOCERT', 'GUILLIN',
  'HAULOTTE', 'ISAGRI', 'LACTALIS', 'WIRQUIN', 'TRESCAL', 'VOLTALIA',
  'SONEPAR', 'STREAMWIDE', 'SEBIA', 'ACENSI', 'ALSTEF', 'PIRIOU', 'NOVARES',
  'ATALIAN', 'SAMSIC', 'EVERIENCE', 'QAIR', 'HELEXIA', 'THIRARD', 'VALEO',
  'RENAULT', 'ATOS', 'AYVENS', 'MEDIAPOSTE', 'LOREAL', 'AKWEL', 'LIDEA',
  'CAPGEMINI', 'COFACE', 'CEGEDIM',
]);

// Ajouts hors-CCIFER (source DG Trésor).
const EXTRAS = [
  { name: 'VALEO', city: '', evidence: TRESOR },
];

const STRIP_TOKENS = new Set([
  'SRL', 'S.R.L.', 'SA', 'S.A.', 'S.A', 'SAS', 'SASU', 'SE', 'IFN', 'SCA',
  'ROMANIA', 'ROMÂNIA', 'ROUMANIE', 'RO', 'BUCURESTI', 'BUCAREST', 'BUCHAREST',
  'TIMISOARA', 'SUCURSALA', 'REPREZENTANTA', 'FILIALA', 'EASTERN', 'EUROPE',
  'EAST', 'LYON', 'PARIS',
]);

const norm = (s) => s
  .toUpperCase()
  .normalize('NFD').replace(/[̀-ͯ]/g, '')
  .replace(/[^A-Z0-9&' -]/g, ' ')
  .replace(/\s+/g, ' ')
  .trim();

function baseName(name) {
  const cleaned = norm(name).replace(/[.,"]/g, '');
  const tokens = cleaned.split(' ').filter(Boolean);
  // Retire en queue : formes juridiques, géographie, ET les lettres isolées
  // (« S.A. » devient « S A » après normalisation).
  while (tokens.length > 1) {
    const last = tokens[tokens.length - 1];
    if (STRIP_TOKENS.has(last) || /^[A-Z]$/.test(last) || last === '-') tokens.pop();
    else break;
  }
  // « SAS X » / « EURL X » en tête = forme française EN PREMIER : garder.
  return tokens.join(' ').replace(/[ -]+$/, '');
}

async function search(q) {
  const url = 'https://recherche-entreprises.api.gouv.fr/search?q=' + encodeURIComponent(q) + '&per_page=5&page=1&etat_administratif=A';
  const res = await fetch(url, { headers: { accept: 'application/json' } });
  if (!res.ok) return null;
  return res.json();
}

function acceptable(base, apiName) {
  const a = norm(apiName);
  const b = norm(base);
  if (a === b) return true;
  if (a.startsWith(b + ' ') || b.startsWith(a + ' ')) return true;
  // Tolérance L'OREAL / LOREAL etc.
  if (a.replace(/[' -]/g, '') === b.replace(/[' -]/g, '')) return true;
  return false;
}

const lines = readFileSync(RAW, 'utf8').split('\n').map((l) => l.trim()).filter(Boolean);
const entries = lines.map((l) => {
  const [name, city, page] = l.split('|');
  return { name: name.trim(), city: (city || '').trim(), evidence: page ? EVIDENCE_BASE + page.trim() + '.html' : TRESOR };
});
for (const e of EXTRAS) entries.push(e);

const excluded = [];
const pending = [];
const bySiren = new Map();

let done = 0;
for (const e of entries) {
  done++;
  const U = norm(e.name);
  if (DENY_PATTERNS.some((r) => r.test(U)) || DENY_KNOWN.some((k) => U.includes(norm(k)))) {
    excluded.push({ ...e, reason: 'hors-cible (ONG/cabinet/université ou groupe non français connu)' });
    continue;
  }
  if (AMBIGUOUS.some((a) => U.startsWith(norm(a)))) {
    pending.push({ ...e, base: baseName(e.name), reason: 'nom générique : arbitrage humain requis' });
    continue;
  }
  let base = baseName(e.name);
  const overrideKey = Object.keys(OVERRIDES).find((k) => U.startsWith(norm(k)));
  let queried = base;
  let viaOverride = false;
  if (overrideKey) { queried = OVERRIDES[overrideKey]; viaOverride = true; }

  const tokens = queried.split(' ').filter(Boolean);
  const singleToken = tokens.length === 1;
  if (!viaOverride && singleToken && !KNOWN_FRENCH_SINGLE.has(tokens[0])) {
    pending.push({ ...e, base: queried, reason: 'nom mono-token non distinctif : arbitrage humain requis' });
    continue;
  }
  if (!viaOverride && queried.length < 4) {
    pending.push({ ...e, base: queried, reason: 'nom trop court' });
    continue;
  }

  let hit = null;
  if (PINNED[queried]) {
    hit = { siren: PINNED[queried].siren, nom_complet: PINNED[queried].nom, activite_principale: '' };
  }
  try {
    if (!hit) {
    const data = await search(queried);
    if (data && Array.isArray(data.results)) {
      // Parmi les correspondances de nom acceptables, préférer l'entité au
      // plus gros effectif : « LOREAL » doit résoudre L'Oréal, pas un commerce
      // homonyme.
      const rank = (r) => {
        const t = parseInt(r.tranche_effectif_salarie ?? '-1', 10);
        return Number.isNaN(t) ? -1 : t;
      };
      hit = data.results
        .filter((r) => acceptable(queried, r.nom_complet || r.nom_raison_sociale || ''))
        .sort((x, y) => rank(y) - rank(x))[0] || null;
    }
    }
  } catch { /* réseau : l'entrée part en pending */ }
  await new Promise((r) => setTimeout(r, 250));

  if (!hit) {
    pending.push({ ...e, base: queried, reason: 'aucune correspondance française fiable' });
    process.stdout.write(`\r${done}/${entries.length} …`);
    continue;
  }

  const siren = hit.siren;
  const rec = bySiren.get(siren) || {
    siren,
    denomination: hit.nom_complet || hit.nom_raison_sociale,
    naf: hit.activite_principale || '',
    implantations: [],
    evidences: new Set(),
    viaOverride,
    sources: [],
  };
  rec.implantations.push({ country: 'RO', name_local: e.name, ...(e.city ? { city: e.city } : {}) });
  rec.evidences.add(e.evidence);
  rec.sources.push(e.name);
  bySiren.set(siren, rec);
  process.stdout.write(`\r${done}/${entries.length} …`);
}

// ── Sorties ─────────────────────────────────────────────────────────────────
const jsonl = [];
for (const rec of bySiren.values()) {
  jsonl.push(JSON.stringify({
    schema_version: 1,
    source: 'implantations-fr-etranger',
    run_id: 'ro-ccifer-2026-08-15-' + rec.siren,
    fetched_at: '2026-08-15T12:00:00+02:00',
    status: 'success',
    company: {
      siren: rec.siren,
      fields: { denomination: rec.denomination },
      implantations: rec.implantations,
    },
    evidence: { url: [...rec.evidences][0] },
    confidence: rec.viaOverride ? 90 : 80,
  }));
}
writeFileSync('implantations-roumanie.jsonl', jsonl.join('\n') + '\n');

// Seules les entités à FORME FRANÇAISE évidente (SAS/SASU/EURL en tête) mais
// sans correspondance API partent en file d'arbitrage — le reste (PME roumaines
// membres CCIFER) reste dans le rapport, pas dans le CRM.
const pendJsonl = pending
  .filter((p) => /^(SAS |SASU |EURL )/.test(norm(p.name)))
  .map((p) => JSON.stringify({
    schema_version: 1,
    source: 'implantations-fr-etranger',
    run_id: 'ro-ccifer-2026-08-15-pm-' + norm(p.name).replace(/[^A-Z0-9]+/g, '-').toLowerCase().slice(0, 40),
    fetched_at: '2026-08-15T12:00:00+02:00',
    status: 'success',
    company: {
      match_hint: { denomination: p.name, ...(p.city ? { city: p.city } : {}) },
      implantations: [{ country: 'RO', name_local: p.name, ...(p.city ? { city: p.city } : {}) }],
    },
    evidence: { url: p.evidence },
    confidence: 50,
  }));
writeFileSync('implantations-roumanie-pending.jsonl', pendJsonl.join('\n') + (pendJsonl.length ? '\n' : ''));

writeFileSync('resolution-report.json', JSON.stringify({
  total: entries.length,
  matched_sirens: bySiren.size,
  matched_local_entities: [...bySiren.values()].reduce((n, r) => n + r.implantations.length, 0),
  pending: pending.length,
  excluded: excluded.length,
  pending_list: pending.map((p) => ({ name: p.name, base: p.base, reason: p.reason })),
  excluded_list: excluded.map((x) => ({ name: x.name, reason: x.reason })),
  matched_list: [...bySiren.values()].map((r) => ({ siren: r.siren, fr: r.denomination, ro: r.sources })),
}, null, 2));

console.log(`\nOK — ${bySiren.size} SIREN, ${pending.length} en arbitrage, ${excluded.length} exclus.`);
