import Constants from 'expo-constants'

const extra = Constants.expoConfig?.extra ?? {}

export const API_URL = extra.apiUrl || 'http://127.0.0.1:8000/api'

export const firebaseConfig = {
  apiKey: extra.firebase?.apiKey || '',
  authDomain: extra.firebase?.authDomain || '',
  databaseURL: extra.firebase?.databaseURL || '',
  projectId: extra.firebase?.projectId || '',
  appId: extra.firebase?.appId || '',
}

export const FIREBASE_ENABLED = Boolean(firebaseConfig.databaseURL)
