import { useRef } from 'react'
import gsap from 'gsap'
import { ScrollTrigger } from 'gsap/ScrollTrigger'
import { useGSAP } from '@gsap/react'

// ScrollTrigger needs matchMedia at registration; where it is missing (jsdom in tests) there is no scroll motion anyway.
const CAN_MOTION = typeof window !== 'undefined' && typeof window.matchMedia === 'function'
gsap.registerPlugin(useGSAP)
if (CAN_MOTION) gsap.registerPlugin(ScrollTrigger)

// Shared GSAP motion: calm, smooth, and still for people who ask for less motion.
// Every helper scopes its selectors to `scope` and cleans up on unmount (useGSAP).
const EASE = 'power3.out'
const QUIET = '(prefers-reduced-motion: reduce)'

/** Runs `build(gsap)` only where motion is allowed (not for reduced motion, nor where matchMedia is missing, e.g. tests). Returns a cleanup. */
export function calmly(build) {
  if (!CAN_MOTION) return undefined
  const mm = gsap.matchMedia()
  mm.add(`not all and ${QUIET}`, () => build(gsap))
  return () => mm.revert()
}

function useCalm(scope, build, dependencies = []) {
  useGSAP(() => calmly(build), { scope, dependencies, revertOnUpdate: true })
}

/** Children matching `selector` rise and fade in one after another. */
export function useStaggerIn(selector, { y = 18, stagger = 0.08, delay = 0, dependencies = [] } = {}) {
  const scope = useRef(null)
  useCalm(scope, (g) => {
    const items = g.utils.toArray(selector, scope.current)
    if (!items.length) return
    g.from(items, { autoAlpha: 0, y, duration: 0.7, ease: EASE, stagger, delay, clearProps: 'transform,opacity,visibility' })
  }, dependencies)
  return scope
}

export { gsap, ScrollTrigger, EASE }
