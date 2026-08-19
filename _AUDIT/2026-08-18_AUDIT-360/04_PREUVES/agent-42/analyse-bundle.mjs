// Attribution des octets générés par module, à partir de la sourcemap.
// Algorithme source-map-explorer : chaque segment de mapping couvre les octets
// générés jusqu'au segment suivant de la même ligne.
import fs from 'node:fs';
import path from 'node:path';

const CHARS = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789+/';
const DEC = new Map([...CHARS].map((c, i) => [c, i]));

function decodeVLQ(str) {
  const out = [];
  let shift = 0, value = 0;
  for (const c of str) {
    const d = DEC.get(c);
    if (d === undefined) throw new Error('char invalide: ' + c);
    const cont = d & 32;
    value += (d & 31) << shift;
    if (cont) { shift += 5; }
    else {
      const neg = value & 1;
      value >>= 1;
      out.push(neg ? (value === 0 ? -0x80000000 : -value) : value);
      value = 0; shift = 0;
    }
  }
  return out;
}

function analyser(jsPath) {
  const mapPath = jsPath + '.map';
  const code = fs.readFileSync(jsPath, 'utf8');
  const map = JSON.parse(fs.readFileSync(mapPath, 'utf8'));
  const lignes = code.split('\n');
  const sources = map.sources;
  const par = new Map();
  const add = (k, n) => par.set(k, (par.get(k) ?? 0) + n);

  let srcIdx = 0, srcLine = 0, srcCol = 0;
  const groupes = map.mappings.split(';');
  let totalMappe = 0;

  for (let gl = 0; gl < groupes.length; gl++) {
    const g = groupes[gl];
    const ligne = lignes[gl] ?? '';
    let genCol = 0;
    if (!g) { add('[non mappé]', ligne.length + 1); continue; }
    const segs = g.split(',');
    const decodes = [];
    for (const s of segs) {
      const f = decodeVLQ(s);
      genCol += f[0];
      if (f.length >= 4) { srcIdx += f[1]; srcLine += f[2]; srcCol += f[3]; }
      decodes.push({ genCol, src: f.length >= 4 ? sources[srcIdx] : null });
    }
    // octets avant le premier segment
    if (decodes.length > 0 && decodes[0].genCol > 0) add('[non mappé]', decodes[0].genCol);
    for (let i = 0; i < decodes.length; i++) {
      const fin = i + 1 < decodes.length ? decodes[i + 1].genCol : ligne.length;
      const n = Math.max(0, fin - decodes[i].genCol);
      const clef = decodes[i].src ?? '[non mappé]';
      add(clef, n);
      if (decodes[i].src) totalMappe += n;
    }
    add('[non mappé]', 1); // le \n
  }

  return { par, taille: Buffer.byteLength(code), totalMappe };
}

function regrouper(par) {
  const g = new Map();
  const add = (k, n) => g.set(k, (g.get(k) ?? 0) + n);
  for (const [src, n] of par) {
    if (src === '[non mappé]') { add('[non mappé]', n); continue; }
    const p = src.replace(/\\/g, '/');
    const i = p.lastIndexOf('node_modules/');
    if (i >= 0) {
      let reste = p.slice(i + 'node_modules/'.length);
      const parts = reste.split('/');
      const pkg = parts[0].startsWith('@') ? parts[0] + '/' + parts[1] : parts[0];
      add('npm:' + pkg, n);
    } else {
      const j = p.indexOf('/src/');
      add(j >= 0 ? 'src' + p.slice(j + 4) : p, n);
    }
  }
  return g;
}

const cibles = process.argv.slice(2);
for (const f of cibles) {
  const { par, taille, totalMappe } = analyser(f);
  const g = regrouper(par);
  const tri = [...g.entries()].sort((a, b) => b[1] - a[1]);
  console.log('\n================ ' + path.basename(f) + ' — ' + taille + ' octets ================');
  console.log('octets attribués à une source : ' + totalMappe);
  let cumul = 0;
  for (const [k, n] of tri) {
    cumul += n;
    console.log(String(n).padStart(9) + '  ' + (100 * n / taille).toFixed(2).padStart(6) + '%  ' + k);
  }
  console.log('cumul = ' + cumul);
}
