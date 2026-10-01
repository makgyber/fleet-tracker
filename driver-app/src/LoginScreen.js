import { useState } from 'react'
import { View, Text, TextInput, TouchableOpacity, StyleSheet } from 'react-native'
import { api, setToken } from './api'

export default function LoginScreen({ onAuthed }) {
  const [email, setEmail] = useState('')
  const [password, setPassword] = useState('')
  const [error, setError] = useState('')
  const [busy, setBusy] = useState(false)

  const submit = async () => {
    setError('')
    setBusy(true)
    try {
      const res = await api.login(email.trim(), password)
      await setToken(res.token)
      onAuthed()
    } catch (e) {
      setError(e.message)
    } finally {
      setBusy(false)
    }
  }

  return (
    <View style={styles.container}>
      <Text style={styles.title}>Fleet Driver</Text>
      <Text style={styles.sub}>Sign in to start your shift</Text>

      <TextInput
        style={styles.input}
        placeholder="Email"
        placeholderTextColor="#8b97ad"
        autoCapitalize="none"
        keyboardType="email-address"
        value={email}
        onChangeText={setEmail}
      />
      <TextInput
        style={styles.input}
        placeholder="Password"
        placeholderTextColor="#8b97ad"
        secureTextEntry
        value={password}
        onChangeText={setPassword}
      />
      {error ? <Text style={styles.error}>{error}</Text> : null}
      <TouchableOpacity style={styles.button} onPress={submit} disabled={busy}>
        <Text style={styles.buttonText}>{busy ? '...' : 'Sign in'}</Text>
      </TouchableOpacity>
    </View>
  )
}

const styles = StyleSheet.create({
  container: { flex: 1, backgroundColor: '#0f1420', justifyContent: 'center', padding: 24 },
  title: { color: '#e6ebf5', fontSize: 28, fontWeight: '700' },
  sub: { color: '#8b97ad', marginTop: 4, marginBottom: 24 },
  input: {
    backgroundColor: '#171d2b',
    borderColor: '#2a3346',
    borderWidth: 1,
    borderRadius: 10,
    color: '#e6ebf5',
    padding: 14,
    marginBottom: 12,
  },
  button: {
    backgroundColor: '#4f8cff',
    borderRadius: 10,
    padding: 16,
    alignItems: 'center',
    marginTop: 8,
  },
  buttonText: { color: 'white', fontWeight: '700', fontSize: 16 },
  error: { color: '#ff6b6b', marginBottom: 8 },
})
