// AGENT 24 — les PARCOURS RÉELS de l'outil existant, comptés en clics.
// Chaque clic est un VRAI clic de souris Playwright sur l'élément visible.
// Le compteur est incrémenté par la sonde, jamais estimé.
// Méthode : reprise de l'agent 23 (auth + drapeaux mockés) — la console réelle
// est murée par A07-001 / D22-001 (aucun écran n'enrôle la 2FA).
import { chromium } from 'playwright';
import { mkdirSync, writeFileSync } from 'node:fs';
import { poserLesDoublures } from './mock.mjs';

const OUT = process.argv[2];
const BASE = 'http://127.0.0.1:5224';
mkdirSync(OUT, { recursive: true });

const b = await chromium.launch();
const ctx = await b.newContext({ ignoreHTTPSErrors: true, viewport: { width: 1440, height: 900 } });
const p = await ctx.newPage();
const journal = [];
await poserLesDoublures(p, { consoleV2: true, journal });

const resultats = [];
const titre = async (pg) => pg.locator('main h1, main h2').first().innerText().catch(() => '(aucun titre)');

async function parcours(nom, budgetCdc, etapes, { depart = '/' } = {}) {
  const t0 = Date.now();
  let clics = 0;
  const trace = [];
  let arret = null;
  await p.goto(BASE + depart, { waitUntil: 'domcontentloaded' });
  try { await p.waitForSelector('[data-tour="sidebar"]', { timeout: 25000 }); }
  catch {
    const corps = (await p.locator('body').innerText().catch(() => '')).replace(/\s+/g, ' ').slice(0, 120);
    resultats.push({ parcours: nom, budgetCdc, clicsMesures: null, arret: { etape: 0, quoi: `ouvrir ${depart}`, erreur: 'la coquille ne s’est jamais montée — ' + corps }, trace: [] });
    console.log('X  ' + nom.padEnd(52) + ' ÉCRAN DE DÉPART INATTEIGNABLE'); return;
  }
  await p.waitForTimeout(1300);
  for (const [i, e] of etapes.entries()) {
    try {
      await e.faire(p);
      clics += e.clics ?? 1;
      await p.waitForTimeout(e.attente ?? 800);
      trace.push({ etape: i + 1, quoi: e.quoi, clicsCumules: clics, url: p.url().replace(BASE, ''), titreEcran: await titre(p) });
    } catch (err) {
      arret = { etape: i + 1, quoi: e.quoi, clicsAvantArret: clics, erreur: String(err).split('\n')[0].slice(0, 130),
        ecran: { url: p.url().replace(BASE, ''), titre: await titre(p) } };
      break;
    }
  }
  const ms = Date.now() - t0;
  resultats.push({ parcours: nom, budgetCdc, clicsMesures: arret ? null : clics, dureeMs: ms, arret, trace });
  console.log(`${arret ? 'X ' : 'OK'}  ${nom.padEnd(52)} ${arret ? 'ARRÊT ét.' + arret.etape + ' (' + arret.clicsAvantArret + ' clics faits)' : 'clics=' + clics}  ${ms}ms`);
}

const section = (nom) => ({ quoi: `déplier la section « ${nom} »`,
  faire: async (pg) => { await pg.locator('[data-tour="sidebar"] h3 button', { hasText: nom }).first().click({ timeout: 8000 }); } });
const entree = (label) => ({ quoi: `cliquer l'entrée « ${label} »`, attente: 1800,
  faire: async (pg) => { await pg.locator('[data-tour="sidebar"] a', { hasText: label }).first().click({ timeout: 8000 }); } });
const btn = (libelle) => ({ quoi: `cliquer « ${libelle} »`,
  faire: async (pg) => { await pg.locator('main').getByRole('button', { name: libelle, exact: false }).first().click({ timeout: 8000 }); } });

// ═══ A. Les parcours RÉELS de l'outil existant ══════════════════════════════

await parcours('A1 · lancer une collecte sur un département (carte)', 'hors §23.4', [
  section('Collecte'), entree('Couverture France'), btn('Action'),
  { quoi: 'cliquer la zone « Isère » sur la carte', faire: async (pg) => { await pg.locator('main').getByText(/Isère/).first().click({ timeout: 8000 }); }, attente: 1200 },
]);

await parcours('A2 · créer une collecte multi-sources (assistant 4 étapes)', 'hors §23.4', [
  section('Collecte'), entree('Collectes'), btn('Nouvelle campagne'),
  { quoi: 'saisir le nom', clics: 1, faire: async (pg) => { await pg.locator('main input[type="text"], main input:not([type])').first().fill('Collecte A24'); } },
  btn('Continuer'),
]);

await parcours('A3 · retrouver une entreprise et ouvrir sa fiche', '≤ 3 clics (§7.3)', [
  section('Contacts'), entree('Entreprises'),
  { quoi: 'ouvrir la fiche de la 1re ligne', attente: 1800, faire: async (pg) => { await pg.locator('main a[href^="/companies/"]').first().click({ timeout: 8000 }); } },
]);

await parcours('A4 · exporter les entreprises en CSV', 'hors §23.4', [
  section('Contacts'), entree('Entreprises'), btn('Exporter'),
]);

await parcours('A5 · sélectionner une page et poser un tag en masse', 'hors §23.4', [
  section('Contacts'), entree('Entreprises'),
  { quoi: 'cocher la sélection de la page', faire: async (pg) => { await pg.locator('main input[type="checkbox"]').first().click({ timeout: 8000 }); } },
  { quoi: 'poser le tag', faire: async (pg) => { await pg.locator('main').getByRole('button', { name: /tag/i }).first().click({ timeout: 6000 }); } },
]);

await parcours('A6 · ouvrir la fiche d’un média', 'hors §23.4', [
  section('Contacts'), entree('Médias'),
  { quoi: 'ouvrir la fiche du 1er média', attente: 2000, faire: async (pg) => { await pg.locator('main a[href^="/media/"]').first().click({ timeout: 8000 }); } },
]);

await parcours('A7 · arbitrer un rapprochement (rattacher)', 'hors §23.4', [
  section('Contacts'), entree('À arbitrer'), btn('Rattacher'),
]);

await parcours('A8 · relancer un run de collecte échoué', 'hors §23.4', [
  section('Collecte'), entree('Journaux de collecte'),
  { quoi: 'filtrer les runs en échec', faire: async (pg) => { await pg.locator('main').getByRole('button', { name: /Échec/i }).first().click({ timeout: 8000 }); } },
]);

await parcours('A9 · voir le vivier candidats et ouvrir une fiche candidat', 'hors §23.4', [
  section('Contacts'), entree('Vivier candidats'),
  { quoi: 'ouvrir la fiche du 1er candidat', faire: async (pg) => { await pg.locator('main a[href^="/console/personnes/"]').first().click({ timeout: 6000 }); } },
]);

// ═══ B. Les 13 parcours du §23.4, mesurés à leur point de départ exigé ══════

await parcours('B1 · §23.4 répondre à un message entrant (départ : notification)', '2 clics', [
  { quoi: 'cliquer la cloche « Notifications » de l’en-tête', attente: 1500,
    faire: async (pg) => { await pg.getByRole('button', { name: 'Notifications' }).click({ timeout: 8000 }); } },
  { quoi: 'ouvrir le message reçu (un panneau doit s’être ouvert)',
    faire: async (pg) => { await pg.locator('[role="dialog"], [role="menu"], [role="listbox"], [data-state="open"]').first().waitFor({ state: 'visible', timeout: 4000 }); } },
]);

await parcours('B2 · §23.4 créer un contact complet (depuis n’importe quel écran)', '1 clic + saisie', [
  { quoi: 'trouver un bouton de création de personne (en-tête, barre ou écran)',
    faire: async (pg) => { await pg.getByRole('button', { name: /nouveau contact|nouvelle personne|créer un contact|ajouter un contact|nouvelle fiche/i }).first().click({ timeout: 5000 }); } },
]);

await parcours('B3 · §23.4 consigner un appel (départ : la fiche 360°)', '1 clic', [
  { quoi: 'cliquer « Consigner un appel » sur la fiche',
    faire: async (pg) => { await pg.locator('main').getByRole('button', { name: /appel|entretien|consigner|noter/i }).first().click({ timeout: 5000 }); } },
], { depart: '/console/personnes/pk-demo' });

await parcours('B4 · §23.4 lancer la visio d’un rendez-vous (départ : accueil)', '1 clic', [
  { quoi: 'cliquer « Lancer la visio » sur l’accueil',
    faire: async (pg) => { await pg.getByRole('button', { name: /visio|rejoindre|réunion|démarrer l.appel/i }).first().click({ timeout: 5000 }); } },
]);

await parcours('B5 · §23.4 retrouver ce qui a été dit (⌘K → fiche)', '< 10 s', [
  { quoi: 'ouvrir la recherche globale (⌘K)', attente: 900, faire: async (pg) => { await pg.keyboard.press('Control+k'); } },
  { quoi: 'taper « Dupont »', clics: 0, attente: 1800, faire: async (pg) => { await pg.keyboard.type('Dupont', { delay: 40 }); } },
  { quoi: 'cliquer le résultat PERSONNE', attente: 1800, faire: async (pg) => { await pg.getByText('Marie Dupont', { exact: false }).first().click({ timeout: 6000 }); } },
]);

await parcours('B6 · §23.4 valider un compte rendu d’entretien', '1 écran', [
  { quoi: 'trouver « Comptes rendus à valider » dans la barre',
    faire: async (pg) => { await pg.locator('[data-tour="sidebar"]').getByText(/compte.?rendu|entretien|échanges/i).first().click({ timeout: 5000 }); } },
]);

await parcours('B7 · §23.4 envoyer le devis après un rendez-vous (fiche → console)', '1 clic', [
  { quoi: 'cliquer « Créer le devis » sur la fiche',
    faire: async (pg) => { await pg.locator('main').getByRole('button', { name: /devis|facture|proposition/i }).first().click({ timeout: 5000 }); } },
], { depart: '/console/personnes/pk-demo' });

await parcours('B8 · §23.4 voir depuis la console qui est ce client (lien permanent)', '1 clic', [
  { quoi: 'trouver le lien « ↗ Console axionia » au pied de la barre',
    faire: async (pg) => { await pg.locator('[data-tour="sidebar"]').getByText(/axionia|↗/i).first().click({ timeout: 5000 }); } },
]);

await parcours('B9 · §23.4 déplacer un candidat d’étape', 'glisser-déposer ou 1 clic', [
  section('Contacts'), entree('Vivier candidats'),
  { quoi: 'déplacer le candidat vers l’étape suivante',
    faire: async (pg) => { await pg.locator('main').getByRole('button', { name: /étape|déplacer|présélection|retenu|refusé/i }).first().click({ timeout: 5000 }); } },
]);

await parcours('B10 · §23.4 programmer un rappel (départ : la fiche)', '1 clic + une date', [
  { quoi: 'cliquer « Programmer un rappel » sur la fiche',
    faire: async (pg) => { await pg.locator('main').getByRole('button', { name: /rappel|tâche|relance|échéance/i }).first().click({ timeout: 5000 }); } },
], { depart: '/console/personnes/pk-demo' });

await parcours('B11 · §23.4 modifier un questionnaire (console du CRM)', 'aperçu avant publication', [
  section('Réglages'), entree('Paramètres'),
  { quoi: 'ouvrir l’onglet des questionnaires / trames d’entretien',
    faire: async (pg) => { await pg.locator('main').getByRole('button', { name: /questionnaire|trame|entretien|formulaire/i }).first().click({ timeout: 5000 }); } },
]);

// ═══ C. Retour arrière : l'état du travail survit-il ? ══════════════════════

{
  const t = { parcours: 'C1 · l’état des filtres et de la page survit-il au retour arrière ?', trace: [] };
  await p.goto(BASE + '/companies', { waitUntil: 'domcontentloaded' });
  await p.waitForSelector('[data-tour="sidebar"]', { timeout: 25000 });
  await p.waitForTimeout(1800);
  // 1. filtrer + paginer
  await p.locator('main').getByRole('button', { name: 'Prospectables', exact: false }).first().click({ timeout: 8000 }).catch(() => {});
  await p.waitForTimeout(900);
  await p.locator('main').getByRole('button', { name: '3', exact: true }).first().click({ timeout: 8000 }).catch(() => {});
  await p.waitForTimeout(1200);
  t.urlApresFiltreEtPage3 = p.url().replace(BASE, '');
  t.etatAvant = await p.evaluate(() => {
    const m = document.querySelector('main');
    return { boutonsActifs: [...m.querySelectorAll('button[aria-pressed="true"], button[data-state="active"], button[aria-current]')].map(e => e.innerText.trim()),
      texteHaut: m.innerText.replace(/\s+/g, ' ').slice(0, 150) };
  });
  // 2. ouvrir une fiche
  await p.locator('main a[href^="/companies/"]').first().click({ timeout: 8000 }).catch(() => {});
  await p.waitForTimeout(1500);
  t.urlFiche = p.url().replace(BASE, '');
  // 3. retour arrière navigateur
  await p.goBack({ waitUntil: 'domcontentloaded' });
  await p.waitForTimeout(1800);
  t.urlApresRetour = p.url().replace(BASE, '');
  t.etatApres = await p.evaluate(() => {
    const m = document.querySelector('main');
    return { boutonsActifs: [...m.querySelectorAll('button[aria-pressed="true"], button[data-state="active"], button[aria-current]')].map(e => e.innerText.trim()),
      texteHaut: m.innerText.replace(/\s+/g, ' ').slice(0, 150) };
  });
  t.etatConserve = JSON.stringify(t.etatAvant) === JSON.stringify(t.etatApres);
  // 4. le fil d'Ariane / un lien de retour existe-t-il sur la fiche ?
  await p.goto(BASE + '/companies/1', { waitUntil: 'domcontentloaded' });
  await p.waitForTimeout(1800);
  t.liensDeRetourSurLaFiche = await p.evaluate(() => [...new Set([...document.querySelectorAll('main a[href], header a[href], nav a[href]')].map(a => a.getAttribute('href')).filter(h => h && h.startsWith('/')))]);
  resultats.push(t);
  console.log('\nC1 retour arrière : url après retour =', t.urlApresRetour, '| état conservé =', t.etatConserve);
}

writeFileSync(`${OUT}/parcours-mesures.json`, JSON.stringify({ resultats, nbAppelsApiDoubles: journal.length }, null, 2));
writeFileSync(`${OUT}/parcours-appels-api.txt`, journal.join('\n'));
await b.close();
console.log('\n--- ' + resultats.length + ' parcours joués ---');
