import { createRequire } from 'node:module';
const require = createRequire('C:/Users/willi/Documents/Projets/Axion-CRM-Pro/frontend/package.json');
const { chromium } = require('playwright');
const BASE = 'http://127.0.0.1:4188';

const ROUTES = [
  ['login', '/login'], ['2fa', '/2fa'], ['magic-link', '/magic-link'], ['password-reset', '/password-reset'],
  ['dashboard', '/'], ['companies', '/companies'], ['companies-$id', '/companies/1'],
  ['contacts', '/contacts'], ['roumanie', '/international/roumanie'],
  ['media', '/media'], ['media-$id', '/media/1'], ['journalists', '/journalists'],
  ['coverage', '/coverage'], ['scraper-runs', '/scraper-runs'],
  ['llm-router', '/llm/router'], ['llm-proxy-providers', '/llm/proxy-providers'], ['llm-rotations', '/llm/rotations'],
  ['rgpd-requests', '/rgpd/requests'], ['rgpd-ai-act', '/rgpd/ai-act'], ['audit-logs', '/audit-logs'],
  ['users', '/users'], ['settings', '/settings'],
  ['campaigns', '/campaigns'], ['campaigns-new', '/campaigns/new'], ['campaigns-$id', '/campaigns/1'],
  ['tags', '/tags'], ['audiences', '/audiences'], ['audiences-new', '/audiences/new'], ['audiences-$id', '/audiences/1'],
  ['admin-observability', '/admin/observability'],
  ['console-contacts', '/console/contacts'], ['console-vivier', '/console/vivier'],
  ['console-arbitrage', '/console/arbitrage'], ['console-personnes-$k', '/console/personnes/abc'],
  ['cold-email', '/cold-email'], ['linkedin', '/linkedin'],
  ['404', '/route-qui-nexiste-pas'],
];

const browser = await chromium.launch();

// ── 1. Lien d'évitement : mesure au PIXEL, sur la capture d'écran ───────────
console.log('══ 1. Lien d\'évitement — couleur RÉELLEMENT peinte sous le lien ══');
for (const mode of ['clair', 'sombre']) {
  const ctx = await browser.newContext({ viewport: { width: 1440, height: 900 } });
  await ctx.addInitScript((m) => { try { localStorage.setItem('axion-theme', m); } catch {} }, mode === 'sombre' ? 'dark' : 'light');
  const page = await ctx.newPage();
  await page.goto(BASE + '/companies', { waitUntil: 'networkidle' });
  await page.waitForTimeout(800);
  // Capture SANS le lien focalisé : on lit le fond derrière sa position.
  const box = await page.evaluate(() => { const a = document.querySelector('.skip-link'); a.focus();
    const r = a.getBoundingClientRect(); a.blur();
    return { x: Math.round(r.x), y: Math.round(r.y), w: Math.round(r.width), h: Math.round(r.height),
      couleurTexte: getComputedStyle(a).color, fondPropre: getComputedStyle(a).backgroundColor, padding: getComputedStyle(a).padding }; });
  const shot = (await page.screenshot({ clip: { x: box.x, y: box.y, width: Math.max(box.w, 4), height: Math.max(box.h, 4) } })).toString('base64');
  const px = await page.evaluate(async (b64) => {
    const img = new Image(); img.src = 'data:image/png;base64,' + b64;
    await img.decode();
    const cv = document.createElement('canvas'); cv.width = img.width; cv.height = img.height;
    const c = cv.getContext('2d'); c.drawImage(img, 0, 0);
    const d = c.getImageData(0, 0, img.width, img.height).data;
    const comptes = {};
    for (let i = 0; i < d.length; i += 4) { const k = `${d[i]},${d[i+1]},${d[i+2]}`; comptes[k] = (comptes[k] || 0) + 1; }
    return Object.entries(comptes).sort((a, b2) => b2[1] - a[1]).slice(0, 3);
  }, shot);
  const lum = (a) => { const f = (c) => { c /= 255; return c <= 0.03928 ? c / 12.92 : Math.pow((c + 0.055) / 1.055, 2.4); };
    return 0.2126 * f(a[0]) + 0.7152 * f(a[1]) + 0.0722 * f(a[2]); };
  const fondDominant = px[0][0].split(',').map(Number);
  const txt = await page.evaluate(() => { const cv = document.createElement('canvas'); cv.width = cv.height = 1;
    const c = cv.getContext('2d'); c.fillStyle = getComputedStyle(document.querySelector('.skip-link')).color;
    c.fillRect(0, 0, 1, 1); const d = c.getImageData(0, 0, 1, 1).data; return [d[0], d[1], d[2]]; });
  const L1 = lum(txt), L2 = lum(fondDominant);
  const ratio = (Math.max(L1, L2) + 0.05) / (Math.min(L1, L2) + 0.05);
  console.log(`  ${mode.padEnd(7)} | texte rgb(${txt}) | fond peint dominant rgb(${px[0][0]}) (${px[0][1]} px) | ratio ${Math.round(ratio * 100) / 100} | seuil 4.5 -> ${ratio >= 4.5 ? 'OK' : 'ECHEC AA'}`);
  console.log(`          | fond propre du lien : ${box.fondPropre} | padding : ${box.padding} | position ${box.x},${box.y}`);
  console.log(`          | 3 couleurs les plus presentes : ${JSON.stringify(px)}`);
  await ctx.close();
}

// ── 2. Titre de page, lang, mode sombre appliqué, hiérarchie de titres ─────
console.log('\n══ 2. Titre de page (WCAG 2.4.2), lang (3.1.1), mode sombre, h1 ══');
{
  const ctx = await browser.newContext({ viewport: { width: 1440, height: 900 } });
  await ctx.addInitScript(() => { try { localStorage.setItem('axion-theme', 'dark'); } catch {} });
  const page = await ctx.newPage();
  const titres = new Set();
  console.log('  ecran'.padEnd(24), '| titre du document'.padEnd(34), '| lang | .dark | h1 | landmarks main/nav/banner');
  for (const [nom, url] of ROUTES) {
    try {
      await page.goto(BASE + url, { waitUntil: 'domcontentloaded', timeout: 20000 });
      await page.waitForTimeout(600);
      const r = await page.evaluate(() => ({
        titre: document.title, lang: document.documentElement.lang,
        dark: document.documentElement.classList.contains('dark'),
        h1: document.querySelectorAll('h1').length,
        main: document.querySelectorAll('main,[role=main]').length,
        nav: document.querySelectorAll('nav,[role=navigation]').length,
        banner: document.querySelectorAll('header,[role=banner]').length,
        niveaux: [...document.querySelectorAll('h1,h2,h3,h4,h5,h6')].map((h) => +h.tagName[1]),
      }));
      titres.add(r.titre);
      const saut = r.niveaux.some((n, i) => i > 0 && n - r.niveaux[i - 1] > 1) || (r.niveaux.length && r.niveaux[0] !== 1);
      console.log('  ' + nom.padEnd(22), '|', String(r.titre).slice(0, 32).padEnd(32), '|', (r.lang || 'ABSENT').padEnd(4), '|', String(r.dark).padEnd(5), '|', String(r.h1).padStart(2), '|', `${r.main}/${r.nav}/${r.banner}`, saut ? '| SAUT DE NIVEAU' : '');
    } catch (e) { console.log('  ' + nom.padEnd(22), '| ERREUR', String(e).slice(0, 60)); }
  }
  console.log('  >>> titres de document DISTINCTS sur 37 ecrans :', titres.size, JSON.stringify([...titres]));
  await ctx.close();
}

// ── 3. prefers-reduced-motion ──────────────────────────────────────────────
console.log('\n══ 3. Mouvement (WCAG 2.2.2 / 2.3.3) ══');
{
  const ctx = await browser.newContext({ viewport: { width: 1440, height: 900 }, reducedMotion: 'reduce' });
  const page = await ctx.newPage();
  await page.goto(BASE + '/companies', { waitUntil: 'networkidle' });
  await page.waitForTimeout(600);
  const r = await page.evaluate(() => {
    const d = document.createElement('span'); d.className = 'axion-pulse-dot'; document.body.appendChild(d);
    const cs = getComputedStyle(d); const out = { animationName: cs.animationName, duree: cs.animationDuration, iterations: cs.animationIterationCount };
    d.remove(); return out;
  });
  console.log('  sous prefers-reduced-motion: reduce, la classe .axion-pulse-dot :', JSON.stringify(r));
  console.log('  (iterations=infinite + duree>0 => le mouvement continue malgre la preference)');
  await ctx.close();
}

// ── 4. Témoin : bouton imbriqué (le motif de 5 emplacements du code) ───────
console.log('\n══ 4. TEMOIN — ce qu\'axe dit du motif <button><button></button></button> ══');
{
  const fs = await import('node:fs');
  const AXE = fs.readFileSync(require.resolve('axe-core/axe.min.js'), 'utf8');
  const ctx = await browser.newContext();
  const page = await ctx.newPage();
  await page.setContent(`<!doctype html><html lang="fr"><head><title>t</title></head><body>
    <main>
    <button type="button" aria-haspopup="menu" aria-expanded="false" class="inline-flex">
      <button type="button" aria-label="Actions"><svg width="20" height="20" aria-hidden="true"></svg></button>
    </button>
    <button type="button" aria-haspopup="menu" aria-expanded="false" class="inline-flex">
      <span aria-label="Menu utilisateur">MW</span>
    </button>
    </main></body></html>`);
  await page.addScriptTag({ content: AXE });
  const r = await page.evaluate(async () => await axe.run(document, { runOnly: { type: 'tag', values: ['wcag2a','wcag2aa','wcag21a','wcag21aa','wcag22aa'] }, resultTypes: ['violations'] }));
  console.log('  ', JSON.stringify(r.violations.map((v) => ({ regle: v.id, impact: v.impact, noeuds: v.nodes.length, cibles: v.nodes.map((n) => n.target.join(' ')) })), null, 1));
  console.log('  (le 2e declencheur, avec un <span>, est le motif de UserMenu/WorkspaceSelector : il ne doit PAS etre releve)');
  await ctx.close();
}

await browser.close();
