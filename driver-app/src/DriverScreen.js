import { useEffect, useMemo, useState } from 'react'
import { View, Text, TouchableOpacity, StyleSheet, ScrollView, ActivityIndicator } from 'react-native'
import MapView, { Marker, Polyline, PROVIDER_DEFAULT } from 'react-native-maps'
import { api, clearToken } from './api'
import { useTracking } from './useTracking'
import { FIREBASE_ENABLED } from './config'

function etaLabel(iso) {
  if (!iso) return '—'
  const d = new Date(iso)
  const mins = Math.round((d.getTime() - Date.now()) / 60000)
  const time = d.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })
  return mins < 0 ? `${time} (due)` : `${time} · ${mins} min`
}

export default function DriverScreen({ onSignOut }) {
  const [loading, setLoading] = useState(true)
  const [me, setMe] = useState(null)
  const [trip, setTrip] = useState(null)
  const [error, setError] = useState('')

  // The vehicle assigned to this trip drives GPS reporting.
  const vehicleId = trip?.vehicle_id ?? null
  const tripId = trip?.id ?? null
  const { tracking, position, error: trackError, start, stop } = useTracking(vehicleId, tripId)

  useEffect(() => {
    let cancelled = false
    ;(async () => {
      try {
        const meRes = await api.me()
        if (cancelled) return
        setMe(meRes)

        // Find this driver's active/optimized trip.
        const tripsRes = await api.trips()
        const driverId = meRes.driver?.id
        const mine = (tripsRes.data || []).find(
          (t) =>
            t.driver_id === driverId &&
            ['optimized', 'in_progress', 'planned'].includes(t.status),
        )
        if (mine) {
          const detail = await api.trip(mine.id)
          if (!cancelled) setTrip(detail.data)
        }
      } catch (e) {
        if (!cancelled) setError(e.message)
      } finally {
        if (!cancelled) setLoading(false)
      }
    })()
    return () => {
      cancelled = true
    }
  }, [])

  const routeCoords = useMemo(() => {
    const geo = trip?.route?.geometry
    if (!geo) return []
    try {
      const parsed = JSON.parse(geo)
      return (parsed.coordinates || []).map(([lng, lat]) => ({ latitude: lat, longitude: lng }))
    } catch {
      return []
    }
  }, [trip])

  const nextStop = useMemo(
    () => (trip?.stops || []).find((s) => s.status !== 'arrived'),
    [trip],
  )

  const initialRegion = useMemo(() => {
    const first = routeCoords[0] || { latitude: 14.6507, longitude: 121.1029 } // Marikina, PH
    return { ...first, latitudeDelta: 0.08, longitudeDelta: 0.08 }
  }, [routeCoords])

  if (loading) {
    return (
      <View style={styles.center}>
        <ActivityIndicator color="#4f8cff" />
      </View>
    )
  }

  return (
    <View style={styles.container}>
      <MapView style={styles.map} provider={PROVIDER_DEFAULT} initialRegion={initialRegion}>
        {routeCoords.length > 1 && (
          <Polyline coordinates={routeCoords} strokeColor="#4f8cff" strokeWidth={4} />
        )}
        {(trip?.stops || []).map((s) =>
          s.destination ? (
            <Marker
              key={s.id}
              coordinate={{
                latitude: Number(s.destination.latitude),
                longitude: Number(s.destination.longitude),
              }}
              title={`${s.sequence ?? '–'}. ${s.destination.name}`}
              description={`ETA ${etaLabel(s.eta)}`}
            />
          ) : null,
        )}
        {position && (
          <Marker
            coordinate={{ latitude: position.lat, longitude: position.lng }}
            title="You"
            pinColor="#37c871"
          />
        )}
      </MapView>

      <ScrollView style={styles.panel} contentContainerStyle={{ paddingBottom: 24 }}>
        <View style={styles.headerRow}>
          <View>
            <Text style={styles.hello}>Hi, {me?.name || 'Driver'}</Text>
            <Text style={styles.sub}>
              {FIREBASE_ENABLED ? 'Live tracking via Firebase' : 'Reporting via API'}
            </Text>
          </View>
          <TouchableOpacity onPress={onSignOut}>
            <Text style={styles.signout}>Sign out</Text>
          </TouchableOpacity>
        </View>

        {error ? <Text style={styles.error}>{error}</Text> : null}

        {!trip ? (
          <Text style={styles.sub}>No trip assigned yet. Check back with dispatch.</Text>
        ) : (
          <>
            <Text style={styles.tripTitle}>
              Trip #{trip.id} · {trip.status}
            </Text>
            {trip.route?.total_distance_m ? (
              <Text style={styles.sub}>
                {(trip.route.total_distance_m / 1000).toFixed(1)} km ·{' '}
                {Math.round((trip.route.total_duration_s || 0) / 60)} min total
              </Text>
            ) : null}

            {nextStop && (
              <View style={styles.nextBox}>
                <Text style={styles.nextLabel}>NEXT STOP</Text>
                <Text style={styles.nextName}>{nextStop.destination?.name}</Text>
                <Text style={styles.nextEta}>ETA {etaLabel(nextStop.eta)}</Text>
              </View>
            )}

            <Text style={styles.sectionTitle}>All stops</Text>
            {(trip.stops || []).map((s) => (
              <View key={s.id} style={styles.stopRow}>
                <Text style={styles.stopSeq}>{s.sequence ?? '–'}</Text>
                <View style={{ flex: 1 }}>
                  <Text style={styles.stopName}>{s.destination?.name}</Text>
                  <Text style={styles.stopEta}>ETA {etaLabel(s.eta)}</Text>
                </View>
              </View>
            ))}

            {trackError ? <Text style={styles.error}>{trackError}</Text> : null}
            <TouchableOpacity
              style={[styles.button, tracking && styles.buttonStop]}
              onPress={tracking ? stop : start}
            >
              <Text style={styles.buttonText}>
                {tracking ? 'Stop sharing location' : 'Start trip & share location'}
              </Text>
            </TouchableOpacity>
          </>
        )}
      </ScrollView>
    </View>
  )
}

const styles = StyleSheet.create({
  container: { flex: 1, backgroundColor: '#0f1420' },
  center: { flex: 1, backgroundColor: '#0f1420', justifyContent: 'center', alignItems: 'center' },
  map: { height: '45%' },
  panel: { flex: 1, padding: 16 },
  headerRow: { flexDirection: 'row', justifyContent: 'space-between', alignItems: 'flex-start' },
  hello: { color: '#e6ebf5', fontSize: 20, fontWeight: '700' },
  sub: { color: '#8b97ad', marginTop: 2 },
  signout: { color: '#4f8cff', fontWeight: '600' },
  error: { color: '#ff6b6b', marginTop: 8 },
  tripTitle: { color: '#e6ebf5', fontSize: 16, fontWeight: '700', marginTop: 16 },
  nextBox: {
    backgroundColor: '#171d2b',
    borderColor: '#2a3346',
    borderWidth: 1,
    borderRadius: 12,
    padding: 14,
    marginTop: 12,
  },
  nextLabel: { color: '#8b97ad', fontSize: 11, letterSpacing: 1 },
  nextName: { color: '#e6ebf5', fontSize: 18, fontWeight: '700', marginTop: 2 },
  nextEta: { color: '#37c871', marginTop: 2 },
  sectionTitle: { color: '#8b97ad', marginTop: 18, marginBottom: 6, fontSize: 12, letterSpacing: 1 },
  stopRow: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: 12,
    paddingVertical: 8,
    borderBottomColor: '#2a3346',
    borderBottomWidth: 1,
  },
  stopSeq: {
    color: '#4f8cff',
    fontWeight: '700',
    width: 24,
    textAlign: 'center',
  },
  stopName: { color: '#e6ebf5' },
  stopEta: { color: '#8b97ad', fontSize: 12 },
  button: {
    backgroundColor: '#37c871',
    borderRadius: 10,
    padding: 16,
    alignItems: 'center',
    marginTop: 20,
  },
  buttonStop: { backgroundColor: '#c8434f' },
  buttonText: { color: 'white', fontWeight: '700', fontSize: 16 },
})
