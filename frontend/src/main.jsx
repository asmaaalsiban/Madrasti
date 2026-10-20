import { StrictMode } from 'react'
import { createRoot } from 'react-dom/client'
import './index.css'
import App from './App.jsx'
import { watchSchoolChanges } from './tenant/school'

// Another tab switched school: reload so this tab shows the same school (never mixes two schools).
watchSchoolChanges(() => window.location.reload())

createRoot(document.getElementById('root')).render(
  <StrictMode>
    <App />
  </StrictMode>,
)
