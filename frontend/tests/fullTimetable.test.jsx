import { fireEvent, render, screen, within } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { MemoryRouter } from 'react-router-dom'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import i18n from '../src/i18n'
import { api } from '../src/api/client'
import FullTimetablePage from '../src/features/Scheduling/pages/FullTimetablePage'

vi.mock('../src/api/client', () => ({ api: { get: vi.fn() } }))
const wrap = () => render(<QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}><MemoryRouter><FullTimetablePage /></MemoryRouter></QueryClientProvider>)

const sub = (id, en) => ({ id, name_en: en, name_ar: en })
const overview = { data: { data: {
  periods: [{ id: 1, day_of_week: 0, number: 1, starts_at: '08:00', ends_at: '08:45' }, { id: 2, day_of_week: 0, number: 2, starts_at: '08:50', ends_at: '09:35' }, { id: 3, day_of_week: 1, number: 1, starts_at: '08:00', ends_at: '08:45' }],
  classrooms: [{ id: 10, name: '7-A', grade_level_id: 1, grade_en: 'Grade 7', grade_ar: 'x', periods_per_day: 6, homeroom: 'Mr H' }, { id: 11, name: '8-A', grade_level_id: 2, grade_en: 'Grade 8', grade_ar: 'y', periods_per_day: 6, homeroom: null }],
  entries: [{ id: 1, classroom_id: 10, period_id: 1, subject: sub(1, 'Math'), teacher: { id: 5, name: 'Sara' } }, { id: 2, classroom_id: 11, period_id: 2, subject: sub(2, 'Art'), teacher: { id: 5, name: 'Sara' } }, { id: 3, classroom_id: 11, period_id: 1, subject: sub(3, 'Science'), teacher: { id: 6, name: 'Omar' } }],
} } }
const checkData = { data: { data: { counts: { error: 1, warning: 1, info: 0 }, issues: [
  { type: 'teacher_unavailable', severity: 'error', classroom_id: 10, teacher_id: 5, period_id: 1, params: { classroom: '7-A', teacher: 'Sara', day: 0, period: 1 } },
  { type: 'periods_missing', severity: 'warning', classroom_id: 11, subject_id: 2, teacher_id: 5, params: { classroom: '8-A', subject: 'Art', count: 2, placed: 1, planned: 3 } }] } } }

beforeEach(async () => {
  vi.resetAllMocks()
  await i18n.changeLanguage('en')
  api.get.mockImplementation(async (url) => (url === '/timetable/overview' ? overview : checkData))
})

describe('FullTimetablePage', () => {
  it('shows every section in one table with the checker summary and its problems', async () => {
    wrap()
    expect(await screen.findByRole('status')).toHaveTextContent('1 errors, 1 warnings')
    const tables = await screen.findAllByRole('table')
    expect(tables).toHaveLength(2) // Sunday and Monday
    expect(within(tables[0]).getByText('7-A')).toBeInTheDocument()
    expect(within(tables[0]).getByText('8-A')).toBeInTheDocument()
    expect(await screen.findByText(/Sara has a lesson in 7-A \(Sunday, period 1\) while unavailable/)).toBeInTheDocument()
    expect(screen.getByText(/8-A · Art: 2 lesson\(s\) still to place/)).toBeInTheDocument()
  })

  it('shows one day, swaps to teachers as columns and highlights a teacher', async () => {
    wrap()
    await screen.findAllByRole('table')
    fireEvent.click(screen.getAllByRole('tab', { name: 'Sunday' })[0]) // the first is the grid's own day tab; the phone view has one too
    expect(screen.getAllByRole('table')).toHaveLength(1)
    fireEvent.click(screen.getByRole('tab', { name: 'By teachers' }))
    const table = screen.getByRole('table')
    expect(within(table).getByText('Omar')).toBeInTheDocument()
    expect(within(table).getByText('Sara')).toBeInTheDocument()
    fireEvent.change(screen.getByLabelText('Highlight a teacher'), { target: { value: '6' } })
    expect(screen.getByLabelText('Highlight a teacher')).toHaveValue('6')
  })

  describe('on a phone', () => {
    it('picks a section in a row of pills and a day, and lists that day as one card per period with time, subject, teacher and room', async () => {
      overview.data.data.entries[0].room = 'R-12'
      wrap()
      const phone = within(await screen.findByTestId('phone-timetable'))
      fireEvent.click(phone.getByRole('tab', { name: 'Sunday' }))
      // The first section (7-A) is chosen by default: period 1 is Math with Sara in room R-12, period 2 is free.
      const lessons = phone.getAllByRole('listitem')
      expect(lessons).toHaveLength(2)
      expect(within(lessons[0]).getByText('Math')).toBeInTheDocument()
      expect(within(lessons[0]).getByText('Sara')).toBeInTheDocument()
      expect(within(lessons[0]).getByText('R-12')).toBeInTheDocument()
      expect(within(lessons[0]).getByText('08:00')).toBeInTheDocument()
      expect(within(lessons[0]).getByText('08:45')).toBeInTheDocument()
      expect(within(lessons[1]).getByText('Free period')).toBeInTheDocument()
      // Another section: 8-A has Science (Omar) in period 1 and Art (Sara) in period 2.
      fireEvent.click(phone.getByRole('tab', { name: '8-A' }))
      expect(within(phone.getAllByRole('listitem')[0]).getByText('Science')).toBeInTheDocument()
      expect(within(phone.getAllByRole('listitem')[1]).getByText('Art')).toBeInTheDocument()
      delete overview.data.data.entries[0].room
    })

    it('marks a lesson that has a problem, swaps to teachers, and can show only the lessons with problems', async () => {
      wrap()
      const phone = within(await screen.findByTestId('phone-timetable'))
      fireEvent.click(phone.getByRole('tab', { name: 'Sunday' }))
      expect(phone.getAllByRole('listitem')[0]).toHaveClass('border-s-red-500') // Sara is unavailable in 7-A period 1
      fireEvent.click(screen.getByRole('tab', { name: 'By teachers' }))
      fireEvent.click(phone.getByRole('tab', { name: 'Sara' }))
      expect(within(phone.getAllByRole('listitem')[0]).getByText('7-A')).toBeInTheDocument() // a teacher's card names the section
      fireEvent.click(screen.getByLabelText('Show only lessons with problems'))
      expect(phone.getAllByRole('listitem')).toHaveLength(1)
    })
  })
})
