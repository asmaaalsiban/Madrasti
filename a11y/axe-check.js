// Accessibility check (WCAG 2.1 A/AA + best practice) of the main screens of every role, in Arabic and English, with axe-core.
//   cd a11y && npm i axe-core playwright && node axe-check.js
// Needs the app running (frontend on :5173 proxying /api, backend with the demo school "demo" and the demo logins).
// It prints every kind of violation with how many elements and pages are affected and a few examples; aim for none.
const { chromium } = require('playwright')
const fs = require('fs')
const axeSrc = fs.readFileSync(require.resolve('axe-core/axe.min.js'), 'utf8')
const BASE = process.env.BASE || 'http://127.0.0.1:5173'
const H = { 'Content-Type': 'application/json', Accept: 'application/json', 'X-School': process.env.SCHOOL || 'demo' }
const login = async (l, p) => (await (await fetch(`${BASE}/api/auth/login`, { method: 'POST', headers: H, body: JSON.stringify({ login: l, password: p }) })).json()).token
const PEOPLE = {
  public: [null, ['/', '/login', '/privacy', '/terms', '/cookies']],
  admin: [['admin@demo.test', 'Admin12345'], ['/admin', '/admin/students', '/admin/finance', '/admin/tasks', '/admin/transport', '/admin/payroll', '/admin/privacy-requests']],
  teacher: [['teacher1@demo.test', 'Teacher12345'], ['/teacher', '/teacher/attendance', '/teacher/tasks', '/teacher/payslips', '/teacher/stories']],
  parent: [['parent1@demo.test', '123456789'], ['/parent', '/parent/children/1/fees', '/parent/children/1/grades', '/parent/privacy', '/parent/stories']],
  student: [['ahmad.d1001@demo.edu', '123456789'], ['/student', '/student/homework', '/student/timetable']],
}
;(async () => {
  const browser = await chromium.launch(); const found = {}
  for (const lang of ['ar', 'en']) for (const [who, [cred, routes]] of Object.entries(PEOPLE)) {
    const token = cred ? await login(...cred) : null
    const ctx = await browser.newContext({ viewport: { width: 1100, height: 900 } })
    await ctx.addInitScript(([t, l, s]) => { localStorage.setItem('school', s); if (t) localStorage.setItem(`token:${s}`, t); localStorage.setItem('lang', l) }, [token, lang, process.env.SCHOOL || 'demo'])
    const page = await ctx.newPage()
    for (const r of routes) {
      await page.goto(BASE + r, { waitUntil: 'networkidle' })
      await page.evaluate(axeSrc)
      const res = await page.evaluate(() => axe.run(document, { runOnly: ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'best-practice'] }))
      for (const v of res.violations) {
        const f = (found[v.id] ??= { impact: v.impact, help: v.help, count: 0, pages: new Set(), samples: [] })
        f.count += v.nodes.length; f.pages.add(`${who}:${r}:${lang}`)
        if (f.samples.length < 3) f.samples.push(`${v.nodes[0].html.slice(0, 140)} | ${(v.nodes[0].any[0]?.message || '').slice(0, 120)}`)
      }
    }
    await ctx.close()
  }
  await browser.close()
  const rows = Object.entries(found).sort((a, b) => b[1].count - a[1].count)
  if (rows.length === 0) console.log('No violations found.')
  for (const [id, f] of rows) console.log(`\n${id} [${f.impact}] x${f.count} on ${f.pages.size} pages: ${f.help}\n  ${f.samples.join('\n  ')}`)
  process.exit(rows.length ? 1 : 0)
})()
