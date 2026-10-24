import { StrictMode } from 'react'
import { createRoot } from 'react-dom/client'
import './index.css'
import App from './App.jsx'
import { watchSchoolChanges } from './tenant/school'

// Another tab switched school: reload so this tab shows the same school (never mixes two schools).
watchSchoolChanges(() => window.location.reload())

// The service worker shows push notifications; it is only registered where the browser supports it.
if ('serviceWorker' in navigator) navigator.serviceWorker.register('/sw.js').catch(() => {})

createRoot(document.getElementById('root')).render(
  <StrictMode>
    <App />
  </StrictMode>,
)
