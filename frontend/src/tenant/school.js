const KEY = 'school'

// Same rule as the backend: 3-30 chars, lowercase letters/digits/hyphens, no leading or trailing hyphen.
const CODE_PATTERN = /^[a-z0-9]([a-z0-9-]{1,28}[a-z0-9])$/

export const isValidSchoolCode = (code) => CODE_PATTERN.test(code)

export const normalizeSchoolCode = (input) => input.trim().toLowerCase()

const readStored = () => {
  try { return localStorage.getItem(KEY) } catch { return null }
}

// The school this page works in. One public URL serves every school, so it is kept in the browser. It is read
// once and then kept in memory: another tab choosing a different school must not redirect this tab's requests.
let current = readStored()

export const getSchool = () => current

export const setSchool = (code) => {
  current = code
  try { localStorage.setItem(KEY, code) } catch { /* storage blocked: it only lasts for this page */ }
}

export const clearSchool = () => {
  current = null
  try { localStorage.removeItem(KEY) } catch { /* ignore */ }
}

// Calls onChange when another tab changes the stored school (the page should then reload). Returns a stop function.
export const watchSchoolChanges = (onChange) => {
  const listener = (event) => {
    if (event.key === KEY || event.key === null) onChange()
  }
  window.addEventListener('storage', listener)
  return () => window.removeEventListener('storage', listener)
}

// Full page load: drops every cached query so nothing from the previous school stays on screen.
export const goTo = (path = '/') => window.location.assign(path)
