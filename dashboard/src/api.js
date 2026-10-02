import { API_URL } from './config'

// Sanctum personal-access-token stored in localStorage for the dashboard.
const TOKEN_KEY = 'fleet_token'

export function getToken() {
  return localStorage.getItem(TOKEN_KEY)
}
export function setToken(token) {
  localStorage.setItem(TOKEN_KEY, token)
}
export function clearToken() {
  localStorage.removeItem(TOKEN_KEY)
}

async function request(path, { method = 'GET', body, auth = true } = {}) {
  const headers = { Accept: 'application/json' }
  if (body) headers['Content-Type'] = 'application/json'
  if (auth) {
    const token = getToken()
    if (token) headers.Authorization = `Bearer ${token}`
  }

  const res = await fetch(`${API_URL}${path}`, {
    method,
    headers,
    body: body ? JSON.stringify(body) : undefined,
  })

  if (res.status === 204) return null

  const data = await res.json().catch(() => ({}))
  if (!res.ok) {
    const message = data.message || `Request failed (${res.status})`
    throw new Error(message)
  }
  return data
}

export const api = {
  // Auth
  login: (email, password) =>
    request('/auth/login', { method: 'POST', body: { email, password }, auth: false }),
  register: (name, email, password) =>
    request('/auth/register', { method: 'POST', body: { name, email, password }, auth: false }),
  me: () => request('/auth/me'),

  // Resources
  vehicles: () => request('/vehicles'),
  drivers: () => request('/drivers'),
  destinations: () => request('/destinations'),
  createDestination: (payload) => request('/destinations', { method: 'POST', body: payload }),
  trips: () => request('/trips'),
  trip: (id) => request(`/trips/${id}`),
  createTrip: (payload) => request('/trips', { method: 'POST', body: payload }),
  optimizeTrip: (id) => request(`/trips/${id}/optimize`, { method: 'POST' }),

  // Positions (REST fallback for when Firebase is disabled)
  positions: (vehicleId, limit = 1) =>
    request(`/vehicles/${vehicleId}/positions?limit=${limit}`),

  // tbss teams
  teams: (date) => request(`/teams${date ? `?date=${date}` : ''}`),
  teamsOverview: (date) => request(`/teams/overview${date ? `?date=${date}` : ''}`),
  importSchedule: (date) =>
    request('/teams/import', { method: 'POST', body: date ? { date } : {} }),
  assignVehicle: (teamId, vehicleId) =>
    request(`/teams/${teamId}/assign-vehicle`, { method: 'POST', body: { vehicle_id: vehicleId } }),
}
