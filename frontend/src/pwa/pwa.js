import { useEffect, useState, useSyncExternalStore } from 'react'

// The browser fires "beforeinstallprompt" once, early: it is kept here until the person taps "Install the app".
let deferred = null
const listeners = new Set()
const notify = () => listeners.forEach((l) => l())

export function startPwa() {
  if (typeof window === 'undefined') return
  window.addEventListener('beforeinstallprompt', (e) => { e.preventDefault(); deferred = e; notify() })
  window.addEventListener('appinstalled', () => { deferred = null; notify() })
  if (!('serviceWorker' in navigator)) return
  navigator.serviceWorker.register('/sw.js').then((reg) => {
    // A new version of the app was downloaded: tell the screen so it can offer to reload.
    const announce = () => window.dispatchEvent(new CustomEvent('pwa:update', { detail: reg }))
    if (reg.waiting && navigator.serviceWorker.controller) announce()
    reg.addEventListener('updatefound', () => {
      const worker = reg.installing
      worker?.addEventListener('statechange', () => { if (worker.state === 'installed' && navigator.serviceWorker.controller) announce() })
    })
  }).catch(() => {})
  // A new version took over: reload to use it. But the very first install also "takes over" the page, and reloading then
  // only loads everything twice (the page was already new): reload only when a worker was in charge before.
  const hadWorker = Boolean(navigator.serviceWorker.controller)
  let reloading = false
  navigator.serviceWorker.addEventListener('controllerchange', () => { if (hadWorker && !reloading) { reloading = true; window.location.reload() } })
}

export const isStandalone = () => typeof window !== 'undefined' && (window.matchMedia?.('(display-mode: standalone)').matches || window.navigator.standalone === true)
export const isIos = () => typeof navigator !== 'undefined' && /iphone|ipad|ipod/i.test(navigator.userAgent) && !/crios|fxios/i.test(navigator.userAgent)

// Can the app be installed from here? `prompt` (Android/desktop Chrome: one tap) or `ios` (Safari: Share, then Add to Home Screen).
export function useInstall() {
  const prompt = useSyncExternalStore((cb) => { listeners.add(cb); return () => listeners.delete(cb) }, () => deferred)
  const standalone = isStandalone()
  return {
    installed: standalone,
    mode: standalone ? null : prompt ? 'prompt' : isIos() ? 'ios' : null,
    install: async () => { if (!deferred) return; deferred.prompt(); await deferred.userChoice; deferred = null; notify() },
  }
}

export function useOnline() {
  const [online, setOnline] = useState(() => (typeof navigator === 'undefined' ? true : navigator.onLine))
  useEffect(() => {
    const on = () => setOnline(true)
    const off = () => setOnline(false)
    window.addEventListener('online', on)
    window.addEventListener('offline', off)
    return () => { window.removeEventListener('online', on); window.removeEventListener('offline', off) }
  }, [])
  return online
}

// A new version is waiting: returns it, and a function that activates it (the page reloads by itself).
export function useUpdate() {
  const [reg, setReg] = useState(null)
  useEffect(() => {
    const on = (e) => setReg(e.detail)
    window.addEventListener('pwa:update', on)
    return () => window.removeEventListener('pwa:update', on)
  }, [])
  return { available: Boolean(reg), apply: () => reg?.waiting?.postMessage('SKIP_WAITING') }
}
