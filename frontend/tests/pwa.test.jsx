import { act, fireEvent, render, screen } from '@testing-library/react'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { readFileSync, existsSync } from 'node:fs'
import i18n from '../src/i18n'
import PwaBar from '../src/pwa/PwaBar'
import InstallMenuItem from '../src/pwa/InstallMenuItem'
import { startPwa } from '../src/pwa/pwa'

beforeEach(async () => { await i18n.changeLanguage('en') })

describe('the installable app', () => {
  it('the manifest names the app, is right to left and its icons exist (including a maskable one)', () => {
    const m = JSON.parse(readFileSync('public/manifest.webmanifest', 'utf8'))
    expect(m).toMatchObject({ display: 'standalone', lang: 'ar', dir: 'rtl', scope: '/' })
    expect(m.icons.some((i) => i.purpose === 'maskable')).toBe(true)
    expect(m.icons.some((i) => i.sizes === '512x512' && i.purpose === 'any')).toBe(true)
    for (const i of m.icons) expect(existsSync(`public${i.src}`), i.src).toBe(true)
    expect(existsSync('public/offline.html')).toBe(true)
    expect(existsSync('public/apple-touch-icon.png')).toBe(true)
  })

  it('the service worker never keeps anything from /api (no personal data on the device)', () => {
    const sw = readFileSync('public/sw.js', 'utf8')
    expect(sw).toMatch(/pathname\.startsWith\('\/api\/'\)\) return/)
    expect(sw).not.toMatch(/caches\.[a-z]+\([^)]*api/)
  })

  it('says when there is no connection, and goes away when it is back', () => {
    render(<PwaBar />)
    expect(screen.queryByRole('status')).not.toBeInTheDocument()
    act(() => { window.dispatchEvent(new Event('offline')) })
    expect(screen.getByRole('status')).toHaveTextContent('No connection')
    act(() => { window.dispatchEvent(new Event('online')) })
    expect(screen.queryByRole('status')).not.toBeInTheDocument()
  })

  it('offers a new version and reloads through the waiting worker', () => {
    const post = vi.fn()
    render(<PwaBar />)
    act(() => { window.dispatchEvent(new CustomEvent('pwa:update', { detail: { waiting: { postMessage: post } } })) })
    expect(screen.getByRole('status')).toHaveTextContent('A new version is ready')
    fireEvent.click(screen.getByRole('button', { name: 'Update' }))
    expect(post).toHaveBeenCalledWith('SKIP_WAITING')
  })

  describe('taking over of the service worker', () => {
    const run = (controller) => {
      const listeners = {}
      const reload = vi.fn()
      const original = window.location
      Object.defineProperty(window, 'location', { value: { ...original, reload }, configurable: true })
      Object.defineProperty(navigator, 'serviceWorker', { value: { controller, register: () => Promise.resolve({ addEventListener() {} }), addEventListener: (type, cb) => { listeners[type] = cb } }, configurable: true })
      startPwa()
      listeners.controllerchange?.()
      Object.defineProperty(window, 'location', { value: original, configurable: true })
      return reload
    }

    it('does not reload the page on the very first visit (nothing old to replace)', () => {
      expect(run(null)).not.toHaveBeenCalled()
    })

    it('reloads once when a new version takes over from an old one', () => {
      expect(run({})).toHaveBeenCalledTimes(1)
    })
  })

  it('shows "Install the app" when the browser allows it, and installs with one tap', async () => {
    startPwa()
    const prompt = vi.fn()
    const ev = Object.assign(new Event('beforeinstallprompt', { cancelable: true }), { prompt, userChoice: Promise.resolve({ outcome: 'accepted' }) })
    render(<InstallMenuItem className="x" />)
    expect(screen.queryByRole('menuitem')).not.toBeInTheDocument()
    await act(async () => { window.dispatchEvent(ev) })
    fireEvent.click(screen.getByRole('menuitem', { name: 'Install the app' }))
    expect(prompt).toHaveBeenCalled()
  })

  it('on iPhone it explains Share then Add to Home Screen', async () => {
    const orig = navigator.userAgent
    Object.defineProperty(navigator, 'userAgent', { value: 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) Safari/604.1', configurable: true })
    render(<InstallMenuItem className="x" />)
    fireEvent.click(screen.getByRole('menuitem', { name: 'Install the app' }))
    expect(screen.getByRole('dialog', { name: 'Install on iPhone' })).toHaveTextContent('Add to Home Screen')
    Object.defineProperty(navigator, 'userAgent', { value: orig, configurable: true })
  })
})
