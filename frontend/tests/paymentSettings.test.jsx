import { fireEvent, render, screen, waitFor } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import i18n from '../src/i18n'
import { api } from '../src/api/client'
import PaymentSettingsCard from '../src/features/Finance/components/PaymentSettingsCard'

vi.mock('../src/api/client', () => ({ api: { get: vi.fn(), put: vi.fn(), post: vi.fn() } }))
const settings = { profile_id: '777', base_url: 'https://secure.paytabs.sa', enabled: true, server_key_set: true, server_key_hint: '••••9876', regions: { jordan: 'https://secure-jordan.paytabs.com', saudi: 'https://secure.paytabs.sa' }, using_platform_account: false, callback_url: 'https://x.test/api/central/payments/webhook/demo/paytabs' }
const show = () => render(<QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}><PaymentSettingsCard /></QueryClientProvider>)

beforeEach(async () => { vi.resetAllMocks(); await i18n.changeLanguage('en') })

describe('PaymentSettingsCard', () => {
  it('shows the saved account without ever showing the key, and saves changes', async () => {
    api.get.mockResolvedValue({ data: { data: settings } })
    api.put.mockResolvedValue({ data: {} })
    show()
    expect(await screen.findByLabelText('Profile ID')).toHaveValue('777')
    expect(screen.getByLabelText('Server key')).toHaveValue('')
    expect(screen.getByLabelText('Server key')).toHaveAttribute('placeholder', expect.stringContaining('••••9876'))
    expect(screen.getByText(/api\/central\/payments\/webhook\/demo\/paytabs/)).toBeInTheDocument()
    fireEvent.change(screen.getByLabelText('Profile ID'), { target: { value: '888' } })
    fireEvent.change(screen.getByLabelText('Server key'), { target: { value: 'NEW-KEY' } })
    fireEvent.click(screen.getByRole('button', { name: 'Save' }))
    await waitFor(() => expect(api.put).toHaveBeenCalledWith('/finance/payment-settings', { profile_id: '888', server_key: 'NEW-KEY', base_url: 'https://secure.paytabs.sa', enabled: true }))
  })

  it('tests the connection and says what PayTabs answered', async () => {
    api.get.mockResolvedValue({ data: { data: settings } })
    api.post.mockResolvedValue({ data: { ok: false, message: 'Invalid profile' } })
    show()
    fireEvent.click(await screen.findByRole('button', { name: 'Test the connection' }))
    expect(await screen.findByText('Invalid profile')).toBeInTheDocument()
    expect(api.post).toHaveBeenCalledWith('/finance/payment-settings/test')
  })

  it('shows nothing to people who may not set it', async () => {
    api.get.mockRejectedValue({ response: { status: 403 } })
    const { container } = show()
    await waitFor(() => expect(api.get).toHaveBeenCalled())
    expect(container).toBeEmptyDOMElement()
  })
})
