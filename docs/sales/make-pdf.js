// Makes the two printable PDFs from the HTML files. Needs: npm i playwright (and a Chromium).   node make-pdf.js
const { chromium } = require('playwright')
const path = require('path')
;(async () => {
  const b = await chromium.launch(); const page = await b.newPage()
  for (const f of ['brochure', 'questions']) {
    await page.goto('file://' + path.join(__dirname, `${f}.html`), { waitUntil: 'networkidle' })
    await page.pdf({ path: path.join(__dirname, `${f}.pdf`), format: 'A4', printBackground: true, preferCSSPageSize: true })
  }
  await b.close()
})()
