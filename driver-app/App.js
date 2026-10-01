import { useEffect, useState } from 'react'
import { View, ActivityIndicator, StyleSheet } from 'react-native'
import { StatusBar } from 'expo-status-bar'
import { getToken, clearToken } from './src/api'
import LoginScreen from './src/LoginScreen'
import DriverScreen from './src/DriverScreen'

export default function App() {
  const [authed, setAuthed] = useState(false)
  const [checking, setChecking] = useState(true)

  useEffect(() => {
    ;(async () => {
      const token = await getToken()
      setAuthed(Boolean(token))
      setChecking(false)
    })()
  }, [])

  const signOut = async () => {
    await clearToken()
    setAuthed(false)
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
      {authed ? (
        <DriverScreen onSignOut={signOut} />
      ) : (
        <LoginScreen onAuthed={() => setAuthed(true)} />
      )}
    </>
  )
}

const styles = StyleSheet.create({
  center: { flex: 1, backgroundColor: '#0f1420', justifyContent: 'center', alignItems: 'center' },
})
