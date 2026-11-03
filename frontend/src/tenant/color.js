// Contrast helpers (WCAG): the school's accent colour is used for buttons with white text and for links on white and cream paper, so it must
// reach a contrast ratio of 4.5 against both. A lighter colour is darkened (same hue) until it does.
const toRgb = (hex) => { const h = hex.replace('#', ''); return [0, 2, 4].map((i) => parseInt(h.slice(i, i + 2), 16)) }
const toHex = (rgb) => `#${rgb.map((v) => Math.round(Math.min(255, Math.max(0, v))).toString(16).padStart(2, '0')).join('')}`
const luminance = (hex) => {
  const [r, g, b] = toRgb(hex).map((v) => { const c = v / 255; return c <= 0.03928 ? c / 12.92 : ((c + 0.055) / 1.055) ** 2.4 })
  return 0.2126 * r + 0.7152 * g + 0.0722 * b
}
export const contrast = (a, b) => { const [hi, lo] = [luminance(a), luminance(b)].sort((x, y) => y - x); return (hi + 0.05) / (lo + 0.05) }

const PAPER = ['#ffffff', '#f9f7f0']
const ok = (hex) => PAPER.every((p) => contrast(hex, p) >= 4.5)

export function readableBrand(hex) {
  if (!/^#[0-9a-fA-F]{6}$/.test(hex ?? '')) return null
  let rgb = toRgb(hex)
  for (let i = 0; i < 60 && !ok(toHex(rgb)); i++) rgb = rgb.map((v) => v * 0.95)
  return toHex(rgb)
}
