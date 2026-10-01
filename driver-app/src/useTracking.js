import { useEffect, useRef, useState, useCallback } from 'react'
import * as Location from 'expo-location'
import { writeVehiclePosition } from './live'
import { api } from './api'
import { FIREBASE_ENABLED } from './config'

/**
 * Streams device GPS while active. Each fix is pushed to Firebase (low latency)
 * and to the REST API (authoritative history). Returns the latest position and
 * controls to start/stop tracking.
 *
 * @param {number|null} vehicleId
 * @param {number|null} tripId
 */
export function useTracking(vehicleId, tripId) {
  const [tracking, setTracking] = useState(false)
  const [position, setPosition] = useState(null)
  const [error, setError] = useState('')
  const subRef = useRef(null)

  const handleFix = useCallback(
    async (loc) => {
      const fix = {
        lat: loc.coords.latitude,
        lng: loc.coords.longitude,
        speed: loc.coords.speed ?? null,
        heading: loc.coords.heading ?? null,
        accuracy: loc.coords.accuracy ?? null,
        trip_id: tripId ?? null,
      }
      setPosition(fix)

      // Fire both transports; don't let one failure block the other.
      if (FIREBASE_ENABLED) {
        writeVehiclePosition(vehicleId, fix).catch(() => {})
      }
      api.postPosition(vehicleId, fix).catch(() => {})
    },
    [vehicleId, tripId],
  )

  const start = useCallback(async () => {
    setError('')
    const { status } = await Location.requestForegroundPermissionsAsync()
    if (status !== 'granted') {
      setError('Location permission denied.')
      return
    }

    subRef.current = await Location.watchPositionAsync(
      {
        accuracy: Location.Accuracy.High,
        timeInterval: 3000, // ~every 3s (throttles Firebase egress)
        distanceInterval: 10, // or every 10 meters
      },
      handleFix,
    )
    setTracking(true)
  }, [handleFix])

  const stop = useCallback(() => {
    subRef.current?.remove()
    subRef.current = null
    setTracking(false)
  }, [])

  useEffect(() => () => subRef.current?.remove(), [])

  return { tracking, position, error, start, stop }
}
