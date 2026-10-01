import { useState } from 'react'
import { api, setToken } from '../api'

export default function Login({ onAuthed }) {
  const [mode, setMode] = useState('login')
  const [name, setName] = useState('')
  const [email, setEmail] = useState('')
  const [password, setPassword] = useState('')
  const [error, setError] = useState('')
  const [busy, setBusy] = useState(false)

  const submit = async (e) => {
    e.preventDefault()
    setError('')
    setBusy(true)
    try {
      const res =
        mode === 'login'
          ? await api.login(email, password)
          : await api.register(name, email, password)
      setToken(res.token)
      onAuthed()
    } catch (err) {
      setError(err.message)
    } finally {
      setBusy(false)
    }
  }

  return (
    <div className="login">
      <h1>Fleet Tracker</h1>
      <p className="sub" style={{ color: 'var(--muted)' }}>
        {mode === 'login' ? 'Sign in to the dashboard' : 'Create an operator account'}
      </p>
      <form onSubmit={submit}>
        {mode === 'register' && (
          <input
            placeholder="Name"
            value={name}
            onChange={(e) => setName(e.target.value)}
            required
          />
        )}
        <input
          type="email"
          placeholder="Email"
          value={email}
          onChange={(e) => setEmail(e.target.value)}
          required
        />
        <input
          type="password"
          placeholder="Password"
          value={password}
          onChange={(e) => setPassword(e.target.value)}
          required
        />
        {error && <div className="error">{error}</div>}
        <button type="submit" disabled={busy} style={{ width: '100%', marginTop: 8 }}>
          {busy ? '...' : mode === 'login' ? 'Sign in' : 'Register'}
        </button>
      </form>
      <button
        className="secondary"
        style={{ width: '100%', marginTop: 8 }}
        onClick={() => setMode(mode === 'login' ? 'register' : 'login')}
      >
        {mode === 'login' ? 'Need an account? Register' : 'Have an account? Sign in'}
      </button>
    </div>
  )
}
