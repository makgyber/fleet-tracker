import { defineConfig } from 'vite'
import react from '@vitejs/plugin-react'

// The dashboard is co-served from the Laravel API under one subdomain.
// Production builds are written into the API's public dir (api/public/app) so
// Cloudways git-deploys the static assets alongside the API. Served at /app/,
// with Laravel serving index.html at / (see api/routes/web.php).
export default defineConfig({
  plugins: [react()],
  base: '/app/',
  build: {
    outDir: '../api/public/app',
    emptyOutDir: true,
  },
  server: {
    port: 5173,
  },
})
