import { StrictMode } from 'react'
import { createRoot } from 'react-dom/client'
// Fonts are served from our own server (never from Google): no visitor's address is sent to a third party. Licence: SIL Open Font License.
import '@fontsource/nunito/latin-400.css'
import '@fontsource/nunito/latin-500.css'
import '@fontsource/nunito/latin-600.css'
import '@fontsource/nunito/latin-700.css'
import '@fontsource/nunito/latin-800.css'
import '@fontsource/cairo/arabic-400.css'
import '@fontsource/cairo/arabic-500.css'
import '@fontsource/cairo/arabic-600.css'
import '@fontsource/cairo/arabic-700.css'
import '@fontsource/cairo/arabic-800.css'
import '@fontsource/cairo/latin-400.css'
import '@fontsource/cairo/latin-700.css'
import './index.css'
import App from './App.jsx'
import { watchSchoolChanges } from './tenant/school'
import { startPwa } from './pwa/pwa'
import { applyLiteMode } from './lite'

applyLiteMode()

// Another tab switched school: reload so this tab shows the same school (never mixes two schools).
watchSchoolChanges(() => window.location.reload())

// The service worker opens the app without a connection, shows push notifications and updates the app; the install prompt is kept for the user menu.
startPwa()

createRoot(document.getElementById('root')).render(
  <StrictMode>
    <App />
  </StrictMode>,
)
