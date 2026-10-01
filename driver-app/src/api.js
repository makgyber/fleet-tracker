import AsyncStorage from '@react-native-async-storage/async-storage'
import { API_URL } from './config'

// The scanned team UUID is persisted so the app reopens to the same team.
const TEAM_KEY = 'fleet_team_uuid'

let cachedUuid = null

export async function getTeamUuid() {
  if (cachedUuid) return cachedUuid
  cachedUuid = await AsyncStorage.getItem(TEAM_KEY)
  return cachedUuid
}
export async function setTeamUuid(uuid) {
  cachedUuid = uuid
  await AsyncStorage.setItem(TEAM_KEY, uuid)
}
export async function clearTeamUuid() {
  cachedUuid = null
  await AsyncStorage.removeItem(TEAM_KEY)
}

async function request(path, { method = 'GET', body } = {}) {
  const headers = { Accept: 'application/json' }
  if (body) headers['Content-Type'] = 'application/json'

  const res = await fetch(`${API_URL}${path}`, {
    method,
    headers,
    body: body ? JSON.stringify(body) : undefined,
  })

  if (res.status === 204) return null
  const data = await res.json().catch(() => ({}))
  if (!res.ok) throw new Error(data.message || `Request failed (${res.status})`)
  return data
}

export const api = {
  // Public QR-driven endpoints (team UUID is the credential).
  teamTrip: (uuid) => request(`/teams/${encodeURIComponent(uuid)}/trip`),
  postTeamPosition: (uuid, fix) =>
    request(`/teams/${encodeURIComponent(uuid)}/positions`, { method: 'POST', body: fix }),
}
