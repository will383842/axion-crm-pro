import { defineConfig, loadEnv, type Plugin } from 'vite';
import react from '@vitejs/plugin-react-swc';
import tailwindcss from '@tailwindcss/vite';
import path from 'node:path';
import { rmSync } from 'node:fs';

/**
 * HMR config — 3 modes :
 *  - VITE_DISABLE_HMR=true               → désactivé (prod sans WS, par défaut)
 *  - VITE_HMR_PUBLIC=true                → activé via Caddy WSS public (wss://host:443 -> /__vite_hmr_websocket)
 *  - rien                                → dev local, clientPort 443 (CF Tunnel ou tls Caddy)
 */
function resolveHmrConfig(): false | Record<string, unknown> {
  // Sprint 18.9c — HMR désactivé par défaut (prod live).
  // Activer en posant VITE_HMR_PUBLIC=true (via Caddy) ou VITE_DISABLE_HMR=false (dev local).
  if (process.env['VITE_DISABLE_HMR'] !== 'false') return false;

  if (process.env['VITE_HMR_PUBLIC'] === 'true') {
    return {
      protocol: 'wss',
      host: process.env['VITE_HMR_HOST'] ?? 'app.axion-crm-pro.com',
      clientPort: Number(process.env['VITE_HMR_CLIENT_PORT'] ?? 443),
      path: process.env['VITE_HMR_PATH'] ?? '/__vite_hmr_websocket',
    };
  }

  return { clientPort: 443 };
}

/**
 * Garde de configuration — `VITE_API_BASE_URL`.
 *
 * Cette variable est figée DANS LE BUNDLE au build : une valeur fausse ne se
 * découvre qu'en production, dans le navigateur, sous forme de 404 ou de CORS.
 * Aucun test d'exécution ne peut la rattraper — il faut donc refuser le build.
 *
 * Deux formes fautives, toutes deux vues en vrai :
 *  - un suffixe `/api` : le client ajoute déjà `/api/v1` (`src/lib/api.ts`),
 *    la requête partirait sur `/api/api/v1` ;
 *  - une valeur locale (`localhost`) embarquée dans un build de production —
 *    c'est exactement ce qu'un `.env` avec DEUX lignes `VITE_API_BASE_URL`
 *    produit dès que quelqu'un réordonne le fichier : la dernière gagne.
 */
function verifierBaseApi(): void {
  const brut = process.env['VITE_API_BASE_URL'];
  if (!brut) return; // absente → le défaut du code s'applique, rien à vérifier

  const erreurs: string[] = [];
  if (/\/api\/?$/.test(brut)) {
    erreurs.push(`suffixe « /api » interdit (le client ajoute /api/v1) : ${brut}`);
  }
  if (brut.endsWith('/')) {
    erreurs.push(`barre finale interdite : ${brut}`);
  }
  // ⚠️ Le discriminant N'EST PAS `NODE_ENV` : Vite le force à `production`
  // dans TOUT `vite build`, y compris le build de vérification de la CI qui
  // pointe légitimement sur `api.localhost`. On exige donc un signal explicite,
  // posé par le seul chemin qui EXPÉDIE le bundle (`docker-compose.prod.yml`).
  if (process.env['VITE_BUILD_CIBLE'] === 'prod' && /localhost|127\.0\.0\.1/.test(brut)) {
    erreurs.push(`valeur locale dans un build destiné à la production : ${brut}`);
  }

  if (erreurs.length > 0) {
    const detail = erreurs.map((e) => `  - ${e}`).join('\n');
    throw new Error(
      [
        'VITE_API_BASE_URL invalide :',
        detail,
        'Forme attendue : origine nue, ex. https://app.axion-crm-pro.com',
      ].join('\n'),
    );
  }
}

verifierBaseApi();

/**
 * DM0-DEMO — GARDE DE CONSTRUCTION DE LA DÉMO (mode `demo` seulement).
 *
 * La démo est un site statique montré à des prospects : elle ne doit JAMAIS
 * pouvoir parler à un serveur réel ni embarquer une clé. On refuse donc de la
 * construire (ou de la servir) si :
 *  - `VITE_API_BASE_URL` n'est pas exactement `https://demo.invalid` (domaine
 *    réservé, qui ne se résout jamais : aucune requête ne peut sortir) ;
 *  - une variable `VITE_SENTRY_DSN` ou `VITE_REVERB_*` est réglée ;
 *  - `VITE_BUILD_CIBLE=prod` (le chemin qui expédie le CRM réel) ;
 *  - `VITE_DEMO` ne vaut pas `1`.
 * Les valeurs viennent de `.env.demo` ET de l'environnement du processus
 * (`loadEnv` les fusionne, le processus l'emporte) : une variable de CI ne
 * peut pas passer en douce.
 */
export function verifierDemo(env: Record<string, string>): void {
  const erreurs: string[] = [];
  if (env['VITE_DEMO'] !== '1') erreurs.push('VITE_DEMO doit valoir 1');
  if (env['VITE_API_BASE_URL'] !== 'https://demo.invalid') {
    erreurs.push(`VITE_API_BASE_URL doit valoir https://demo.invalid (trouvé : ${env['VITE_API_BASE_URL'] ?? 'rien'})`);
  }
  if ((env['VITE_SENTRY_DSN'] ?? '') !== '') erreurs.push('VITE_SENTRY_DSN est réglée');
  for (const cle of Object.keys(env)) {
    if (cle.startsWith('VITE_REVERB_') && (env[cle] ?? '') !== '') erreurs.push(`${cle} est réglée`);
  }
  if (env['VITE_BUILD_CIBLE'] === 'prod') erreurs.push('VITE_BUILD_CIBLE=prod');
  if (erreurs.length > 0) {
    throw new Error(['Construction de la démo refusée :', ...erreurs.map((e) => `  - ${e}`)].join('\n'));
  }
}

/**
 * Mode démo : page non indexée. Hors démo : `public/mockServiceWorker.js`
 * (copié d'office par Vite comme tout `public/`) est RETIRÉ de la sortie — le
 * build normal ne contient aucun octet de démo.
 */
function pluginDemo(estDemo: boolean): Plugin {
  let sortie = '';
  return {
    name: 'axion-demo',
    configResolved(config) {
      sortie = path.resolve(config.root, config.build.outDir);
    },
    transformIndexHtml(html) {
      // `index.html` porte déjà `noindex` (CRM privé) ; la démo l'EXIGE, on
      // l'ajoute donc si quelqu'un venait à le retirer du gabarit.
      if (!estDemo || /name="robots"[^>]*noindex/.test(html)) return html;
      return html.replace('<head>', '<head>\n    <meta name="robots" content="noindex, nofollow" />');
    },
    closeBundle() {
      if (!estDemo && sortie !== '') rmSync(path.join(sortie, 'mockServiceWorker.js'), { force: true });
    },
  };
}

/** Mode `demo` : vérifie la garde de construction, puis le signale. */
function preparerDemo(mode: string): boolean {
  const estDemo = mode === 'demo';
  if (estDemo) verifierDemo(loadEnv(mode, __dirname, ''));
  return estDemo;
}

export default defineConfig(({ mode }) => ({
  plugins: [react(), tailwindcss(), pluginDemo(preparerDemo(mode))],
  define: {
    // Figé au build : hors démo, `import.meta.env.VITE_DEMO !== '1'` devient
    // une constante et la branche de démo de `main.tsx` est éliminée.
    'import.meta.env.VITE_DEMO': JSON.stringify(mode === 'demo' ? '1' : ''),
  },
  resolve: {
    alias: {
      '@': path.resolve(__dirname, './src'),
    },
  },
  server: {
    host: '0.0.0.0',
    port: 5173,
    strictPort: true,
    hmr: resolveHmrConfig(),
    allowedHosts: ['app.axion-crm-pro.com', '.axion-crm-pro.com', 'localhost', 'app.localhost'],
  },
  preview: {
    host: '0.0.0.0',
    port: 4173,
  },
  build: {
    target: 'es2023',
    // Démo publique : pas de cartes de source (le code d'origine n'a rien à
    // faire sur un site de démonstration).
    sourcemap: mode !== 'demo',
    cssCodeSplit: true,
    rollupOptions: {
      output: {
        /**
         * G42-005 — CE DÉCOUPAGE NE COUVRE PAS REACT, ET IL FAUT LE DIRE.
         *
         * L'entrée `react: ['react', 'react-dom']` a été RETIRÉE le 2026-08-22.
         * Elle n'a jamais rien découpé : mesure sur le dernier build présent
         * (`dist/assets/`), le morceau annoncé `react-l0sNRNKZ.js` faisait
         * 44 octets — son contenu intégral était la ligne
         * `//# sourceMappingURL=…`. React et react-dom sont restés dans
         * `index-Bi4ad1jA.js` (1 046 694 o).
         *
         * POURQUOI : la forme OBJET de `manualChunks` ne retient que le module
         * exactement nommé. `react` et `react-dom` sont des paquets CommonJS
         * dont le point d'entrée n'est qu'une façade ; le code réel vit dans
         * `react/cjs/…`, qui n'est nommé nulle part ici. Les trois autres
         * entrées, elles, tiennent parce que leurs paquets sont en ESM —
         * `router` (88 127 o), `query` (87 325 o) et `maplibre` (802 715 o)
         * sortent bien.
         *
         * SI ON VEUT VRAIMENT SORTIR REACT : passer à la forme FONCTION
         * (`manualChunks(id)` testant `id.includes('/node_modules/react-dom/')`
         * puis `/node_modules/react/`) — et MESURER le build avant/après. Un
         * découpage mal posé duplique le runtime React 19 et casse les hooks à
         * l'exécution ; aucune gate de bundle ne le rattraperait.
         * Garde : `tests/perf/decoupage-manuel.test.ts`.
         */
        manualChunks: {
          router: ['@tanstack/react-router'],
          query: ['@tanstack/react-query', 'axios'],
          maplibre: ['maplibre-gl'],
        },
      },
    },
    chunkSizeWarningLimit: 500,
  },
}));
