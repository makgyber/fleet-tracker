import { useEffect, useMemo, useRef, useState } from 'react'
import { api, getToken, clearToken } from './api'
import { MAPBOX_TOKEN, FIREBASE_ENABLED } from './config'
import { subscribeVehiclePosition, subscribeTeamPosition, subscribeTripRoute } from './live'
import Login from './components/Login'
import MapView from './components/MapView'
import TeamsPanel from './components/TeamsPanel'

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
  const [teams, setTeams] = useState([])
  const [trips, setTrips] = useState([])
  const [positions, setPositions] = useState({})
  const [teamPositions, setTeamPositions] = useState({})
  const [selectedVehicleId, setSelectedVehicleId] = useState(null)
  const [selectedTrip, setSelectedTrip] = useState(null)
  const [liveRoute, setLiveRoute] = useState(null)
  const [error, setError] = useState('')
  const [view, setView] = useState('vehicles') // 'vehicles' | 'teams'
  const [focusTripId, setFocusTripId] = useState(null)
  const [overview, setOverview] = useState(false) // show all teams/routes at once
  const [overviewData, setOverviewData] = useState([])

  const unsubsRef = useRef([])

  // Load core data once authed.
  useEffect(() => {
    if (!authed) return
    let cancelled = false
    ;(async () => {
      try {
        const [v, t, tm] = await Promise.all([api.vehicles(), api.trips(), api.teams()])
        if (cancelled) return
        setVehicles(v.data || [])
        setTrips(t.data || [])
        setTeams(tm.data || [])
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

  // Subscribe to each team's live position (teams/{uuid}/position). Teams are
  // the primary tracked unit — the driver app keys positions by scanned UUID,
  // so this works whether or not a fleet vehicle has been assigned.
  useEffect(() => {
    if (!authed || teams.length === 0) return
    const unsubs = teams
      .filter((t) => t.team_uuid)
      .map((t) =>
        subscribeTeamPosition(t.team_uuid, (pos) => {
          setTeamPositions((prev) => ({ ...prev, [t.id]: pos }))
        }),
      )
    return () => {
      unsubs.forEach((u) => u && u())
    }
  }, [authed, teams])

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

  // When a trip is selected directly (e.g. from the Teams panel), load it and
  // subscribe to its live route, independent of vehicle selection.
  useEffect(() => {
    if (!focusTripId) return
    let cancelled = false
    ;(async () => {
      try {
        const detail = await api.trip(focusTripId)
        if (!cancelled) {
          setSelectedTrip(detail.data)
          setSelectedVehicleId(detail.data?.vehicle_id ?? null)
        }
      } catch {
        /* ignore */
      }
    })()
    const unsub = subscribeTripRoute(focusTripId, (routePayload) => {
      if (routePayload?.geometry) setLiveRoute(routePayload.geometry)
    })
    return () => {
      cancelled = true
      unsub && unsub()
    }
  }, [focusTripId])

  // Fleet overview: fetch all teams' routes + stops in one call when enabled.
  useEffect(() => {
    if (!overview) {
      setOverviewData([])
      return
    }
    // Clear any single-trip selection so the overview owns the map.
    setSelectedVehicleId(null)
    setFocusTripId(null)
    setSelectedTrip(null)
    let cancelled = false
    ;(async () => {
      try {
        const res = await api.teamsOverview()
        if (!cancelled) setOverviewData(res.data || [])
      } catch (e) {
        if (!cancelled) setError(e.message)
      }
    })()
    return () => {
      cancelled = true
    }
  }, [overview])

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

  // Merge vehicle + team positions into a single marker map for the map.
  // Team keys are namespaced to avoid colliding with numeric vehicle ids.
  const mapPositions = useMemo(() => {
    const merged = { ...positions }
    for (const [teamId, pos] of Object.entries(teamPositions)) {
      if (pos) merged[`team:${teamId}`] = pos
    }
    return merged
  }, [positions, teamPositions])

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

        <div className="toolbar" style={{ justifyContent: 'space-between' }}>
          <div style={{ display: 'flex', gap: 6 }}>
            <button
              className={view === 'vehicles' ? '' : 'secondary'}
              onClick={() => setView('vehicles')}
            >
              Vehicles
            </button>
            <button
              className={view === 'teams' ? '' : 'secondary'}
              onClick={() => setView('teams')}
            >
              Teams
            </button>
            <button
              className={overview ? '' : 'secondary'}
              onClick={() => setOverview((v) => !v)}
              title="Show all teams and destinations on the map"
            >
              {overview ? 'Overview: on' : 'Overview'}
            </button>
          </div>
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

        {view === 'teams' && <TeamsPanel onSelectTrip={(id) => setFocusTripId(id)} />}

        {view === 'vehicles' && (
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
        )}
      </div>

      <div className="map-wrap">
        <MapView
          positions={mapPositions}
          routeGeometry={routeGeometry}
          focusVehicleId={selectedVehicleId}
          stops={selectedTrip?.stops || []}
          teamRoutes={overview ? overviewData : null}
        />
      </div>
    </div>
  )
}
