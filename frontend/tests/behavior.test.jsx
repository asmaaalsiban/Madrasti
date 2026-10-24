import { fireEvent, render, screen, waitFor, within } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import i18n from '../src/i18n'
import { api } from '../src/api/client'
import BehaviorPage from '../src/features/Behavior/pages/BehaviorPage'
import BehaviorCriteriaPage from '../src/features/Behavior/pages/BehaviorCriteriaPage'
import StudentBehaviorPage from '../src/features/Behavior/pages/StudentBehaviorPage'

vi.mock('../src/api/client', () => ({ api: { get: vi.fn(), post: vi.fn(), put: vi.fn(), delete: vi.fn() } }))
vi.mock('../src/features/Auth/AuthProvider', () => ({ useAuth: () => ({ user: { children: [{ id: 7, name_en: 'Lina', name_ar: 'لينا' }] } }) }))

const wrap = (ui, path = '/', route = '/') =>
  render(<QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}><MemoryRouter initialEntries={[path]}><Routes><Route path={route} element={ui} /></Routes></MemoryRouter></QueryClientProvider>)

const criteria = [{ id: 1, name_ar: 'الانضباط', name_en: 'Discipline', is_active: true }, { id: 2, name_ar: 'الاحترام', name_en: 'Respect', is_active: true }]

beforeEach(async () => {
  vi.resetAllMocks()
  await i18n.changeLanguage('en')
})

describe('BehaviorPage', () => {
  beforeEach(() => api.get.mockImplementation((url) => {
    if (url === '/behavior/classrooms') return Promise.resolve({ data: { data: [{ id: 3, name: '5-A' }] } })
    if (url === '/behavior/week') return Promise.resolve({ data: { data: { week_starts_on: '2026-10-18', students: [{ id: 9, name_ar: 'سلمى', name_en: 'Salma', evaluation: null }] } } })
    if (url === '/behavior/criteria') return Promise.resolve({ data: { data: criteria } })
    return Promise.resolve({ data: { data: [] } })
  }))

  it('evaluates a student: scores, note and the date', async () => {
    api.put.mockResolvedValue({ data: { data: {} } })
    wrap(<BehaviorPage base="/teacher" />)
    expect(await screen.findByText('Salma')).toBeInTheDocument()
    expect(screen.getByText('Week of 2026-10-18')).toBeInTheDocument()
    fireEvent.click(screen.getByRole('button', { name: 'Evaluate' }))
    const group = await screen.findByRole('group', { name: 'Discipline' })
    fireEvent.click(within(group).getByRole('button', { name: '5' }))
    fireEvent.change(screen.getByLabelText('Note'), { target: { value: 'Calm week' } })
    fireEvent.click(screen.getByRole('button', { name: 'Save' }))
    await waitFor(() => expect(api.put).toHaveBeenCalledWith('/behavior/evaluations', expect.objectContaining({ student_id: 9, scores: { 1: 5 }, note: 'Calm week', visible_to_parent: true, evaluated_on: expect.stringMatching(/^\d{4}-\d{2}-\d{2}$/) })))
  })

  it('adds a quick negative note with the date', async () => {
    api.post.mockResolvedValue({ data: { data: {} } })
    wrap(<BehaviorPage base="/teacher" />)
    fireEvent.click(await screen.findByRole('button', { name: 'Evaluate' }))
    await screen.findByRole('group', { name: 'Discipline' })
    fireEvent.change(screen.getByLabelText('Type'), { target: { value: 'negative' } })
    fireEvent.change(screen.getByLabelText('What happened'), { target: { value: 'Hit a classmate' } })
    fireEvent.click(screen.getByRole('button', { name: 'Add the note' }))
    await waitFor(() => expect(api.post).toHaveBeenCalledWith('/behavior/notes', expect.objectContaining({ student_id: 9, type: 'negative', note: 'Hit a classmate', noted_on: expect.any(String) })))
    expect(await screen.findByText('The note was added.')).toBeInTheDocument()
  })

  it('shows the reason when refused', async () => {
    api.put.mockRejectedValue({ response: { data: { errors: { scores: ['Give at least one score or write a note.'] } } } })
    wrap(<BehaviorPage base="/teacher" />)
    fireEvent.click(await screen.findByRole('button', { name: 'Evaluate' }))
    await screen.findByRole('group', { name: 'Discipline' })
    fireEvent.click(screen.getByRole('button', { name: 'Save' }))
    expect(await screen.findByRole('alert')).toHaveTextContent('at least one score')
  })

  it('offers the criteria page to the admin only', async () => {
    wrap(<BehaviorPage base="/admin" />)
    expect(await screen.findByRole('link', { name: 'Behaviour criteria' })).toHaveAttribute('href', '/admin/behavior-criteria')
  })
})

describe('BehaviorCriteriaPage', () => {
  it('adds, hides and removes criteria', async () => {
    api.get.mockResolvedValue({ data: { data: criteria } })
    api.post.mockResolvedValue({ data: { data: {} } })
    api.put.mockResolvedValue({ data: { data: {} } })
    api.delete.mockResolvedValue({})
    wrap(<BehaviorCriteriaPage />)
    await screen.findByText(/Discipline/)
    fireEvent.change(screen.getByLabelText('Name (Arabic)'), { target: { value: 'الصلاة' } })
    fireEvent.change(screen.getByLabelText('Name (English)'), { target: { value: 'Prayer' } })
    fireEvent.click(screen.getByRole('button', { name: 'Add' }))
    await waitFor(() => expect(api.post).toHaveBeenCalledWith('/behavior/criteria', { name_ar: 'الصلاة', name_en: 'Prayer' }))
    fireEvent.click(screen.getAllByRole('button', { name: 'Hide' })[0])
    await waitFor(() => expect(api.put).toHaveBeenCalledWith('/behavior/criteria/1', { name_ar: 'الانضباط', name_en: 'Discipline', is_active: false }))
    fireEvent.click(screen.getAllByRole('button', { name: 'Delete' })[1])
    await waitFor(() => expect(api.delete).toHaveBeenCalledWith('/behavior/criteria/2'))
  })
})

describe('StudentBehaviorPage', () => {
  const result = {
    criteria: criteria,
    weeks: [{ week_starts_on: '2026-10-18', evaluations: 1, criteria: { 1: 4, 2: 5 }, overall: 4.5 }],
    months: [{ month: '2026-10', weeks: 4, criteria: { 1: 3.25, 2: 4 }, overall: 3.63 }],
    terms: [{ term: { id: 1, name: 'First term' }, months: 2, weeks: 5, criteria: { 1: 4.13 }, overall: 4.13 }],
    remarks: [{ id: 1, evaluated_on: '2026-10-20', teacher: 'Sara', note: 'Calm week' }],
    notes: [{ id: 5, type: 'positive', note: 'Helped a friend', noted_on: '2026-10-21', teacher: 'Sara', visible_to_parent: true }],
  }

  it('shows the weekly, monthly and term tables built from each other, and the notes', async () => {
    api.get.mockResolvedValue({ data: { data: result } })
    wrap(<StudentBehaviorPage />, '/c/7', '/c/:id')
    expect(await screen.findByText('Week of 2026-10-18')).toBeInTheDocument()
    expect(screen.getByRole('heading', { level: 1 })).toHaveTextContent('Lina')
    expect(api.get).toHaveBeenCalledWith('/students/7/behavior')
    expect(screen.getByText('Calm week')).toBeInTheDocument()
    expect(screen.getByText(/Helped a friend/)).toBeInTheDocument()

    fireEvent.click(screen.getByRole('tab', { name: 'Monthly' }))
    expect(screen.getByText('2026-10 (4 weeks)')).toBeInTheDocument()
    expect(screen.getByText('Each month is the mean of its weekly evaluations.')).toBeInTheDocument()
    fireEvent.click(screen.getByRole('tab', { name: 'Term' }))
    expect(screen.getByText('First term (2 months)')).toBeInTheDocument()
    expect(screen.getByText('Each term is the mean of its months.')).toBeInTheDocument()
  })

  it('lets staff delete a note', async () => {
    api.get.mockResolvedValue({ data: { data: result } })
    api.delete.mockResolvedValue({})
    wrap(<StudentBehaviorPage staff />, '/s/7', '/s/:id')
    fireEvent.click(await screen.findByRole('button', { name: 'Delete' }))
    await waitFor(() => expect(api.delete).toHaveBeenCalledWith('/behavior/notes/5'))
  })

  it('says when there are no evaluations', async () => {
    api.get.mockResolvedValue({ data: { data: { ...result, weeks: [], months: [], terms: [], remarks: [], notes: [] } } })
    wrap(<StudentBehaviorPage />, '/c/7', '/c/:id')
    expect(await screen.findByText('No evaluations yet.')).toBeInTheDocument()
  })
})
