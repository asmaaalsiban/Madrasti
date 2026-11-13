import Lenis from 'lenis'
import 'lenis/dist/lenis.css'
import { useEffect } from 'react'

// Inertial ("smooth") scrolling for a long public page. Only the public landing page uses it: the dashboards have tables, dialogs and
// panels that scroll by themselves, and smoothing the wheel there would fight them. Lenis scrolls the real page (native scrollbar and
// find-in-page keep working) and touch screens keep their own native momentum. People who ask for less motion get plain scrolling.
export function useSmoothScroll() {
  useEffect(() => {
    // Plain scrolling for people who ask for less motion, and wherever the browser cannot watch size changes (Lenis needs that).
    if (typeof window === 'undefined' || typeof ResizeObserver === 'undefined' || window.matchMedia?.('(prefers-reduced-motion: reduce)').matches) return undefined
    const lenis = new Lenis({ duration: 1.1, easing: (t) => Math.min(1, 1.001 - 2 ** (-10 * t)), smoothWheel: true })
    let frame = requestAnimationFrame(function raf(time) {
      lenis.raf(time)
      frame = requestAnimationFrame(raf)
    })
    return () => {
      cancelAnimationFrame(frame)
      lenis.destroy()
    }
  }, [])
}
