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
