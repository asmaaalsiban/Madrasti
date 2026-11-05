// Marks the page as "lite" on weak devices (little memory, few cores, or the person asked to save data), so the heavy
// decoration (blurred glow, glass blur, film, endless loops) is switched off by CSS: html[data-lite]. The page looks the same, just calmer.
export function isLowEndDevice() {
  if (typeof navigator === 'undefined') return false
  const memory = navigator.deviceMemory // Chrome only: GB, rounded down to a power of two
  const cores = navigator.hardwareConcurrency
  return Boolean(navigator.connection?.saveData) || (typeof memory === 'number' && memory <= 4) || (typeof cores === 'number' && cores <= 4)
}

export function applyLiteMode() {
  if (typeof document !== 'undefined' && isLowEndDevice()) document.documentElement.dataset.lite = 'true'
}
