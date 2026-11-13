import { render } from '@testing-library/react'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

const instances = []
vi.mock('lenis', () => ({
  default: class {
    constructor(options) { this.options = options; this.raf = vi.fn(); this.destroy = vi.fn(); instances.push(this) }
  },
}))
vi.mock('lenis/dist/lenis.css', () => ({}))

const { useSmoothScroll } = await import('../src/hooks/useSmoothScroll')

function Page() { useSmoothScroll(); return <p>page</p> }

const reduced = (value) => { window.matchMedia = vi.fn().mockReturnValue({ matches: value }) }

beforeEach(() => {
  instances.length = 0
  globalThis.ResizeObserver = class { observe() {} disconnect() {} unobserve() {} }
  vi.spyOn(window, 'requestAnimationFrame').mockImplementation(() => 7)
  vi.spyOn(window, 'cancelAnimationFrame').mockImplementation(() => {})
})
afterEach(() => { vi.restoreAllMocks(); delete globalThis.ResizeObserver })

describe('useSmoothScroll', () => {
  it('starts smooth scrolling and stops it when the page goes away', () => {
    reduced(false)
    const { unmount } = render(<Page />)
    expect(instances).toHaveLength(1)
    expect(instances[0].options.smoothWheel).toBe(true)
    expect(instances[0].options.duration).toBeLessThanOrEqual(1.3)
    unmount()
    expect(instances[0].destroy).toHaveBeenCalledOnce()
    expect(window.cancelAnimationFrame).toHaveBeenCalledWith(7)
  })

  it('keeps plain scrolling for people who ask for less motion', () => {
    reduced(true)
    render(<Page />)
    expect(instances).toHaveLength(0)
  })

  it('keeps plain scrolling where the browser cannot watch size changes', () => {
    reduced(false)
    delete globalThis.ResizeObserver
    render(<Page />)
    expect(instances).toHaveLength(0)
  })
})
