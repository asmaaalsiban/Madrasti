// Service worker: makes the app open when the connection is poor or gone, shows push notifications and opens the right page when one is tapped.
// It NEVER stores anything from /api (no personal data is kept on the device): only the app's own files (the shell, scripts, styles, fonts, icons).
const VERSION = 'v2'
const SHELL = `shell-${VERSION}`
const ASSETS = `assets-${VERSION}`
const SHELL_KEY = '/__app-shell'
const PRECACHE = ['/offline.html', '/manifest.webmanifest', '/pwa-192.png', '/pwa-512.png', '/favicon-48.png']

self.addEventListener('install', (event) => {
  event.waitUntil(caches.open(SHELL).then((c) => c.addAll(PRECACHE)))
  // A new version waits until the person agrees to reload (the app shows "New version"), except the very first install.
  if (!self.registration.active) self.skipWaiting()
})

self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys().then((keys) => Promise.all(keys.filter((k) => ![SHELL, ASSETS].includes(k)).map((k) => caches.delete(k)))).then(() => self.clients.claim()),
  )
})

self.addEventListener('message', (event) => {
  if (event.data === 'SKIP_WAITING') self.skipWaiting()
})

self.addEventListener('fetch', (event) => {
  const req = event.request
  const url = new URL(req.url)
  if (req.method !== 'GET' || url.origin !== self.location.origin || url.pathname.startsWith('/api/')) return

  // Opening a page: the network first (always the newest app); when it fails, the app shell saved last time, else the offline page.
  if (req.mode === 'navigate') {
    event.respondWith(
      fetch(req)
        .then((res) => {
          // The copy is made now, before the page starts reading the answer.
          if (res.ok && res.headers.get('content-type')?.includes('text/html')) { const copy = res.clone(); caches.open(SHELL).then((c) => c.put(SHELL_KEY, copy)) }
          return res
        })
        .catch(async () => (await caches.match(SHELL_KEY)) || (await caches.match('/offline.html'))),
    )
    return
  }

  // The built files have a hash in their name and never change: saved the first time, then served at once.
  if (url.pathname.startsWith('/assets/')) {
    event.respondWith(
      caches.match(req).then((hit) => hit || fetch(req).then((res) => { if (res.ok) { const copy = res.clone(); caches.open(ASSETS).then((c) => c.put(req, copy)) } return res })),
    )
    return
  }

  // Icons and the manifest: the saved copy at once, refreshed in the background.
  if (/\.(png|svg|webmanifest|ico)$/.test(url.pathname)) {
    event.respondWith(
      caches.open(SHELL).then((c) => c.match(req).then((hit) => {
        const fresh = fetch(req).then((res) => { if (res.ok) c.put(req, res.clone()); return res }).catch(() => hit) // cloned at once, inside this callback
        return hit || fresh
      })),
    )
  }
})

self.addEventListener('push', (event) => {
  let data = {}
  try {
    data = event.data ? event.data.json() : {}
  } catch {
    data = { body: event.data ? event.data.text() : '' }
  }
  event.waitUntil(
    self.registration.showNotification(data.title || 'مدرستي', {
      body: data.body || '',
      icon: '/pwa-192.png',
      badge: '/pwa-192.png',
      data: { url: data.url || '/' },
    }),
  )
})

self.addEventListener('notificationclick', (event) => {
  event.notification.close()
  const url = event.notification.data?.url || '/'
  event.waitUntil(
    self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then((windows) => {
      for (const w of windows) {
        if ('focus' in w) {
          w.navigate(url)
          return w.focus()
        }
      }
      return self.clients.openWindow(url)
    }),
  )
})
