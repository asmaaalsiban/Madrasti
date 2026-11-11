import { useEffect } from 'react'
import { labelTables } from './labelTables'

// Keeps every table on the page labelled for the phone card layout (see labelTables.js): once now, and again whenever tables appear.
export default function ResponsiveTables() {
  useEffect(() => {
    let frame = 0
    const run = () => { frame = 0; labelTables(document) }
    const schedule = () => { if (!frame) frame = requestAnimationFrame(run) }
    run()
    // Only added or removed nodes matter (setting an attribute does not trigger this), so labelling never loops.
    const observer = new MutationObserver(schedule)
    observer.observe(document.body, { childList: true, subtree: true })
    return () => { observer.disconnect(); if (frame) cancelAnimationFrame(frame) }
  }, [])
  return null
}
