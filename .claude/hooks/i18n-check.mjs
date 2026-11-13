// PostToolUse check (see .claude/settings.json): every key of frontend/src/i18n/ar.json must exist in en.json and the other way round.
// Arabic has extra plural forms (_zero, _two, _few, _many) that English does not: those are allowed to be missing in en.json.
import { readFileSync } from 'node:fs'
import { dirname, join } from 'node:path'
import { fileURLToPath } from 'node:url'

const PLURAL = /_(zero|two|few|many)$/

export function flatten(obj, prefix = '') {
  return Object.entries(obj).flatMap(([k, v]) => (v && typeof v === 'object' && !Array.isArray(v) ? flatten(v, `${prefix}${k}.`) : [`${prefix}${k}`]))
}

export function compare(ar, en) {
  const a = new Set(flatten(ar)); const e = new Set(flatten(en))
  return {
    missingInEn: [...a].filter((k) => !e.has(k) && !PLURAL.test(k)),
    missingInAr: [...e].filter((k) => !a.has(k)),
  }
}

const here = dirname(fileURLToPath(import.meta.url))
if (process.argv[1]?.endsWith('i18n-check.mjs')) {
  let raw = ''
  for await (const chunk of process.stdin) raw += chunk
  const path = String(JSON.parse(raw || '{}').tool_input?.file_path ?? '').replaceAll('\\', '/')
  if (/frontend\/src\/i18n\/(ar|en)\.json$/.test(path)) {
    const dir = join(here, '..', '..', 'frontend', 'src', 'i18n')
    const { missingInEn, missingInAr } = compare(JSON.parse(readFileSync(join(dir, 'ar.json'), 'utf8')), JSON.parse(readFileSync(join(dir, 'en.json'), 'utf8')))
    if (missingInEn.length || missingInAr.length) {
      const list = (keys) => keys.slice(0, 15).join(', ') + (keys.length > 15 ? ` ... (+${keys.length - 15})` : '')
      console.error(['The two language files do not have the same keys (project rule: add each key to both).',
        missingInEn.length ? `Missing in en.json: ${list(missingInEn)}` : '', missingInAr.length ? `Missing in ar.json: ${list(missingInAr)}` : ''].filter(Boolean).join('\n'))
      process.exit(2)
    }
  }
}
