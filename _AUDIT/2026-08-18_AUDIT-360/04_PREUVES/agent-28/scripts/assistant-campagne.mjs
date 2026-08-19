/**
 * L'assistant de création de campagne a 4 étapes ; la porte a11y ne visite
 * même pas cet écran, et un contrôle qui s'arrête à l'étape 1 ne voit pas
 * les champs des étapes 2 à 4.
 */
import { createRequire } from 'node:module';
import fs from 'node:fs';
const require = createRequire('C:/Users/willi/Documents/Projets/Axion-CRM-Pro/frontend/package.json');
const { chromium } = require('playwright');
const AXE = fs.readFileSync(require.resolve('axe-core/axe.min.js'), 'utf8');

function sonde() {
  const champs = [...document.querySelectorAll('input:not([type=hidden]):not([type=submit]):not([type=button]),select,textarea')];
  return champs.map((c) => {
    const id = c.id;
    const explicite = id ? !!document.querySelector('label[for="' + id + '"]') : false;
    const l = c.closest('label');
    let premier = false;
    if (l) { const e = l.querySelectorAll('button,input:not([type=hidden]),select,textarea'); premier = e[0] === c; }
    const nom = explicite || premier || c.getAttribute('aria-label') || c.getAttribute('aria-labelledby') || c.getAttribute('title');
    return { tag: c.tagName.toLowerCase(), type: c.type || '', ph: c.getAttribute('placeholder'), nom: nom ? 'OUI' : 'NON' };
  });
}

const browser = await chromium.launch();
const ctx = await browser.newContext({ viewport: { width: 1440, height: 1000 } });
const page = await ctx.newPage();
await page.goto('http://127.0.0.1:4188/campaigns/new', { waitUntil: 'networkidle' });
await page.waitForTimeout(900);
// L'etape 1 exige un nom pour deverrouiller « Continuer ».
await page.locator('input[type=text], input:not([type])').first().fill('Campagne de mesure a11y');
await page.waitForTimeout(400);

for (let etape = 1; etape <= 4; etape++) {
  const champs = await page.evaluate(sonde);
  const sans = champs.filter((x) => x.nom === 'NON');
  console.log(`etape ${etape} : champs=${champs.length} sans nom accessible=${sans.length} ${JSON.stringify(sans)}`);
  await page.addScriptTag({ content: AXE });
  const r = await page.evaluate(async () => await axe.run(document, { runOnly: { type: 'tag', values: ['wcag2a', 'wcag2aa', 'wcag22aa'] }, resultTypes: ['violations'] }));
  console.log('   axe (tags exacts de la porte) :', JSON.stringify(r.violations.map((v) => `${v.id}[${v.impact}]x${v.nodes.length}`)));
  const crit = r.violations.filter((v) => v.impact === 'critical');
  console.log('   >>> la porte retiendrait :', crit.map((v) => v.id).join(', ') || 'rien');
  if (etape < 4) {
    const suivant = page.locator('button:has-text("Continuer")').first();
    if (await suivant.count() && await suivant.isEnabled()) { await suivant.click(); await page.waitForTimeout(900); }
    else { console.log('   (« Continuer » absent ou desactive — arret a l etape ' + etape + ')'); break; }
  }
}
await browser.close();
