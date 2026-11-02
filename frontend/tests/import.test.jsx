import { fireEvent, render, screen, waitFor } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import i18n from '../src/i18n'
import { api } from '../src/api/client'
import ImportPage from '../src/features/Import/pages/ImportPage'

vi.mock('../src/api/client', () => ({ api: { get: vi.fn(), post: vi.fn(), put: vi.fn(), delete: vi.fn() } }))

const renderPage = () => render(<QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}><ImportPage /></QueryClientProvider>)
const pick = () => fireEvent.change(screen.getByLabelText('File'), { target: { files: [new File(['x'], 'students.xlsx')] } })

beforeEach(async () => {
  vi.resetAllMocks()
  await i18n.changeLanguage('en')
})

describe('ImportPage', () => {
  it('checks the file first, shows the problem rows, then imports the valid ones and lists the new accounts', async () => {
    api.post
      .mockResolvedValueOnce({ data: { data: { total: 3, valid: 2, created: null, problem_count: 1, problems: [{ line: 4, errors: ['There is no section named "9-Z" this year.'] }] } } })
      .mockResolvedValueOnce({ data: { data: { total: 3, valid: 2, created: [{ student_number: '2026001', name: 'ليلى', email: 'layla@school.test' }, { student_number: '2026002', name: 'سامي', email: 'sami@school.test' }], problem_count: 1, problems: [{ line: 4, errors: ['No section'] }] } } })
    renderPage()
    expect(screen.getByRole('button', { name: 'Check the file' })).toBeDisabled()
    pick()
    fireEvent.click(screen.getByRole('button', { name: 'Check the file' }))

    expect(await screen.findByText('3 rows read: 2 are ready, 1 have problems.')).toBeInTheDocument()
    expect(screen.getByText(/There is no section named "9-Z"/)).toBeInTheDocument()
    expect(api.post.mock.calls[0][0]).toBe('/import/students')
    expect(api.post.mock.calls[0][1].get('confirm')).toBe('0')

    fireEvent.click(screen.getByRole('button', { name: 'Import 2 rows' }))
    expect(await screen.findByText('Done: 2 added, 1 skipped.')).toBeInTheDocument()
    expect(api.post.mock.calls[1][1].get('confirm')).toBe('1')
    expect(screen.getByText('layla@school.test')).toBeInTheDocument()
    expect(screen.getByText(/default password/)).toBeInTheDocument()
  })

  it('shows the server message when the file is refused', async () => {
    api.post.mockRejectedValue({ response: { data: { errors: { file: ['The file has no column for: Student number.'] } } } })
    renderPage()
    pick()
    fireEvent.click(screen.getByRole('button', { name: 'Check the file' }))
    expect(await screen.findByRole('alert')).toHaveTextContent('The file has no column for: Student number.')
  })

  it('switches to staff, which has its own template and note', async () => {
    api.get.mockResolvedValue({ data: new Blob(['x']), headers: {} })
    URL.createObjectURL = vi.fn(() => 'blob:x')
    URL.revokeObjectURL = vi.fn()
    vi.spyOn(HTMLAnchorElement.prototype, 'click').mockImplementation(() => {})
    renderPage()
    fireEvent.click(screen.getByRole('tab', { name: 'Staff' }))
    expect(screen.getByText(/Required: name, email and position/)).toBeInTheDocument()
    fireEvent.click(screen.getByRole('button', { name: 'Download the template' }))
    await waitFor(() => expect(api.get).toHaveBeenCalledWith('/import/staff/template', { params: {}, responseType: 'blob' }))
  })
})
