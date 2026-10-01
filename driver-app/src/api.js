import AsyncStorage from '@react-native-async-storage/async-storage'
import { API_URL } from './config'

const TOKEN_KEY = 'fleet_driver_token'

let cachedToken = null

export async function getToken() {
  if (cachedToken) return cachedToken
  cachedToken = await AsyncStorage.getItem(TOKEN_KEY)
  return cachedToken
}
export async function setToken(token) {
  cachedToken = token
  await AsyncStorage.setItem(TOKEN_KEY, token)
}
export async function clearToken() {
  cachedToken = null
  await AsyncStorage.removeItem(TOKEN_KEY)
}

async function request(path, { method = 'GET', body, auth = true } = {}) {
  const headers = { Accept: 'application/json' }
  if (body) headers['Content-Type'] = 'application/json'
  if (auth) {
    const token = await getToken()
    if (token) headers.Authorization = `Bearer ${token}`
  }

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
  login: (email, password) =>
    request('/auth/login', {
      method: 'POST',
      body: { email, password, device_name: 'driver-app' },
      auth: false,
    }),
  me: () => request('/auth/me'),
  trips: () => request('/trips'),
  trip: (id) => request(`/trips/${id}`),
  // Server-side ingest (used alongside/instead of direct Firebase writes).
  postPosition: (vehicleId, fix) =>
    request(`/vehicles/${vehicleId}/positions`, { method: 'POST', body: fix }),
}
