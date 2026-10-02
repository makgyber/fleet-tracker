# Fleet Tracker — Production Deployment Guide

This document is a self-contained reference for taking the Fleet Tracker system
from local development to production. It assumes no prior context beyond the
repository itself.

## System overview

Four deployable pieces:

| Piece | Path | What it is | Where it runs in prod |
| --- | --- | --- | --- |
| **API** | `api/` | Laravel REST API + route optimization + Firebase bridge | A server (VPS/PaaS) with HTTPS |
| **tbss integration** | (lives in the separate `tbss` project) | Endpoint + team UUID that the fleet API imports from | tbss's own production server |
| **Dashboard** | `dashboard/` | React + Vite monitoring UI (static) | Static host / CDN |
| **Driver app** | `driver-app/` | Expo (React Native) app, QR-scan based | Built with EAS, installed on devices |

External services: **Mapbox** (routing + map tiles), **Firebase Realtime
Database** (live positions/routes transport), **Google Maps SDK** (driver app
native map on Android).

Data flow: tbss daily schedule → fleet API imports teams+destinations → builds
& optimizes a trip per team → publishes routes to Firebase → driver scans team
QR, app loads the trip and streams GPS → dashboard shows live positions + ETAs.

---

## Can I just share the current APK?

**No.** The APK built so far is a **development build**: it requires the Metro
dev server running on the developer's machine and points `apiUrl` at a laptop
LAN IP (`192.168.5.136`). It will not work on anyone else's device off that
network.

To share with users you need a **preview** or **production** build (JS bundled
in, pointing at a deployed HTTPS API):

```bash
cd driver-app
eas build --profile preview --platform android     # standalone internal APK
# or, for Play Store:
eas build --profile production --platform android
eas submit --platform android
```

A `preview` APK can be side-loaded (users enable "install from unknown
sources"). A `production` build goes through the Play Store. Either way, the app
MUST point at a real deployed API first (see below).

---

## 1. Deploy the Laravel API (`api/`)

### Hosting options
- **Laravel Forge + a VPS** (DigitalOcean / AWS / Linode) — most common, gives
  Nginx + PHP-FPM + queue + scheduler management.
- **PaaS**: Laravel Cloud, Railway, Render, Fly.io — simpler, less control.

### Database — move off SQLite
SQLite was used in dev. For production use **MySQL or PostgreSQL** (concurrency,
backups, multiple app servers). The Eloquent models are DB-agnostic, so only
config changes:

```ini
# api/.env (production)
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=fleet_tracker
DB_USERNAME=fleet
DB_PASSWORD=<strong-password>
```

Then run migrations on the server: `php artisan migrate --force`.
(Unlike the tbss project, the fleet API's migrations are clean and safe to run
with the standard `migrate` command.)

### Production .env (api/.env) — key settings
```ini
APP_NAME="Fleet Tracker"
APP_ENV=production
APP_DEBUG=false
APP_KEY=<run: php artisan key:generate>
APP_URL=https://api.yourdomain.com

# Database: MySQL/Postgres as above

# Mapbox (routing + ETAs). Empty = local nearest-neighbor fallback.
MAPBOX_TOKEN=<server-side mapbox token with Directions/Optimization scope>
MAPBOX_PROFILE=mapbox/driving

# Firebase Admin (realtime transport)
FIREBASE_CREDENTIALS=/var/www/fleet/storage/app/firebase/service-account.json
FIREBASE_DATABASE_URL=https://<project>-default-rtdb.<region>.firebasedatabase.app

# tbss integration
TBSS_API_URL=https://tbss.yourdomain.com/api
TBSS_API_TOKEN=<PRODUCTION service token issued in tbss (NOT the dev one)>
```

### Web server + HTTPS (required)
- Nginx + PHP-FPM, document root at `api/public`.
- TLS via Let's Encrypt (certbot) or the platform's managed certs.
- **HTTPS is mandatory**: production Android builds block cleartext HTTP, and
  the dashboard/app must call `https://api.yourdomain.com`.

### Scheduler (daily tbss import)
The daily import (`fleet:import-schedule`, 05:30 Asia/Manila) needs Laravel's
scheduler running. Add a system cron:
```
* * * * * cd /var/www/fleet && php artisan schedule:run >> /dev/null 2>&1
```
(Forge has a one-click "scheduler" toggle that does this.)

### CORS
Allow the dashboard origin so browser calls aren't blocked. In
`config/cors.php` (or the framework default), set allowed origins to
`https://dashboard.yourdomain.com`.

### Firebase service account
Copy the service-account JSON to the server at the path in
`FIREBASE_CREDENTIALS`. Never commit it. The repo's `.gitignore` already
excludes `*firebase-adminsdk*.json` / `*service-account*.json`.

---

## 2. tbss integration (in the separate tbss project)

The integration code we added lives in the `tbss` project (not this repo):
- Migration: `database/migrations/*_add_uuid_to_teams_table.php` (adds the team
  `uuid` that backs the QR). **Run it on tbss production with
  `php artisan migrate --path=<that file>`** — NOT a bare `migrate`, because
  tbss's migration history is out of sync with its live DB.
- Endpoint: `GET /api/fleet/schedule?date=` (Sanctum-protected),
  `app/Http/Controllers/Api/FleetScheduleController.php` +
  `app/Http/Resources/FleetTeamResource.php` + route in `routes/api.php`.
- QR display: `app/View/Components/TeamQr.php` + the team-details Blade partial
  (uses the already-installed `bacon/bacon-qr-code`).

### Issue a production service token in tbss
```bash
php artisan tinker
>>> $u = App\Models\User::firstOrCreate(['email'=>'fleet-service@tbss.local'], ['name'=>'Fleet Service','password'=>Hash::make(Str::random(40)),'active_status'=>1]);
>>> $u->createToken('fleet-tracker-prod')->plainTextToken;   // put this in the fleet API's TBSS_API_TOKEN
```
Rotate/replace the dev token (`170|EPIK...`) — do not reuse it in production.

---

## 3. Deploy the dashboard (`dashboard/`)

Static Vite build — host anywhere that serves static files.

```bash
cd dashboard
# set production env first (see below)
npm ci
npm run build          # outputs dist/
```
Deploy `dist/` to Netlify, Vercel, Cloudflare Pages, S3+CloudFront, or Nginx.

### Production env (dashboard/.env)
```ini
VITE_API_URL=https://api.yourdomain.com/api
VITE_MAPBOX_TOKEN=<mapbox PUBLIC token (pk....)>
VITE_FIREBASE_API_KEY=<firebase web apiKey>
VITE_FIREBASE_AUTH_DOMAIN=<project>.firebaseapp.com
VITE_FIREBASE_DATABASE_URL=https://<project>-default-rtdb.<region>.firebasedatabase.app
VITE_FIREBASE_PROJECT_ID=<project-id>
VITE_FIREBASE_APP_ID=<firebase WEB app id (1:...:web:...)>
```
Note: the dashboard currently uses an Android Firebase appId; for the web you
should use a proper **Web** app id from Firebase console (Project settings →
Your apps → Web).

---

## 4. Driver app (`driver-app/`) — production build

### Point at the production API
In `driver-app/app.json`, set:
```json
"extra": {
  "apiUrl": "https://api.yourdomain.com/api",
  "firebase": { ... same project web config ... }
}
```
The dev value was a LAN IP (`http://192.168.5.136:8000/api`) — that only worked
for local testing. `apiUrl` is baked into the build, so changing it requires a
rebuild.

### Build + distribute
```bash
cd driver-app
eas build --profile preview --platform android     # shareable internal APK
# or production + eas submit for the Play Store
```
Profiles are defined in `driver-app/eas.json` (development / preview /
production). The `development` profile is dev-client only (needs Metro); use
`preview`/`production` for anything you hand to users.

### Maps key (Google Maps SDK for Android)
- The Android key lives in `app.json` → `expo.android.config.googleMaps.apiKey`.
- The key MUST be committed in `app.json` for EAS to embed it (a past bug: the
  key was uncommitted, so builds shipped without it → blank map).
- `android/` and `ios/` are gitignored so EAS generates native config from
  `app.json` (don't commit a local prebuild, it overrides the key).
- In Google Cloud Console: **enable "Maps SDK for Android"** and **enable
  billing** on the project (map display on mobile SDKs is free but billing must
  be linked). Restrict the key to the package `com.topbest.fleetdriver` + the
  EAS signing SHA-1 (`eas credentials` shows it).

### iOS (optional)
iOS uses Apple Maps by default (no key). To use Google Maps on iOS instead, set
`expo.ios.config.googleMapsApiKey` AND change the MapView provider to
`PROVIDER_GOOGLE` for iOS (currently `PROVIDER_GOOGLE` is used for both).

---

## 5. Security hardening (do before real users)

- **Firebase Realtime Database rules**: currently likely open. Lock down so the
  dashboard/app can only read what they should and only authenticated/intended
  writes are accepted. Paths in use: `vehicles/{id}/position`,
  `trips/{id}/route`, `teams/{uuid}/position`.
- **Team UUID is a public credential**: the driver endpoints
  `GET /api/teams/{uuid}/trip` and `POST /api/teams/{uuid}/positions` are
  unauthenticated — the QR UUID is the secret. Acceptable for a per-day QR, but
  for stronger security consider signed/expiring tokens. At minimum, keep QR
  codes from leaking publicly.
- **Secrets**: Firebase service-account JSON, Mapbox token, tbss token, Google
  Maps key — all server-side/restricted, none in git. Rotate the dev tbss token.
- **API keys restriction**: Mapbox public token is fine client-side but can be
  URL-restricted; Google Maps key must be app-restricted.
- **CORS + HTTPS**: enforce HTTPS everywhere; restrict API CORS to the dashboard
  domain.

---

## 6. Recommended deployment order

1. Provision server + domain + HTTPS; deploy Laravel API with MySQL/Postgres;
   run `php artisan migrate --force`; put the Firebase service-account on the
   server; set all `.env` secrets.
2. Deploy the tbss changes to tbss production; run the uuid migration via
   `--path`; issue a production service token; set it in the fleet API's
   `TBSS_API_TOKEN`.
3. Configure the daily scheduler cron on the API server.
4. Lock down Firebase security rules; restrict Google/Mapbox keys.
5. Deploy the dashboard static build with production env.
6. Point the driver app `apiUrl` at the production API; `eas build --profile
   preview`; test on a device; then `production` + `eas submit` if going to the
   Play Store.

---

## Current dev-only values to replace (quick checklist)

- [ ] API: SQLite → MySQL/Postgres
- [ ] API: `APP_ENV=production`, `APP_DEBUG=false`, fresh `APP_KEY`
- [ ] API `.env`: real Mapbox/Firebase/tbss values (prod tbss token, not dev)
- [ ] Driver app `app.json`: `apiUrl` LAN IP → `https://api.yourdomain.com/api`
- [ ] Dashboard `.env`: `VITE_API_URL` → production; Firebase **Web** appId
- [ ] Driver app: build with `preview`/`production` profile (not `development`)
- [ ] Google Cloud: Maps SDK for Android enabled + billing + key restricted
- [ ] Firebase: production security rules
- [ ] HTTPS + CORS configured

---

## Key facts worth remembering (gotchas hit during development)

- `driver-app/` is a subfolder inside the `fleet-tracker` git repo (no root
  package.json). Run Expo/EAS commands from **inside `driver-app/`**.
- EAS builds from **committed git state** — anything not committed (keys,
  config) won't be in the build.
- Expo SDK 57 / React Native 0.86 needs `driver-app/.npmrc` with
  `legacy-peer-deps=true` or the EAS install phase fails.
- `android/`/`ios/` are gitignored on purpose so EAS generates them from
  `app.json`.
- tbss migration history is out of sync with its live DB → always migrate tbss
  with `--path=<specific file>`, never a bare `php artisan migrate`.
- The driver app's `apiUrl` is baked in at build time; changing networks/hosts
  requires a rebuild (use a stable domain to avoid this).
