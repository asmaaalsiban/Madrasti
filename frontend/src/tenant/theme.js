import { readableBrand } from './color'

// The school's look, from its logo: the main colour and a dark one. Everything else (the pale tints, the glow of the landing page, the
// pop shadow) is worked out from these two in CSS, so it always matches. Without colours the default look stays untouched.
const VARS = ['--color-brand', '--color-deep-harbor', '--color-deep-wave', '--color-sky-tint', '--color-sky-wash', '--lp-navy', '--lp-glow']

export function applyTheme({ primary, dark } = {}) {
  const root = document.documentElement.style
  const brand = readableBrand(primary)
  if (!brand) { clearTheme(); return }
  root.setProperty('--color-brand', brand)
  if (/^#[0-9a-fA-F]{6}$/.test(dark ?? '')) {
    root.setProperty('--color-deep-harbor', dark)
    root.setProperty('--color-deep-wave', 'color-mix(in srgb, var(--color-deep-harbor) 65%, var(--color-brand))')
    root.setProperty('--lp-navy', 'color-mix(in srgb, var(--color-deep-harbor) 55%, black)')
  }
  root.setProperty('--color-sky-tint', 'color-mix(in srgb, var(--color-brand) 28%, white)')
  root.setProperty('--color-sky-wash', 'color-mix(in srgb, var(--color-brand) 15%, white)')
  root.setProperty('--lp-glow', 'color-mix(in srgb, var(--color-brand) 60%, white)')
}

export function clearTheme() {
  VARS.forEach((v) => document.documentElement.style.removeProperty(v))
}
