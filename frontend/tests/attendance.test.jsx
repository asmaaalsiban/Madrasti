import { fireEvent, render, screen, waitFor, within } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { MemoryRouter } from 'react-router-dom'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import i18n from '../src/i18n'
import { api } from '../src/api/client'
import AttendanceReportPage from '../src/features/Attendance/pages/AttendanceReportPage'
import TakeAttendancePage from '../src/features/Attendance/pages/TakeAttendancePage'
import TeacherHome from '../src/features/Teacher/pages/TeacherHome'

vi.mock('../src/api/client', () => ({
  api: { get: vi.fn(), post: vi.fn(), put: vi.fn(), delete: vi.fn() },
}))
const auth = vi.hoisted(() => ({ user: { id: 1, role: 'teacher', name: 'Sara' } }))
vi.mock('../src/features/Auth/AuthProvider', () => ({ useAuth: () => ({ user: auth.user }) }))

const renderPage = (ui) =>
  render(
    <QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}>
      <MemoryRouter>{ui}</MemoryRouter>
    </QueryClientProvider>,
  )

const room = { id: 3, name: '1-A', grade_level: { id: 1, name_ar: 'الصف الأول', name_en: 'Grade 1' } }
const students = [
  { id: 11, student_number: 'S-1', name_ar: 'أحمد', name_en: 'Ahmad', status: null, note: null },
  { id: 12, student_number: 'S-2', name_ar: 'ليلى', name_en: 'Layla', status: null, note: null },
  { id: 13, student_number: 'S-3', name_ar: 'يوسف', name_en: 'Yousef', status: null, note: null },
]
const roster = (over = {}) => ({ data: { data: { classroom: { id: 3, name: '1-A' }, date: '2026-10-20', recorded: false, students, ...over } } })

const serveRoster = (over, classrooms = [room]) => {
  api.get.mockImplementation((url) => {
    if (url === '/attendance/classrooms') return Promise.resolve({ data: { data: classrooms } })
    if (url === '/attendance') return Promise.resolve(roster(over))
    return Promise.resolve({ data: { data: [] } })
  })
}
const row = (name) => screen.getByRole('group', { name })

beforeEach(async () => {
  vi.resetAllMocks()
  auth.user = { id: 1, role: 'teacher', name: 'Sara' }
  await i18n.changeLanguage('en')
})

describe('TakeAttendancePage', () => {
  it('lists the students numbered, everyone present by default', async () => {
    serveRoster()
    renderPage(<TakeAttendancePage />)
    expect(await screen.findByRole('group', { name: 'Ahmad' })).toBeInTheDocument()
    expect(within(row('Ahmad')).getByRole('button', { name: 'Present' })).toHaveAttribute('aria-pressed', 'true')
    expect(within(row('Layla')).getByRole('button', { name: 'Absent' })).toHaveAttribute('aria-pressed', 'false')
    expect(within(row('Layla')).getByText('2')).toBeInTheDocument()
    expect(screen.getByText('Present: 3 · Late: 0 · Absent: 0')).toBeInTheDocument()
    expect(api.get).toHaveBeenCalledWith('/attendance', { params: expect.objectContaining({ classroom_id: 3 }) })
  })

  it('saves everybody present except the students marked absent or late', async () => {
    serveRoster()
    api.put.mockResolvedValue({ data: { message: 'ok' } })
    renderPage(<TakeAttendancePage />)
    await screen.findByRole('group', { name: 'Ahmad' })

    fireEvent.click(within(row('Layla')).getByRole('button', { name: 'Absent' }))
    fireEvent.click(within(row('Yousef')).getByRole('button', { name: 'Late' }))
    expect(within(row('Layla')).getByRole('button', { name: 'Absent' })).toHaveAttribute('aria-pressed', 'true')
    expect(within(row('Layla')).getByRole('button', { name: 'Present' })).toHaveAttribute('aria-pressed', 'false')
    expect(screen.getByText('Present: 1 · Late: 1 · Absent: 1')).toBeInTheDocument()

    fireEvent.click(screen.getByRole('button', { name: 'Save' }))
    await waitFor(() => expect(api.put).toHaveBeenCalledWith('/attendance', {
      classroom_id: 3,
      date: expect.stringMatching(/^\d{4}-\d{2}-\d{2}$/),
      records: [
        { student_id: 11, status: 'present' },
        { student_id: 12, status: 'absent' },
        { student_id: 13, status: 'late' },
      ],
    }))
    expect(await screen.findByText('Saved. The parents of absent students are being notified.')).toBeInTheDocument()
  })

  it('shows what was already recorded for the day', async () => {
    serveRoster({ recorded: true, students: [{ ...students[0], status: 'absent' }, { ...students[1], status: 'late' }, { ...students[2], status: 'present' }] })
    renderPage(<TakeAttendancePage />)
    await screen.findByRole('group', { name: 'Ahmad' })
    expect(within(row('Ahmad')).getByRole('button', { name: 'Absent' })).toHaveAttribute('aria-pressed', 'true')
    expect(within(row('Layla')).getByRole('button', { name: 'Late' })).toHaveAttribute('aria-pressed', 'true')
    expect(screen.getByText('This day was already recorded. Saving will correct it.')).toBeInTheDocument()
  })

  it('explains when the user has no classroom', async () => {
    serveRoster({}, [])
    renderPage(<TakeAttendancePage />)
    expect(await screen.findByText('You are not the homeroom teacher of any classroom.')).toBeInTheDocument()
  })

  it('shows an error when saving fails', async () => {
    serveRoster()
    api.put.mockRejectedValue({ response: { status: 422, data: { message: 'The date is outside the academic year of the classroom.' } } })
    renderPage(<TakeAttendancePage />)
    await screen.findByRole('group', { name: 'Ahmad' })
    fireEvent.click(screen.getByRole('button', { name: 'Save' }))
    expect(await screen.findByRole('alert')).toHaveTextContent('The date is outside the academic year of the classroom.')
  })

  it('uses Arabic names and labels', async () => {
    await i18n.changeLanguage('ar')
    serveRoster()
    renderPage(<TakeAttendancePage />)
    expect(await screen.findByRole('group', { name: 'أحمد' })).toBeInTheDocument()
    expect(within(row('أحمد')).getByRole('button', { name: 'غائب' })).toBeInTheDocument()
    expect(within(row('أحمد')).getByRole('button', { name: 'متأخر' })).toBeInTheDocument()
  })
})

describe('AttendanceReportPage', () => {
  const rows = [
    { student: { id: 11, student_number: 'S-1', name_ar: 'أحمد', name_en: 'Ahmad' }, classroom: '1-A', absent: 7, late: 2 },
    { student: { id: 12, student_number: 'S-2', name_ar: 'ليلى', name_en: 'Layla' }, classroom: '1-A', absent: 0, late: 1 },
  ]
  const serveReport = () => {
    api.get.mockImplementation((url, config) => {
      if (url === '/attendance/classrooms') return Promise.resolve({ data: { data: [room] } })
      if (url === '/attendance/report') return Promise.resolve({ data: { data: rows, meta: { current_page: 1, last_page: 1, from: '2026-09-01', to: '2027-06-30' } } })
      if (url === '/attendance/students/11') {
        return Promise.resolve({ data: { data: { summary: { absent: 7, late: 2, present: 20 }, records: [{ date: '2026-10-18', status: 'absent' }, { date: '2026-10-12', status: 'absent' }] } } })
      }
      return Promise.resolve({ data: { data: [] } })
    })
  }

  it('shows how many days each student was absent and late', async () => {
    serveReport()
    renderPage(<AttendanceReportPage />)
    expect(await screen.findByText('Ahmad')).toBeInTheDocument()
    expect(screen.getByRole('button', { name: '7' })).toBeInTheDocument()
    expect(screen.getByText('Layla')).toBeInTheDocument()
  })

  it('opens the dates of absence behind a number', async () => {
    serveReport()
    renderPage(<AttendanceReportPage />)
    await screen.findByText('Ahmad')
    fireEvent.click(screen.getByRole('button', { name: '7' }))
    const dialog = await screen.findByRole('dialog', { name: 'Absence dates: Ahmad' })
    expect(await within(dialog).findByText('2026-10-18')).toBeInTheDocument()
    expect(within(dialog).getByText('2026-10-12')).toBeInTheDocument()
    expect(api.get).toHaveBeenCalledWith('/attendance/students/11', { params: expect.objectContaining({ status: 'absent' }) })
    fireEvent.click(within(dialog).getByRole('button', { name: 'Close' }))
    expect(screen.queryByRole('dialog')).not.toBeInTheDocument()
  })

  it('filters by classroom and dates', async () => {
    serveReport()
    renderPage(<AttendanceReportPage />)
    await screen.findByText('Ahmad')
    fireEvent.change(screen.getByLabelText('Classroom'), { target: { value: '3' } })
    fireEvent.change(screen.getByLabelText('From'), { target: { value: '2026-10-01' } })
    await waitFor(() => expect(api.get).toHaveBeenCalledWith('/attendance/report', { params: expect.objectContaining({ classroom_id: '3', from: '2026-10-01' }) }))
  })
})

describe('TeacherHome', () => {
  it('links to taking attendance and the absence report', () => {
    renderPage(<TeacherHome />)
    expect(screen.getByRole('link', { name: /Take attendance/ })).toHaveAttribute('href', '/teacher/attendance')
    expect(screen.getByRole('link', { name: /Absence report/ })).toHaveAttribute('href', '/teacher/attendance/report')
  })
})
