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

## tbss daily field schedule integration

The fleet tracker integrates with the **tbss** field-service system. Each day
tbss has a schedule of **teams**, each with a set of **destinations** (job
orders + tasks). The fleet tracker imports these, builds an optimized route per
team, shows them on the dashboard, and lets drivers start tracking by scanning
a team QR code — no login.

```
 tbss (Laravel + MySQL)                 fleet-tracker (Laravel + SQLite)
 ┌────────────────────────┐  import     ┌──────────────────────────────┐
 │ GET /api/fleet/schedule │ ──────────▶ │ fleet:import-schedule        │
 │  (Sanctum service token)│   teams +   │  → upsert teams              │
 │  teams[].uuid  ← QR      │ destinations│  → create+optimize trips     │
 └────────────────────────┘             │  → publish routes to Firebase │
                                        └──────────────┬───────────────┘
 Driver app (Expo)                                     │
 ┌────────────────────────┐  scan QR (team uuid)        │
 │ ScanScreen (camera)     │ ──────────────────────────▶ GET /teams/{uuid}/trip
 │ DriverScreen            │  stream GPS by uuid ──────▶ POST /teams/{uuid}/positions
 └────────────────────────┘                            → Firebase teams/{uuid}/position
                                                        → SQLite history (if vehicle assigned)
```

### tbss side

- Each team has a globally-unique `uuid` (migration
  `add_uuid_to_teams_table`) — this backs the QR code. Teams are per-day, so a
  QR identifies a team on a specific schedule day.
- `GET /api/fleet/schedule?date=YYYY-MM-DD` (defaults to today), protected by
  Sanctum. Returns `{ date, schedule_id, teams: [{ uuid, code, color, vehicle,
  members, destinations: [{ source_type, source_id, code, name, latitude,
  longitude }] }] }`. Destinations come from each team's job orders + tasks,
  skipping any without coordinates.
- Issue a service token in tbss (e.g. `fleet-service@tbss.local`) and put it in
  the fleet tracker's `.env`.

### fleet-tracker side

Add to `api/.env`:

```ini
TBSS_API_URL=https://tbss.example.com/api
TBSS_API_TOKEN=<sanctum service token issued in tbss>
```

Import the schedule (also runs daily at 05:30 Asia/Manila via the scheduler):

```bash
php artisan fleet:import-schedule            # today
php artisan fleet:import-schedule 2026-10-16 # a specific day
```

Import is idempotent — re-running refreshes stops/routes without duplicating.

On the dashboard, use the **Teams** tab: pick a date, click **Import from
tbss**, then click a team to see its route and ETAs on the map. Assign a fleet
vehicle to a team to persist its GPS history.

### Driver app (QR flow)

The driver app has no login. The driver opens it and scans the team's QR code
(which encodes the team UUID). The app loads that team's trip and route, and
the **Start trip & share location** button streams GPS to the fleet API keyed
by the UUID. Positions publish live to Firebase; once an operator assigns a
fleet vehicle to the team, positions are also persisted to history.

Security note: the team UUID is the credential for the public driver endpoints
(`GET /teams/{uuid}/trip`, `POST /teams/{uuid}/positions`). Treat the QR code
as sensitive. Because teams are per-day, a QR is scoped to that day's team.

## Driver app maps (Google / Apple)

The driver app renders its map with `react-native-maps` using the **native base
map**, which is free for map display:

- **Android** → Google Maps. Requires a Google Maps API key.
- **iOS** → Apple Maps by default (no key, works out of the box).

Map **display** in the mobile native SDKs is not a billed event, so the driver
map is effectively free regardless of volume. (Routing/ETAs are handled
separately by Mapbox on the backend, not Google.)

### Where the API keys go

In `driver-app/app.json`:

```json
"android": {
  "config": { "googleMaps": { "apiKey": "<ANDROID_KEY>" } }
},
"ios": {
  "config": { "googleMapsApiKey": "<IOS_KEY>" }   // only if using Google Maps on iOS
}
```

Get keys from Google Cloud Console → enable **Maps SDK for Android** (and
**Maps SDK for iOS** if used) → create an API key. **Restrict each key** to the
specific SDK and to your app's package name + signing fingerprint, since the key
ships inside the app binary.

iOS uses Apple Maps unless you both set the iOS key above **and** change the
`MapView` provider to `PROVIDER_GOOGLE` in `src/DriverScreen.js`. Apple Maps is
fine for most cases and needs neither.

### Important: Expo Go vs. a native build

The keys in `app.json` only take effect in a **native build**, not in Expo Go
(Expo Go uses its own bundled map key on Android). To run the app with your own
key:

```bash
cd driver-app
npx expo run:android     # local dev build (needs Android Studio), or
eas build --profile development --platform android   # cloud build
```

- iOS: the map works immediately in Expo Go via Apple Maps.
- Android: use a development build to pick up your Google Maps key.
