import { fireEvent, render, screen, waitFor } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { MemoryRouter } from 'react-router-dom'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import i18n from '../src/i18n'
import { api } from '../src/api/client'
import AuditLogPage from '../src/features/Audit/AuditLogPage'

vi.mock('../src/api/client', () => ({ api: { get: vi.fn() } }))
const wrap = () => render(<QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}><MemoryRouter><AuditLogPage /></MemoryRouter></QueryClientProvider>)

const log = (id, action, extra = {}) => ({ id, at: '2026-11-10T09:30:00+00:00', action, type: 'StudentFee', record_id: 7, user: { id: 1, name: 'Sami', role: 'teacher' }, changes: [], ...extra })

beforeEach(async () => {
  vi.resetAllMocks()
  await i18n.changeLanguage('en')
  api.get.mockImplementation((url) => Promise.resolve({ data: url === '/audit-logs/filters' ? { types: ['StudentFee', 'Weird'], users: [{ id: 1, name: 'Sami' }] } : { data: [
    log(1, 'updated', { changes: [{ field: 'discount', old: '0.00', new: '50.00' }] }),
    log(2, 'created', { type: 'Weird', changes: [{ field: 'name_en', old: null, new: 'Ali' }] }),
    log(3, 'deleted', { changes: [{ field: 'total_amount', old: '900.00', new: null }] }),
  ], meta: { current_page: 1, last_page: 2, total: 40 } } }))
})

describe('AuditLogPage', () => {
  it('shows who changed what with the old and new values', async () => {
    wrap()
    expect(await screen.findByText('Discount')).toBeInTheDocument()
    expect(screen.getByText('0.00')).toBeInTheDocument()
    expect(screen.getByText('50.00')).toBeInTheDocument()
    expect(screen.getAllByText('Sami').length).toBeGreaterThan(0)
    expect(screen.getAllByText('Updated').length).toBeGreaterThan(1)
    expect(screen.getAllByText('Weird').length).toBeGreaterThan(1) // an unknown kind falls back to its own name
    expect(screen.getByText('40 entries')).toBeInTheDocument()
  })

  it('filters and pages', async () => {
    wrap()
    await screen.findByText('Discount')
    fireEvent.change(screen.getByLabelText('Action'), { target: { value: 'deleted' } })
    await waitFor(() => expect(api.get).toHaveBeenCalledWith('/audit-logs', { params: { action: 'deleted', page: 1 } }))
    fireEvent.click(await screen.findByRole('button', { name: 'Next' }))
    await waitFor(() => expect(api.get).toHaveBeenCalledWith('/audit-logs', { params: { action: 'deleted', page: 2 } }))
  })
})
