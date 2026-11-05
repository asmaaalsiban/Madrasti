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
  const dash = {
    people: { students: 40, teachers: 5 },
    students_today: { present: 31, late: 5, absent: 2, excused: 2 },
    staff_today: { present: 4, late: 0, absent: 1, leave: 0 },
    attendance_trend: [{ date: '2026-11-03', rate: 90, total: 40 }, { date: '2026-11-04', rate: 87.5, total: 40 }],
    finance: { currency: 'JOD', billed: 10000, collected: 6000, overdue: 500, monthly: [{ month: '2026-10', amount: 3000 }, { month: '2026-11', amount: 3000 }] },
    students_by_level: [{ id: 1, name_ar: 'الأول', name_en: 'Grade 1', count: 22 }],
    marks_by_subject: [{ id: 1, name_ar: 'رياضيات', name_en: 'Math', average: 81.5 }],
    waiting: { procurement: 2, privacy: 0 },
    frequent_absences: [{ id: 9, name_ar: 'سامي', name_en: 'Sami', days: 3 }],
  }
  const mockApi = () => api.get.mockImplementation((url) => Promise.resolve({ data: { data: url === '/admin/dashboard' ? dash : { academic_years: 2, grade_levels: 12, classrooms: 30, subjects: 9 } } }))

  it('renders the four counts', async () => {
    mockApi()
    renderPage(<OverviewPage />)
    expect(await screen.findByText('30')).toBeInTheDocument()
    expect(screen.getByText('12')).toBeInTheDocument()
    expect(api.get).toHaveBeenCalledWith('/admin/overview')
  })

  it('draws today, the money, and what waits', async () => {
    mockApi()
    renderPage(<OverviewPage />)
    expect(await screen.findByText('Student attendance today')).toBeInTheDocument()
    expect(screen.getByRole('img', { name: 'Student attendance today: 90%' })).toBeInTheDocument()
    expect(screen.getByRole('img', { name: 'Student attendance rate (last 14 days)' })).toBeInTheDocument()
    expect(screen.getByText('Purchase requests')).toBeInTheDocument()
    expect(screen.queryByText('Data privacy requests')).not.toBeInTheDocument()
    expect(screen.getByText('Sami')).toBeInTheDocument()
    expect(screen.getByText('10,000 JOD')).toBeInTheDocument()
  })

  it('says so when nothing was recorded today', async () => {
    api.get.mockImplementation((url) => Promise.resolve({ data: { data: url === '/admin/dashboard' ? { ...dash, students_today: { present: 0, late: 0, absent: 0, excused: 0 }, attendance_trend: [], frequent_absences: [], marks_by_subject: [], waiting: { procurement: 0, privacy: 0 } } : {} } }))
    renderPage(<OverviewPage />)
    expect(await screen.findByText('Nothing is waiting. 👍')).toBeInTheDocument()
    expect(screen.getByText('No published marks yet.')).toBeInTheDocument()
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

  it('shows a generic error when saving fails without validation errors', async () => {
    api.get.mockResolvedValue(paged([]))
    api.post.mockRejectedValue({ response: { status: 500, data: { message: 'Server Error' } } })
    renderPage(<SubjectsPage />)
    await screen.findByText('Nothing to show yet.')
    fireEvent.click(screen.getByRole('button', { name: 'Add' }))
    fireEvent.click(screen.getByRole('button', { name: 'Save' }))
    expect(await screen.findByText('Something went wrong, please try again.')).toBeInTheDocument()
  })

  it('shows the empty state when a search has no matches', async () => {
    api.get.mockResolvedValueOnce(paged([{ id: 1, code: 'MTH', name_ar: 'رياضيات', name_en: 'Math' }]))
    api.get.mockResolvedValue(paged([]))
    renderPage(<SubjectsPage />)
    await screen.findByText('MTH')
    fireEvent.change(screen.getByRole('searchbox'), { target: { value: 'zzz' } })
    expect(await screen.findByText('Nothing to show yet.')).toBeInTheDocument()
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
