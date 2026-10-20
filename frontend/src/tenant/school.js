const KEY = 'school'

// Same rule as the backend: 3-30 chars, lowercase letters/digits/hyphens, no leading or trailing hyphen.
const CODE_PATTERN = /^[a-z0-9]([a-z0-9-]{1,28}[a-z0-9])$/

export const isValidSchoolCode = (code) => CODE_PATTERN.test(code)

export const normalizeSchoolCode = (input) => input.trim().toLowerCase()

// The school the user is working in. One public URL serves every school, so it lives in the browser.
export const getSchool = () => {
  try { return localStorage.getItem(KEY) } catch { return null }
}

export const setSchool = (code) => {
  try { localStorage.setItem(KEY, code) } catch { /* storage blocked: the school is chosen again next time */ }
}

export const clearSchool = () => {
  try { localStorage.removeItem(KEY) } catch { /* ignore */ }
}

// Full page load: drops every cached query so nothing from the previous school stays on screen.
export const goTo = (path = '/') => window.location.assign(path)
