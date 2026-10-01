import { useEffect, useMemo, useRef, useState } from 'react'
import { api, getToken, clearToken } from './api'
import { MAPBOX_TOKEN, FIREBASE_ENABLED } from './config'
import { subscribeVehiclePosition, subscribeTripRoute } from './live'
import Login from './components/Login'
import MapView from './components/MapView'

function formatEta(iso) {
  if (!iso) return '—'
  const d = new Date(iso)
  const mins = Math.round((d.getTime() - Date.now()) / 60000)
  const time = d.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })
  if (mins < 0) return `${time} (due)`
  return `${time} (${mins} min)`
}

export default function App() {
  const [authed, setAuthed] = useState(Boolean(getToken()))
  const [vehicles, setVehicles] = useState([])
  const [trips, setTrips] = useState([])
  const [positions, setPositions] = useState({})
  const [selectedVehicleId, setSelectedVehicleId] = useState(null)
  const [selectedTrip, setSelectedTrip] = useState(null)
  const [liveRoute, setLiveRoute] = useState(null)
  const [error, setError] = useState('')

  const unsubsRef = useRef([])

  // Load core data once authed.
  useEffect(() => {
    if (!authed) return
    let cancelled = false
    ;(async () => {
      try {
        const [v, t] = await Promise.all([api.vehicles(), api.trips()])
        if (cancelled) return
        setVehicles(v.data || [])
        setTrips(t.data || [])
      } catch (err) {
        if (err.message?.includes('401')) {
          clearToken()
          setAuthed(false)
        } else {
          setError(err.message)
        }
      }
    })()
    return () => {
      cancelled = true
    }
  }, [authed])

  // Subscribe to every vehicle's live position.
  useEffect(() => {
    if (!authed || vehicles.length === 0) return
    const unsubs = vehicles.map((v) =>
      subscribeVehiclePosition(v.id, (pos) => {
        setPositions((prev) => ({ ...prev, [v.id]: pos }))
      }),
    )
    unsubsRef.current = unsubs
    return () => {
      unsubs.forEach((u) => u && u())
      unsubsRef.current = []
    }
  }, [authed, vehicles])

  // Map vehicle -> its most relevant trip (optimized/in_progress).
  const tripByVehicle = useMemo(() => {
    const map = {}
    for (const t of trips) {
      if (['optimized', 'in_progress'].includes(t.status)) {
        map[t.vehicle_id] = t
      }
    }
    return map
  }, [trips])

  // When a vehicle is selected, load its trip detail + subscribe to live route.
  useEffect(() => {
    if (!selectedVehicleId) {
      setSelectedTrip(null)
      setLiveRoute(null)
      return
    }
    const trip = tripByVehicle[selectedVehicleId]
    if (!trip) {
      setSelectedTrip(null)
      setLiveRoute(null)
      return
    }
    let cancelled = false
    ;(async () => {
      try {
        const detail = await api.trip(trip.id)
        if (!cancelled) setSelectedTrip(detail.data)
      } catch {
        /* ignore */
      }
    })()

    const unsub = subscribeTripRoute(trip.id, (routePayload) => {
      if (routePayload?.geometry) setLiveRoute(routePayload.geometry)
    })
    return () => {
      cancelled = true
      unsub && unsub()
    }
  }, [selectedVehicleId, tripByVehicle])

  // Prefer the live route payload; fall back to the trip's stored geometry.
  const routeGeometry = useMemo(() => {
    if (liveRoute) return liveRoute
    if (selectedTrip?.route?.geometry) {
      try {
        return JSON.parse(selectedTrip.route.geometry)
      } catch {
        return null
      }
    }
    return null
  }, [liveRoute, selectedTrip])

  if (!authed) return <Login onAuthed={() => setAuthed(true)} />

  return (
    <div className="app">
      <div className="sidebar">
        <header>
          <h1>Fleet Tracker</h1>
          <div className="sub">
            {vehicles.length} vehicles ·{' '}
            {FIREBASE_ENABLED ? 'live via Firebase' : 'REST polling (no Firebase)'}
          </div>
        </header>

        {!MAPBOX_TOKEN && (
          <div className="banner">
            Set VITE_MAPBOX_TOKEN in dashboard/.env to render the map.
          </div>
        )}

        <div className="toolbar">
          <button
            className="secondary"
            onClick={() => {
              clearToken()
              setAuthed(false)
            }}
          >
            Sign out
          </button>
        </div>

        {error && <div className="error" style={{ padding: '0 16px' }}>{error}</div>}

        <div className="list">
          {vehicles.map((v) => {
            const trip = tripByVehicle[v.id]
            const pos = positions[v.id]
            const isActive = selectedVehicleId === v.id
            const nextStop =
              isActive && selectedTrip
                ? (selectedTrip.stops || []).find((s) => s.status !== 'arrived')
                : null
            return (
              <div
                key={v.id}
                className={`vehicle-card ${isActive ? 'active' : ''}`}
                onClick={() => setSelectedVehicleId(isActive ? null : v.id)}
              >
                <div className="row">
                  <div>
                    <div className="label">{v.label}</div>
                    <div className="reg">{v.registration}</div>
                  </div>
                  <div className="row" style={{ gap: 6 }}>
                    {pos && <span className="badge live">live</span>}
                    <span className={`badge ${v.status}`}>{v.status}</span>
                  </div>
                </div>
                {trip && (
                  <div className="eta-line">
                    Trip #{trip.id} · {trip.status}
                  </div>
                )}
                {isActive && selectedTrip && (
                  <div style={{ marginTop: 8 }}>
                    {(selectedTrip.stops || []).map((s) => (
                      <div key={s.id} className="eta-line">
                        <b>{s.sequence ?? '–'}.</b> {s.destination?.name} · ETA{' '}
                        <b>{formatEta(s.eta)}</b>
                      </div>
                    ))}
                    {nextStop && (
                      <div className="eta-line" style={{ marginTop: 4 }}>
                        Next: <b>{nextStop.destination?.name}</b>
                      </div>
                    )}
                  </div>
                )}
              </div>
            )
          })}
          {vehicles.length === 0 && (
            <div style={{ padding: 16, color: 'var(--muted)' }}>
              No vehicles yet. Create some via the API, then optimize a trip.
            </div>
          )}
        </div>
      </div>

      <div className="map-wrap">
        <MapView
          positions={positions}
          routeGeometry={routeGeometry}
          focusVehicleId={selectedVehicleId}
        />
      </div>
    </div>
  )
}
