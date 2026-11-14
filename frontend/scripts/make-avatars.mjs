// Draws the 24 ready-made characters (public/avatars/a01.svg ... a24.svg). Run: node scripts/make-avatars.mjs
// Eight looks (short hair, long hair, curls, bun, ponytail, cap, beard, headscarf) in three colour variations each. Flat shapes only.
import { mkdirSync, writeFileSync } from 'node:fs'
import { dirname, join } from 'node:path'
import { fileURLToPath } from 'node:url'

const out = join(dirname(fileURLToPath(import.meta.url)), '..', 'public', 'avatars')
mkdirSync(out, { recursive: true })

const SKIN = ['#f6d3b3', '#e8b98f', '#c68e63', '#9b6a47', '#6f4b32']
const HAIR = ['#2b1d12', '#5a3a22', '#b57a3b', '#1f1f24', '#8a8f98', '#7a2e1d']
const BG = ['#dbeafe', '#fde7d4', '#d9f2e6', '#ede0fb', '#fde0e6', '#fff1c2', '#d6f1f5', '#e4e7ec']
const SHIRT = ['#2563eb', '#0f766e', '#e11d48', '#7c3aed', '#d97706', '#0369a1', '#15803d', '#be185d']
const LOOKS = ['short', 'long', 'curly', 'bun', 'ponytail', 'cap', 'beard', 'scarf']

const shade = (hex, k) => '#' + [1, 3, 5].map((i) => Math.max(0, Math.min(255, Math.round(parseInt(hex.slice(i, i + 2), 16) * k))).toString(16).padStart(2, '0')).join('')

function draw(look, v) {
  const skin = SKIN[(v.skin) % SKIN.length]
  const hair = HAIR[(v.hair) % HAIR.length]
  const bg = BG[v.bg % BG.length]
  const shirt = SHIRT[v.shirt % SHIRT.length]
  const dark = shade(skin, 0.9)
  const back = []
  const front = []
  const face = [`<ellipse cx="60" cy="53" rx="24" ry="27" fill="${skin}"/>`, `<circle cx="36" cy="55" r="4.5" fill="${dark}"/>`, `<circle cx="84" cy="55" r="4.5" fill="${dark}"/>`]
  const fringe = `<path d="M36 49 C33 26 49 19 60 19 C72 19 87 26 84 49 C79 38 70 34 60 35 C50 34 41 38 36 49 Z" fill="${hair}"/>`
  if (look === 'short') front.push(fringe)
  if (look === 'long') { back.push(`<path d="M31 54 C26 22 46 14 60 14 C74 14 94 22 89 54 L92 96 L28 96 Z" fill="${hair}"/>`); front.push(fringe) }
  if (look === 'curly') front.push([[40, 30], [50, 24], [60, 22], [70, 24], [80, 30], [35, 42], [85, 42]].map(([x, y]) => `<circle cx="${x}" cy="${y}" r="10" fill="${hair}"/>`).join(''))
  if (look === 'bun') { back.push(`<circle cx="60" cy="15" r="10" fill="${hair}"/>`); front.push(fringe) }
  if (look === 'ponytail') { back.push(`<ellipse cx="91" cy="58" rx="7" ry="18" fill="${hair}" transform="rotate(-12 91 58)"/>`); front.push(fringe) }
  if (look === 'cap') front.push(fringe, `<path d="M33 41 C35 18 85 18 87 41 Z" fill="${shade(shirt, 1.1)}"/><path d="M28 41 H98 a4 4 0 0 1 4 4 H24 a4 4 0 0 1 4 -4 Z" fill="${shade(shirt, 0.8)}"/>`)
  if (look === 'beard') { front.push(fringe, `<path d="M37 56 C37 84 83 84 83 56 C79 71 71 75 60 75 C49 75 41 71 37 56 Z" fill="${hair}"/>`) }
  let body = `<path d="M14 120 C14 92 34 83 60 83 C86 83 106 92 106 120 Z" fill="${shirt}"/><rect x="50" y="70" width="20" height="18" rx="7" fill="${dark}"/>`
  if (look === 'scarf') {
    const scarf = HAIR[(v.hair + 2) % HAIR.length] === hair ? '#1f2937' : shirt
    const cloth = v.scarf
    back.push(`<path d="M27 60 C25 24 44 12 60 12 C76 12 95 24 93 60 C93 88 80 102 60 102 C40 102 27 88 27 60 Z" fill="${cloth}"/>`)
    face.splice(1, 2) // the ears are covered
    front.push(`<path d="M24 120 C24 98 38 90 60 96 C82 90 96 98 96 120 Z" fill="${cloth}"/>`)
    body = `<path d="M14 120 C14 96 34 86 60 86 C86 86 106 96 106 120 Z" fill="${shirt}"/>`
    face[0] = `<ellipse cx="60" cy="56" rx="20" ry="23" fill="${skin}"/>`
  }
  const eyeY = look === 'scarf' ? 55 : 53
  const eyes = `<circle cx="50" cy="${eyeY}" r="2.7" fill="#1f2937"/><circle cx="70" cy="${eyeY}" r="2.7" fill="#1f2937"/>`
  const mouth = `<path d="M52 ${eyeY + 12} Q60 ${eyeY + 18} 68 ${eyeY + 12}" fill="none" stroke="#7c2d12" stroke-width="2.4" stroke-linecap="round"/>`
  const blush = `<circle cx="45" cy="${eyeY + 8}" r="4" fill="#f9a8a8" opacity=".35"/><circle cx="75" cy="${eyeY + 8}" r="4" fill="#f9a8a8" opacity=".35"/>`
  const glasses = v.glasses ? `<g fill="none" stroke="#111827" stroke-width="2"><circle cx="50" cy="${eyeY}" r="7"/><circle cx="70" cy="${eyeY}" r="7"/><path d="M57 ${eyeY} H63"/></g>` : ''
  return `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 120 120" width="120" height="120" role="img"><defs><clipPath id="c"><circle cx="60" cy="60" r="60"/></clipPath></defs><g clip-path="url(#c)"><rect width="120" height="120" fill="${bg}"/>${back.join('')}${body}${face.join('')}${blush}${eyes}${mouth}${glasses}${front.join('')}</g></svg>\n`
}

const SCARF = ['#7c3aed', '#0f766e', '#be185d']
for (let i = 0; i < 24; i += 1) {
  const look = LOOKS[i % 8]
  const round = Math.floor(i / 8) // 0..2: three variations of every look
  const v = { skin: (i * 2 + round) % 5, hair: (i * 3 + round) % 6, bg: (i + round * 3) % 8, shirt: (i * 5 + round) % 8, glasses: i % 5 === 2, scarf: SCARF[round] }
  writeFileSync(join(out, `a${String(i + 1).padStart(2, '0')}.svg`), draw(look, v))
}
console.log('24 characters written to', out)
