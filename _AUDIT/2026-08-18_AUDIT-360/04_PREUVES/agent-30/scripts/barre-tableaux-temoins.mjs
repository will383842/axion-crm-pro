// AGENT 30 — barre laterale (repliee / tiroir mobile), tableaux larges, temoins negatifs
import { chromium } from 'playwright';
import fs from 'node:fs';
import path from 'node:path';

const BASE = process.argv[2] || 'http://app.localhost:58130';
const OUT = process.argv[3];
const L = [];
const log = (...a) => { const s = a.join(' '); L.push(s); console.log(s); };

const b = await chromium.launch();

// ---------------------------------------------------------------- 1. BARRE LATERALE
log('=== 1. BARRE LATERALE — deployee vs repliee (1280x900, seuil lg atteint) ===');
{
  const ctx = await b.newContext({ viewport: { width: 1280, height: 900 } });
  const p = await ctx.newPage();
  const snap = async (collapsed, route) => {
    await p.goto(BASE + route, { waitUntil: 'load' }).catch(() => {});
    await p.evaluate((c) => localStorage.setItem('axion-crm:sidebar:collapsed', c ? '1' : '0'), collapsed);
    await p.reload({ waitUntil: 'load' }).catch(() => {});
    await p.waitForTimeout(2500);
    return p.evaluate(() => {
      const a = document.querySelector('aside[data-tour="sidebar"]');
      const vis = (e) => { const r = e.getBoundingClientRect(); return r.width > 0 && r.height > 0; };
      return {
        largeur: Math.round(a.getBoundingClientRect().width),
        titres: Array.from(a.querySelectorAll('nav h3 button')).filter(vis).map((x) => x.innerText.trim()),
        liens: Array.from(a.querySelectorAll('nav a')).filter(vis).map((x) => { const r = x.getBoundingClientRect(); return { href: x.getAttribute('href'), y: Math.round(r.top), w: Math.round(r.width), h: Math.round(r.height) }; }),
        pied: Array.from(a.querySelectorAll('button')).filter(vis).map((x) => (x.innerText || x.getAttribute('aria-label') || '').trim()).slice(-1),
      };
    });
  };
  for (const route of ['/', '/companies']) {
    const dep = await snap(false, route);
    const rep = await snap(true, route);
    log('\n--- route ' + route);
    log('  DEPLOYEE  largeur=' + dep.largeur + 'px  titres=' + dep.titres.length + ' [' + dep.titres.join(', ') + ']  liens visibles=' + dep.liens.length);
    dep.liens.forEach((l) => log('     ' + l.href.padEnd(26) + ' y=' + l.y + '  ' + l.w + 'x' + l.h));
    log('  REPLIEE   largeur=' + rep.largeur + 'px  titres=' + rep.titres.length + '  liens visibles=' + rep.liens.length);
    rep.liens.forEach((l) => log('     ' + l.href.padEnd(26) + ' y=' + l.y + '  ' + l.w + 'x' + l.h));
    const map = Object.fromEntries(rep.liens.map((l) => [l.href, l.y]));
    log('  ECARTS DE POSITION (exigence CDC 23.3 : « aux memes positions ») :');
    dep.liens.forEach((l) => log('     ' + l.href.padEnd(26) + ' deployee y=' + l.y + '  repliee y=' + (map[l.href] ?? 'ABSENTE') + '  ecart=' + (map[l.href] !== undefined ? (l.y - map[l.href]) + 'px' : 'n/a')));
    log('  entrees affichees en repliee mais ABSENTES en deployee : ' + rep.liens.filter((l) => !dep.liens.some((d) => d.href === l.href)).length);
    log('  pied de barre deployee=' + JSON.stringify(dep.pied) + '  repliee=' + JSON.stringify(rep.pied));
  }
  await ctx.close();
}

// ---------------------------------------------------------------- 2. TIROIR MOBILE
log('\n=== 2. TIROIR DE NAVIGATION MOBILE (375x812) ===');
{
  const ctx = await b.newContext({ viewport: { width: 375, height: 812 }, isMobile: true, hasTouch: true, deviceScaleFactor: 2 });
  const p = await ctx.newPage();
  await p.goto(BASE + '/companies', { waitUntil: 'load' }).catch(() => {});
  await p.waitForTimeout(2600);
  log('barre laterale de bureau visible a 375px : ' + await p.evaluate(() => { const a = document.querySelector('aside[data-tour="sidebar"]'); return a ? a.getBoundingClientRect().width > 0 : false; }));
  log('bouton hamburger present : ' + await p.evaluate(() => !!document.querySelector('button[aria-label="Ouvrir le menu"]')));
  await p.click('button[aria-label="Ouvrir le menu"]');
  await p.waitForTimeout(1200);
  if (OUT) await p.screenshot({ path: path.join(OUT, 'captures-375', 'tiroir-navigation.png') });
  const tiroir = await p.evaluate(() => {
    const dlg = document.querySelector('[role="dialog"]');
    const vis = (e) => { const r = e.getBoundingClientRect(); return r.width > 0 && r.height > 0; };
    const SEL = 'a[href],button,input,select,textarea,[role="button"]';
    const cibles = Array.from(dlg.querySelectorAll(SEL)).filter(vis).map((e) => { const r = e.getBoundingClientRect(); return { lbl: ((e.getAttribute('aria-label') || e.innerText || '') + '').trim().replace(/\s+/g, ' ').slice(0, 34), w: Math.round(r.width), h: Math.round(r.height), ok: r.width >= 44 && r.height >= 44 }; });
    return { texte: (dlg.innerText || '').replace(/\n/g, ' | '), nCibles: cibles.length, nSous44: cibles.filter((c) => !c.ok).length, cibles };
  });
  log('contenu du tiroir : ' + tiroir.texte);
  log('cibles tactiles du tiroir : ' + tiroir.nCibles + ' dont ' + tiroir.nSous44 + ' sous 44x44');
  tiroir.cibles.forEach((c) => log('   ' + (c.ok ? 'OK ' : '<44') + ' ' + String(c.w) + 'x' + String(c.h) + '  ' + c.lbl));
  // le tiroir se referme-t-il apres navigation ?
  await p.click('[role="dialog"] a[href="/journalists"]');
  await p.waitForTimeout(1600);
  const apres = await p.evaluate(() => ({ url: location.pathname, tiroirOuvert: !!document.querySelector('[role="dialog"]'), bodyOverflow: document.body.style.overflow }));
  log('APRES clic sur une entree du menu : url=' + apres.url + '  tiroir encore ouvert=' + apres.tiroirOuvert + '  body.overflow=' + JSON.stringify(apres.bodyOverflow));
  // recherche mobile
  await p.goto(BASE + '/companies', { waitUntil: 'load' }).catch(() => {});
  await p.waitForTimeout(2500);
  await p.click('button[aria-label="Rechercher"]');
  await p.waitForTimeout(1000);
  const rech1 = await p.evaluate(() => { const d = document.querySelector('[role="dialog"]'); const vis = (e) => { const r = e.getBoundingClientRect(); return r.width > 0 && r.height > 0; }; return { txt: (d.innerText || '').replace(/\n/g, ' | '), boutons: Array.from(d.querySelectorAll('button,input')).filter(vis).map((e) => { const r = e.getBoundingClientRect(); return ((e.getAttribute('aria-label') || e.innerText || e.placeholder || '') + '').trim().replace(/\s+/g, ' ') + ' ' + Math.round(r.width) + 'x' + Math.round(r.height); }) }; });
  log('recherche mobile, 1er niveau : ' + rech1.txt);
  log('  controles : ' + JSON.stringify(rech1.boutons));
  await p.evaluate(() => { const d = document.querySelector('[role="dialog"]'); const b = Array.from(d.querySelectorAll('button')).find((x) => /Rechercher/.test(x.innerText)); b.click(); });
  await p.waitForTimeout(1000);
  const rech2 = await p.evaluate(() => ({ nDialogs: document.querySelectorAll('[role="dialog"]').length, txt: Array.from(document.querySelectorAll('[role="dialog"]')).map((d) => (d.innerText || '').replace(/\n/g, ' | ').slice(0, 160)) }));
  log('recherche mobile, apres 2e appui : ' + rech2.nDialogs + ' dialogues empiles ; ' + JSON.stringify(rech2.txt));
  if (OUT) await p.screenshot({ path: path.join(OUT, 'captures-375', 'recherche-mobile.png') });
  await ctx.close();
}

// ---------------------------------------------------------------- 3. TABLEAUX LARGES
log('\n=== 3. TABLEAUX LARGES a 375px — sonde du patron reel + TEMOIN ===');
{
  const ctx = await b.newContext({ viewport: { width: 375, height: 812 }, isMobile: true, hasTouch: true, deviceScaleFactor: 2 });
  const p = await ctx.newPage();
  await p.goto(BASE + '/contacts', { waitUntil: 'load' }).catch(() => {});
  await p.waitForTimeout(2600);
  const r = await p.evaluate(() => {
    const d = document, W = window;
    const main = d.getElementById('main');
    const GRID = 'minmax(220px,1.4fr) minmax(140px,1fr) minmax(220px,1.6fr) 110px minmax(140px,1fr) minmax(140px,1fr)';
    const HDR = 'sticky top-0 z-10 grid items-center gap-3 border-b border-slate-200 bg-slate-50/80 px-4 py-3 text-[11px] font-semibold uppercase tracking-wider text-slate-600 backdrop-blur';
    const CARD = 'rounded-2xl bg-white ring-1 ring-slate-200/70 overflow-hidden';
    const mk = (wrap) => {
      const card = d.createElement('div'); card.className = CARD;
      let inner = card;
      if (wrap) { inner = d.createElement('div'); inner.className = wrap; card.appendChild(inner); }
      const row = d.createElement('div'); row.setAttribute('role', 'row'); row.className = HDR; row.style.gridTemplateColumns = GRID;
      ['Contact', 'Role', 'Email', 'Score', 'Telephone', 'Entreprise'].forEach((t) => { const c = d.createElement('div'); c.textContent = t; row.appendChild(c); });
      inner.appendChild(row); main.appendChild(card);
      return { card, inner, row };
    };
    const A = mk(null);            // patron REEL du produit : Card overflow-hidden, aucun conteneur defilable
    const B = mk('overflow-x-auto'); // TEMOIN : le meme, dans un conteneur defilable
    const info = (o) => ({
      carteLargeurVisible: o.card.clientWidth, carteLargeurContenu: o.card.scrollWidth,
      carteOverflowX: W.getComputedStyle(o.card).overflowX,
      conteneurOverflowX: W.getComputedStyle(o.inner).overflowX,
      conteneurLargeurContenu: o.inner.scrollWidth,
      defilableParUtilisateur: /(auto|scroll)/.test(W.getComputedStyle(o.inner).overflowX) && o.inner.scrollWidth > o.inner.clientWidth,
      derniereColonneDroite: Math.round(o.row.lastElementChild.getBoundingClientRect().right),
      derniereColonneVisible: o.row.lastElementChild.getBoundingClientRect().right <= 375,
    });
    return { VW: d.documentElement.clientWidth, mainOverflowX: W.getComputedStyle(main).overflowX, SONDE_A_patron_produit: info(A), SONDE_B_temoin: info(B) };
  });
  log(JSON.stringify(r, null, 2));
  await ctx.close();
}

// ---------------------------------------------------------------- 4. TEMOINS NEGATIFS
log('\n=== 4. TEMOINS NEGATIFS DU DETECTEUR ===');
{
  const ctx = await b.newContext({ viewport: { width: 375, height: 812 }, isMobile: true, hasTouch: true, deviceScaleFactor: 2 });
  const p = await ctx.newPage();
  await p.goto(BASE + '/', { waitUntil: 'load' }).catch(() => {});
  await p.waitForTimeout(2600);
  const avant = await p.evaluate(() => {
    const W = window, VW = document.documentElement.clientWidth;
    const vis = (e) => { const r = e.getBoundingClientRect(); return r.width > 0 && r.height > 0; };
    const bb = Array.from(document.querySelectorAll('*')).filter((e) => { if (!vis(e)) return false; const s = W.getComputedStyle(e); if (s.position !== 'fixed' && s.position !== 'sticky') return false; const r = e.getBoundingClientRect(); return r.bottom > W.innerHeight - 8 && r.width > VW * 0.7 && r.top > W.innerHeight * 0.5; });
    return bb.length;
  });
  log('barres basses detectees SANS plantage : ' + avant);
  const apres = await p.evaluate(() => {
    // on plante une barre basse a cinq entrees, exactement celle que le CDC 23.3 exige
    const nav = document.createElement('nav');
    nav.id = 'a30-temoin-barre-basse';
    nav.style.cssText = 'position:fixed;left:0;right:0;bottom:0;height:56px;display:flex;background:#fff;z-index:60';
    ['Aujourd’hui', 'Contacts', 'Échanges', 'Rechercher', 'Plus'].forEach((t) => { const a = document.createElement('a'); a.href = '#'; a.textContent = t; a.style.cssText = 'flex:1;display:flex;align-items:center;justify-content:center;height:56px'; nav.appendChild(a); });
    document.body.appendChild(nav);
    const W = window, VW = document.documentElement.clientWidth;
    const vis = (e) => { const r = e.getBoundingClientRect(); return r.width > 0 && r.height > 0; };
    const bb = Array.from(document.querySelectorAll('*')).filter((e) => { if (!vis(e)) return false; const s = W.getComputedStyle(e); if (s.position !== 'fixed' && s.position !== 'sticky') return false; const r = e.getBoundingClientRect(); return r.bottom > W.innerHeight - 8 && r.width > VW * 0.7 && r.top > W.innerHeight * 0.5; });
    // et une cible tactile conforme 44x44
    const btn = document.createElement('button'); btn.textContent = 'temoin44'; btn.style.cssText = 'width:48px;height:48px'; document.getElementById('main').appendChild(btn);
    const SEL = 'a[href],button,input:not([type=hidden]),select,textarea,[role="button"],[role="tab"],[tabindex]:not([tabindex="-1"])';
    const cibles = Array.from(document.querySelectorAll(SEL)).filter(vis).map((e) => { const r = e.getBoundingClientRect(); return { ok: r.width >= 44 && r.height >= 44, lbl: (e.innerText || '').trim().slice(0, 20) }; });
    return { barresBasses: bb.map((e) => ({ tag: e.tagName.toLowerCase(), txt: (e.innerText || '').replace(/\n/g, ' | ') })), conformes: cibles.filter((c) => c.ok).map((c) => c.lbl) };
  });
  log('barres basses detectees APRES plantage : ' + apres.barresBasses.length + ' -> ' + JSON.stringify(apres.barresBasses));
  log('cibles >= 44x44 detectees apres plantage du temoin44 : ' + JSON.stringify(apres.conformes));
  await ctx.close();
}

await b.close();
if (OUT) fs.writeFileSync(path.join(OUT, '02_barre-tableaux-temoins.txt'), L.join('\n'));
