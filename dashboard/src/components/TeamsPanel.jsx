import { useEffect, useState, useCallback } from 'react'
import { api } from '../api'

function today() {
  return new Date().toISOString().slice(0, 10)
}

/**
 * Lists tbss-imported teams for a given schedule date and lets the operator
 * trigger an import. Selecting a team with a trip focuses it on the map.
 */
export default function TeamsPanel({ onSelectTrip }) {
  const [date, setDate] = useState(today())
  const [teams, setTeams] = useState([])
  const [loading, setLoading] = useState(false)
  const [importing, setImporting] = useState(false)
  const [message, setMessage] = useState('')

  const load = useCallback(async (d) => {
    setLoading(true)
    try {
      const res = await api.teams(d)
      setTeams(res.data || [])
    } catch (e) {
      setMessage(e.message)
    } finally {
      setLoading(false)
    }
  }, [])

  useEffect(() => {
    load(date)
  }, [date, load])

  const runImport = async () => {
    setImporting(true)
    setMessage('')
    try {
      const res = await api.importSchedule(date)
      setMessage(
        `Imported ${res.teams_imported} team(s): ${res.trips_optimized} optimized, ${res.skipped_no_destinations} without stops.`,
      )
      await load(date)
    } catch (e) {
      setMessage(`Import failed: ${e.message}`)
    } finally {
      setImporting(false)
    }
  }

  return (
    <div>
      <div className="toolbar" style={{ flexDirection: 'column', alignItems: 'stretch', gap: 8 }}>
        <div style={{ display: 'flex', gap: 8, alignItems: 'center' }}>
          <input
            type="date"
            value={date}
            onChange={(e) => setDate(e.target.value)}
            style={{
              background: 'var(--bg)',
              color: 'var(--text)',
              border: '1px solid var(--border)',
              borderRadius: 8,
              padding: '8px 10px',
              flex: 1,
            }}
          />
          <button onClick={runImport} disabled={importing}>
            {importing ? 'Importing…' : 'Import from tbss'}
          </button>
        </div>
        {message && <div className="eta-line">{message}</div>}
      </div>

      <div className="list">
        {loading && <div style={{ padding: 16, color: 'var(--muted)' }}>Loading…</div>}
        {!loading && teams.length === 0 && (
          <div style={{ padding: 16, color: 'var(--muted)' }}>
            No teams for {date}. Click “Import from tbss”.
          </div>
        )}
        {teams.map((t) => (
          <div
            key={t.id}
            className="vehicle-card"
            onClick={() => t.trip && onSelectTrip?.(t.trip.id)}
            style={{ cursor: t.trip ? 'pointer' : 'default' }}
          >
            <div className="row">
              <div>
                <div className="label" style={{ display: 'flex', alignItems: 'center', gap: 6 }}>
                  {t.color && (
                    <span
                      style={{
                        width: 10,
                        height: 10,
                        borderRadius: '50%',
                        background: t.color,
                        display: 'inline-block',
                      }}
                    />
                  )}
                  {t.code}
                </div>
                <div className="reg">
                  {(t.members || []).length} member(s)
                  {t.vehicle ? ` · ${t.vehicle.label}` : ' · no vehicle'}
                </div>
              </div>
              {t.trip ? (
                <span className={`badge ${t.trip.status === 'optimized' ? 'on_trip' : ''}`}>
                  {t.trip.status}
                </span>
              ) : (
                <span className="badge">no trip</span>
              )}
            </div>
            {t.trip && (
              <div className="eta-line">
                {t.trip.stops_count} stop(s)
                {t.trip.total_distance_m
                  ? ` · ${(t.trip.total_distance_m / 1000).toFixed(1)} km`
                  : ''}
              </div>
            )}
          </div>
        ))}
      </div>
    </div>
  )
}
