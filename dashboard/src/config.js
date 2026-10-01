// Centralized runtime config sourced from Vite env vars.
export const API_URL = import.meta.env.VITE_API_URL || 'http://127.0.0.1:8000/api'
export const MAPBOX_TOKEN = import.meta.env.VITE_MAPBOX_TOKEN || ''

export const firebaseConfig = {
  apiKey: import.meta.env.VITE_FIREBASE_API_KEY || '',
  authDomain: import.meta.env.VITE_FIREBASE_AUTH_DOMAIN || '',
  databaseURL: import.meta.env.VITE_FIREBASE_DATABASE_URL || '',
  projectId: import.meta.env.VITE_FIREBASE_PROJECT_ID || '',
  appId: import.meta.env.VITE_FIREBASE_APP_ID || '',
}

// Firebase live updates are only enabled when a database URL is provided.
export const FIREBASE_ENABLED = Boolean(firebaseConfig.databaseURL)
