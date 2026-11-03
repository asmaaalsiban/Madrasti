import { fireEvent, render, screen, waitFor } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { MemoryRouter } from 'react-router-dom'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import i18n from '../src/i18n'
import { api } from '../src/api/client'
import TransfersPage from '../src/features/Finance/pages/TransfersPage'
import BankTransferSettingsCard from '../src/features/Finance/components/BankTransferSettingsCard'

vi.mock('../src/api/client', () => ({ api: { get: vi.fn(), post: vi.fn(), put: vi.fn() } }))
const wrap = (ui) => render(<QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}><MemoryRouter>{ui}</MemoryRouter></QueryClientProvider>)
const row = { id: 5, reference: 'r', status: 'pending', amount: '250.00', payer_name: 'Abu Ali', transfer_reference: 'CLQ-889', fee: 'Tuition', student: { name_ar: 'لينا', name_en: 'Lina' }, review_note: null, receipt: null, attachments: [{ id: 9, name: 'proof.pdf', mime: 'application/pdf', size: 1000, is_image: false }] }

beforeEach(async () => { vi.resetAllMocks(); await i18n.changeLanguage('en') })

describe('bank transfers', () => {
  it('the accountant sees the proof and confirms a transfer', async () => {
    api.get.mockImplementation(async (url) => (url === '/finance/transfers' ? { data: { data: [row], pending: 1 } } : { data: { data: { currency: 'JOD' } } }))
    api.post.mockResolvedValue({ data: {} })
    wrap(<TransfersPage base="/teacher" />)
    expect(await screen.findByText('CLQ-889')).toBeInTheDocument()
    expect(screen.getByText('proof.pdf')).toBeInTheDocument()
    fireEvent.click(screen.getByRole('button', { name: 'Confirm: the money arrived' }))
    await waitFor(() => expect(api.post).toHaveBeenCalledWith('/finance/transfers/5/approve'))
  })

  it('refusing needs a reason the parent will read', async () => {
    api.get.mockImplementation(async (url) => (url === '/finance/transfers' ? { data: { data: [row], pending: 1 } } : { data: { data: { currency: 'JOD' } } }))
    api.post.mockResolvedValue({ data: {} })
    wrap(<TransfersPage base="/teacher" />)
    fireEvent.click(await screen.findByRole('button', { name: 'Refuse' }))
    fireEvent.change(screen.getByLabelText(/Reason/), { target: { value: 'Not received' } })
    fireEvent.click(screen.getAllByRole('button', { name: 'Refuse' }).at(-1))
    await waitFor(() => expect(api.post).toHaveBeenCalledWith('/finance/transfers/5/reject', { note: 'Not received' }))
  })

  it('the school sets the accounts and instructions parents will see', async () => {
    api.get.mockResolvedValue({ data: { data: { enabled: false, instructions: '', accounts: [] } } })
    api.put.mockResolvedValue({ data: {} })
    wrap(<BankTransferSettingsCard />)
    fireEvent.change(await screen.findByLabelText('Bank'), { target: { value: 'Arab Bank' } })
    fireEvent.change(screen.getByLabelText('CliQ'), { target: { value: 'DEMOSCHOOL' } })
    fireEvent.click(screen.getByLabelText('Offer bank transfer to parents'))
    fireEvent.click(screen.getByRole('button', { name: 'Save' }))
    await waitFor(() => expect(api.put).toHaveBeenCalledWith('/finance/bank-transfer-settings', { enabled: true, instructions: '', accounts: [{ bank: 'Arab Bank', holder: '', iban: '', cliq: 'DEMOSCHOOL' }] }))
  })
})
