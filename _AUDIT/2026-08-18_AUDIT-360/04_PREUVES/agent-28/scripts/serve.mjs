import http from 'node:http';
import fs from 'node:fs';
import path from 'node:path';

const ROOT = 'C:/Users/willi/AppData/Local/Temp/claude/C--Users-willi-Documents-Projets-Axion-CRM-Pro/1db6a17f-df98-48a0-95d2-e361a24d41b6/scratchpad/a28/dist';
const MIME = { '.html': 'text/html', '.js': 'text/javascript', '.css': 'text/css', '.json': 'application/json', '.svg': 'image/svg+xml', '.png': 'image/png', '.map': 'application/json', '.woff2': 'font/woff2', '.ico': 'image/x-icon' };

http.createServer((req, res) => {
  const url = decodeURIComponent(req.url.split('?')[0]);
  // Toute requête /api/* est refusée franchement : la console n'est pas
  // joignable (A-010 + 502 mesuré), on mesure la STRUCTURE, pas les données.
  if (url.startsWith('/api')) { res.writeHead(503, { 'content-type': 'application/json' }); res.end('{"message":"api indisponible (mesure a11y structurelle)"}'); return; }
  let p = path.join(ROOT, url);
  if (!fs.existsSync(p) || fs.statSync(p).isDirectory()) p = path.join(ROOT, 'index.html');
  const ext = path.extname(p);
  res.writeHead(200, { 'content-type': MIME[ext] ?? 'application/octet-stream' });
  fs.createReadStream(p).pipe(res);
}).listen(4188, '127.0.0.1', () => console.log('serve http://127.0.0.1:4188'));
