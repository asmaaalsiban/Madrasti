import { fireEvent, render, screen, waitFor } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { MemoryRouter } from 'react-router-dom'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import i18n from '../src/i18n'
import { api } from '../src/api/client'
import TaxPage from '../src/features/Finance/pages/TaxPage'

vi.mock('../src/api/client', () => ({ api: { get: vi.fn(), put: vi.fn() } }))
const wrap = () => render(<QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}><MemoryRouter><TaxPage /></MemoryRouter></QueryClientProvider>)

beforeEach(async () => {
  vi.resetAllMocks()
  await i18n.changeLanguage('en')
  api.get.mockResolvedValue({ data: { data: { enabled: false, name: 'VAT', rate: 0, number: '', fee_kinds: [] }, kinds: ['yearly', 'transport'], can_manage: true } })
  api.put.mockResolvedValue({ data: {} })
})

describe('TaxPage', () => {
  it('turns the tax on with a rate and the fee kinds that carry it', async () => {
    wrap()
    fireEvent.click(await screen.findByLabelText('This school charges tax'))
    fireEvent.change(screen.getByLabelText('Rate (%)'), { target: { value: '16' } })
    fireEvent.click(screen.getByLabelText('Transport'))
    fireEvent.click(screen.getByRole('button', { name: 'Save' }))
    await waitFor(() => expect(api.put).toHaveBeenCalledWith('/finance/tax-settings', { enabled: true, name: 'VAT', rate: 16, number: '', fee_kinds: ['transport'] }))
    expect(await screen.findByRole('status')).toHaveTextContent('Saved')
  })

  it('is read only for someone who cannot manage', async () => {
    api.get.mockResolvedValue({ data: { data: { enabled: true, name: 'VAT', rate: 16, number: '', fee_kinds: [] }, kinds: ['yearly'], can_manage: false } })
    wrap()
    expect(await screen.findByLabelText('Rate (%)')).toBeDisabled()
    expect(screen.queryByRole('button', { name: 'Save' })).toBeNull()
  })
})
