# Fleet Tracker — Cloudways Deployment (single subdomain)

Deploys the Laravel API **and** the React dashboard together as **one app** on
**one subdomain**: `https://fleet.topbestsystems.com`.

- The dashboard is a decoupled SPA (token auth, no cookies, no client-side
  router) that calls the API over `fetch`. It is **co-served by Laravel**: the
  built assets live in `api/public/app/` and Laravel serves `index.html` at `/`.
- Because the dashboard and API share one origin, **there is no CORS to
  configure**.
- The dashboard is **built on the server during deploy** — the build output
  (`api/public/app/`) and the dashboard env file (`dashboard/.env.production`)
  are **gitignored**, so no tokens ever enter the repo. The frontend tokens live
  only in a server-side `dashboard/.env.production` you create once.

This is the companion to `DEPLOYMENT.md` (the full multi-piece guide). This file
covers only the Cloudways single-subdomain setup for the API + dashboard. The
tbss integration and the driver app are unchanged — see `DEPLOYMENT.md`.

---

## How it fits together

```
Browser → https://fleet.topbestsystems.com/            → Laravel route '/' → public/app/index.html (dashboard SPA)
Browser → https://fleet.topbestsystems.com/app/assets/* → static files served directly by the web server
Browser → https://fleet.topbestsystems.com/api/*        → Laravel API (routes/api.php)
```

- `vite.config.js` → `base: '/app/'`, `build.outDir: '../api/public/app'`.
- `api/routes/web.php` → `GET /` returns `public/app/index.html` (and a clean
  503 if the build is missing, e.g. a failed deploy build).
- `dashboard/.env.production` (server-only, gitignored) →
  `VITE_API_URL=https://fleet.topbestsystems.com/api` + Mapbox/Firebase values.

> **Why build on the server?** The Mapbox token (and the whole Firebase web
> config) get baked into the client JS bundle at build time. Building on the
> server keeps those values in a server-only env file instead of committing
> them to git. Cloudways servers have Node available, so this is a plain
> `npm ci && npm run build` as part of each deploy.

---

## 1. Create the app on Cloudways

1. **Launch/choose a server** (DigitalOcean/AWS/etc.), then **Add Application** →
   choose **Laravel** (or "PHP Stack" / "Custom PHP"). This provisions MySQL
   automatically.
2. Set **PHP version to 8.3+** (the API requires `php: ^8.3`). Cloudways →
   Server → Settings & Packages → PHP version.
3. Confirm **Node + npm are available** on the server (Cloudways includes them;
   check with `node -v && npm -v` over SSH). Needed for the dashboard build.
4. Note the app's **MySQL credentials** (Application → Access Details): DB name,
   user, password, host (usually `localhost`).

### Document root
Cloudways serves from `public_html` by default. The Laravel public dir is
`api/public`. Two options:

- **Simplest:** in Application Settings → set the **Webroot** to
  `public_html/api/public` (Cloudways supports a custom webroot field), and
  deploy the repo into `public_html`.
- **Or** symlink after deploy: point `public_html` at `api/public`.

Pick the webroot override if your Cloudways plan exposes it; it avoids symlink
upkeep.

---

## 2. Git deployment

1. Application → **Deployment via Git**.
2. Add the repo SSH URL and branch (e.g. `main`). Add Cloudways' deploy key to
   the repo as a read-only deploy key.
3. **Deployment path**: the repo root (so both `api/` and `dashboard/` land on
   the server). Set the webroot to the `api/public` subpath as above.
4. **Pull** to do the initial deploy.

> Cloudways Git deployment pulls files but does **not** run Composer or the
> dashboard build by default. Run the post-deploy steps below over SSH (or add
> them as a Cloudways deployment hook / cron if your plan supports it).

---

## 3. One-time: create the server-side dashboard env

`dashboard/.env.production` is **gitignored**, so it does not come down with the
pull. Create it **once on the server** (it persists across future git deploys
because git won't touch an untracked file). Vite auto-loads it during
`npm run build`.

```bash
cd applications/<app>/public_html/dashboard
cat > .env.production <<'EOF'
VITE_API_URL=https://fleet.topbestsystems.com/api
VITE_MAPBOX_TOKEN=pk.<your domain-restricted mapbox public token>
VITE_FIREBASE_API_KEY=AIzaSyAeluycXDZwDlVhi3A2xEsB4krqkNJztqI
VITE_FIREBASE_AUTH_DOMAIN=topbest-aee58.firebaseapp.com
VITE_FIREBASE_DATABASE_URL=https://topbest-aee58-default-rtdb.asia-southeast1.firebasedatabase.app
VITE_FIREBASE_PROJECT_ID=topbest-aee58
VITE_FIREBASE_APP_ID=1:1085221155173:web:c151883d3c9f773ea2f18c
EOF
```

Notes:
- The Firebase `appId` here is the **Web** app id (`1:...:web:...`), taken from
  `driver-app/app.json` (`extra.firebase`). Do not use the Android id.
- The Mapbox token is a **public** `pk.` token — restrict it in your Mapbox
  account to `https://fleet.topbestsystems.com/*` so the exposed-in-bundle value
  only works from your domain.
- These `VITE_*` values are baked into the client bundle at build time; they are
  not secrets in the server sense, but keeping them server-side avoids
  committing them to git.

---

## 4. Post-deploy steps (SSH)

SSH in (Application → Launch SSH Terminal, or `ssh master@<server-ip>`), then run
the dashboard build followed by the API steps.

```bash
# --- Build the dashboard (writes ../api/public/app) ---
cd applications/<app>/public_html/dashboard
npm ci
npm run build            # uses the server-side .env.production from step 3

# --- API ---
cd ../api
composer install --no-dev --optimize-autoloader

# First deploy only: generate the app key (writes to .env)
php artisan key:generate

# Run migrations against MySQL
php artisan migrate --force

# Cache config/routes/views for production
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

If the dashboard build fails or hasn't run yet, `GET /` returns a clean 503
("Dashboard build not found") instead of erroring — fix the build and re-run
`npm run build`.

> After any deploy that changes `.env` or routes, re-run
> `php artisan config:cache` and `php artisan route:cache` (or
> `php artisan optimize`).

---

## 5. Production `.env` (api/.env)

Set these in `applications/<app>/public_html/api/.env` (via SSH or Cloudways'
file manager). `.env` is gitignored, so it never ships in the repo.

```ini
APP_NAME="Fleet Tracker"
APP_ENV=production
APP_DEBUG=false
APP_KEY=            # set by: php artisan key:generate
APP_URL=https://fleet.topbestsystems.com

# Database — use the Cloudways MySQL credentials from Access Details
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=<cloudways db name>
DB_USERNAME=<cloudways db user>
DB_PASSWORD=<cloudways db password>

# Mapbox (server-side token with Directions/Optimization scope).
# Empty = local nearest-neighbor fallback.
# NOTE: this is the API's own server-side token — separate from the dashboard's
# public pk. token in dashboard/.env.production.
MAPBOX_TOKEN=<server-side mapbox token>
MAPBOX_PROFILE=mapbox/driving

# Firebase Admin (realtime transport). Upload the service-account JSON to the
# server (NOT in git) and point here:
FIREBASE_CREDENTIALS=/home/master/applications/<app>/public_html/api/storage/app/firebase/service-account.json
FIREBASE_DATABASE_URL=https://topbest-aee58-default-rtdb.asia-southeast1.firebasedatabase.app

# tbss integration — use the PRODUCTION service token (not the dev one)
TBSS_API_URL=https://tbss.topbestsystems.com/api
TBSS_API_TOKEN=<production tbss service token>
```

Upload the Firebase service-account JSON to the path in `FIREBASE_CREDENTIALS`
(create `storage/app/firebase/` if needed). It must never be committed —
`.gitignore` already excludes `*service-account*.json`.

---

## 6. HTTPS + domain

1. Application → **Domain Management** → add `fleet.topbestsystems.com` as the
   primary domain. Point the subdomain's DNS A record at the Cloudways server IP.
2. Application → **SSL Certificate** → Let's Encrypt → issue for
   `fleet.topbestsystems.com`. Enable **Force HTTPS**.

HTTPS is mandatory: production Android builds block cleartext HTTP, and the
dashboard calls the API over HTTPS.

---

## 7. Scheduler (daily tbss import)

The daily import (`fleet:import-schedule`, 05:30 Asia/Manila) needs Laravel's
scheduler. On Cloudways → Application → **Cron Job Management**, add:

```
* * * * * cd /home/master/applications/<app>/public_html/api && php artisan schedule:run >> /dev/null 2>&1
```

---

## 8. Verify after deploy

```bash
# Dashboard loads (expect 200 + HTML referencing /app/assets/...)
curl -I https://fleet.topbestsystems.com/

# A static asset resolves (expect 200, application/javascript)
curl -I https://fleet.topbestsystems.com/app/assets/<hashed>.js

# API responds (expect 401 JSON when unauthenticated)
curl -i https://fleet.topbestsystems.com/api/vehicles
```

Then open `https://fleet.topbestsystems.com/` in a browser, log in, and confirm
the map + live positions load.

---

## Redeploy checklist (every release)

1. Commit + push code changes (API and/or dashboard source).
2. Cloudways → Git deployment → **Pull** (or auto-deploy).
3. SSH, then:
   - If dashboard source changed: `cd dashboard && npm ci && npm run build`.
   - If PHP deps changed: `composer install --no-dev --optimize-autoloader`.
   - If new migrations: `php artisan migrate --force`.
   - Always after route/config/env changes: `php artisan optimize`.
4. Verify with the curl checks above.

> Changing `VITE_API_URL`, the Mapbox token, or the Firebase appId means editing
> the server-side `dashboard/.env.production` and re-running `npm run build` —
> those values are baked into the bundle at build time.

---

## Notes / gotchas

- **One origin, no CORS.** Keep it that way — if you ever split the dashboard to
  its own host, you must add a CORS config allowing that origin.
- **Nothing sensitive in git.** The built bundle (`api/public/app`) and
  `dashboard/.env.production` are gitignored and built/created on the server.
  Don't un-ignore them — that's what reintroduces tokens into the repo.
- **Node is required on the server** for the dashboard build. If a build fails,
  `GET /` serves a 503 until it's fixed (the site degrades gracefully rather
  than erroring).
- **Rebuild to change the API URL, Mapbox token, or Firebase appId** — they're
  baked into the bundle at build time.
- **Migrations are safe here.** Unlike the tbss project, the fleet API's
  migrations run with a plain `php artisan migrate --force`.
- **PHP 8.3+ required** (`composer.json`: `php: ^8.3`).
