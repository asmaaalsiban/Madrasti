import { fireEvent, render, screen, waitFor, within } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { MemoryRouter } from 'react-router-dom'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import i18n from '../src/i18n'
import { api } from '../src/api/client'
import TeachingAssignmentsPage from '../src/features/Academic/pages/TeachingAssignmentsPage'
import TermsPage from '../src/features/Academic/pages/TermsPage'

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

const assignment = { id: 1, weekly_periods: 4, classroom: { id: 3, name: '5-A' }, subject: { id: 7, name_ar: 'الرياضيات', name_en: 'Math' }, teacher: { id: 9, name: 'Sara Khaled' } }

beforeEach(async () => {
  vi.resetAllMocks()
  await i18n.changeLanguage('en')
  api.get.mockImplementation((url) => {
    if (url === '/teaching-assignments') return Promise.resolve(paged([assignment]))
    if (url === '/terms') return Promise.resolve(paged([{ id: 1, name: 'First term', academic_year: { id: 2, name: '2026/2027' }, starts_on: '2026-09-01', ends_on: '2027-01-31' }]))
    if (url === '/classrooms') return Promise.resolve(paged([{ id: 3, name: '5-A' }, { id: 4, name: '5-B' }]))
    if (url === '/subjects') return Promise.resolve(paged([{ id: 7, name_ar: 'الرياضيات', name_en: 'Math' }]))
    if (url === '/academic-years') return Promise.resolve(paged([{ id: 2, name: '2026/2027' }]))
    if (url === '/staff') return Promise.resolve(paged([{ id: 9, name: 'Sara Khaled' }]))
    return Promise.resolve(paged([]))
  })
})

describe('TermsPage', () => {
  it('lists the terms with their year and creates one', async () => {
    api.post.mockResolvedValue({ data: { data: { id: 2 } } })
    renderPage(<TermsPage />)
    expect(await screen.findByText('First term')).toBeInTheDocument()
    expect(screen.getByText('2026/2027', { selector: 'td' })).toBeInTheDocument()

    fireEvent.click(screen.getByRole('button', { name: 'Add' }))
    await within(screen.getByRole('dialog')).findByRole('option', { name: '2026/2027' })
    fireEvent.change(screen.getByLabelText('Academic year'), { target: { value: '2' } })
    fireEvent.change(screen.getByLabelText('Name'), { target: { value: 'Second term' } })
    fireEvent.change(screen.getByLabelText('Starts on'), { target: { value: '2027-02-01' } })
    fireEvent.change(screen.getByLabelText('Ends on'), { target: { value: '2027-06-30' } })
    fireEvent.click(screen.getByRole('button', { name: 'Save' }))
    await waitFor(() => expect(api.post).toHaveBeenCalledWith('/terms', { academic_year_id: '2', name: 'Second term', starts_on: '2027-02-01', ends_on: '2027-06-30' }))
  })

  it('shows the server error for overlapping terms', async () => {
    api.post.mockRejectedValue({ response: { status: 422, data: { errors: { starts_on: ['This term overlaps another term of the same academic year.'] } } } })
    renderPage(<TermsPage />)
    await screen.findByText('First term')
    fireEvent.click(screen.getByRole('button', { name: 'Add' }))
    fireEvent.click(screen.getByRole('button', { name: 'Save' }))
    expect(await screen.findByText('This term overlaps another term of the same academic year.')).toBeInTheDocument()
  })
})

describe('TeachingAssignmentsPage', () => {
  it('lists who teaches what and filters by classroom', async () => {
    renderPage(<TeachingAssignmentsPage />)
    expect(await screen.findByText('Sara Khaled', { selector: 'td' })).toBeInTheDocument()
    expect(screen.getByText('Math', { selector: 'td' })).toBeInTheDocument()

    await screen.findByRole('option', { name: '5-B' })
    fireEvent.change(screen.getByLabelText('Filter by classroom'), { target: { value: '4' } })
    await waitFor(() => expect(api.get).toHaveBeenCalledWith('/teaching-assignments', { params: expect.objectContaining({ classroom_id: '4' }) }))
  })

  it('offers only the teachers of the chosen subject and saves the assignment', async () => {
    api.post.mockResolvedValue({ data: { data: { id: 2 } } })
    renderPage(<TeachingAssignmentsPage />)
    await screen.findByText('Sara Khaled', { selector: 'td' })
    fireEvent.click(screen.getByRole('button', { name: 'Add' }))
    const dialog = screen.getByRole('dialog')
    await within(dialog).findByRole('option', { name: 'Math' })

    fireEvent.change(within(dialog).getByLabelText('Subject'), { target: { value: '7' } })
    await waitFor(() => expect(api.get).toHaveBeenCalledWith('/staff', { params: expect.objectContaining({ category: 'teaching', subject_id: '7' }) }))
    await within(dialog).findByRole('option', { name: 'Sara Khaled' })
    fireEvent.change(within(dialog).getByLabelText('Classroom'), { target: { value: '3' } })
    fireEvent.change(within(dialog).getByLabelText('Teacher'), { target: { value: '9' } })
    fireEvent.click(within(dialog).getByRole('button', { name: 'Save' }))
    await waitFor(() => expect(api.post).toHaveBeenCalledWith('/teaching-assignments', { classroom_id: '3', subject_id: '7', teacher_id: '9', weekly_periods: 3 }))
  })

  it('has Arabic labels', async () => {
    await i18n.changeLanguage('ar')
    renderPage(<TeachingAssignmentsPage />)
    expect(await screen.findByRole('heading', { name: 'توزيع المعلمين على المواد والشعب' })).toBeInTheDocument()
  })
})

describe('Automatic distribution', () => {
  const preview = {
    dry_run: true, max_load: 24, assigned: 40,
    teachers: [
      { id: 9, name: 'Sara Khaled', subject: 'Math', subject_ar: 'الرياضيات', classrooms: 5, load: 24 },
      { id: 10, name: 'Omar Ali', subject: 'Math', subject_ar: 'الرياضيات', classrooms: 3, load: 15 },
    ],
    unassigned: [{ classroom: '4-C', subject: 'Arabic', subject_ar: 'العربية' }, { classroom: '4-B', subject: 'Arabic', subject_ar: 'العربية' }],
    shortages: [{ subject: 'Arabic', subject_ar: 'العربية', periods: 12, teachers_needed: 1, teachers_available: 0, teachers_missing: 1 }],
  }

  it('previews who would teach what, with the missing teachers, then applies it', async () => {
    api.post.mockResolvedValueOnce({ data: { data: preview } }).mockResolvedValueOnce({ data: { data: { ...preview, dry_run: false } } })
    renderPage(<TeachingAssignmentsPage />)
    await screen.findByText('Sara Khaled', { selector: 'td' })
    fireEvent.click(screen.getByRole('button', { name: 'Distribute automatically' }))
    const dialog = screen.getByRole('dialog')
    expect(within(dialog).getByLabelText('Maximum periods per teacher per week')).toHaveValue(24)

    fireEvent.click(within(dialog).getByRole('button', { name: 'Preview' }))
    await waitFor(() => expect(api.post).toHaveBeenCalledWith('/teaching-assignments/auto', { max_load: 24, dry_run: true }))
    expect(await within(dialog).findByText('40 assignments will be made with 2 teachers.')).toBeInTheDocument()
    expect(within(dialog).getByText('2 subjects of classrooms still have no teacher.')).toBeInTheDocument()
    expect(within(dialog).getByText('Arabic: 1 more teacher needed (12 periods).')).toBeInTheDocument()
    expect(within(dialog).getByText('Omar Ali')).toBeInTheDocument()

    fireEvent.click(within(dialog).getByRole('button', { name: 'Apply the distribution' }))
    await waitFor(() => expect(api.post).toHaveBeenLastCalledWith('/teaching-assignments/auto', { max_load: 24 }))
    expect(await within(dialog).findByText('40 assignments were made.')).toBeInTheDocument()
  })

  it('sends the maximum load that was typed and shows the server error', async () => {
    api.post.mockRejectedValue({ response: { status: 422, data: { message: 'The max load must be between 8 and 40.' } } })
    renderPage(<TeachingAssignmentsPage />)
    await screen.findByText('Sara Khaled', { selector: 'td' })
    fireEvent.click(screen.getByRole('button', { name: 'Distribute automatically' }))
    const dialog = screen.getByRole('dialog')
    fireEvent.change(within(dialog).getByLabelText('Maximum periods per teacher per week'), { target: { value: '18' } })
    fireEvent.click(within(dialog).getByRole('button', { name: 'Preview' }))
    await waitFor(() => expect(api.post).toHaveBeenCalledWith('/teaching-assignments/auto', { max_load: 18, dry_run: true }))
    expect(await within(dialog).findByRole('alert')).toHaveTextContent('The max load must be between 8 and 40.')
  })

  it('offers the subject distribution table as an Excel download', async () => {
    global.URL.createObjectURL = vi.fn(() => 'blob:x')
    global.URL.revokeObjectURL = vi.fn()
    renderPage(<TeachingAssignmentsPage />)
    fireEvent.click(await screen.findByRole('button', { name: 'Export distribution table (Excel)' }))
    await waitFor(() => expect(api.get).toHaveBeenCalledWith('/teaching-assignments/export', expect.objectContaining({ responseType: 'blob' })))
  })
})
