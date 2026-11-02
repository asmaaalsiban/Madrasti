import { fireEvent, render, screen, waitFor } from '@testing-library/react'
import { MemoryRouter } from 'react-router-dom'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import i18n from '../src/i18n'
import { api } from '../src/api/client'
import ExportButton from '../src/components/shared/ExportButton'
import AccountsPage from '../src/features/Finance/pages/AccountsPage'

vi.mock('../src/api/client', () => ({ api: { get: vi.fn(), post: vi.fn(), put: vi.fn(), delete: vi.fn() } }))
vi.mock('../src/features/Auth/AuthProvider', () => ({ useAuth: () => ({ user: { name: 'Acc' } }) }))

beforeEach(async () => {
  vi.resetAllMocks()
  await i18n.changeLanguage('en')
  URL.createObjectURL = vi.fn(() => 'blob:x')
  URL.revokeObjectURL = vi.fn()
})

describe('ExportButton', () => {
  it('downloads the file with the filters and the name the server gave', async () => {
    api.get.mockResolvedValue({ data: new Blob(['x']), headers: { 'content-disposition': 'attachment; filename=Student_accounts_2026-11-02.xlsx' } })
    const click = vi.spyOn(HTMLAnchorElement.prototype, 'click').mockImplementation(() => {})
    render(<ExportButton path="/finance/accounts/export" params={{ status: 'owing' }} fallbackName="accounts.xlsx" />)
    fireEvent.click(screen.getByRole('button', { name: 'Export to Excel' }))
    await waitFor(() => expect(click).toHaveBeenCalled())
    expect(api.get).toHaveBeenCalledWith('/finance/accounts/export', { params: { status: 'owing' }, responseType: 'blob' })
    expect(URL.createObjectURL).toHaveBeenCalled()
    click.mockRestore()
  })

  it('says so when the download fails', async () => {
    api.get.mockRejectedValue(new Error('403'))
    render(<ExportButton path="/x" />)
    fireEvent.click(screen.getByRole('button', { name: 'Export to Excel' }))
    expect(await screen.findByRole('alert')).toHaveTextContent('The file could not be downloaded.')
  })

  it('is on the accounts page and exports the chosen filter without the page number', async () => {
    api.get.mockImplementation((url) => Promise.resolve({ data: url === '/finance/accounts/export' ? new Blob(['x']) : { data: [], meta: { current_page: 1, last_page: 1 }, currency: 'JOD' }, headers: {} }))
    vi.spyOn(HTMLAnchorElement.prototype, 'click').mockImplementation(() => {})
    render(<QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}><MemoryRouter><AccountsPage base="/teacher" /></MemoryRouter></QueryClientProvider>)
    fireEvent.click(await screen.findByRole('button', { name: 'Export to Excel' }))
    await waitFor(() => expect(api.get).toHaveBeenCalledWith('/finance/accounts/export', { params: {}, responseType: 'blob' }))
  })
})
