import { useState, useEffect } from 'react'
import { View, Text, TouchableOpacity, StyleSheet, TextInput } from 'react-native'
import { CameraView, useCameraPermissions } from 'expo-camera'
import { api, setTeamUuid } from './api'

/**
 * Scans a team's QR code (which encodes the team UUID), validates it against
 * the fleet API, and hands the resolved team/trip back to the app. Falls back
 * to manual UUID entry if the camera is unavailable.
 */
export default function ScanScreen({ onTeam }) {
  const [permission, requestPermission] = useCameraPermissions()
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState('')
  const [manual, setManual] = useState('')

  useEffect(() => {
    if (permission && !permission.granted && permission.canAskAgain) {
      requestPermission()
    }
  }, [permission])

  const resolve = async (uuid) => {
    const value = (uuid || '').trim()
    if (!value || busy) return
    setBusy(true)
    setError('')
    try {
      const data = await api.teamTrip(value)
      await setTeamUuid(value)
      onTeam(data)
    } catch (e) {
      setError(e.message || 'Could not load that team.')
      setBusy(false)
    }
  }

  return (
    <View style={styles.container}>
      <Text style={styles.title}>Scan your team QR</Text>
      <Text style={styles.sub}>Point the camera at the QR code on your work order.</Text>

      <View style={styles.cameraWrap}>
        {permission?.granted ? (
          <CameraView
            style={StyleSheet.absoluteFill}
            barcodeScannerSettings={{ barcodeTypes: ['qr'] }}
            onBarcodeScanned={busy ? undefined : ({ data }) => resolve(data)}
          />
        ) : (
          <View style={styles.center}>
            <Text style={styles.sub}>Camera permission needed to scan.</Text>
            <TouchableOpacity style={styles.button} onPress={requestPermission}>
              <Text style={styles.buttonText}>Grant camera access</Text>
            </TouchableOpacity>
          </View>
        )}
        <View style={styles.reticle} pointerEvents="none" />
      </View>

      {error ? <Text style={styles.error}>{error}</Text> : null}
      {busy ? <Text style={styles.sub}>Loading team…</Text> : null}

      <Text style={[styles.sub, { marginTop: 24 }]}>Or enter the team code manually:</Text>
      <View style={styles.manualRow}>
        <TextInput
          style={styles.input}
          placeholder="team UUID"
          placeholderTextColor="#8b97ad"
          autoCapitalize="none"
          value={manual}
          onChangeText={setManual}
        />
        <TouchableOpacity style={styles.button} onPress={() => resolve(manual)} disabled={busy}>
          <Text style={styles.buttonText}>Go</Text>
        </TouchableOpacity>
      </View>
    </View>
  )
}

const styles = StyleSheet.create({
  container: { flex: 1, backgroundColor: '#0f1420', padding: 24, justifyContent: 'center' },
  title: { color: '#e6ebf5', fontSize: 24, fontWeight: '700' },
  sub: { color: '#8b97ad', marginTop: 4 },
  cameraWrap: {
    marginTop: 20,
    height: 320,
    borderRadius: 16,
    overflow: 'hidden',
    backgroundColor: '#000',
    borderWidth: 1,
    borderColor: '#2a3346',
  },
  center: { flex: 1, justifyContent: 'center', alignItems: 'center', padding: 16 },
  reticle: {
    position: 'absolute',
    top: '20%',
    left: '20%',
    width: '60%',
    height: '60%',
    borderWidth: 2,
    borderColor: 'rgba(79,140,255,0.8)',
    borderRadius: 12,
  },
  error: { color: '#ff6b6b', marginTop: 12 },
  manualRow: { flexDirection: 'row', gap: 8, marginTop: 8 },
  input: {
    flex: 1,
    backgroundColor: '#171d2b',
    borderColor: '#2a3346',
    borderWidth: 1,
    borderRadius: 10,
    color: '#e6ebf5',
    padding: 12,
  },
  button: {
    backgroundColor: '#4f8cff',
    borderRadius: 10,
    paddingHorizontal: 18,
    justifyContent: 'center',
    alignItems: 'center',
  },
  buttonText: { color: 'white', fontWeight: '700' },
})
