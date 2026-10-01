import { useEffect, useState } from 'react'
import { View, ActivityIndicator, StyleSheet } from 'react-native'
import { StatusBar } from 'expo-status-bar'
import { api, getTeamUuid, clearTeamUuid } from './src/api'
import ScanScreen from './src/ScanScreen'
import DriverScreen from './src/DriverScreen'

export default function App() {
  const [teamData, setTeamData] = useState(null)
  const [checking, setChecking] = useState(true)

  // On launch, re-resolve a previously scanned team (if any).
  useEffect(() => {
    ;(async () => {
      const uuid = await getTeamUuid()
      if (uuid) {
        try {
          const data = await api.teamTrip(uuid)
          setTeamData(data)
        } catch {
          await clearTeamUuid()
        }
      }
      setChecking(false)
    })()
  }, [])

  const exit = async () => {
    await clearTeamUuid()
    setTeamData(null)
  }

  if (checking) {
    return (
      <View style={styles.center}>
        <ActivityIndicator color="#4f8cff" />
        <StatusBar style="light" />
      </View>
    )
  }

  return (
    <>
      <StatusBar style="light" />
      {teamData ? (
        <DriverScreen teamData={teamData} onExit={exit} />
      ) : (
        <ScanScreen onTeam={setTeamData} />
      )}
    </>
  )
}

const styles = StyleSheet.create({
  center: { flex: 1, backgroundColor: '#0f1420', justifyContent: 'center', alignItems: 'center' },
})
