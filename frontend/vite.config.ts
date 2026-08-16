import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react-swc';
import tailwindcss from '@tailwindcss/vite';
import path from 'node:path';

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

export default defineConfig({
  plugins: [react(), tailwindcss()],
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
    sourcemap: true,
    cssCodeSplit: true,
    rollupOptions: {
      output: {
        manualChunks: {
          react: ['react', 'react-dom'],
          router: ['@tanstack/react-router'],
          query: ['@tanstack/react-query', 'axios'],
          maplibre: ['maplibre-gl'],
        },
      },
    },
    chunkSizeWarningLimit: 500,
  },
});
