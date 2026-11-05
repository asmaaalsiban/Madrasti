// Reads the colours of a school's logo: the main colour (the most present vivid one) and a dark one for headings and the side bar.
// Greys, whites and blacks are ignored (a logo on a white square must not give "white"). A logo with no colour at all gives null:
// the site then keeps its default look.
import { contrast, readableBrand } from './color'

const hex = (rgb) => `#${rgb.map((v) => Math.round(Math.min(255, Math.max(0, v))).toString(16).padStart(2, '0')).join('')}`

function hsl([r, g, b]) {
  const [R, G, B] = [r, g, b].map((v) => v / 255)
  const max = Math.max(R, G, B)
  const min = Math.min(R, G, B)
  const l = (max + min) / 2
  const d = max - min
  if (d === 0) return [0, 0, l]
  const s = d / (1 - Math.abs(2 * l - 1))
  let h = max === R ? ((G - B) / d) % 6 : max === G ? (B - R) / d + 2 : (R - G) / d + 4
  h = (h * 60 + 360) % 360
  return [h, s, l]
}

function fromHsl(h, s, l) {
  const c = (1 - Math.abs(2 * l - 1)) * s
  const x = c * (1 - Math.abs(((h / 60) % 2) - 1))
  const m = l - c / 2
  const [r, g, b] = h < 60 ? [c, x, 0] : h < 120 ? [x, c, 0] : h < 180 ? [0, c, x] : h < 240 ? [0, x, c] : h < 300 ? [x, 0, c] : [c, 0, x]
  return [(r + m) * 255, (g + m) * 255, (b + m) * 255]
}

// A dark colour that white text can sit on (contrast 4.5 or more): darkened step by step, keeping its hue.
function darkEnough(rgb) {
  let [h, s, l] = hsl(rgb)
  l = Math.min(l, 0.28)
  for (let i = 0; i < 30 && contrast(hex(fromHsl(h, s, l)), '#ffffff') < 7; i++) l *= 0.92
  return hex(fromHsl(h, Math.min(s, 0.85), l))
}

/**
 * @param {ArrayLike<number>} data RGBA pixels (as from a canvas)
 * @returns {{ primary: string, dark: string, swatches: string[] } | null}
 */
export function paletteFromPixels(data) {
  const bins = new Map() // hue bucket -> { weight, sum: [r,g,b], n }
  const darks = new Map()
  for (let i = 0; i < data.length; i += 4) {
    if (data[i + 3] < 200) continue
    const rgb = [data[i], data[i + 1], data[i + 2]]
    const [h, s, l] = hsl(rgb)
    if (s < 0.22 || l > 0.93 || l < 0.05) continue
    const key = Math.floor(h / 15)
    const target = l < 0.3 ? darks : bins
    const bin = target.get(key) ?? { weight: 0, sum: [0, 0, 0], n: 0 }
    bin.weight += s
    bin.n += 1
    bin.sum = bin.sum.map((v, k) => v + rgb[k])
    target.set(key, bin)
  }
  const average = (bin) => bin.sum.map((v) => v / bin.n)
  // Dark pixels also count for the main colour when the logo is all dark (navy on white): they are lightened a little.
  const pool = bins.size > 0 ? bins : darks
  if (pool.size === 0) return null
  const ranked = [...pool.values()].sort((a, b) => b.weight - a.weight)
  const primaryRgb = average(ranked[0])
  const darkBin = [...darks.values()].sort((a, b) => b.weight - a.weight)[0]
  const dark = darkEnough(darkBin && darkBin.n > 8 ? average(darkBin) : primaryRgb)
  return { primary: hex(primaryRgb), dark, swatches: ranked.slice(0, 4).map((b) => hex(average(b))) }
}

/** Draws the image small and reads it. `source` is an <img> or an ImageBitmap that has loaded. */
export function paletteFromImage(source) {
  const size = 96
  const canvas = document.createElement('canvas')
  canvas.width = size
  canvas.height = size
  const ctx = canvas.getContext('2d', { willReadFrequently: true })
  ctx.drawImage(source, 0, 0, size, size)
  return paletteFromPixels(ctx.getImageData(0, 0, size, size).data)
}

/** Reads a chosen file. */
export function paletteFromFile(file) {
  return new Promise((resolve, reject) => {
    const url = URL.createObjectURL(file)
    const img = new Image()
    img.onload = () => { try { resolve(paletteFromImage(img)) } catch (e) { reject(e) } finally { URL.revokeObjectURL(url) } }
    img.onerror = () => { URL.revokeObjectURL(url); reject(new Error('unreadable image')) }
    img.src = url
  })
}

// How the main colour will really look: darkened just enough for text and white-on-colour buttons.
export const shownPrimary = (primary) => readableBrand(primary) ?? primary
