import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react-swc';
import tailwindcss from '@tailwindcss/vite';
import path from 'node:path';

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
    // HMR : false en prod (Caddy ne forward pas le WS upgrade proprement), true en dev local
    hmr: process.env['VITE_DISABLE_HMR'] === 'true' ? false : { clientPort: 443 },
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
