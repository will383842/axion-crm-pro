import { createRequire } from 'node:module';
const require = createRequire('C:/Users/willi/Documents/Projets/Axion-CRM-Pro/frontend/package.json');
const { chromium } = require('playwright');

const browser = await chromium.launch();
const page = await browser.newPage();

// ---------------------------------------------------------------------------
// TÉMOIN de la sonde « indicateur de focus » : trois boutons plantés.
//  A = anneau retiré et NON remplacé  -> la sonde DOIT dire AUCUN
//  B = anneau retiré et remplacé par un box-shadow -> box-shadow
//  C = bouton nu, anneau par défaut du navigateur -> outline
// ---------------------------------------------------------------------------
await page.setContent(`<!doctype html><html><body>
  <button id="A" style="outline:none">A - outline retire, rien en remplacement</button>
  <button id="B" style="outline:none">B - remplace par box-shadow</button>
  <button id="C">C - anneau par defaut</button>
  <style>#B:focus{box-shadow:0 0 0 2px rgb(0 0 0);}</style>
</body></html>`);

const lire = async () => page.evaluate(() => {
  const el = document.activeElement;
  const cs = getComputedStyle(el);
  const outline = cs.outlineStyle !== 'none' && parseFloat(cs.outlineWidth) > 0;
  const ring = /rgb|rgba/.test(cs.boxShadow) && cs.boxShadow !== 'none';
  return { id: el.id, verdict: outline ? 'outline' : ring ? 'box-shadow' : 'AUCUN', outlineStyle: cs.outlineStyle, outlineWidth: cs.outlineWidth, boxShadow: cs.boxShadow };
});

for (let i = 0; i < 3; i++) { await page.keyboard.press('Tab'); console.log(JSON.stringify(await lire())); }

// ---------------------------------------------------------------------------
// TÉMOIN du piège de focus : une modale qui piège correctement doit renvoyer
// le focus au début ; la sonde compte les sorties hors du conteneur.
// ---------------------------------------------------------------------------
await page.setContent(`<!doctype html><html><body>
  <button id="derriere1">arriere-plan 1</button>
  <div id="modale" role="dialog" aria-modal="true"><button id="m1">m1</button><button id="m2">m2</button></div>
  <button id="derriere2">arriere-plan 2</button>
</body></html>`);
await page.focus('#m2');
const suite = [];
for (let i = 0; i < 4; i++) { await page.keyboard.press('Tab'); suite.push(await page.evaluate(() => ({ id: document.activeElement.id, dansModale: !!document.activeElement.closest('#modale') }))); }
console.log('temoin piege de focus (modale SANS piege) :', JSON.stringify(suite));

await browser.close();
