#!/usr/bin/env node
// Load test for one school: virtual users open the pages of the app (each page = the API calls the screen makes, in parallel, like a browser)
// and the script reports, per endpoint, how long it took (p50 / p95 / max), how many database queries it ran and how many calls failed.
//
//   node loadtest/run.mjs --tokens tokens.json --users 50 --duration 60
//
// Options: --base http://127.0.0.1:8000  --school demo  --users 50  --duration 60 (seconds)  --ramp 10 (seconds)  --think 1500 (ms between pages)
//          --polling badges|old|none (what every open tab asks in the background; badges = the current app, old = before the /badges endpoint)
//          --max-p95 1500 (fail when any endpoint is slower)  --max-errors 1 (fail above this % of failed calls)  --json out.json
// Tokens come from: php artisan tenants:run loadtest:seed --tenants=demo --option=tokens=tokens.json (see loadtest/README.md).
import { readFileSync, writeFileSync } from 'node:fs'

const arg = (name, d) => { const i = process.argv.indexOf(`--${name}`); return i > 0 ? process.argv[i + 1] : d }
const BASE = arg('base', 'http://127.0.0.1:8000')
const SCHOOL = arg('school', 'demo')
const USERS = Number(arg('users', 50))
const DURATION = Number(arg('duration', 60)) * 1000
const RAMP = Number(arg('ramp', 10)) * 1000
const THINK = Number(arg('think', 1500))
const POLLING = arg('polling', 'badges')
const MAX_P95 = Number(arg('max-p95', 0))
const MAX_ERR = Number(arg('max-errors', 1))
const tokens = JSON.parse(readFileSync(arg('tokens', 'tokens.json'), 'utf8'))

// What each screen asks the API for. {child} is the student of the signed-in parent or student.
// What every open tab asks for in the background, [url, every N seconds]: the header counters.
const POLLS = {
  badges: [['/badges', 30]],
  old: [['/chat/unread-count', 10], ['/announcements/unread-count', 30], ['/notifications', 30], ['/my-surveys/pending', 60]],
  none: [],
}
const PAGES = {
  admin: [
    [3, ['/admin/overview', '/school']], [3, ['/students?page=1']], [2, ['/attendance/classrooms', '/attendance?classroom_id={room}&date={today}']],
    [2, ['/finance/summary', '/finance/settings']], [2, ['/timetable?classroom_id={room}']], [1, ['/announcements', '/chat/conversations']], [1, ['/payroll/runs']], [1, ['/staff?page=1', '/staff-types']],
  ],
  teacher: [
    [4, ['/staff-attendance/me', '/timetable/me']], [4, ['/attendance/classrooms', '/attendance?classroom_id={room}&date={today}']], [2, ['/homework']], [2, ['/grading/options', '/assessments?classroom_id={room}&subject_id=1&term_id=1']],
    [2, ['/behavior/classrooms', '/behavior/week?classroom_id={room}&date={today}']], [1, ['/announcements', '/chat/conversations']], [1, ['/tasks/mine']], [1, ['/payroll/payslips/mine', '/payroll/commitment', '/payroll/my-contract']],
  ],
  parent: [
    [4, ['/students/{child}/grades']], [3, ['/students/{child}/timetable']], [3, ['/attendance/students/{child}']], [2, ['/students/{child}/homework']], [2, ['/students/{child}/fees']],
    [2, ['/students/{child}/behavior']], [2, ['/announcements', '/chat/conversations']],
  ],
  student: [
    [4, ['/students/{child}/grades']], [3, ['/timetable/me', '/homework/me']], [2, ['/students/{child}/behavior']], [2, ['/announcements', '/chat/conversations']],
  ],
}
const WEIGHT = { parent: 55, student: 20, teacher: 20, admin: 5 }

const today = new Date().toISOString().slice(0, 10)
const stats = new Map() // pattern -> { ms: [], queries: [], queryMs: [], errors: 0, calls: 0, statuses: Map }
const normalize = (u) => u.replace(/\/\d+/g, '/:id').replace(/=\d{4}-\d\d-\d\d/g, '=:date').replace(/(classroom_id|subject_id|term_id|page)=\d+/g, '$1=:n')
let started = 0
const pick = (items) => { const total = items.reduce((s, [w]) => s + w, 0); let r = Math.random() * total; for (const it of items) { r -= it[0]; if (r <= 0) return it[1] } return items[0][1] }
const pickRole = () => { const total = Object.values(WEIGHT).reduce((a, b) => a + b, 0); let r = Math.random() * total; for (const [k, w] of Object.entries(WEIGHT)) { r -= w; if (r <= 0 && tokens[k]?.length) return k } return 'parent' }

async function call(url, who) {
  const t0 = performance.now()
  let status = 0
  let queries = null
  let queryMs = null
  try {
    const res = await fetch(BASE + '/api' + url, { headers: { Accept: 'application/json', 'X-School': SCHOOL, Authorization: `Bearer ${who.token}`, 'Accept-Language': 'en' } })
    status = res.status
    queries = Number(res.headers.get('x-queries') ?? NaN)
    queryMs = Number(res.headers.get('x-query-ms') ?? NaN)
    await res.arrayBuffer()
  } catch { status = 0 }
  const ms = performance.now() - t0
  const key = normalize(url)
  const s = stats.get(key) ?? { ms: [], queries: [], queryMs: [], errors: 0, calls: 0, statuses: new Map() }
  s.calls++
  s.ms.push(ms)
  if (!Number.isNaN(queries)) { s.queries.push(queries); s.queryMs.push(queryMs) }
  if (status < 200 || status >= 400) { s.errors++; s.statuses.set(status, (s.statuses.get(status) ?? 0) + 1) }
  stats.set(key, s)
}

async function getJson(url, who) {
  try { return await (await fetch(BASE + '/api' + url, { headers: { Accept: 'application/json', 'X-School': SCHOOL, Authorization: `Bearer ${who.token}` } })).json() } catch { return null }
}

async function user(i) {
  await new Promise((r) => setTimeout(r, (RAMP * i) / USERS))
  const role = pickRole()
  const who = { ...tokens[role][i % tokens[role].length] }
  // The section this person works with: a teacher's first section, any section for the principal.
  if (role === 'teacher' || role === 'admin') who.room = (await getJson('/attendance/classrooms', who))?.data?.[0]?.id ?? null
  const end = started + DURATION
  // The tab's own background polling runs next to the person's clicks.
  for (const [url, every] of POLLS[POLLING] ?? []) {
    ;(async () => {
      await new Promise((r) => setTimeout(r, Math.random() * every * 1000))
      while (performance.now() < end) {
        await call(url, who)
        await new Promise((r) => setTimeout(r, every * 1000))
      }
    })()
  }
  while (performance.now() < end) {
    // A teacher without a section of their own has no attendance or behavior screens.
    const pages = PAGES[role].filter(([, urls]) => who.room !== null || !urls.some((u) => u.includes('{room}')))
    const page = pick(pages)
    await Promise.all(page.map((u) => call(u.replace('{today}', today).replace('{child}', who.child ?? 1).replace('{room}', who.room ?? 1), who)))
    await new Promise((r) => setTimeout(r, THINK * (0.5 + Math.random())))
  }
}

const q = (a, p) => { if (!a.length) return NaN; const s = [...a].sort((x, y) => x - y); return s[Math.min(s.length - 1, Math.floor((p / 100) * s.length))] }
const avg = (a) => (a.length ? a.reduce((x, y) => x + y, 0) / a.length : NaN)

console.log(`Load test: polling=${POLLING}, ${USERS} virtual users for ${DURATION / 1000}s (ramp ${RAMP / 1000}s, think ${THINK}ms) against ${BASE}, school "${SCHOOL}"`)
started = performance.now()
await Promise.all(Array.from({ length: USERS }, (_, i) => user(i)))
const seconds = (performance.now() - started) / 1000

const rows = [...stats.entries()].map(([k, s]) => ({ endpoint: k, calls: s.calls, errors: s.errors, p50: q(s.ms, 50), p95: q(s.ms, 95), max: Math.max(...s.ms), queries: avg(s.queries), maxQueries: s.queries.length ? Math.max(...s.queries) : NaN, queryMs: avg(s.queryMs), statuses: Object.fromEntries(s.statuses) }))
rows.sort((a, b) => b.p95 - a.p95)
const f = (n, d = 0) => (Number.isNaN(n) ? '-' : n.toFixed(d))
console.log('\n' + 'endpoint'.padEnd(52) + 'calls'.padStart(7) + 'err'.padStart(5) + 'p50ms'.padStart(8) + 'p95ms'.padStart(8) + 'max'.padStart(8) + 'queries'.padStart(9) + 'max'.padStart(5) + 'dbms'.padStart(7))
for (const r of rows) console.log(r.endpoint.slice(0, 51).padEnd(52) + String(r.calls).padStart(7) + String(r.errors).padStart(5) + f(r.p50).padStart(8) + f(r.p95).padStart(8) + f(r.max).padStart(8) + f(r.queries, 1).padStart(9) + f(r.maxQueries).padStart(5) + f(r.queryMs, 1).padStart(7))
const calls = rows.reduce((s, r) => s + r.calls, 0)
const errors = rows.reduce((s, r) => s + r.errors, 0)
const all = [...stats.values()].flatMap((s) => s.ms)
console.log(`\nTotal: ${calls} calls in ${seconds.toFixed(0)}s = ${(calls / seconds).toFixed(1)} requests/s, errors ${errors} (${((errors / calls) * 100).toFixed(2)}%), p50 ${f(q(all, 50))}ms, p95 ${f(q(all, 95))}ms, p99 ${f(q(all, 99))}ms`)
for (const r of rows.filter((x) => x.errors)) console.log(`  errors on ${r.endpoint}: ${JSON.stringify(r.statuses)}`)
if (arg('json')) writeFileSync(arg('json'), JSON.stringify({ users: USERS, seconds, calls, errors, rows }, null, 1))
let failed = false
if ((errors / calls) * 100 > MAX_ERR) { console.log(`FAIL: more than ${MAX_ERR}% of the calls failed`); failed = true }
if (MAX_P95 && rows.some((r) => r.p95 > MAX_P95)) { console.log(`FAIL: some endpoints have p95 above ${MAX_P95}ms`); failed = true }
process.exit(failed ? 1 : 0)
