/**
 * Mesure des contrastes SUR LE RENDU RÉEL.
 *
 * Méthode : on injecte les composants concernés dans la page réelle de
 * l'application (feuille de style complète, y compris les 4 règles `!important`
 * de `index.css:88-91` relevées par D27-002), puis on lit les couleurs
 * CALCULÉES et on remonte la chaîne des ancêtres pour le fond effectif.
 * Les couleurs sont résolues en sRGB par un canvas 1×1 : la feuille de style
 * est en `oklch()`, qu'aucune expression régulière ne sait convertir.
 * On ne lit JAMAIS la classe Tailwind — on lit ce qui est peint.
 */
import { createRequire } from 'node:module';
const require = createRequire('C:/Users/willi/Documents/Projets/Axion-CRM-Pro/frontend/package.json');
const { chromium } = require('playwright');
const BASE = 'http://127.0.0.1:4188';

const SONDES = [
  ['QualityBadge complete',  'inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-xs font-medium bg-emerald-100 text-emerald-800'],
  ['QualityBadge partielle', 'inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-xs font-medium bg-amber-100 text-amber-800'],
  ['QualityBadge basique',   'inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-xs font-medium bg-rose-100 text-rose-800'],
  ['SizeCategoryBadge artisan', 'inline-flex rounded-md px-2 py-0.5 text-xs font-medium bg-orange-100 text-orange-800'],
  ['SizeCategoryBadge tpe',     'inline-flex rounded-md px-2 py-0.5 text-xs font-medium bg-sky-100 text-sky-800'],
  ['SizeCategoryBadge pme',     'inline-flex rounded-md px-2 py-0.5 text-xs font-medium bg-indigo-100 text-indigo-800'],
  ['SizeCategoryBadge eti',     'inline-flex rounded-md px-2 py-0.5 text-xs font-medium bg-violet-100 text-violet-800'],
  ['SizeCategoryBadge grande',  'inline-flex rounded-md px-2 py-0.5 text-xs font-medium bg-fuchsia-100 text-fuchsia-800'],
  ['SizeCategoryBadge inconnue','inline-flex rounded-md px-2 py-0.5 text-xs font-medium bg-slate-100 text-slate-600'],
  ['StatusPill neutral', 'inline-flex items-center gap-1.5 rounded-full px-2 py-0.5 text-[11px] font-medium bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-300'],
  ['StatusPill success', 'inline-flex items-center gap-1.5 rounded-full px-2 py-0.5 text-[11px] font-medium bg-emerald-50 dark:bg-emerald-950/40 text-emerald-700 dark:text-emerald-300'],
  ['StatusPill warning', 'inline-flex items-center gap-1.5 rounded-full px-2 py-0.5 text-[11px] font-medium bg-amber-50 dark:bg-amber-950/40 text-amber-700 dark:text-amber-300'],
  ['StatusPill danger',  'inline-flex items-center gap-1.5 rounded-full px-2 py-0.5 text-[11px] font-medium bg-rose-50 dark:bg-rose-950/40 text-rose-700 dark:text-rose-300'],
  ['StatusPill info',    'inline-flex items-center gap-1.5 rounded-full px-2 py-0.5 text-[11px] font-medium bg-sky-50 dark:bg-sky-950/40 text-sky-700 dark:text-sky-300'],
  ['StatusPill pending', 'inline-flex items-center gap-1.5 rounded-full px-2 py-0.5 text-[11px] font-medium bg-slate-100 dark:bg-slate-800 text-slate-600 dark:text-slate-400'],
  ['Card + texte secondaire', 'bg-white dark:bg-slate-900 text-slate-500 dark:text-slate-400'],
  ['Input placeholder',       'bg-white dark:bg-slate-900 text-slate-400'],
  ['TEMOIN bon (slate-900 sur blanc)',   'bg-white text-slate-900'],
  ['TEMOIN mauvais (slate-300 sur blanc)', 'bg-white text-slate-300'],
];

const CODE = String.raw`(sondes) => {
  const cv = document.createElement('canvas'); cv.width = cv.height = 1;
  const c2 = cv.getContext('2d', { willReadFrequently: true });
  const rgba = (css) => { c2.clearRect(0,0,1,1); c2.fillStyle = '#000'; c2.fillStyle = css;
    c2.fillRect(0,0,1,1); const d = c2.getImageData(0,0,1,1).data;
    return { rgb: [d[0], d[1], d[2]], a: d[3] / 255 }; };
  const lum = (a) => { const f = (c) => { c /= 255; return c <= 0.03928 ? c/12.92 : Math.pow((c+0.055)/1.055, 2.4); };
    return 0.2126*f(a[0]) + 0.7152*f(a[1]) + 0.0722*f(a[2]); };
  const melange = (fg, bg) => fg.rgb.map((c,i) => Math.round(c*fg.a + bg[i]*(1-fg.a)));
  const fondEffectif = (el) => { let n = el;
    while (n && n.nodeType === 1) {
      const c = rgba(getComputedStyle(n).backgroundColor);
      if (c.a > 0) { if (c.a >= 0.999) return c.rgb; return melange(c, fondEffectif(n.parentElement || document.body)); }
      n = n.parentElement; }
    return [255,255,255]; };
  const hex = (a) => '#' + a.map(v => v.toString(16).padStart(2,'0')).join('');
  const hote = document.createElement('div');
  hote.style.cssText = 'position:fixed;left:0;top:300px;z-index:99999';
  document.body.appendChild(hote);
  const res = [];
  for (const [nom, cls] of sondes) {
    const s = document.createElement('span'); s.className = cls; s.textContent = 'Exemple';
    hote.appendChild(s);
    const cs = getComputedStyle(s);
    const fgp = rgba(cs.color);
    const bg = fondEffectif(s);
    const fg = fgp.a >= 0.999 ? fgp.rgb : melange(fgp, bg);
    const L1 = lum(fg), L2 = lum(bg);
    const ratio = (Math.max(L1,L2)+0.05) / (Math.min(L1,L2)+0.05);
    res.push({ nom, fg: hex(fg), bg: hex(bg), ratio: Math.round(ratio*100)/100, taille: cs.fontSize, gras: cs.fontWeight });
    hote.removeChild(s);
  }
  // Lien d'évitement : sa propre couleur et le fond réellement peint dessous.
  const a = document.querySelector('.skip-link');
  if (a) {
    a.focus();
    const cs = getComputedStyle(a);
    const fgp = rgba(cs.color);
    const propre = rgba(cs.backgroundColor);
    const bg = fondEffectif(a.parentElement);
    const fg = fgp.a >= 0.999 ? fgp.rgb : melange(fgp, bg);
    const L1 = lum(fg), L2 = lum(bg);
    res.push({ nom: 'LIEN D EVITEMENT (.skip-link:focus)', fg: hex(fg), bg: hex(bg),
      ratio: Math.round(((Math.max(L1,L2)+0.05)/(Math.min(L1,L2)+0.05))*100)/100,
      taille: cs.fontSize, gras: cs.fontWeight, fondPropre: 'alpha=' + propre.a, padding: cs.padding });
  }
  hote.remove();
  return res;
}`;

const browser = await chromium.launch();
for (const mode of ['clair', 'sombre']) {
  const ctx = await browser.newContext({ viewport: { width: 1440, height: 900 } });
  await ctx.addInitScript((m) => { try { localStorage.setItem('axion-theme', m); } catch {} }, mode === 'sombre' ? 'dark' : 'light');
  const page = await ctx.newPage();
  await page.goto(BASE + '/companies', { waitUntil: 'networkidle' });
  await page.waitForTimeout(900);
  const dark = await page.evaluate(() => document.documentElement.classList.contains('dark'));
  console.log(`\n══════ MODE ${mode.toUpperCase()} (html.dark=${dark}) ══════`);
  console.log('composant'.padEnd(38), 'texte'.padEnd(9), 'fond'.padEnd(9), ' ratio', ' seuil verdict');
  const r = await page.evaluate('(' + CODE + ')(' + JSON.stringify(SONDES) + ')');
  for (const x of r) {
    const px = parseFloat(x.taille);
    const gros = px >= 24 || (px >= 18.66 && Number(x.gras) >= 700);
    const seuil = gros ? 3 : 4.5;
    console.log(x.nom.padEnd(38), x.fg.padEnd(9), x.bg.padEnd(9), String(x.ratio).padStart(6), `  ${seuil}  `,
      x.ratio >= seuil ? 'OK' : 'ECHEC AA', x.fondPropre ? `| fond propre ${x.fondPropre} padding ${x.padding}` : '');
  }
  await ctx.close();
}
await browser.close();
