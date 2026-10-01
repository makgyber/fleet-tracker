import { initializeApp } from 'firebase/app'
import { getDatabase, ref, onValue } from 'firebase/database'
import { firebaseConfig, FIREBASE_ENABLED } from './config'
import { api } from './api'

// Initialize Firebase only when configured.
let db = null
if (FIREBASE_ENABLED) {
  const app = initializeApp(firebaseConfig)
  db = getDatabase(app)
}

/**
 * Subscribe to a vehicle's live position.
 *
 * When Firebase is configured, this attaches a Realtime Database listener on
 * /vehicles/{id}/position. Otherwise it degrades to polling the REST position
 * history endpoint so the dashboard still shows movement in development.
 *
 * @param {number} vehicleId
 * @param {(pos: {lat:number, lng:number, heading?:number, speed?:number, ts?:number}) => void} onPosition
 * @returns {() => void} unsubscribe
 */
export function subscribeVehiclePosition(vehicleId, onPosition) {
  if (db) {
    const r = ref(db, `vehicles/${vehicleId}/position`)
    return onValue(r, (snap) => {
      const val = snap.val()
      if (val && typeof val.lat === 'number' && typeof val.lng === 'number') {
        onPosition(val)
      }
    })
  }

  // REST polling fallback (every 3s).
  let active = true
  const poll = async () => {
    if (!active) return
    try {
      const res = await api.positions(vehicleId, 1)
      const p = res?.data?.[0]
      if (p) {
        onPosition({
          lat: Number(p.latitude),
          lng: Number(p.longitude),
          heading: p.heading_deg ?? undefined,
          speed: p.speed_mps ?? undefined,
          ts: p.recorded_at,
        })
      }
    } catch {
      // ignore transient errors while polling
    }
  }
  poll()
  const id = setInterval(poll, 3000)
  return () => {
    active = false
    clearInterval(id)
  }
}

/**
 * Subscribe to a trip's live route/ETA payload published by the server.
 * Firebase-only; returns a no-op unsubscribe when Firebase is disabled.
 */
export function subscribeTripRoute(tripId, onRoute) {
  if (!db) return () => {}
  const r = ref(db, `trips/${tripId}/route`)
  return onValue(r, (snap) => {
    const val = snap.val()
    if (val) onRoute(val)
  })
}
