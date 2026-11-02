import { fireEvent, render, screen, waitFor } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import i18n from '../src/i18n'
import { api } from '../src/api/client'
import ChildFeesPage from '../src/features/Finance/pages/ChildFeesPage'

vi.mock('../src/api/client', () => ({ api: { get: vi.fn(), post: vi.fn(), put: vi.fn(), delete: vi.fn() } }))
vi.mock('../src/features/Auth/AuthProvider', () => ({ useAuth: () => ({ user: { name: 'Abu Layla', children: [{ id: 7, name_en: 'Lina', name_ar: 'لينا' }] } }) }))

const wrap = (path = '/c/7') => render(
  <QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}><MemoryRouter initialEntries={[path]}><Routes><Route path="/c/:id" element={<ChildFeesPage />} /></Routes></MemoryRouter></QueryClientProvider>,
)

const statement = (online) => ({
  currency: 'JOD', online,
  totals: { net: '1000.00', paid: '250.00', remaining: '750.00', overdue: '250.00' },
  fees: [{
    id: 3, kind: 'yearly', description: 'Tuition', total: '1000.00', discount: '0.00', net: '1000.00', paid: '250.00', remaining: '750.00', overdue: '250.00', items: [],
    installments: [
      { id: 11, number: 1, due_date: '2026-09-01', amount: '500.00', paid: '250.00', remaining: '250.00', status: 'overdue' },
      { id: 12, number: 2, due_date: '2026-12-01', amount: '500.00', paid: '0.00', remaining: '500.00', status: 'pending' },
    ],
    payments: [],
  }],
})

beforeEach(async () => {
  vi.resetAllMocks()
  await i18n.changeLanguage('en')
})

describe('paying online', () => {
  it('shows no pay button when the school has no payment provider', async () => {
    api.get.mockResolvedValue({ data: { data: statement({ enabled: false, mode: null }) } })
    wrap()
    await screen.findByText('Tuition')
    expect(screen.queryByRole('button', { name: 'Pay online' })).not.toBeInTheDocument()
  })

  it('pays an installment with a card in the demo and shows the receipt', async () => {
    api.get.mockResolvedValue({ data: { data: statement({ enabled: true, mode: 'demo' }) } })
    api.post.mockImplementation((url) => Promise.resolve({ data: { data: url.endsWith('online-payments')
      ? { reference: 'ref-1', mode: 'demo_card', url: null }
      : { status: 'paid', receipt: 'R-000030', reference: 'ref-1' } } }))
    wrap()
    fireEvent.click(await screen.findByRole('button', { name: 'Pay online' }))

    fireEvent.change(screen.getByLabelText('Apply to'), { target: { value: '12' } })
    expect(screen.getByLabelText('Amount')).toHaveValue(500)
    expect(screen.getByLabelText(/^Paid by/)).toHaveValue('Abu Layla')
    fireEvent.change(screen.getByLabelText(/^Paid by/), { target: { value: 'Uncle Sami' } })
    fireEvent.click(screen.getByRole('button', { name: 'Continue to payment' }))
    await waitFor(() => expect(api.post).toHaveBeenCalledWith('/students/7/online-payments', expect.objectContaining({ student_fee_id: 3, installment_id: 12, amount: '500.00', payer_name: 'Uncle Sami' })))

    expect(await screen.findByText(/Demo mode/)).toBeInTheDocument()
    fireEvent.change(screen.getByLabelText('Card number'), { target: { value: '4242 4242 4242 4242' } })
    fireEvent.change(screen.getByLabelText('Name on card'), { target: { value: 'Abu Layla' } })
    fireEvent.change(screen.getByLabelText('Expiry (MM/YY)'), { target: { value: '12/40' } })
    fireEvent.change(screen.getByLabelText('Security code'), { target: { value: '123' } })
    fireEvent.click(screen.getByRole('button', { name: 'Pay 500.00 JOD' }))
    await waitFor(() => expect(api.post).toHaveBeenCalledWith('/online-payments/ref-1/demo-confirm', { card_number: '4242424242424242', holder: 'Abu Layla', expiry: '12/40', cvc: '123' }))
    expect(await screen.findByText('Payment received. Receipt number: R-000030.')).toBeInTheDocument()
  })

  it('says so when the card is declined and lets the parent try again', async () => {
    api.get.mockResolvedValue({ data: { data: statement({ enabled: true, mode: 'demo' }) } })
    api.post.mockImplementation((url) => Promise.resolve({ data: { data: url.endsWith('online-payments')
      ? { reference: 'ref-2', mode: 'demo_card', url: null }
      : { status: 'failed', failure_reason: 'declined', receipt: null } } }))
    wrap()
    fireEvent.click(await screen.findByRole('button', { name: 'Pay online' }))
    fireEvent.click(screen.getByRole('button', { name: 'Continue to payment' }))
    await screen.findByLabelText('Card number')
    for (const [label, value] of [['Card number', '4000 0000 0000 0002'], ['Name on card', 'A'], ['Expiry (MM/YY)', '12/40'], ['Security code', '123']]) fireEvent.change(screen.getByLabelText(label), { target: { value } })
    fireEvent.click(screen.getByRole('button', { name: 'Pay 750.00 JOD' }))
    expect(await screen.findByText('The card was declined.')).toBeInTheDocument()
    fireEvent.click(screen.getByRole('button', { name: 'Try again' }))
    expect(screen.getByRole('button', { name: 'Continue to payment' })).toBeInTheDocument()
  })

  it('goes to the provider page when a real provider is configured', async () => {
    api.get.mockResolvedValue({ data: { data: statement({ enabled: true, mode: 'tap' }) } })
    api.post.mockResolvedValue({ data: { data: { reference: 'ref-3', mode: 'redirect', url: 'https://pay.example/checkout' } } })
    const assign = vi.fn()
    vi.stubGlobal('location', { ...window.location, assign, origin: 'http://localhost', pathname: '/c/7' })
    wrap()
    fireEvent.click(await screen.findByRole('button', { name: 'Pay online' }))
    fireEvent.click(screen.getByRole('button', { name: 'Continue to payment' }))
    await waitFor(() => expect(assign).toHaveBeenCalledWith('https://pay.example/checkout'))
    expect(api.post).toHaveBeenCalledWith('/students/7/online-payments', expect.objectContaining({ return_url: 'http://localhost/c/7' }))
    vi.unstubAllGlobals()
  })

  it('tells a returning parent how the payment ended', async () => {
    api.get.mockImplementation((url) => Promise.resolve({ data: { data: url === '/online-payments/ref-9' ? { status: 'paid', receipt: 'R-000044' } : statement({ enabled: true, mode: 'tap' }) } }))
    wrap('/c/7?online=ref-9')
    expect(await screen.findByText('Payment received. Receipt number: R-000044.')).toBeInTheDocument()
  })
})
