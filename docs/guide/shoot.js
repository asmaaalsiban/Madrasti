// Takes the screenshots of the guide (Arabic) from the running demo school. Needs: npm i playwright (and the app running on :5173).
const { chromium } = require('playwright')
const path = require('path')
const API = 'http://127.0.0.1:5173/api'
const H = { 'Content-Type': 'application/json', Accept: 'application/json', 'X-School': 'demo' }
const login = async (l, p) => (await (await fetch(`${API}/auth/login`, { method: 'POST', headers: H, body: JSON.stringify({ login: l, password: p }) })).json()).token
const OUT = path.join(__dirname, 'img')
const SHOTS = {
  admin: [['admin@demo.test', 'Admin12345', 1280, 800], [['', 'overview'], ['/students', 'students'], ['/classrooms', 'classrooms'], ['/attendance', 'attendance'], ['/grades', 'grades'], ['/timetable', 'timetable'], ['/exams', 'exams'], ['/duties', 'duties'], ['/finance', 'finance'], ['/finance/transfers', 'transfers'], ['/payroll', 'payroll'], ['/tasks', 'tasks'], ['/transport', 'transport'], ['/announcements', 'announcements'], ['/surveys', 'surveys'], ['/stories', 'stories'], ['/staff-access', 'staff-access'], ['/audit-log', 'audit-log'], ['/privacy-requests', 'privacy-requests'], ['/photo-consent', 'photo-consent']]],
  teacher: [['teacher1@demo.test', 'Teacher12345', 1280, 800], [['', 'home'], ['/attendance', 'attendance'], ['/timetable', 'timetable'], ['/homework', 'homework'], ['/grades', 'grades'], ['/tasks', 'tasks'], ['/payslips', 'payslips'], ['/stories', 'stories']]],
  accountant: [['accountant1@demo.test', 'Teacher12345', 1280, 800], [['', 'home'], ['/finance/accounts', 'accounts'], ['/finance/transfers', 'transfers'], ['/payroll', 'payroll']]],
  parent: [['parent1@demo.test', '123456789', 390, 844], [['', 'home'], ['/children/1/grades', 'grades'], ['/children/1/timetable', 'timetable'], ['/children/1/fees', 'fees'], ['/children/1/transport', 'transport'], ['/stories', 'stories'], ['/privacy', 'privacy'], ['/surveys', 'surveys']]],
  student: [['ahmad.d1001@demo.edu', '123456789', 390, 844], [['', 'home'], ['/timetable', 'timetable'], ['/homework', 'homework']]],
}
;(async () => {
  const b = await chromium.launch()
  for (const [who, [l, p, w, h], shots] of Object.entries(SHOTS).map(([k, v]) => [k, v[0], v[1]])) {
    const tok = await login(l, p)
    const ctx = await b.newContext({ viewport: { width: w, height: h }, deviceScaleFactor: 1 })
    await ctx.addInitScript(([t]) => { localStorage.setItem('school', 'demo'); localStorage.setItem('token:demo', t); localStorage.setItem('lang', 'ar') }, [tok])
    const page = await ctx.newPage()
    const prefix = who === 'accountant' ? '/teacher' : `/${who}`
    for (const [path, name] of shots) {
      try { await page.goto('http://127.0.0.1:5173' + prefix + path, { waitUntil: 'networkidle' }); await page.waitForTimeout(400); await page.screenshot({ path: `${OUT}/${who}-${name}.png` }) } catch (e) { console.log('ERR', who, name, e.message.slice(0, 60)) }
    }
    await ctx.close()
  }
  // public pages
  const ctx = await b.newContext({ viewport: { width: 1280, height: 800 } })
  await ctx.addInitScript(() => { localStorage.setItem('school', 'demo'); localStorage.setItem('lang', 'ar') })
  const page = await ctx.newPage()
  for (const [path, name] of [['/', 'landing'], ['/login', 'login']]) { await page.goto('http://127.0.0.1:5173' + path, { waitUntil: 'networkidle' }); await page.screenshot({ path: `${OUT}/public-${name}.png` }) }
  await b.close()
})()
