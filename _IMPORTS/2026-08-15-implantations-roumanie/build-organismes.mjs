#!/usr/bin/env node
// Génère organismes-roumanie.jsonl à partir de ccifer-full.txt,
// en excluant les entités déjà en base (resolution-report.json > matched_list[].ro)
// et les villes hors Roumanie.

import { readFileSync, writeFileSync } from "node:fs";
import { fileURLToPath } from "node:url";
import { dirname, join } from "node:path";

const HERE = dirname(fileURLToPath(import.meta.url));

const SRC_DIR =
  "C:/Users/willi/Documents/Projets/Axion-CRM-Pro/.claude/worktrees/prospection-roumanie/_IMPORTS/2026-08-15-implantations-roumanie";
const SRC_TXT = join(SRC_DIR, "ccifer-full.txt");
const SRC_JSON = join(SRC_DIR, "resolution-report.json");
const OUT = join(HERE, "organismes-roumanie.jsonl");

// --- helpers de normalisation -------------------------------------------

/** Retire les diacritiques (NFD + suppression des marques combinantes). */
function stripAccents(s) {
  return s.normalize("NFD").replace(/[\u0300-\u036f]/g, "");
}

/** Slug : minuscules, sans accents, non-alphanum -> '-', tirets compactés, tronqué à 60. */
function slugify(name) {
  return stripAccents(name)
    .toLowerCase()
    .replace(/[^a-z0-9]+/g, "-")
    .replace(/-+/g, "-")
    .replace(/^-+|-+$/g, "")
    .slice(0, 60)
    .replace(/^-+|-+$/g, "");
}

/** Clé de comparaison de noms : MAJUSCULES, sans accents, sans non-alphanum. */
function nameKey(name) {
  return stripAccents(name).toUpperCase().replace(/[^A-Z0-9]/g, "");
}

/** Nom en MAJUSCULES sans accents (espaces conservés) — base des règles de nature. */
function upperNoAccents(name) {
  return stripAccents(name).toUpperCase();
}

/** Clé de ville : MAJUSCULES sans accents, espaces/tirets compactés. */
function cityKey(city) {
  return stripAccents(city)
    .toUpperCase()
    .replace(/[^A-Z0-9]+/g, " ")
    .trim();
}

// --- règles métier -------------------------------------------------------

const EXCLUDED_CITIES = new Set(
  [
    "Paris",
    "Boulogne-Billancourt",
    "Courbevoie",
    "Lyon",
    "Marseille",
    "Greasque",
    "Brou",
    "Nyons",
    "Creuzier-le-Vieux",
    "Champigny sur Mer",
    "Chalons-en-Champagne",
    "Quetteville",
    "Domfront en Champagne",
    "Geneve",
    "Kiev",
    "Athenes",
    "Chisinau",
  ].map(cityKey),
);

/** Déduit la nature. Première règle qui matche gagne. */
function detectNature(name, sector) {
  const U = upperNoAccents(name); // ASOCIAŢIA -> ASOCIATIA

  if (U.includes("CCIFER") || U.startsWith("CAMERA DE COMERT")) return "cci";

  if (
    U.startsWith("ASOCIATIA") ||
    U.startsWith("FUNDATIA")
  )
    return "association";

  if (
    U.startsWith("UNIVERSITATEA") ||
    U.startsWith("ACADEMIA") ||
    U.startsWith("GRADINITA") ||
    U.startsWith("SCOALA") ||
    U.includes("LYCEE") ||
    U.includes("ECOLE ")
  )
    return "enseignement";

  if (
    U.includes("AVOCAT") || // couvre AVOCATI / AVOCATURA
    U.includes("SOCIETATE CIVILA")
  )
    return "cabinet";

  if (U.includes("INSTITUT FRANCAIS") || U.includes("CRUCE ROSIE"))
    return "institution";

  if (stripAccents(String(sector || "")).trim().toLowerCase() === "media")
    return "media";

  return "entreprise";
}

// --- chargement ----------------------------------------------------------

const report = JSON.parse(readFileSync(SRC_JSON, "utf8"));
const alreadyInCrm = new Set();
for (const m of report.matched_list || []) {
  for (const ro of m.ro || []) alreadyInCrm.add(nameKey(ro));
}

const raw = readFileSync(SRC_TXT, "utf8").replace(/^\uFEFF/, "");
const lines = raw.split(/\r?\n/).filter((l) => l.trim() !== "");

// --- traitement ----------------------------------------------------------

const slugCounts = new Map();
const out = [];
const stats = {
  read: lines.length,
  skippedMalformed: 0,
  skippedDuplicate: 0,
  skippedCity: 0,
  withWebsite: 0,
  natures: {},
  slugCollisions: 0,
};

for (const line of lines) {
  const parts = line.split("|");
  if (parts.length < 5) {
    stats.skippedMalformed++;
    continue;
  }
  const [nameRaw, siteRaw, sectorRaw, cityRaw, pageRaw] = parts;
  const name = nameRaw.trim();
  const site = (siteRaw || "").trim();
  const sector = (sectorRaw || "").trim();
  const city = (cityRaw || "").trim();
  const page = (pageRaw || "").trim();

  if (!name) {
    stats.skippedMalformed++;
    continue;
  }

  // Exclusion 1 : déjà en base (rattachée à une maison-mère française)
  if (alreadyInCrm.has(nameKey(name))) {
    stats.skippedDuplicate++;
    continue;
  }

  // Exclusion 2 : ville hors Roumanie
  if (city && EXCLUDED_CITIES.has(cityKey(city))) {
    stats.skippedCity++;
    continue;
  }

  // Slug unique
  const base = slugify(name);
  const n = (slugCounts.get(base) || 0) + 1;
  slugCounts.set(base, n);
  const slug = n === 1 ? base : `${base}-${n}`;
  if (n > 1) stats.slugCollisions++;

  const nature = detectNature(name, sector);
  stats.natures[nature] = (stats.natures[nature] || 0) + 1;

  const fields = { denomination: name };
  if (site && site !== "-") {
    fields.website = site;
    stats.withWebsite++;
  }
  if (city) fields.city = city;

  out.push({
    schema_version: 1,
    source: "implantations-fr-etranger",
    run_id: `ro-org-2026-08-15-${slug}`,
    fetched_at: "2026-08-15T16:00:00+02:00",
    status: "success",
    company: {
      foreign_id: `ccifer:${slug}`,
      country: "RO",
      nature,
      fields,
    },
    evidence: {
      url: `https://www.ccifer.ro/adhesion/annuaire-des-membres/directory-page/${page}.html`,
    },
    confidence: 75,
  });
}

writeFileSync(OUT, out.map((o) => JSON.stringify(o)).join("\n") + "\n", "utf8");

// --- contrôles -----------------------------------------------------------

const checkLines = readFileSync(OUT, "utf8").split("\n");
const last = checkLines.pop(); // doit être "" (fichier terminé par \n)
let parseErrors = 0;
const seenSlugs = new Map();
const dupSlugs = [];
for (let i = 0; i < checkLines.length; i++) {
  try {
    const o = JSON.parse(checkLines[i]);
    const s = o.company.foreign_id;
    if (seenSlugs.has(s)) dupSlugs.push(s);
    else seenSlugs.set(s, i);
  } catch (e) {
    parseErrors++;
    console.error(`JSON invalide ligne ${i + 1}: ${e.message}`);
  }
}

console.log("Fichier         :", OUT);
console.log("Lignes source   :", stats.read);
console.log("Exclues doublon :", stats.skippedDuplicate);
console.log("Exclues ville   :", stats.skippedCity);
console.log("Lignes malformées:", stats.skippedMalformed);
console.log("LIGNES PRODUITES:", out.length, "(relues:", checkLines.length + ")");
console.log("Termine par \\n  :", last === "" ? "OUI" : "NON");
console.log("Avec website    :", stats.withWebsite);
console.log("Collisions slug résolues:", stats.slugCollisions);
console.log("Slugs en double :", dupSlugs.length === 0 ? "AUCUN" : dupSlugs.join(", "));
console.log("JSON invalides  :", parseErrors);
console.log("Répartition par nature:");
for (const [k, v] of Object.entries(stats.natures).sort((a, b) => b[1] - a[1])) {
  console.log(`  ${k.padEnd(14)} ${v}`);
}
console.log("\n3 premières lignes:");
for (const l of checkLines.slice(0, 3)) console.log(l);
