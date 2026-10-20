// Today as YYYY-MM-DD in the browser's local time (toISOString would use UTC and be a day off near midnight).
export function today() {
  const d = new Date()
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`
}
