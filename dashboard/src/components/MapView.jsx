import { useEffect, useRef } from 'react'
import mapboxgl from 'mapbox-gl'
import { MAPBOX_TOKEN } from '../config'

// Default view: Marikina, PH ([lng, lat]).
const DEFAULT_CENTER = [121.1029, 14.6507]

// Compact ETA like "3:45 PM (12 min)" for stop popups.
function formatEta(iso) {
  if (!iso) return ''
  const d = new Date(iso)
  if (Number.isNaN(d.getTime())) return ''
  const mins = Math.round((d.getTime() - Date.now()) / 60000)
  const time = d.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })
  if (mins < 0) return `${time} (due)`
  return `${time} (${mins} min)`
}

// Minimal HTML escaping for user-supplied text injected into popup markup.
function escapeHtml(str) {
  return String(str).replace(
    /[&<>"']/g,
    (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c],
  )
}

/**
 * Renders a Mapbox map with one marker per vehicle (keyed by id) and an
 * optional route line for the selected trip. Markers are updated in place as
 * new positions arrive so movement is smooth.
 *
 * @param {Object} props
 * @param {Record<number, {lat:number,lng:number,heading?:number}>} props.positions
 * @param {Object|null} props.routeGeometry  GeoJSON LineString for the selected route
 * @param {number|null} props.focusVehicleId  pan to this vehicle when it updates
 * @param {Array<{id:number, sequence?:number, eta?:string, status?:string,
 *   destination?:{name?:string, latitude:number, longitude:number}}>} [props.stops]
 *   ordered stops for the selected trip; rendered as numbered destination markers
 * @param {Array<{id:number, code?:string, color?:string, geometry?:object,
 *   stops?:Array}>} [props.teamRoutes]  when set, renders ALL teams' routes +
 *   stops at once (fleet overview). Overrides the single route/stops rendering.
 */
export default function MapView({ positions, markerLabels = {}, routeGeometry, focusVehicleId, stops = [], teamRoutes = null, office = null }) {
  const containerRef = useRef(null)
  const mapRef = useRef(null)
  const markersRef = useRef({})
  const stopMarkersRef = useRef([])
  const overviewMarkersRef = useRef([])
  const overviewLayerIdsRef = useRef([])
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
      const label = markerLabels[id] || (String(id).startsWith('team:') ? 'Team' : 'Vehicle')
      let marker = markersRef.current[id]
      if (!marker) {
        const isTeam = String(id).startsWith('team:')
        // A wrapper holds the colored dot plus an always-visible name caption so
        // operators can read which crew/vehicle each moving icon is.
        const el = document.createElement('div')
        el.className = isTeam ? 'marker-wrap team' : 'marker-wrap'

        const dot = document.createElement('div')
        dot.className = isTeam ? 'marker team' : 'marker'
        el.appendChild(dot)

        const caption = document.createElement('div')
        caption.className = 'marker-caption'
        caption.textContent = label
        el.appendChild(caption)
        el.title = label

        // A click popup echoes the name for small/overlapping markers.
        const popup = new mapboxgl.Popup({ offset: 14, closeButton: false }).setHTML(
          `<div class="stop-popup"><b>${escapeHtml(label)}</b></div>`,
        )

        marker = new mapboxgl.Marker({ element: el })
          .setLngLat([pos.lng, pos.lat])
          .setPopup(popup)
          .addTo(map)
        marker._captionEl = caption
        markersRef.current[id] = marker
      } else {
        marker.setLngLat([pos.lng, pos.lat])
        // Keep the caption/popup in sync if the label resolved later.
        if (marker._captionEl && marker._captionEl.textContent !== label) {
          marker._captionEl.textContent = label
          marker.getElement().title = label
          marker.getPopup()?.setHTML(`<div class="stop-popup"><b>${escapeHtml(label)}</b></div>`)
        }
      }
    }
  }, [positions, markerLabels])

  // Render one numbered marker per stop for the selected trip. Rebuilt whenever
  // the stop list changes (markers are cheap and the list is small).
  useEffect(() => {
    const map = mapRef.current
    if (!map) return

    // Clear previous stop markers.
    for (const m of stopMarkersRef.current) m.remove()
    stopMarkersRef.current = []

    // In fleet-overview mode the overview effect owns the stop markers.
    if (teamRoutes && teamRoutes.length > 0) return

    for (const stop of stops) {
      const dest = stop.destination
      if (!dest || dest.latitude == null || dest.longitude == null) continue

      const el = document.createElement('div')
      el.className = `stop-marker${stop.status === 'arrived' ? ' arrived' : ''}`
      el.textContent = String(stop.sequence ?? '•')

      const name = dest.name ?? 'Destination'
      const etaText = formatEta(stop.eta)
      const popup = new mapboxgl.Popup({ offset: 18, closeButton: false }).setHTML(
        `<div class="stop-popup"><b>${escapeHtml(name)}</b>` +
          (dest.client_name ? `<div class="client">${escapeHtml(dest.client_name)}</div>` : '') +
          (stop.sequence != null ? `<div>Stop #${stop.sequence}</div>` : '') +
          (etaText ? `<div>ETA ${escapeHtml(etaText)}</div>` : '') +
          `</div>`,
      )

      const marker = new mapboxgl.Marker({ element: el })
        .setLngLat([dest.longitude, dest.latitude])
        .setPopup(popup)
        .addTo(map)
      stopMarkersRef.current.push(marker)
    }
  }, [stops])

  // Fleet overview: render every team's route line + stop markers at once.
  // A palette gives each team a distinct color when it has none.
  useEffect(() => {
    const map = mapRef.current
    if (!map || !readyRef.current) return

    // Tear down any previous overview layers/sources + markers.
    for (const id of overviewLayerIdsRef.current) {
      if (map.getLayer(id)) map.removeLayer(id)
      if (map.getSource(id)) map.removeSource(id)
    }
    overviewLayerIdsRef.current = []
    for (const m of overviewMarkersRef.current) m.remove()
    overviewMarkersRef.current = []

    if (!teamRoutes || teamRoutes.length === 0) return

    const palette = ['#4f8cff', '#37c871', '#f5a623', '#e35cff', '#d0021b', '#9013fe',
      '#50e3c2', '#f8e71c', '#ff6b6b', '#7ed321', '#bd10e0', '#4a90d9']
    const bounds = new mapboxgl.LngLatBounds()
    let hasBounds = false

    teamRoutes.forEach((team, i) => {
      const color = team.color || palette[i % palette.length]
      const coords = team.geometry?.coordinates || []

      if (coords.length > 1) {
        const srcId = `ov-${team.id}`
        map.addSource(srcId, {
          type: 'geojson',
          data: { type: 'Feature', geometry: { type: 'LineString', coordinates: coords } },
        })
        map.addLayer({
          id: srcId,
          type: 'line',
          source: srcId,
          layout: { 'line-cap': 'round', 'line-join': 'round' },
          paint: { 'line-color': color, 'line-width': 3, 'line-opacity': 0.7 },
        })
        overviewLayerIdsRef.current.push(srcId)
        for (const c of coords) {
          bounds.extend(c)
          hasBounds = true
        }
      }

      for (const stop of team.stops || []) {
        const dest = stop.destination
        if (!dest || dest.latitude == null || dest.longitude == null) continue

        const el = document.createElement('div')
        el.className = `stop-marker${stop.status === 'arrived' ? ' arrived' : ''}`
        el.style.background = color
        el.textContent = String(stop.sequence ?? '•')

        const popup = new mapboxgl.Popup({ offset: 18, closeButton: false }).setHTML(
          `<div class="stop-popup"><b>${escapeHtml(team.code || 'Team')}</b>` +
            `<div>${escapeHtml(dest.name ?? 'Destination')}</div>` +
            (dest.client_name ? `<div class="client">${escapeHtml(dest.client_name)}</div>` : '') +
            (stop.sequence != null ? `<div>Stop #${stop.sequence}</div>` : '') +
            (stop.eta ? `<div>ETA ${escapeHtml(formatEta(stop.eta))}</div>` : '') +
            `</div>`,
        )

        const marker = new mapboxgl.Marker({ element: el })
          .setLngLat([dest.longitude, dest.latitude])
          .setPopup(popup)
          .addTo(map)
        overviewMarkersRef.current.push(marker)
        bounds.extend([dest.longitude, dest.latitude])
        hasBounds = true
      }
    })

    // Shared office origin, rendered once as "stop #0" for all teams.
    if (office && office.latitude != null && office.longitude != null) {
      const el = document.createElement('div')
      el.className = 'stop-marker origin'
      el.textContent = '0'

      const popup = new mapboxgl.Popup({ offset: 18, closeButton: false }).setHTML(
        `<div class="stop-popup"><b>${escapeHtml(office.name || 'Office')}</b>` +
          `<div>Origin (Stop #0)</div>` +
          (office.address ? `<div>${escapeHtml(office.address)}</div>` : '') +
          `</div>`,
      )

      const marker = new mapboxgl.Marker({ element: el })
        .setLngLat([office.longitude, office.latitude])
        .setPopup(popup)
        .addTo(map)
      overviewMarkersRef.current.push(marker)
      bounds.extend([office.longitude, office.latitude])
      hasBounds = true
    }

    if (hasBounds) map.fitBounds(bounds, { padding: 60, maxZoom: 14 })
  }, [teamRoutes, office])

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

    // In overview mode, keep the single-route line empty.
    const coords = (teamRoutes && teamRoutes.length > 0) ? [] : (routeGeometry?.coordinates || [])
    src.setData({ type: 'Feature', geometry: { type: 'LineString', coordinates: coords } })

    if (coords.length > 1) {
      const bounds = coords.reduce(
        (b, c) => b.extend(c),
        new mapboxgl.LngLatBounds(coords[0], coords[0]),
      )
      map.fitBounds(bounds, { padding: 80, maxZoom: 14 })
    }
  }, [routeGeometry, teamRoutes])

  return <div id="map" ref={containerRef} />
}
