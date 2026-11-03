import { fireEvent, render, screen, waitFor } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import i18n from '../src/i18n'
import { api } from '../src/api/client'
import ExamsAdminPage from '../src/features/Exams/ExamsAdminPage'
import MyExamsPage from '../src/features/Exams/MyExamsPage'
import ChildExamsPage from '../src/features/Exams/ChildExamsPage'

vi.mock('../src/api/client', () => ({ api: { get: vi.fn(), post: vi.fn(), put: vi.fn(), delete: vi.fn() } }))
const auth = { user: { role: 'admin' } }
vi.mock('../src/features/Auth/AuthProvider', () => ({ useAuth: () => ({ user: auth.user }) }))
const wrap = (ui, path = '/', route = '/') => render(<QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}><MemoryRouter initialEntries={[path]}><Routes><Route path={route} element={ui} /></Routes></MemoryRouter></QueryClientProvider>)

const session = (id, date, subject, extra = {}) => ({ kind: 'monthly', round: 1, id, date, period_from: 2, period_to: 3, starts_at: '08:35', ends_at: '10:10', term: { id: 1, name: 'Term 1' }, classroom: { id: 1, name: '1-A', grade: { id: 1, name_ar: 'الأول', name_en: 'Grade 1' } }, subject: { id: id, name_ar: subject, name_en: subject }, invigilators: [{ id: 9, name: 'Sara Ali' }], ...extra })

beforeEach(async () => {
  vi.resetAllMocks()
  auth.user = { role: 'admin' }
  await i18n.changeLanguage('en')
})

describe('ExamsAdminPage', () => {
  beforeEach(() => {
    api.get.mockImplementation((url) => Promise.resolve({ data: { term_id: 1, data: url === '/terms' ? [{ id: 1, name: 'Term 1' }] : url === '/grade-levels' ? [{ id: 1, name_ar: 'الأول', name_en: 'Grade 1' }, { id: 2, name_ar: 'الثاني', name_en: 'Grade 2' }] : url === '/payroll/staff' ? [{ id: 9, name: 'Sara Ali', staff_type: 'teacher' }, { id: 10, name: 'Omar', staff_type: 'accountant' }] : [session(1, '2027-01-03', 'Maths'), session(2, '2027-01-04', 'Arabic')] } }))
  })

  it('previews then publishes with the chosen settings', async () => {
    api.post.mockImplementation((url, body) => Promise.resolve({ data: { data: { dry_run: body.dry_run, sessions: 8, days: 3, grades: 1, warnings: ['1-A has too few teachers'] } } }))
    wrap(<ExamsAdminPage />)
    await screen.findByText('Maths')
    fireEvent.change(screen.getAllByLabelText('From')[0], { target: { value: '2027-01-03' } })
    fireEvent.change(screen.getAllByLabelText('To')[0], { target: { value: '2027-01-14' } })
    fireEvent.change(screen.getByLabelText('First period of the exam'), { target: { value: '3' } })
    fireEvent.change(screen.getByLabelText('Periods per exam'), { target: { value: '1' } })
    fireEvent.click(screen.getAllByText('Grade 2')[0]) // only grade 2 (the chip)
    fireEvent.click(screen.getByRole('button', { name: 'Preview' }))
    await waitFor(() => expect(api.post).toHaveBeenCalledWith('/exams/generate', expect.objectContaining({ kind: 'monthly', round: 1, term_id: 1, from: '2027-01-03', to: '2027-01-14', first_period: '3', periods_per_exam: '1', fill_missing: true, grade_level_ids: [2], dry_run: true })))
    expect(await screen.findByText('Preview: 8 exams over 3 days (nothing saved yet).')).toBeInTheDocument()
    expect(screen.getByText(/1-A has too few teachers/)).toBeInTheDocument()
    fireEvent.click(screen.getByRole('button', { name: 'Publish' }))
    await waitFor(() => expect(api.post).toHaveBeenLastCalledWith('/exams/generate', expect.objectContaining({ dry_run: false })))
    expect(await screen.findByText('Published: 8 exams over 3 days.')).toBeInTheDocument()
  })

  it('shows the timetable by day and moves an exam by hand', async () => {
    api.put.mockResolvedValue({ data: {} })
    wrap(<ExamsAdminPage />)
    expect(await screen.findByText('Maths')).toBeInTheDocument()
    expect(screen.getByText('2027-01-04')).toBeInTheDocument()
    fireEvent.click(screen.getAllByRole('button', { name: 'Edit' })[0])
    fireEvent.change(await screen.findByLabelText('From period'), { target: { value: '4' } })
    fireEvent.change(screen.getByLabelText('To period'), { target: { value: '4' } })
    expect(screen.queryByLabelText('Omar')).not.toBeInTheDocument() // only teachers can watch
    fireEvent.click(screen.getByRole('button', { name: 'Save' }))
    // Moved by periods: the server picks the teachers of those periods (no list sent unless it was changed).
    await waitFor(() => expect(api.put).toHaveBeenCalledWith('/exams/1', { exam_date: '2027-01-03', period_from: 4, period_to: 4 }))
  })

  it('can set a custom time and choose the watchers by hand', async () => {
    api.put.mockResolvedValue({ data: {} })
    wrap(<ExamsAdminPage />)
    await screen.findByText('Maths')
    fireEvent.click(screen.getAllByRole('button', { name: 'Edit' })[0])
    fireEvent.change(await screen.findByLabelText('Set the time'), { target: { value: 'time' } })
    fireEvent.change(screen.getByLabelText('Starts'), { target: { value: '10:00' } })
    fireEvent.click(screen.getByLabelText('Sara Ali')) // untick
    fireEvent.click(screen.getByRole('button', { name: 'Save' }))
    await waitFor(() => expect(api.put).toHaveBeenCalledWith('/exams/1', expect.objectContaining({ starts_at: '10:00', invigilator_ids: [] })))
  })
})

describe('final exams', () => {
  beforeEach(() => {
    api.get.mockImplementation((url) => Promise.resolve({ data: { term_id: 1, data: url === '/terms' ? [{ id: 1, name: 'Term 1' }] : url === '/grade-levels' ? [{ id: 1, name_ar: 'الأول', name_en: 'Grade 1' }] : url === '/payroll/staff' ? [] : [session(1, '2027-03-01', 'Maths', { kind: 'final', round: 0, period_from: null, period_to: null, starts_at: '09:00', ends_at: '11:00' })] } }))
    api.post.mockResolvedValue({ data: { data: { dry_run: false, sessions: 8, days: 8, grades: 1, warnings: [] } } })
  })

  it('switches to the final exams settings (own times and free teachers) and sends them', async () => {
    wrap(<ExamsAdminPage />)
    await screen.findByText('Maths')
    expect(screen.getAllByText('Final exams').length).toBeGreaterThan(1) // the list chip and the tab
    fireEvent.click(screen.getByRole('tab', { name: 'Final exams' }))
    expect(screen.queryByLabelText('First period of the exam')).not.toBeInTheDocument()
    fireEvent.change(screen.getAllByLabelText('From')[0], { target: { value: '2027-03-01' } })
    fireEvent.change(screen.getAllByLabelText('To')[0], { target: { value: '2027-03-18' } })
    fireEvent.change(screen.getByLabelText('First exam starts'), { target: { value: '10:00' } })
    fireEvent.change(screen.getByLabelText('Teachers per room'), { target: { value: '3' } })
    fireEvent.click(screen.getByRole('button', { name: 'Publish' }))
    await waitFor(() => expect(api.post).toHaveBeenCalledWith('/exams/generate', expect.objectContaining({ kind: 'final', start_time: '10:00', duration_minutes: 120, invigilators: '3', dry_run: false })))
  })

  it('filters the list by exam type and a final exam can only get a custom time', async () => {
    wrap(<ExamsAdminPage />)
    await screen.findByText('Maths')
    fireEvent.change(screen.getByLabelText('Show'), { target: { value: 'final' } })
    await waitFor(() => expect(api.get).toHaveBeenCalledWith('/exams', { params: { kind: 'final' } }))
    fireEvent.click((await screen.findAllByRole('button', { name: 'Edit' }))[0])
    expect(await screen.findByLabelText('Starts')).toBeInTheDocument()
    expect(screen.queryByRole('option', { name: 'By periods (replaces lessons)' })).not.toBeInTheDocument()
  })
})

describe('changing or deleting all exams at once', () => {
  beforeEach(() => {
    api.get.mockImplementation((url) => Promise.resolve({ data: { term_id: 1, data: url === '/terms' ? [{ id: 1, name: 'Term 1' }] : url === '/grade-levels' ? [{ id: 1, name_ar: 'الأول', name_en: 'Grade 1' }] : url === '/payroll/staff' ? [] : [session(1, '2027-01-03', 'Maths'), session(2, '2027-01-04', 'Arabic')] } }))
  })

  it('deletes everything shown after a confirmation, for the chosen type', async () => {
    api.delete.mockResolvedValue({ data: { data: { deleted: 2 } } })
    vi.spyOn(window, 'confirm').mockReturnValue(true)
    wrap(<ExamsAdminPage />)
    await screen.findByText('2 exams shown')
    fireEvent.change(screen.getByLabelText('Show'), { target: { value: 'monthly-1' } })
    await waitFor(() => expect(api.get).toHaveBeenCalledWith('/exams', { params: { kind: 'monthly', round: '1' } }))
    fireEvent.click(await screen.findByRole('button', { name: 'Delete all shown' }))
    expect(window.confirm).toHaveBeenCalledWith('Delete all 2 exams shown? This cannot be undone.')
    await waitFor(() => expect(api.delete).toHaveBeenCalledWith('/exams', { params: { term_id: 1, kind: 'monthly', round: 1 } }))
  })

  it('does not delete when the confirmation is refused', async () => {
    vi.spyOn(window, 'confirm').mockReturnValue(false)
    wrap(<ExamsAdminPage />)
    fireEvent.click(await screen.findByRole('button', { name: 'Delete all shown' }))
    expect(api.delete).not.toHaveBeenCalled()
  })

  it('moves all the shown exams by days and by periods in one request', async () => {
    api.put.mockResolvedValue({ data: { data: { changed: 2 } } })
    wrap(<ExamsAdminPage />)
    fireEvent.click(await screen.findByRole('button', { name: 'Edit all shown' }))
    fireEvent.change(await screen.findByLabelText('Move all dates by (days, e.g. 7 or -7)'), { target: { value: '7' } })
    fireEvent.change(screen.getByLabelText('From period'), { target: { value: '3' } })
    fireEvent.click(screen.getByRole('button', { name: 'Apply to all' }))
    await waitFor(() => expect(api.put).toHaveBeenCalledWith('/exams', { shift_days: 7, period_from: 3, period_to: 3 }, { params: { term_id: 1 } }))
  })

  it('shows why nothing changed when a clash is found', async () => {
    api.put.mockRejectedValue({ response: { data: { errors: { shift_days: ['Nothing was changed: 1-A has two exams at the same time on 2027-01-10.'] } } } })
    wrap(<ExamsAdminPage />)
    fireEvent.click(await screen.findByRole('button', { name: 'Edit all shown' }))
    fireEvent.change(await screen.findByLabelText('Move all dates by (days, e.g. 7 or -7)'), { target: { value: '-7' } })
    fireEvent.click(screen.getByRole('button', { name: 'Apply to all' }))
    expect(await screen.findByRole('alert')).toHaveTextContent('Nothing was changed: 1-A has two exams')
  })
})

describe('a teacher who watches only some periods', () => {
  it('shows the periods next to the name for admins and the own window for the teacher', async () => {
    auth.user = { role: 'teacher', id: 9 }
    const exam = session(1, '2027-01-03', 'Maths', { invigilators: [{ id: 9, name: 'Sara Ali', period_from: 3, period_to: 3, starts_at: '09:25', ends_at: '10:10' }, { id: 10, name: 'Omar', period_from: 2, period_to: 2, starts_at: '08:35', ends_at: '09:20' }] })
    api.get.mockResolvedValue({ data: { data: { watching: [exam], teaching: [] } } })
    wrap(<MyExamsPage />)
    expect(await screen.findByText('09:25 – 10:10')).toBeInTheDocument() // her own period, not the whole exam
    expect(screen.queryByText('08:35 – 10:10')).not.toBeInTheDocument()
    expect(screen.getByText('Period 3')).toBeInTheDocument()
  })
})

describe('what students, parents and teachers see', () => {
  it('lists a student\'s exams by day', async () => {
    auth.user = { role: 'student' }
    api.get.mockResolvedValue({ data: { data: [session(1, '2027-01-03', 'Maths'), session(2, '2027-01-04', 'Arabic')] } })
    wrap(<MyExamsPage />)
    expect(await screen.findByText('Maths')).toBeInTheDocument()
    expect(screen.getByText('Arabic')).toBeInTheDocument()
    expect(screen.getAllByText('08:35 – 10:10')).toHaveLength(2)
    expect(screen.getAllByText('Periods 2–3')).toHaveLength(2)
    expect(screen.getByText('The lessons of these periods are replaced by the exam.')).toBeInTheDocument()
  })

  it('shows a teacher the rooms they watch and the exams of their classes', async () => {
    auth.user = { role: 'teacher' }
    api.get.mockResolvedValue({ data: { data: { watching: [session(1, '2027-01-03', 'Maths')], teaching: [] } } })
    wrap(<MyExamsPage />)
    expect(await screen.findByText('Rooms I watch')).toBeInTheDocument()
    expect(screen.getByText('Maths')).toBeInTheDocument()
    expect(screen.getByText('No exams for your classes yet.')).toBeInTheDocument()
  })

  it('shows a parent the exams of the chosen child', async () => {
    auth.user = { role: 'parent', children: [{ id: 5, name_ar: 'علي', name_en: 'Ali' }] }
    api.get.mockResolvedValue({ data: { data: [session(1, '2027-01-03', 'Maths')] } })
    wrap(<ChildExamsPage />, '/parent/children/5/exams', '/parent/children/:id/exams')
    expect(await screen.findByRole('heading', { name: 'Exams of Ali' })).toBeInTheDocument()
    expect(api.get).toHaveBeenCalledWith('/students/5/exams')
  })
})
