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
