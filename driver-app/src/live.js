import { initializeApp } from 'firebase/app'
import { getDatabase, ref, set } from 'firebase/database'
import { firebaseConfig, FIREBASE_ENABLED } from './config'

let db = null
if (FIREBASE_ENABLED) {
  const app = initializeApp(firebaseConfig)
  db = getDatabase(app)
}

/**
 * Write the team's current position straight to the Realtime Database at
 * teams/{uuid}/position for minimal-latency dashboard updates. No-op when
 * Firebase isn't configured (the app still posts positions to the REST API).
 *
 * @param {string} teamUuid
 * @param {{lat:number, lng:number, speed?:number, heading?:number, accuracy?:number, trip_id?:number}} fix
 */
export async function writeTeamPosition(teamUuid, fix) {
  if (!db) return false
  await set(ref(db, `teams/${teamUuid}/position`), {
    ...fix,
    ts: Date.now(),
  })
  return true
}
