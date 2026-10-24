import { fireEvent, render, screen, waitFor } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import i18n from '../src/i18n'
import { api } from '../src/api/client'
import PushToggle from '../src/features/Notifications/PushToggle'

vi.mock('../src/api/client', () => ({ api: { get: vi.fn(), post: vi.fn() } }))

const renderToggle = () => render(<QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}><PushToggle /></QueryClientProvider>)

const subscription = { endpoint: 'https://push.example/abc', toJSON: () => ({ endpoint: 'https://push.example/abc', keys: { p256dh: 'P', auth: 'A' } }), unsubscribe: vi.fn(() => Promise.resolve(true)) }
let existing = null
const manager = { getSubscription: vi.fn(() => Promise.resolve(existing)), subscribe: vi.fn(() => { existing = subscription; return Promise.resolve(subscription) }) }

const supportPush = (permission) => {
  Object.defineProperty(navigator, 'serviceWorker', { configurable: true, value: { ready: Promise.resolve({ pushManager: manager }) } })
  window.PushManager = function PushManager() {}
  window.Notification = { permission, requestPermission: vi.fn(() => Promise.resolve(permission === 'denied' ? 'denied' : 'granted')) }
}

beforeEach(async () => {
  vi.resetAllMocks()
  existing = null
  manager.getSubscription.mockImplementation(() => Promise.resolve(existing))
  manager.subscribe.mockImplementation(() => { existing = subscription; return Promise.resolve(subscription) })
  subscription.unsubscribe.mockImplementation(() => Promise.resolve(true))
  await i18n.changeLanguage('en')
})

describe('PushToggle', () => {
  it('shows nothing when the school has no push keys', async () => {
    supportPush('default')
    api.get.mockResolvedValue({ data: { data: { enabled: false, public_key: null } } })
    const { container } = renderToggle()
    await waitFor(() => expect(api.get).toHaveBeenCalled())
    expect(container).toBeEmptyDOMElement()
  })

  it('turns notifications on for this device and tells the server', async () => {
    supportPush('default')
    api.get.mockResolvedValue({ data: { data: { enabled: true, public_key: 'BPUBLICKEY' } } })
    api.post.mockResolvedValue({ data: { data: { subscribed: true } } })
    renderToggle()
    fireEvent.click(await screen.findByRole('button', { name: 'Get notifications on this phone' }))
    await waitFor(() => expect(api.post).toHaveBeenCalledWith('/push/subscribe', { endpoint: 'https://push.example/abc', keys: { p256dh: 'P', auth: 'A' } }))
    expect(manager.subscribe).toHaveBeenCalledWith(expect.objectContaining({ userVisibleOnly: true }))
    expect(await screen.findByText('Notifications are on for this device.')).toBeInTheDocument()
  })

  it('turns them off again', async () => {
    supportPush('granted')
    existing = subscription
    api.get.mockResolvedValue({ data: { data: { enabled: true, public_key: 'BPUBLICKEY' } } })
    api.post.mockResolvedValue({})
    renderToggle()
    fireEvent.click(await screen.findByRole('button', { name: 'Stop notifications on this phone' }))
    await waitFor(() => expect(api.post).toHaveBeenCalledWith('/push/unsubscribe', { endpoint: 'https://push.example/abc' }))
    expect(subscription.unsubscribe).toHaveBeenCalled()
    expect(await screen.findByRole('button', { name: 'Get notifications on this phone' })).toBeInTheDocument()
  })

  it('explains when the browser blocks notifications', async () => {
    supportPush('denied')
    api.get.mockResolvedValue({ data: { data: { enabled: true, public_key: 'BPUBLICKEY' } } })
    renderToggle()
    expect(await screen.findByText(/blocked in your browser settings/)).toBeInTheDocument()
    expect(screen.queryByRole('button')).not.toBeInTheDocument()
  })
})
