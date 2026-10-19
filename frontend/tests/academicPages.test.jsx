import { fireEvent, render, screen, waitFor } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { MemoryRouter } from 'react-router-dom'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import i18n from '../src/i18n'
import { api } from '../src/api/client'
import OverviewPage from '../src/features/Academic/pages/OverviewPage'
import SubjectsPage from '../src/features/Academic/pages/SubjectsPage'

vi.mock('../src/api/client', () => ({
  api: { get: vi.fn(), post: vi.fn(), put: vi.fn(), delete: vi.fn() },
}))

const paged = (data) => ({ data: { data, meta: { current_page: 1, last_page: 1 } } })
const renderPage = (ui) =>
  render(
    <QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}>
      <MemoryRouter>{ui}</MemoryRouter>
    </QueryClientProvider>,
  )

beforeEach(async () => {
  vi.resetAllMocks()
  await i18n.changeLanguage('en')
})

describe('OverviewPage', () => {
  it('renders the four counts', async () => {
    api.get.mockResolvedValue({ data: { data: { academic_years: 2, grade_levels: 12, classrooms: 30, subjects: 9 } } })
    renderPage(<OverviewPage />)
    expect(await screen.findByText('30')).toBeInTheDocument()
    expect(screen.getByText('12')).toBeInTheDocument()
    expect(api.get).toHaveBeenCalledWith('/admin/overview')
  })
})

describe('SubjectsPage', () => {
  it('lists rows and shows the empty state', async () => {
    api.get.mockResolvedValueOnce(paged([{ id: 1, code: 'MTH', name_ar: 'رياضيات', name_en: 'Math' }]))
    renderPage(<SubjectsPage />)
    expect(await screen.findByText('MTH')).toBeInTheDocument()
  })

  it('shows empty state when there are no rows', async () => {
    api.get.mockResolvedValue(paged([]))
    renderPage(<SubjectsPage />)
    expect(await screen.findByText('Nothing to show yet.')).toBeInTheDocument()
  })

  it('creates a subject and refetches', async () => {
    api.get.mockResolvedValue(paged([]))
    api.post.mockResolvedValue({ data: { data: { id: 2 } } })
    renderPage(<SubjectsPage />)
    await screen.findByText('Nothing to show yet.')
    fireEvent.click(screen.getByRole('button', { name: 'Add' }))
    fireEvent.change(screen.getByLabelText('Code'), { target: { value: 'SCI' } })
    fireEvent.change(screen.getByLabelText('Name (Arabic)'), { target: { value: 'علوم' } })
    fireEvent.change(screen.getByLabelText('Name (English)'), { target: { value: 'Science' } })
    fireEvent.click(screen.getByRole('button', { name: 'Save' }))
    await waitFor(() =>
      expect(api.post).toHaveBeenCalledWith('/subjects', { code: 'SCI', name_ar: 'علوم', name_en: 'Science' }),
    )
    await waitFor(() => expect(api.get.mock.calls.length).toBeGreaterThan(1))
  })

  it('shows a 422 error under the field', async () => {
    api.get.mockResolvedValue(paged([]))
    api.post.mockRejectedValue({ response: { status: 422, data: { errors: { code: ['The code has already been taken.'] } } } })
    renderPage(<SubjectsPage />)
    await screen.findByText('Nothing to show yet.')
    fireEvent.click(screen.getByRole('button', { name: 'Add' }))
    fireEvent.click(screen.getByRole('button', { name: 'Save' }))
    expect(await screen.findByText('The code has already been taken.')).toBeInTheDocument()
  })

  it('confirms before deleting', async () => {
    api.get.mockResolvedValue(paged([{ id: 1, code: 'MTH', name_ar: 'رياضيات', name_en: 'Math' }]))
    api.delete.mockResolvedValue({})
    renderPage(<SubjectsPage />)
    await screen.findByText('MTH')
    fireEvent.click(screen.getByRole('button', { name: 'Delete' }))
    expect(api.delete).not.toHaveBeenCalled()
    fireEvent.click(within_dialog('Delete'))
    await waitFor(() => expect(api.delete).toHaveBeenCalledWith('/subjects/1'))
  })

  it('renders Arabic labels', async () => {
    await i18n.changeLanguage('ar')
    api.get.mockResolvedValue(paged([]))
    renderPage(<SubjectsPage />)
    expect(await screen.findByRole('heading', { name: 'المواد' })).toBeInTheDocument()
  })
})

function within_dialog(name) {
  const dialog = screen.getByRole('dialog')
  return Array.from(dialog.querySelectorAll('button')).find((b) => b.textContent === name)
}
