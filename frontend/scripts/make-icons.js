// Draws the app icons (the letter "م" on the school navy) into public/. Needs: npm i playwright.   node scripts/make-icons.js
// "maskable" icons keep the letter inside the central 60% so Android can crop them to any shape.
const { chromium } = require('playwright')
const path = require('path')
const font = path.join(__dirname, '../node_modules/@fontsource/cairo/files/cairo-arabic-800-normal.woff2')
const page = (size, scale, radius) => `<!doctype html><meta charset="utf-8"><style>
@font-face{font-family:C;font-weight:800;src:url(file://${font})}
html,body{margin:0;background:transparent}
.i{width:${size}px;height:${size}px;background:#13426f;border-radius:${radius}px;display:grid;place-items:center;position:relative;overflow:hidden}
.g{font:800 ${Math.round(size * scale)}px/1 C,sans-serif;color:#fff;position:relative;z-index:1;transform:translateY(-28%)}
</style><div class="i"><span class="g">م</span></div>`
const ICONS = [['pwa-192.png', 192, 0.62, 38], ['pwa-512.png', 512, 0.62, 100], ['pwa-maskable-512.png', 512, 0.46, 0], ['apple-touch-icon.png', 180, 0.5, 0], ['favicon-48.png', 48, 0.7, 10], ['favicon-32.png', 32, 0.72, 7]]
;(async () => {
  const b = await chromium.launch()
  for (const [file, size, scale, radius] of ICONS) {
    const p = await b.newPage({ viewport: { width: size, height: size }, deviceScaleFactor: 1 })
    await p.setContent(page(size, scale, radius), { waitUntil: 'load' })
    await p.evaluate(() => document.fonts.ready)
    await p.screenshot({ path: path.join(__dirname, '../public', file), omitBackground: true })
    await p.close()
  }
  await b.close()
})()
