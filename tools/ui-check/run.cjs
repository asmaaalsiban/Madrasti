// UI check: starts a throw-away local demo school (SQLite, in the temp folder), the API and the frontend, signs in as an admin, a teacher, a
// student and a parent, opens every screen of the app and reports what is wrong. Works on Windows, macOS and Linux (needs php and node).
//
//   cd tools/ui-check && npm install && npx playwright install chromium     (once)
//   node run.cjs sweep [--lang=ar,en] [--viewports=phone,tablet,laptop]      every route: sideways scroll, crashes, 5xx answers
//   node run.cjs shots --pages=/admin,/teacher/attendance [--lang=ar] [--viewports=phone]   screenshots into out/
//   node run.cjs up                                                         only start the demo and wait (Ctrl+C stops it)
//
// sweep exits with 1 when a screen scrolls sideways outside a scroll box, crashes, or its API call answers 5xx. It looks for layout
// breakage, not for looks: open the screenshots for that. The data is the demo data (php artisan school:seed-demo), not a real school.
const { spawn, spawnSync } = require('node:child_process')
const crypto = require('node:crypto')
const fs = require('node:fs')
const net = require('node:net')
const os = require('node:os')
const path = require('node:path')

const ROOT = path.resolve(__dirname, '../..')
const BACKEND = path.join(ROOT, 'backend')
const FRONTEND = path.join(ROOT, 'frontend')
const WORK = path.join(os.tmpdir(), 'madrasti-ui-check')
const OUT = path.join(__dirname, 'out')
const API_PORT = 8000
const WEB_PORT = 5199
const SCHOOL = 'school2'
const PASSWORD = 'Test12345'
const PEOPLE = { admin: 'admin@school2.test', teacher: 'head1@school2.test', student: 'ahmad.d1001@school2.edu', parent: 'parent1@school2.test' }
const VIEWPORTS = { phone: { width: 390, height: 844, mobile: true }, tablet: { width: 820, height: 1000, mobile: true }, laptop: { width: 1280, height: 800, mobile: false } }

const [mode = 'sweep', ...rest] = process.argv.slice(2)
const flag = (name, fallback) => (rest.find((a) => a.startsWith(`--${name}=`)) ?? `--${name}=${fallback}`).split('=')[1]
const langs = flag('lang', 'ar,en').split(',')
const viewports = flag('viewports', 'phone').split(',')
const children = []

function fail(message) {
  console.error(`\n${message}`)
  stop()
  process.exit(2)
}

function stop() {
  for (const c of children) { try { c.kill() } catch { /* already gone */ } }
  // The school's own database is a file in backend/database (SQLite): remove it, it is only demo data.
  try { fs.rmSync(path.join(BACKEND, 'database', `madrasati_${SCHOOL}`), { force: true }) } catch { /* ignore */ }
}
process.on('SIGINT', () => { stop(); process.exit(130) })

// The backend's own .env (MySQL) must not be used: process variables win over the .env file, so everything is set here.
const centralDb = path.join(WORK, 'central.sqlite')
const phpEnv = {
  ...process.env, APP_ENV: 'local', APP_DEBUG: 'true', DB_CONNECTION: 'sqlite', DB_DATABASE: centralDb, BROADCAST_CONNECTION: 'null', QUEUE_CONNECTION: 'sync',
  CACHE_STORE: 'array', SESSION_DRIVER: 'array', MAIL_MAILER: 'log', BCRYPT_ROUNDS: '4', APP_MAINTENANCE_DRIVER: 'file',
  APP_KEY: `base64:${crypto.randomBytes(32).toString('base64')}`,
}

function artisan(args) {
  const r = spawnSync('php', ['artisan', ...args], { cwd: BACKEND, env: phpEnv, encoding: 'utf8' })
  if (r.error) fail(`Could not run php (${r.error.message}). Is PHP 8.2+ installed and on the PATH?`)
  if (r.status !== 0) fail(`php artisan ${args.join(' ')} failed:\n${r.stdout}\n${r.stderr}`)
  return r.stdout
}

function waitForPort(port, label, timeoutMs = 60000) {
  const started = Date.now()
  return new Promise((resolve, reject) => {
    const attempt = () => {
      const socket = net.connect(port, '127.0.0.1')
      socket.once('connect', () => { socket.destroy(); resolve() })
      socket.once('error', () => {
        socket.destroy()
        if (Date.now() - started > timeoutMs) reject(new Error(`${label} did not start on port ${port}`))
        else setTimeout(attempt, 500)
      })
    }
    attempt()
  })
}

function setupDemo() {
  fs.mkdirSync(WORK, { recursive: true })
  fs.rmSync(centralDb, { force: true })
  fs.rmSync(path.join(BACKEND, 'database', `madrasati_${SCHOOL}`), { force: true })
  fs.writeFileSync(centralDb, '')
  console.log('Creating the demo school (about a minute)...')
  artisan(['migrate', '--path=database/migrations/central', '--force'])
  artisan(['school:create', SCHOOL, 'مدرسة النور', `--admin-email=${PEOPLE.admin}`, '--admin-password=Admin12345'])
  artisan(['school:seed-demo', SCHOOL])
  artisan(['school:seed-grades', SCHOOL])
  // One known password and no forced password change for the four demo people.
  const emails = JSON.stringify(Object.values(PEOPLE))
  artisan(['tinker', `--execute=tenancy()->initialize('${SCHOOL}'); foreach (${emails.replace(/"/g, "'")} as $e) { App\\Models\\User::where('email', $e)->first()->forceFill(['password' => '${PASSWORD}', 'must_change_password' => false])->save(); }`])
}

async function startServers() {
  // php artisan serve would drop the variables above (it re-reads .env), so PHP's own server runs the public/index.php router.
  children.push(spawn('php', ['-S', `127.0.0.1:${API_PORT}`, 'index.php'], { cwd: path.join(BACKEND, 'public'), env: { ...phpEnv, PHP_CLI_SERVER_WORKERS: '4' }, stdio: 'ignore' }))
  const vite = path.join(FRONTEND, 'node_modules', 'vite', 'bin', 'vite.js')
  if (!fs.existsSync(vite)) fail('Run "npm install" in frontend/ first.')
  children.push(spawn(process.execPath, [vite, '--port', String(WEB_PORT), '--host', '127.0.0.1', '--strictPort'], { cwd: FRONTEND, stdio: 'ignore' }))
  await waitForPort(API_PORT, 'the API')
  await waitForPort(WEB_PORT, 'the frontend')
}

async function tokens() {
  const out = {}
  for (const [role, login] of Object.entries(PEOPLE)) {
    const res = await fetch(`http://127.0.0.1:${API_PORT}/api/auth/login`, {
      method: 'POST', headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-School': SCHOOL }, body: JSON.stringify({ login, password: PASSWORD, device_name: 'ui-check' }),
    })
    if (!res.ok) fail(`Could not sign in as ${login}: HTTP ${res.status}`)
    out[role] = (await res.json()).token
  }
  return out
}

// Every route of router.jsx (admin: /admin/...; the others: their HOME_ROUTES). A ":id" becomes 1.
function routes() {
  const src = fs.readFileSync(path.join(FRONTEND, 'src', 'app', 'router.jsx'), 'utf8')
  const list = [['admin', '/admin']]
  for (const m of src.matchAll(/path: '(\/admin[^']*)'/g)) list.push(['admin', m[1]])
  for (const role of ['parent', 'student', 'teacher']) {
    const start = src.indexOf(`  ${role}: [`)
    const block = src.slice(start, src.indexOf('\n  ],', start))
    list.push([role, `/${role}`])
    for (const m of block.matchAll(/path: '([^']+)'/g)) list.push([role, `/${role}/${m[1]}`])
  }
  const seen = new Set()
  return list.map(([r, p]) => [r, p.replace(/:\w+/g, '1')]).filter(([r, p]) => !seen.has(r + p) && seen.add(r + p))
}

// Runs in the page: elements past the screen edge that are not inside a scroll box, a clipped decoration or a screen-reader-only text.
function measure() {
  const vw = document.documentElement.clientWidth
  const clipped = (el) => {
    for (let p = el.parentElement; p; p = p.parentElement) {
      const s = getComputedStyle(p)
      if (/(hidden|clip)/.test(s.overflowX)) { const r = p.getBoundingClientRect(); if (r.left >= -1 && r.right <= vw + 1) return true }
    }
    return false
  }
  const over = []
  for (const el of document.querySelectorAll('body *')) {
    if (el.closest('.overflow-x-auto, [data-scroll], .sr-only, .tabbar, [role=dialog]')) continue
    const r = el.getBoundingClientRect()
    if (r.width > 0 && (r.right > vw + 1 || r.left < -1) && !clipped(el)) over.push(`${el.tagName.toLowerCase()}.${String(el.className).slice(0, 50)}|${(el.textContent || '').slice(0, 24)}`)
  }
  return { page: document.documentElement.scrollWidth, vw, over: over.slice(0, 3), text: document.body.innerText.length }
}

async function openContext(browser, vp, lang, token) {
  const ctx = await browser.newContext({ viewport: { width: vp.width, height: vp.height }, hasTouch: vp.mobile, isMobile: vp.mobile, deviceScaleFactor: 1 })
  await ctx.addInitScript(([l, t, s]) => { localStorage.setItem('school', s); localStorage.setItem(`token:${s}`, t); localStorage.setItem('lang', l) }, [lang, token, SCHOOL])
  return ctx
}

async function launch() {
  const { chromium } = require('playwright')
  try { return await chromium.launch() } catch (e) { return fail(`Could not start the browser (${e.message.split('\n')[0]}). Run: npx playwright install chromium`) }
}

async function sweep(toks) {
  const browser = await launch()
  const problems = []
  let opened = 0
  for (const vpName of viewports) for (const lang of langs) for (const role of Object.keys(PEOPLE)) {
    const ctx = await openContext(browser, VIEWPORTS[vpName], lang, toks[role])
    const page = await ctx.newPage()
    for (const [, route] of routes().filter(([r]) => r === role)) {
      let crashed = false
      const errors = []
      const onConsole = (m) => { if (m.type() === 'error' && /caught the following error/.test(m.text())) crashed = true }
      const onResponse = (res) => { if (res.url().includes('/api/') && res.status() >= 500) errors.push(`${res.status()} ${res.url().replace(/^.*\/api/, '/api').slice(0, 60)}`) }
      page.on('console', onConsole); page.on('response', onResponse)
      await page.goto(`http://127.0.0.1:${WEB_PORT}${route}`).catch(() => {})
      await page.waitForTimeout(1300)
      const m = await page.evaluate(measure)
      page.off('console', onConsole); page.off('response', onResponse)
      opened += 1
      const where = `${vpName} ${lang} ${role} ${route}`
      if (crashed || m.text < 20) problems.push(`CRASH   ${where}`)
      else if (m.page > m.vw + 1 || m.over.length) problems.push(`SIDEWAYS ${where}  page ${m.page}px on ${m.vw}px  ${m.over.join(' | ')}`)
      for (const e of errors.slice(0, 2)) problems.push(`API 5xx ${where}  ${e}`)
    }
    await ctx.close()
  }
  await browser.close()
  console.log(`\nOpened ${opened} screens (${viewports.join(', ')}; ${langs.join(', ')}).`)
  if (problems.length === 0) console.log('Nothing sideways, no crash, no 5xx.')
  else { console.log(`${problems.length} problem(s):`); problems.forEach((p) => console.log(`  ${p}`)) }
  return problems.length === 0 ? 0 : 1
}

async function shots(toks) {
  const pages = flag('pages', '').split(',').filter(Boolean)
  if (pages.length === 0) fail('Give the pages: --pages=/admin,/teacher/attendance')
  fs.mkdirSync(OUT, { recursive: true })
  const browser = await launch()
  for (const vpName of viewports) for (const lang of langs) for (const route of pages) {
    const role = route.split('/')[1]
    if (!toks[role]) fail(`Cannot tell the role of ${route} (it should start with /admin, /teacher, /student or /parent)`)
    const ctx = await openContext(browser, VIEWPORTS[vpName], lang, toks[role])
    const page = await ctx.newPage()
    await page.goto(`http://127.0.0.1:${WEB_PORT}${route}`).catch(() => {})
    await page.waitForTimeout(1800)
    const file = path.join(OUT, `${vpName}-${lang}-${route.replace(/[^a-z0-9]+/gi, '_')}.png`)
    await page.screenshot({ path: file, fullPage: true })
    console.log(file)
    await ctx.close()
  }
  await browser.close()
  return 0
}

;(async () => {
  if (!['sweep', 'shots', 'up'].includes(mode)) fail('Use: sweep | shots | up')
  for (const v of viewports) if (!VIEWPORTS[v]) fail(`Unknown viewport "${v}" (phone, tablet, laptop)`)
  setupDemo()
  await startServers().catch((e) => fail(e.message))
  const toks = await tokens()
  let code = 0
  if (mode === 'sweep') code = await sweep(toks)
  else if (mode === 'shots') code = await shots(toks)
  else { console.log(`\nDemo school "${SCHOOL}" is up: http://127.0.0.1:${WEB_PORT}  (school code ${SCHOOL}; ${Object.values(PEOPLE).join(', ')} / ${PASSWORD})\nCtrl+C stops it.`); await new Promise(() => {}) }
  stop()
  process.exit(code)
})().catch((e) => fail(e.stack || String(e)))
