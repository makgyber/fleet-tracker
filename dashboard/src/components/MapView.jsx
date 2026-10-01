import { useEffect, useRef } from 'react'
import mapboxgl from 'mapbox-gl'
import { MAPBOX_TOKEN } from '../config'

// Default view: Marikina, PH ([lng, lat]).
const DEFAULT_CENTER = [121.1029, 14.6507]

/**
 * Renders a Mapbox map with one marker per vehicle (keyed by id) and an
 * optional route line for the selected trip. Markers are updated in place as
 * new positions arrive so movement is smooth.
 *
 * @param {Object} props
 * @param {Record<number, {lat:number,lng:number,heading?:number}>} props.positions
 * @param {Object|null} props.routeGeometry  GeoJSON LineString for the selected route
 * @param {number|null} props.focusVehicleId  pan to this vehicle when it updates
 */
export default function MapView({ positions, routeGeometry, focusVehicleId }) {
  const containerRef = useRef(null)
  const mapRef = useRef(null)
  const markersRef = useRef({})
  const readyRef = useRef(false)
  const lastFocusRef = useRef(null)

  // Init map once.
  useEffect(() => {
    if (mapRef.current) return
    mapboxgl.accessToken = MAPBOX_TOKEN

    const map = new mapboxgl.Map({
      container: containerRef.current,
      style: 'mapbox://styles/mapbox/dark-v11',
      center: DEFAULT_CENTER,
      zoom: 12,
    })
    mapRef.current = map

    map.on('load', () => {
      readyRef.current = true
      // Route line source + layer (empty until a route is selected).
      map.addSource('route', {
        type: 'geojson',
        data: { type: 'Feature', geometry: { type: 'LineString', coordinates: [] } },
      })
      map.addLayer({
        id: 'route-line',
        type: 'line',
        source: 'route',
        layout: { 'line-cap': 'round', 'line-join': 'round' },
        paint: { 'line-color': '#4f8cff', 'line-width': 4, 'line-opacity': 0.8 },
      })
    })

    return () => {
      map.remove()
      mapRef.current = null
    }
  }, [])

  // Update / create markers as positions change.
  useEffect(() => {
    const map = mapRef.current
    if (!map) return

    for (const [id, pos] of Object.entries(positions)) {
      if (!pos) continue
      let marker = markersRef.current[id]
      if (!marker) {
        const el = document.createElement('div')
        el.className = 'marker'
        marker = new mapboxgl.Marker({ element: el })
          .setLngLat([pos.lng, pos.lat])
          .addTo(map)
        markersRef.current[id] = marker
      } else {
        marker.setLngLat([pos.lng, pos.lat])
      }
    }
  }, [positions])

  // Pan to a focused vehicle when its position updates.
  useEffect(() => {
    const map = mapRef.current
    if (!map || !focusVehicleId) return
    const pos = positions[focusVehicleId]
    if (pos && lastFocusRef.current !== focusVehicleId) {
      map.easeTo({ center: [pos.lng, pos.lat], zoom: 13 })
      lastFocusRef.current = focusVehicleId
    }
  }, [focusVehicleId, positions])

  // Update the route line when the selected route geometry changes.
  useEffect(() => {
    const map = mapRef.current
    if (!map || !readyRef.current) return
    const src = map.getSource('route')
    if (!src) return

    const coords = routeGeometry?.coordinates || []
    src.setData({ type: 'Feature', geometry: { type: 'LineString', coordinates: coords } })

    if (coords.length > 1) {
      const bounds = coords.reduce(
        (b, c) => b.extend(c),
        new mapboxgl.LngLatBounds(coords[0], coords[0]),
      )
      map.fitBounds(bounds, { padding: 80, maxZoom: 14 })
    }
  }, [routeGeometry])

  return <div id="map" ref={containerRef} />
}
