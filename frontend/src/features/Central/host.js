const CENTRAL_HOSTS = (import.meta.env.VITE_CENTRAL_HOSTS || 'localhost,127.0.0.1').split(',').map((h) => h.trim())
const BASE_DOMAIN = import.meta.env.VITE_BASE_DOMAIN || 'localhost'

// In production these must match the backend's CENTRAL_DOMAINS / TENANCY_BASE_DOMAIN, otherwise the main
// domain shows a school page and school links point at "<code>.localhost".
if (import.meta.env.PROD) {
  for (const name of ['VITE_CENTRAL_HOSTS', 'VITE_BASE_DOMAIN']) {
    if (!import.meta.env[name]) console.error(`${name} is not set: the central site will not work on a real domain.`)
  }
}

// Same rule as the backend: 3-30 chars, lowercase letters/digits/hyphens, no leading or trailing hyphen.
const CODE_PATTERN = /^[a-z0-9]([a-z0-9-]{1,28}[a-z0-9])$/

export const isCentralHost = (hostname) => CENTRAL_HOSTS.includes(hostname)

export const isValidSchoolCode = (code) => CODE_PATTERN.test(code)

export const normalizeSchoolCode = (input) => input.trim().toLowerCase()

// Where a school lives: <code>.<base domain>, on the same port as the current page in dev.
export const schoolUrl = (code, path = '/', location = window.location) =>
  `${location.protocol}//${code}.${BASE_DOMAIN}${location.port ? `:${location.port}` : ''}${path}`

// Full page navigation (the target is another host); a seam so tests can observe it.
export const redirectTo = (url) => window.location.assign(url)
