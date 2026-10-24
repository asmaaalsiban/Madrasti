import { fireEvent, render, screen, waitFor } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import i18n from '../src/i18n'
import { api } from '../src/api/client'
import LessonPlansPage from '../src/features/LessonPlans/pages/LessonPlansPage'
import ReviewPlansPage from '../src/features/LessonPlans/pages/ReviewPlansPage'
import { ChildPlansPage, StudentPlansPage } from '../src/features/LessonPlans/pages/PlansPages'

vi.mock('../src/api/client', () => ({ api: { get: vi.fn(), post: vi.fn(), put: vi.fn(), delete: vi.fn() } }))
vi.mock('../src/features/Auth/AuthProvider', () => ({ useAuth: () => ({ user: { children: [{ id: 7, name_en: 'Lina', name_ar: 'لينا' }] } }) }))

const wrap = (ui, path = '/', route = '/') =>
  render(<QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}><MemoryRouter initialEntries={[path]}><Routes><Route path={route} element={ui} /></Routes></MemoryRouter></QueryClientProvider>)

const math = { id: 5, name_en: 'Math', name_ar: 'رياضيات' }
const plan = (id, status, extra = {}) => ({ id, status, title: `Plan ${id}`, objectives: 'Add fractions', content: 'Monday...', week_starts_on: '2026-10-18', shared_with_parents: true, review_note: null, classroom: { id: 3, name: '5-A' }, subject: math, teacher: { id: 9, name: 'Sara' }, ...extra })

beforeEach(async () => {
  vi.resetAllMocks()
  await i18n.changeLanguage('en')
})

describe('LessonPlansPage (teacher)', () => {
  const serve = (mine = []) => api.get.mockImplementation((url) => Promise.resolve({ data: { data: url === '/lesson-plans' ? mine : { assignments: [{ classroom: { id: 3, name: '5-A' }, subject: math }] } } }))

  it('saves a draft for a week', async () => {
    serve()
    api.put.mockResolvedValue({ data: { data: {} } })
    wrap(<LessonPlansPage />)
    await screen.findByRole('option', { name: '5-A · Math' })
    fireEvent.change(screen.getByLabelText('Title'), { target: { value: 'Fractions' } })
    fireEvent.change(screen.getByLabelText('Objectives'), { target: { value: 'Add fractions' } })
    fireEvent.click(screen.getByRole('button', { name: 'Save draft' }))
    await waitFor(() => expect(api.put).toHaveBeenCalledWith('/lesson-plans', expect.objectContaining({ classroom_id: 3, subject_id: 5, title: 'Fractions', objectives: 'Add fractions', content: null, shared_with_parents: true })))
    expect(await screen.findByRole('status')).toHaveTextContent('Draft saved.')
  })

  it('submits a draft, edits a rejected plan with its reason, and hides the buttons of a plan under review', async () => {
    serve([plan(1, 'draft'), plan(2, 'rejected', { review_note: 'Add objectives' }), plan(3, 'submitted'), plan(4, 'approved')])
    api.post.mockResolvedValue({ data: { data: {} } })
    wrap(<LessonPlansPage />)
    expect(await screen.findByText('Rejected: Add objectives')).toBeInTheDocument()
    expect(screen.getAllByRole('button', { name: 'Submit for approval' })).toHaveLength(2)
    fireEvent.click(screen.getAllByRole('button', { name: 'Submit for approval' })[0])
    await waitFor(() => expect(api.post).toHaveBeenCalledWith('/lesson-plans/1/submit'))
    fireEvent.click(screen.getAllByRole('button', { name: 'Edit' })[1])
    expect(screen.getByLabelText('Title')).toHaveValue('Plan 2')
  })

  it('deletes a draft and shows the reason when refused', async () => {
    serve([plan(1, 'draft')])
    api.delete.mockResolvedValue({})
    api.put.mockRejectedValue({ response: { data: { errors: { title: ['This plan is waiting for review or already approved, so it cannot be changed.'] } } } })
    wrap(<LessonPlansPage />)
    await screen.findByText('Plan 1')
    fireEvent.change(screen.getByLabelText('Title'), { target: { value: 'x' } })
    fireEvent.click(screen.getByRole('button', { name: 'Save draft' }))
    expect(await screen.findByRole('alert')).toHaveTextContent('cannot be changed')
    fireEvent.click(screen.getByRole('button', { name: 'Delete' }))
    fireEvent.click(screen.getAllByRole('button', { name: 'Delete' }).at(-1))
    await waitFor(() => expect(api.delete).toHaveBeenCalledWith('/lesson-plans/1'))
  })
})

describe('ReviewPlansPage (admin)', () => {
  it('approves a plan and rejects another with a note', async () => {
    api.get.mockResolvedValue({ data: { data: [plan(1, 'submitted'), plan(2, 'submitted')] } })
    api.post.mockResolvedValue({ data: { data: {} } })
    wrap(<ReviewPlansPage />)
    await screen.findByText('Plan 1')
    fireEvent.click(screen.getAllByRole('button', { name: 'Approve' })[0])
    await waitFor(() => expect(api.post).toHaveBeenCalledWith('/lesson-plans/1/review', { decision: 'approve', note: null }))
    fireEvent.click(screen.getAllByRole('button', { name: 'Reject' })[1])
    fireEvent.change(screen.getByLabelText('Why (the teacher will see it)'), { target: { value: 'Add objectives' } })
    fireEvent.click(screen.getAllByRole('button', { name: 'Reject' }).at(-1))
    await waitFor(() => expect(api.post).toHaveBeenCalledWith('/lesson-plans/2/review', { decision: 'reject', note: 'Add objectives' }))
  })

  it('says when nothing waits', async () => {
    api.get.mockResolvedValue({ data: { data: [] } })
    wrap(<ReviewPlansPage />)
    expect(await screen.findByText('No plans are waiting for review.')).toBeInTheDocument()
  })
})

describe('plans for students and parents', () => {
  it('shows the student and the parent the approved plans without status or review notes', async () => {
    const shared = { ...plan(1, undefined), status: undefined }
    api.get.mockResolvedValue({ data: { data: [shared] } })
    wrap(<StudentPlansPage />)
    expect(await screen.findByText('Plan 1')).toBeInTheDocument()
    expect(api.get).toHaveBeenCalledWith('/lesson-plans/me')
    expect(screen.queryByText('Approved')).not.toBeInTheDocument()
  })

  it('shows the parent one child and says when there are none', async () => {
    api.get.mockResolvedValue({ data: { data: [] } })
    wrap(<ChildPlansPage />, '/c/7', '/c/:id')
    expect(await screen.findByText('No lesson plans yet.')).toBeInTheDocument()
    expect(api.get).toHaveBeenCalledWith('/students/7/lesson-plans')
    expect(screen.getByRole('heading', { level: 1 })).toHaveTextContent('Lina')
  })
})
