// Putting things in the order a person chose. `order` lists keys; anything not in it keeps its default place, after the chosen ones.
export function orderBy(list, keyOf, order = []) {
  const place = new Map(order.map((k, i) => [k, i]))
  return list
    .map((x, i) => [x, place.has(keyOf(x)) ? place.get(keyOf(x)) : order.length + i])
    .sort((a, b) => a[1] - b[1])
    .map(([x]) => x)
}

// Moves a key one place earlier (delta -1) or later (+1) in a list of keys; stays as it is at the ends.
export function swap(keys, key, delta) {
  const i = keys.indexOf(key)
  const j = i + delta
  if (i < 0 || j < 0 || j >= keys.length) return keys
  const next = [...keys]
  ;[next[i], next[j]] = [next[j], next[i]]
  return next
}

// The sizes a person can choose for their icons, and the colours on offer (any colour can also be picked by hand).
export const SIZES = ['small', 'medium', 'large']
export const PALETTE = ['#2563eb', '#0891b2', '#16a34a', '#ca8a04', '#ea580c', '#dc2626', '#db2777', '#7c3aed', '#475569']

// Pixel sizes of one icon and of the round box around it, for a home tile (`tile`) or a side-menu entry (`menu`).
export const iconSize = (size, kind = 'tile') => {
  const s = SIZES.includes(size) ? size : 'medium'
  if (kind === 'card') return { small: { box: 36, icon: 18 }, medium: { box: 44, icon: 22 }, large: { box: 60, icon: 32 } }[s]
  return kind === 'menu' ? { small: { box: 28, icon: 15 }, medium: { box: 32, icon: 18 }, large: { box: 40, icon: 23 } }[s] : { small: { box: 40, icon: 20 }, medium: { box: 56, icon: 26 }, large: { box: 76, icon: 38 } }[s]
}

// The paint for an icon with a chosen colour: a soft background and the icon in the colour (on a dark card: a solid background, white icon).
export const iconPaint = (color, dark = false) => {
  if (!color) return {}
  return dark ? { background: color, color: '#ffffff' } : { background: `color-mix(in srgb, ${color} 18%, white)`, color }
}
