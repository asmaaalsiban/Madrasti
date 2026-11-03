import { fireEvent, render, screen, waitFor } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { MemoryRouter } from 'react-router-dom'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import i18n from '../src/i18n'
import { api } from '../src/api/client'
import PrivacyPage from '../src/features/Privacy/PrivacyPage'
import PrivacyRequestsPage from '../src/features/Privacy/PrivacyRequestsPage'

vi.mock('../src/api/client', () => ({ api: { get: vi.fn(), post: vi.fn(), put: vi.fn() } }))
const wrap = (ui) => render(<QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}><MemoryRouter>{ui}</MemoryRouter></QueryClientProvider>)

beforeEach(async () => { vi.resetAllMocks(); await i18n.changeLanguage('en'); URL.createObjectURL = vi.fn(() => 'blob:x'); URL.revokeObjectURL = vi.fn() })

describe('my data', () => {
  it('a parent downloads their own data and each childs, and asks for deletion about a child', async () => {
    api.get.mockImplementation(async (url) => (url === '/privacy/requests' ? { data: { data: [{ id: 1, type: 'deletion', status: 'refused', answer: 'Payments must be kept', message: null }], children: [{ id: 7, name_ar: 'لينا', name_en: 'Lina' }] } } : { data: new Blob(['{}']) }))
    api.post.mockResolvedValue({ data: {} })
    wrap(<PrivacyPage />)
    expect(await screen.findByText('Payments must be kept')).toBeInTheDocument()
    expect(screen.getByText('Not possible')).toBeInTheDocument()
    fireEvent.click(screen.getByRole('button', { name: 'My data' }))
    await waitFor(() => expect(api.get).toHaveBeenCalledWith('/privacy/export', { params: {}, responseType: 'blob' }))
    fireEvent.click(screen.getByRole('button', { name: 'Data of Lina' }))
    await waitFor(() => expect(api.get).toHaveBeenCalledWith('/privacy/export', { params: { student_id: 7 }, responseType: 'blob' }))

    fireEvent.change(screen.getByLabelText('About'), { target: { value: '7' } })
    fireEvent.change(screen.getByLabelText('Details (optional)'), { target: { value: 'Moving abroad' } })
    fireEvent.click(screen.getByRole('button', { name: 'Send the request' }))
    await waitFor(() => expect(api.post).toHaveBeenCalledWith('/privacy/requests', { type: 'deletion', student_id: 7, message: 'Moving abroad' }))
    expect(await screen.findByText('Your request was sent to the school.')).toBeInTheDocument()
  })

  it('the principal answers a request: done, or refused with a reason', async () => {
    api.get.mockResolvedValue({ data: { data: [{ id: 3, type: 'deletion', status: 'pending', message: 'Please delete', answer: null, created_at: '2026-11-05T10:00:00Z', user: { id: 2, name: 'Abu Ali', role: 'guardian' }, student: { name_ar: 'لينا', name_en: 'Lina' } }], pending: 1 } })
    api.put.mockResolvedValue({ data: {} })
    wrap(<PrivacyRequestsPage />)
    expect(await screen.findByText('Please delete')).toBeInTheDocument()
    fireEvent.click(screen.getByRole('button', { name: 'Refuse' }))
    fireEvent.change(screen.getByLabelText(/Why it is not possible/), { target: { value: 'Payment records must be kept' } })
    fireEvent.click(screen.getByRole('button', { name: 'Send the answer' }))
    await waitFor(() => expect(api.put).toHaveBeenCalledWith('/privacy/requests/3', { status: 'refused', answer: 'Payment records must be kept' }))
  })
})
