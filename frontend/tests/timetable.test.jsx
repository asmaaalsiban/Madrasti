import { fireEvent, render, screen, waitFor, within } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import i18n from '../src/i18n'
import { api } from '../src/api/client'
import SchoolDayPage from '../src/features/Scheduling/pages/SchoolDayPage'
import TimetablePage from '../src/features/Scheduling/pages/TimetablePage'
import StudentTimetablePage from '../src/features/Student/pages/StudentTimetablePage'
import TeacherTimetablePage from '../src/features/Teacher/pages/TeacherTimetablePage'
import TeacherHome from '../src/features/Teacher/pages/TeacherHome'
import ChildTimetablePage from '../src/features/Parent/pages/ChildTimetablePage'

vi.mock('../src/api/client', () => ({
  api: { get: vi.fn(), post: vi.fn(), put: vi.fn(), delete: vi.fn() },
}))
const auth = vi.hoisted(() => ({ user: null }))
vi.mock('../src/features/Auth/AuthProvider', () => ({ useAuth: () => ({ user: auth.user }) }))

const renderPage = (ui) =>
  render(
    <QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}>
      <MemoryRouter>{ui}</MemoryRouter>
    </QueryClientProvider>,
  )

const renderPageAt = (path, ui, route) =>
  render(
    <QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}>
      <MemoryRouter initialEntries={[path]}><Routes><Route path={route} element={ui} /></Routes></MemoryRouter>
    </QueryClientProvider>,
  )

const paged = (data) => ({ data: { data, meta: { current_page: 1, last_page: 1 } } })
const gradesList = [{ id: 1, name_en: 'Grade 1', name_ar: 'الصف الأول', code: '1', sort_order: 4, periods_per_day: 6 }, { id: 4, name_en: 'Grade 4', name_ar: 'الصف الرابع', code: '4', sort_order: 7, periods_per_day: null }]
const setup = { days: [0, 1, 2, 3, 4], periods_per_day: 7, starts_at: '07:45', period_minutes: 45, gap_minutes: 5, long_break_after: 3, long_break_minutes: 20 }
const periods = [0, 1].flatMap((d) => [1, 2].map((n) => ({ id: d * 2 + n, day_of_week: d, number: n, starts_at: n === 1 ? '07:45' : '08:35', ends_at: n === 1 ? '08:30' : '09:20' })))

beforeEach(async () => {
  vi.resetAllMocks()
  await i18n.changeLanguage('en')
})

describe('SchoolDayPage', () => {
  beforeEach(() => api.get.mockResolvedValue({ data: { data: { setup, periods: [], grades: gradesList } } }))

  it('shows the current setup and a preview of the bells', async () => {
    renderPage(<SchoolDayPage />)
    expect(await screen.findByLabelText('Periods per day')).toHaveValue(7)
    expect(screen.getByLabelText('First period starts at')).toHaveValue('07:45')
    for (const day of ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday']) expect(screen.getByRole('checkbox', { name: day })).toBeChecked()
    expect(screen.getByRole('checkbox', { name: 'Friday' })).not.toBeChecked()
    expect(screen.getByText('Period 4')).toBeInTheDocument()
    expect(screen.getByText('10:30 - 11:15')).toBeInTheDocument() // after the 20 minute break following period 3
    expect(screen.getByText('13:00 - 13:45')).toBeInTheDocument()
  })

  it('saves the changed setup', async () => {
    api.put.mockResolvedValue({ data: { data: { setup, periods: [], grades: gradesList } } })
    renderPage(<SchoolDayPage />)
    await screen.findByLabelText('Periods per day')
    fireEvent.click(screen.getByRole('checkbox', { name: 'Friday' }))
    fireEvent.click(screen.getByRole('checkbox', { name: 'Thursday' }))
    fireEvent.change(screen.getByLabelText('Periods per day'), { target: { value: '8' } })
    fireEvent.change(screen.getByLabelText('First period starts at'), { target: { value: '08:00' } })
    fireEvent.change(screen.getByLabelText('Break after period'), { target: { value: '' } })
    fireEvent.click(screen.getByRole('button', { name: 'Save' }))
    await waitFor(() => expect(api.put).toHaveBeenCalledWith('/periods/setup', {
      days: [0, 1, 2, 3, 5], periods_per_day: 8, starts_at: '08:00', period_minutes: 45, gap_minutes: 5, long_break_after: null, long_break_minutes: null,
    }))
    expect(await screen.findByText('School day saved.')).toBeInTheDocument()
  })

  it('shows the server error when periods are in use', async () => {
    api.put.mockRejectedValue({ response: { status: 422, data: { errors: { periods_per_day: ['Some of the periods this would remove are used by the timetable.'] } } } })
    renderPage(<SchoolDayPage />)
    await screen.findByLabelText('Periods per day')
    fireEvent.change(screen.getByLabelText('Periods per day'), { target: { value: '6' } })
    fireEvent.click(screen.getByRole('button', { name: 'Save' }))
    expect(await screen.findByText('Some of the periods this would remove are used by the timetable.')).toBeInTheDocument()
  })

  it('lets every grade have its own number of periods a day', async () => {
    api.put.mockResolvedValue({ data: { data: { setup, periods: [], grades: gradesList } } })
    renderPage(<SchoolDayPage />)
    expect(await screen.findByLabelText('Grade 1')).toHaveValue(6)
    expect(screen.getByLabelText('Grade 4')).toHaveValue(null)

    fireEvent.change(screen.getByLabelText('Grade 4'), { target: { value: '5' } })
    fireEvent.change(screen.getByLabelText('Grade 1'), { target: { value: '' } })
    fireEvent.click(screen.getByRole('button', { name: 'Save the periods of the grades' }))
    await waitFor(() => expect(api.put).toHaveBeenCalledWith('/periods/grades', { grades: [
      { grade_level_id: 1, periods_per_day: null },
      { grade_level_id: 4, periods_per_day: 5 },
    ] }))
    expect(await screen.findByText('The periods of the grades were saved.')).toBeInTheDocument()
  })

  it('has Arabic labels', async () => {
    await i18n.changeLanguage('ar')
    renderPage(<SchoolDayPage />)
    expect(await screen.findByRole('checkbox', { name: 'الأحد' })).toBeChecked()
    expect(screen.getByRole('heading', { name: 'اليوم الدراسي' })).toBeInTheDocument()
  })
})

describe('TimetablePage', () => {
  const entry = (id, periodId, subject, teacher, classroom, locked = false) => ({
    id, period_id: periodId, subject: { id: id, name_en: subject, name_ar: `${subject}-ar` }, teacher: { id: 90 + id, name: teacher }, classroom: { id: 3, name: classroom }, room: null, is_locked: locked,
  })
  const grid = {
    periods,
    entries: [entry(1, 1, 'Math', 'Sara', '5-A'), entry(2, 2, 'Arabic', 'Omar', '5-A', true), entry(3, 3, 'Science', 'Rana', '5-A')],
  }
  const serve = () => api.get.mockImplementation((url) => {
    if (url === '/classrooms') return Promise.resolve(paged([{ id: 3, name: '5-A' }, { id: 4, name: '5-B' }]))
    if (url === '/staff') return Promise.resolve(paged([{ id: 91, name: 'Sara' }, { id: 92, name: 'Omar' }]))
    if (url === '/timetable') return Promise.resolve({ data: { data: grid } })
    return Promise.resolve(paged([]))
  })

  it('shows the week of the first classroom: periods as rows and days as columns', async () => {
    serve()
    renderPage(<TimetablePage />)
    expect(await screen.findByText('Math')).toBeInTheDocument()
    expect(api.get).toHaveBeenCalledWith('/timetable', { params: { classroom_id: 3 } })
    expect(screen.getAllByRole('columnheader').map((h) => h.textContent)).toEqual(['Periods and days', 'Sunday', 'Monday'])
    const first = screen.getByRole('row', { name: /Period 1/ })
    expect(within(first).getByText('07:45 - 08:30')).toBeInTheDocument()
    expect(within(first).getByText('Math')).toBeInTheDocument()
    expect(within(first).getByText('Sara')).toBeInTheDocument()
    expect(within(screen.getByRole('row', { name: /Period 2/ })).getByText('Arabic')).toBeInTheDocument()
    expect(screen.getByLabelText('Locked')).toBeInTheDocument()
  })

  it('switches to the week of a teacher', async () => {
    serve()
    renderPage(<TimetablePage />)
    await screen.findByText('Math')
    fireEvent.click(screen.getByRole('tab', { name: 'By teacher' }))
    await waitFor(() => expect(api.get).toHaveBeenCalledWith('/timetable', { params: { teacher_id: 91 } }))
    expect(screen.getByLabelText('Teacher')).toHaveValue('91')
  })

  it('previews the generation, shows what could not be placed and applies it', async () => {
    serve()
    const preview = { dry_run: true, seed: 5, attempts: 3, total: 315, placed: 313, locked: 0, incomplete: [{ classroom: '1-A', lessons: 28, slots: 30, empty: 2 }], unplaced: [{ classroom: '5-A', subject: 'Art', subject_ar: 'فنية', count: 2 }], without_teacher: [{ classroom: '5-B', subject: 'Music', subject_ar: 'موسيقى', periods: 1 }] }
    api.post.mockResolvedValueOnce({ data: { data: preview } }).mockResolvedValueOnce({ data: { data: { ...preview, dry_run: false } } })
    renderPage(<TimetablePage />)
    await screen.findByText('Math')
    fireEvent.click(screen.getByRole('button', { name: 'Generate the timetable' }))
    const dialog = screen.getByRole('dialog')
    fireEvent.click(within(dialog).getByRole('button', { name: 'Preview' }))
    await waitFor(() => expect(api.post).toHaveBeenCalledWith('/timetable/generate', { dry_run: true }))
    expect(await within(dialog).findByText('313 of 315 lessons can be placed.')).toBeInTheDocument()
    expect(within(dialog).getByText('5-A · Art: 2')).toBeInTheDocument()
    expect(within(dialog).getByText('5-B · Music: no teacher assigned')).toBeInTheDocument()
    expect(within(dialog).getByText('1-A: 28 lessons for 30 periods (2 empty)')).toBeInTheDocument()

    fireEvent.click(within(dialog).getByRole('button', { name: 'Apply the timetable' }))
    await waitFor(() => expect(api.post).toHaveBeenLastCalledWith('/timetable/generate', { seed: 5 }))
    expect(await within(dialog).findByText('The timetable was saved: 313 lessons.')).toBeInTheDocument()
  })

  it('shows the server message when the school day is not set up', async () => {
    serve()
    api.post.mockRejectedValue({ response: { status: 422, data: { message: 'Set up the school day first (days, periods and times).' } } })
    renderPage(<TimetablePage />)
    await screen.findByText('Math')
    fireEvent.click(screen.getByRole('button', { name: 'Generate the timetable' }))
    fireEvent.click(within(screen.getByRole('dialog')).getByRole('button', { name: 'Preview' }))
    expect(await within(screen.getByRole('dialog')).findByRole('alert')).toHaveTextContent('Set up the school day first')
  })

  it('uses Arabic subject names and day names', async () => {
    await i18n.changeLanguage('ar')
    serve()
    renderPage(<TimetablePage />)
    expect(await screen.findByText('Math-ar')).toBeInTheDocument()
    expect(screen.getAllByRole('columnheader').map((h) => h.textContent)).toEqual(['الحصص والأيام', 'الأحد', 'الإثنين'])
  })
})

describe('Week views of the teacher, the student and the parent', () => {
  const week = {
    title: 'Sara',
    periods: [0, 1, 2].flatMap((d) => [1, 2].map((n) => ({ id: d * 2 + n, day_of_week: d, number: n, starts_at: n === 1 ? '07:45' : '08:35', ends_at: n === 1 ? '08:30' : '09:20' }))),
    entries: [
      { id: 1, period_id: 1, subject: { id: 1, name_en: 'Math', name_ar: 'الرياضيات' }, teacher: { id: 9, name: 'Sara' }, classroom: { id: 3, name: '5-A' }, room: null, is_locked: false },
      { id: 2, period_id: 3, subject: { id: 2, name_en: 'Art', name_ar: 'الفنية' }, teacher: { id: 9, name: 'Sara' }, classroom: { id: 4, name: '5-B' }, room: null, is_locked: false },
    ],
  }
  beforeEach(() => {
    api.get.mockResolvedValue({ data: { data: week } })
    auth.user = { id: 9, role: 'teacher', name: 'Sara', student_id: 21, children: [{ id: 21, name_ar: 'أحمد', name_en: 'Ahmad' }] }
  })

  it('shows the teacher their own week with the section of every lesson and says when a cell is free', async () => {
    renderPage(<TeacherTimetablePage />)
    expect(await screen.findByRole('heading', { name: 'My timetable' })).toBeInTheDocument()
    expect(api.get).toHaveBeenCalledWith('/timetable/me')
    const first = await screen.findByRole('row', { name: /Period 1/ })
    expect(within(first).getByText('Math')).toBeInTheDocument()
    expect(within(first).getByText('5-A')).toBeInTheDocument()
    expect(within(screen.getByRole('row', { name: /Period 2/ })).getAllByText('Free').length).toBeGreaterThan(0)
  })

  it('shows the student the week of the section with the teacher of every lesson', async () => {
    renderPage(<StudentTimetablePage />)
    expect(await screen.findByRole('heading', { name: 'My timetable' })).toBeInTheDocument()
    expect(await screen.findByText('Math')).toBeInTheDocument()
    expect(screen.getAllByText('Sara', { selector: 'span' }).length).toBeGreaterThan(0)
  })

  it('shows a parent the week of one child', async () => {
    renderPageAt('/parent/children/21/timetable', <ChildTimetablePage />, '/parent/children/:id/timetable')
    expect(await screen.findByRole('heading', { name: 'Timetable: Ahmad' })).toBeInTheDocument()
    expect(api.get).toHaveBeenCalledWith('/students/21/timetable')
  })

  it('explains when there is no timetable yet', async () => {
    api.get.mockResolvedValue({ data: { data: { title: null, periods: [], entries: [] } } })
    renderPage(<StudentTimetablePage />)
    expect(await screen.findByText('The timetable has not been prepared yet.')).toBeInTheDocument()
  })

  it('has Arabic labels', async () => {
    await i18n.changeLanguage('ar')
    renderPage(<TeacherTimetablePage />)
    expect(await screen.findByRole('heading', { name: 'جدولي' })).toBeInTheDocument()
  })

  it('is linked from the teacher home', () => {
    renderPage(<TeacherHome />)
    expect(screen.getByRole('link', { name: /My timetable/ })).toHaveAttribute('href', '/teacher/timetable')
  })
})

