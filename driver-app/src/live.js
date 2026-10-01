import { initializeApp } from 'firebase/app'
import { getDatabase, ref, set } from 'firebase/database'
import { firebaseConfig, FIREBASE_ENABLED } from './config'

let db = null
if (FIREBASE_ENABLED) {
  const app = initializeApp(firebaseConfig)
  db = getDatabase(app)
}

/**
 * Write the driver's current position straight to the Realtime Database so the
 * dashboard updates with minimal latency. No-op when Firebase isn't configured
 * (the app still posts positions to the REST API in that case).
 *
 * @param {number} vehicleId
 * @param {{lat:number, lng:number, speed?:number, heading?:number, accuracy?:number, trip_id?:number}} fix
 */
export async function writeVehiclePosition(vehicleId, fix) {
  if (!db) return false
  await set(ref(db, `vehicles/${vehicleId}/position`), {
    ...fix,
    ts: Date.now(),
  })
  return true
}
