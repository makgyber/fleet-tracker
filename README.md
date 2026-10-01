# Fleet Tracker

Real-time fleet tracking system. Smartphones report GPS, a web dashboard
monitors the fleet live, and the backend suggests efficient multi-stop routes
with ETAs.

## Architecture

```
 Driver App (Expo)            Web Dashboard (React + Vite)
   device GPS                   live map + ETAs
       │  writes position            ▲ subscribes
       ▼                              │
 ┌─────────────────── Firebase Realtime Database ───────────────────┐
 │   /vehicles/{id}/position     /trips/{id}/route                   │
 └──────────────────────────────────────────────────────────────────┘
       ▲ publishes route/ETAs           ▲ mirrors positions
       │                                │
 ┌──────────────────── Laravel REST API (SQLite) ───────────────────┐
 │  auth · vehicles · drivers · destinations · trips · positions     │
 │  route optimization + ETA (Mapbox Optimization API / local)       │
 └────────────────────────────────────────────────────────────────── ┘
```

- **api/** — Laravel 13 REST API, SQLite, Sanctum auth. System of record +
  route optimization + Firebase bridge.
- **dashboard/** — React + Vite monitoring dashboard (Mapbox GL map, live
  positions, ETAs).
- **driver-app/** — Expo (React Native) app for drivers: streams GPS, shows the
  assigned route and next-stop ETA.

### Division of responsibilities

- **Laravel + SQLite** — authoritative data, business logic, route/ETA
  computation, GPS history.
- **Firebase Realtime Database** — low-latency transport for live positions and
  routes. The app writes positions; the dashboard subscribes.
- **Mapbox** — driving-route optimization and real ETAs. Falls back to a local
  nearest-neighbor estimate when no token is set (so everything runs offline).

## Prerequisites

- PHP 8.2+ and Composer
- Node.js 18+ and npm
- (Optional) Mapbox account — for real routing and map tiles
- (Optional) Firebase project with Realtime Database — for live updates

The system runs without Mapbox or Firebase: routing uses a local fallback and
the dashboard polls the REST API for positions. Add the credentials to unlock
real routing, map tiles, and push-based live updates.

## 1. Backend API (api/)

```bash
cd api
cp .env.example .env          # if .env doesn't exist
composer install
php artisan key:generate
php artisan migrate
php artisan serve             # http://127.0.0.1:8000
```

Optional credentials in `api/.env`:

```ini
# Mapbox (server-side routing). Empty = local nearest-neighbor fallback.
MAPBOX_TOKEN=sk....

# Firebase Admin (realtime transport). Empty = Firebase disabled.
FIREBASE_CREDENTIALS=/absolute/path/to/service-account.json
FIREBASE_DATABASE_URL=https://your-project-default-rtdb.firebaseio.com
```

Download the service-account JSON from Firebase console → Project settings →
Service accounts. Keep it out of git (there is a `.gitignore` under
`storage/app/firebase/` if you want to store it there).

### GPS simulator (no phone required)

Drives a vehicle along its optimized route, emitting GPS fixes to Firebase + the
API — handy for testing the dashboard end to end:

```bash
php artisan fleet:simulate <vehicleId> --trip=<tripId> --interval=3 --step=0.2
```

## 2. Web dashboard (dashboard/)

```bash
cd dashboard
cp .env.example .env
npm install
npm run dev                   # http://localhost:5173
```

`dashboard/.env`:

```ini
VITE_API_URL=http://127.0.0.1:8000/api
VITE_MAPBOX_TOKEN=pk....                 # public token for map tiles
# Firebase web config (optional; enables push-based live updates)
VITE_FIREBASE_API_KEY=
VITE_FIREBASE_AUTH_DOMAIN=
VITE_FIREBASE_DATABASE_URL=
VITE_FIREBASE_PROJECT_ID=
VITE_FIREBASE_APP_ID=
```

Without `VITE_MAPBOX_TOKEN` the app runs but shows a banner (no map tiles).
Without Firebase config it polls the REST API every 3s instead of subscribing.

## 3. Driver app (driver-app/)

```bash
cd driver-app
npm install
npx expo start                # scan the QR code with Expo Go, or press i / a
```

Configure endpoints in `driver-app/app.json` under `expo.extra`:

```json
"extra": {
  "apiUrl": "http://<your-lan-ip>:8000/api",
  "firebase": {
    "apiKey": "", "authDomain": "", "databaseURL": "",
    "projectId": "", "appId": ""
  }
}
```

Use your machine's LAN IP (not 127.0.0.1) so a physical phone can reach the API.
Device GPS requires a real device or a simulator with a simulated location.

## End-to-end flow

1. Register an operator (dashboard login screen) — issues a Sanctum token.
2. Create drivers, vehicles, and destinations (via API or seed script).
3. Create a trip with a list of destinations.
4. `POST /api/trips/{id}/optimize` — reorders stops efficiently, computes ETAs,
   and publishes the route to Firebase.
5. Driver signs into the app, taps "Start trip", and streams GPS.
6. Dashboard shows the vehicle moving along the route with live ETAs.

## API reference (summary)

| Method | Path | Purpose |
| --- | --- | --- |
| POST | `/api/auth/register` · `/api/auth/login` | Get a token |
| GET | `/api/auth/me` | Current user + linked driver |
| CRUD | `/api/vehicles` `/api/drivers` `/api/destinations` `/api/trips` | Resources |
| POST | `/api/trips/{id}/optimize` | Optimize route + ETAs |
| POST | `/api/vehicles/{id}/positions` | Ingest a GPS fix |
| GET | `/api/vehicles/{id}/positions` | Position history |

All endpoints except register/login require `Authorization: Bearer <token>`.
