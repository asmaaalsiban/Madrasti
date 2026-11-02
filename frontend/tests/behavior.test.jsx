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

  it('adds a permanent criterion from the evaluation screen', async () => {
    api.post.mockResolvedValue({ data: { data: { id: 9 } } })
    wrap(<BehaviorPage base="/teacher" />)
    await screen.findByText('Salma')
    fireEvent.click(screen.getByRole('button', { name: 'Evaluate' }))
    fireEvent.click(await screen.findByRole('button', { name: 'Add a permanent criterion' }))
    fireEvent.change(screen.getByLabelText('Name (Arabic)'), { target: { value: 'القراءة' } })
    fireEvent.change(screen.getByLabelText('Name (English)'), { target: { value: 'Reading' } })
    fireEvent.click(screen.getByRole('button', { name: 'Save criterion' }))
    await waitFor(() => expect(api.post).toHaveBeenCalledWith('/behavior/criteria', { name_ar: 'القراءة', name_en: 'Reading' }))
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

describe('special evaluation (criteria for one student)', () => {
  const state = { special: [{ id: 41, name: 'Raises his hand', is_active: true }] }
  const serve = () => api.get.mockImplementation((url) => {
    if (url === '/behavior/classrooms') return Promise.resolve({ data: { data: [{ id: 3, name: '5-A' }] } })
    if (url === '/behavior/week') return Promise.resolve({ data: { data: { week_starts_on: '2026-10-18', students: [
      { id: 9, name_ar: 'سلمى', name_en: 'Salma', special_count: 1, evaluation: { id: 1, scores: { 1: 4 }, special_scores: { 41: 2 }, note: null, visible_to_parent: true } },
      { id: 10, name_ar: 'علي', name_en: 'Ali', special_count: 0, evaluation: null },
    ] } } })
    if (url === '/behavior/criteria') return Promise.resolve({ data: { data: criteria } })
    if (url === '/behavior/students/9/special') return Promise.resolve({ data: { data: state.special } })
    if (url === '/behavior/students/10/special') return Promise.resolve({ data: { data: [] } })
    return Promise.resolve({ data: { data: [] } })
  })
  beforeEach(() => { state.special = [{ id: 41, name: 'Raises his hand', is_active: true }]; serve() })

  it('marks the students who have their own criteria on the list', async () => {
    wrap(<BehaviorPage base="/teacher" />)
    expect(await screen.findByText('★ 1 special')).toBeInTheDocument()
    expect(screen.getAllByText(/★/)).toHaveLength(1) // not shown for the other student
  })

  it('shows the special section under the general criteria with this student\'s criteria and their saved scores', async () => {
    wrap(<BehaviorPage base="/teacher" />)
    fireEvent.click((await screen.findAllByRole('button', { name: 'Evaluate' }))[0])
    expect(await screen.findByText('Special evaluation: Salma')).toBeInTheDocument()
    expect(screen.getByText(/for this student only/i)).toBeInTheDocument()
    const group = await screen.findByRole('group', { name: 'Raises his hand' })
    expect(within(group).getByRole('button', { name: '2' })).toHaveAttribute('aria-pressed', 'true')
    expect(api.get).toHaveBeenCalledWith('/behavior/students/9/special')
  })

  it('saves the special scores together with the general ones', async () => {
    api.put.mockResolvedValue({ data: { data: {} } })
    wrap(<BehaviorPage base="/teacher" />)
    fireEvent.click((await screen.findAllByRole('button', { name: 'Evaluate' }))[0])
    const group = await screen.findByRole('group', { name: 'Raises his hand' })
    fireEvent.click(within(group).getByRole('button', { name: '5' }))
    fireEvent.click(screen.getByRole('button', { name: 'Save' }))
    await waitFor(() => expect(api.put).toHaveBeenCalledWith('/behavior/evaluations', expect.objectContaining({ student_id: 9, scores: { 1: 4 }, special_scores: { 41: 5 } })))
  })

  it('adds a new special criterion for the student', async () => {
    state.special = []
    api.post.mockImplementation(() => { state.special = [{ id: 50, name: 'Stays in his seat', is_active: true }]; return Promise.resolve({ data: { data: state.special[0] } }) })
    wrap(<BehaviorPage base="/teacher" />)
    fireEvent.click((await screen.findAllByRole('button', { name: 'Evaluate' }))[0])
    expect(await screen.findByText('No special criteria yet. Add one below.')).toBeInTheDocument()
    fireEvent.change(screen.getByLabelText(/New special criterion/), { target: { value: 'Stays in his seat' } })
    fireEvent.click(screen.getByRole('button', { name: 'Add' }))
    await waitFor(() => expect(api.post).toHaveBeenCalledWith('/behavior/students/9/special', { name: 'Stays in his seat' }))
    expect(await screen.findByRole('group', { name: 'Stays in his seat' })).toBeInTheDocument()
  })

  it('removes a special criterion and drops its score', async () => {
    api.delete.mockResolvedValue({})
    api.put.mockResolvedValue({ data: { data: {} } })
    wrap(<BehaviorPage base="/teacher" />)
    fireEvent.click((await screen.findAllByRole('button', { name: 'Evaluate' }))[0])
    await screen.findByRole('group', { name: 'Raises his hand' })
    state.special = []
    fireEvent.click(screen.getByRole('button', { name: 'Remove Raises his hand' }))
    await waitFor(() => expect(api.delete).toHaveBeenCalledWith('/behavior/special/41'))
    await waitFor(() => expect(screen.queryByRole('group', { name: 'Raises his hand' })).not.toBeInTheDocument())
    fireEvent.click(screen.getByRole('button', { name: 'Save' }))
    await waitFor(() => expect(api.put).toHaveBeenCalledWith('/behavior/evaluations', expect.objectContaining({ special_scores: {} })))
  })

  it('shows the message when a name is refused', async () => {
    api.post.mockRejectedValue({ response: { data: { errors: { name: ['This student already has a special criterion with this name.'] } } } })
    wrap(<BehaviorPage base="/teacher" />)
    fireEvent.click((await screen.findAllByRole('button', { name: 'Evaluate' }))[0])
    fireEvent.change(await screen.findByLabelText(/New special criterion/), { target: { value: 'Raises his hand' } })
    fireEvent.click(screen.getByRole('button', { name: 'Add' }))
    expect(await screen.findByRole('alert')).toHaveTextContent('already has a special criterion')
  })

  it('shows the results of each special criterion to the family and the staff', async () => {
    api.get.mockResolvedValue({ data: { data: {
      criteria, weeks: [], months: [], terms: [], remarks: [], notes: [],
      special: [{ id: 41, name: 'Raises his hand', is_active: true, average: 3.5, history: [{ week_starts_on: '2026-10-11', score: 3, teacher: 'Sara' }, { week_starts_on: '2026-10-18', score: 4, teacher: 'Sara' }] }],
    } } })
    wrap(<StudentBehaviorPage />, '/c/7', '/c/:id')
    expect(await screen.findByText('Special evaluation')).toBeInTheDocument()
    expect(screen.getByText('Raises his hand')).toBeInTheDocument()
    expect(screen.getByText('Average 3.5')).toBeInTheDocument()
    const history = screen.getByLabelText('Scores of Raises his hand')
    expect(within(history).getAllByRole('listitem')).toHaveLength(2)
  })
})
