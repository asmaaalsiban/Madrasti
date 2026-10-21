import { fireEvent, render, screen, waitFor, within } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import i18n from '../src/i18n'
import { api } from '../src/api/client'
import AssessmentsPage from '../src/features/Grading/pages/AssessmentsPage'
import ScoreSheetPage from '../src/features/Grading/pages/ScoreSheetPage'

vi.mock('../src/api/client', () => ({
  api: { get: vi.fn(), post: vi.fn(), put: vi.fn(), delete: vi.fn() },
}))

const renderAt = (path, ui, route = path) =>
  render(
    <QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}>
      <MemoryRouter initialEntries={[path]}>
        <Routes><Route path={route} element={ui} /></Routes>
      </MemoryRouter>
    </QueryClientProvider>,
  )

const options = {
  assignments: [
    { classroom: { id: 3, name: '5-A' }, subject: { id: 7, name_ar: 'الرياضيات', name_en: 'Math' } },
    { classroom: { id: 4, name: '5-B' }, subject: { id: 7, name_ar: 'الرياضيات', name_en: 'Math' } },
  ],
  terms: [{ id: 1, name: 'First term' }, { id: 2, name: 'Second term' }],
}
const quiz = { id: 11, title: 'Quiz 1', type: 'quiz', max_score: 20, weight: 10, held_on: '2026-10-05', is_published: false, scored: 2, students: 3 }
const exam = { id: 12, title: 'Midterm', type: 'exam', max_score: 100, weight: 40, held_on: '2026-11-05', is_published: true, scored: 3, students: 3 }

const serve = (rows = [quiz, exam]) => {
  api.get.mockImplementation((url) => {
    if (url === '/grading/options') return Promise.resolve({ data: { data: options } })
    if (url === '/assessments') return Promise.resolve({ data: { data: rows } })
    return Promise.resolve({ data: { data: [] } })
  })
}

beforeEach(async () => {
  vi.resetAllMocks()
  await i18n.changeLanguage('en')
})

describe('AssessmentsPage', () => {
  it('lists the assessments of the first classroom, subject and term with progress and status', async () => {
    serve()
    renderAt('/teacher/grades', <AssessmentsPage />)
    const row = await screen.findByRole('row', { name: /Quiz 1/ })
    expect(within(row).getByText('2 / 3')).toBeInTheDocument()
    expect(within(row).getByText('Draft')).toBeInTheDocument()
    expect(within(screen.getByRole('row', { name: /Midterm/ })).getByText('Published')).toBeInTheDocument()
    expect(screen.getByText('Weights used: 50 / 100')).toBeInTheDocument()
    expect(api.get).toHaveBeenCalledWith('/assessments', { params: { classroom_id: 3, subject_id: 7, term_id: 1 } })
    expect(within(row).getByRole('link', { name: 'Enter scores' })).toHaveAttribute('href', '/teacher/grades/11')
  })

  it('reloads when another classroom or term is picked', async () => {
    serve()
    renderAt('/teacher/grades', <AssessmentsPage />)
    await screen.findByRole('row', { name: /Quiz 1/ })
    fireEvent.change(screen.getByLabelText('Classroom and subject'), { target: { value: '1' } })
    await waitFor(() => expect(api.get).toHaveBeenCalledWith('/assessments', { params: { classroom_id: 4, subject_id: 7, term_id: 1 } }))
    fireEvent.change(screen.getByLabelText('Term'), { target: { value: '2' } })
    await waitFor(() => expect(api.get).toHaveBeenCalledWith('/assessments', { params: { classroom_id: 4, subject_id: 7, term_id: 2 } }))
  })

  it('creates an assessment for the picked classroom, subject and term', async () => {
    serve([])
    api.post.mockResolvedValue({ data: { data: { id: 20 } } })
    renderAt('/teacher/grades', <AssessmentsPage />)
    await screen.findByText('Nothing to show yet.')
    fireEvent.click(screen.getByRole('button', { name: 'Add assessment' }))
    fireEvent.change(screen.getByLabelText('Title'), { target: { value: 'Quiz 2' } })
    fireEvent.change(screen.getByLabelText('Type'), { target: { value: 'exam' } })
    fireEvent.change(screen.getByLabelText('Maximum score'), { target: { value: '50' } })
    fireEvent.change(screen.getByLabelText('Weight (%)'), { target: { value: '25' } })
    fireEvent.change(screen.getByLabelText('Date'), { target: { value: '2026-10-20' } })
    fireEvent.click(screen.getByRole('button', { name: 'Save' }))
    await waitFor(() => expect(api.post).toHaveBeenCalledWith('/assessments', {
      classroom_id: 3, subject_id: 7, term_id: 1, title: 'Quiz 2', type: 'exam', max_score: '50', weight: '25', held_on: '2026-10-20',
    }))
  })

  it('shows the server error for a weight that is too high', async () => {
    serve([])
    api.post.mockRejectedValue({ response: { status: 422, data: { errors: { weight: ['The weights of this subject in this term would exceed 100% (already used: 90%).'] } } } })
    renderAt('/teacher/grades', <AssessmentsPage />)
    await screen.findByText('Nothing to show yet.')
    fireEvent.click(screen.getByRole('button', { name: 'Add assessment' }))
    fireEvent.click(screen.getByRole('button', { name: 'Save' }))
    expect(await screen.findByText(/would exceed 100%/)).toBeInTheDocument()
  })

  it('publishes a draft and unpublishes a published assessment', async () => {
    serve()
    api.post.mockResolvedValue({ data: { data: {} } })
    renderAt('/teacher/grades', <AssessmentsPage />)
    fireEvent.click(await within(await screen.findByRole('row', { name: /Quiz 1/ })).findByRole('button', { name: 'Publish' }))
    await waitFor(() => expect(api.post).toHaveBeenCalledWith('/assessments/11/publish'))
    fireEvent.click(within(screen.getByRole('row', { name: /Midterm/ })).getByRole('button', { name: 'Unpublish' }))
    await waitFor(() => expect(api.post).toHaveBeenCalledWith('/assessments/12/unpublish'))
  })

  it('deletes a draft only after confirmation and hides delete for a published one', async () => {
    serve()
    api.delete.mockResolvedValue({})
    renderAt('/teacher/grades', <AssessmentsPage />)
    const row = await screen.findByRole('row', { name: /Quiz 1/ })
    expect(within(screen.getByRole('row', { name: /Midterm/ })).queryByRole('button', { name: 'Delete' })).not.toBeInTheDocument()
    fireEvent.click(within(row).getByRole('button', { name: 'Delete' }))
    expect(api.delete).not.toHaveBeenCalled()
    fireEvent.click(within(screen.getByRole('dialog')).getByRole('button', { name: 'Delete' }))
    await waitFor(() => expect(api.delete).toHaveBeenCalledWith('/assessments/11'))
  })

  it('explains when the teacher has no subject assigned', async () => {
    api.get.mockResolvedValue({ data: { data: { assignments: [], terms: [] } } })
    renderAt('/teacher/grades', <AssessmentsPage />)
    expect(await screen.findByText('No subject is assigned to you yet. Ask the administration to assign your classrooms.')).toBeInTheDocument()
  })

  it('links to the admin score sheet under the admin area and has Arabic labels', async () => {
    await i18n.changeLanguage('ar')
    serve()
    renderAt('/admin/grades', <AssessmentsPage />)
    const row = await screen.findByRole('row', { name: /Quiz 1/ })
    expect(within(row).getByRole('link', { name: 'إدخال العلامات' })).toHaveAttribute('href', '/admin/grades/11')
    expect(screen.getByRole('heading', { name: 'العلامات' })).toBeInTheDocument()
  })
})

describe('ScoreSheetPage', () => {
  const sheet = (published = false) => ({
    assessment: { ...quiz, is_published: published, classroom: { id: 3, name: '5-A' }, subject: { id: 7, name_ar: 'الرياضيات', name_en: 'Math' }, term: { id: 1, name: 'First term' } },
    students: [
      { id: 21, student_number: 'S-1', name_ar: 'أحمد', name_en: 'Ahmad', score: 18, remark: null },
      { id: 22, student_number: 'S-2', name_ar: 'ليلى', name_en: 'Layla', score: null, remark: null },
      { id: 23, student_number: 'S-3', name_ar: 'يوسف', name_en: 'Yousef', score: 14, remark: 'Good' },
    ],
  })
  const open = (published) => {
    api.get.mockResolvedValue({ data: { data: sheet(published) } })
    renderAt('/teacher/grades/11', <ScoreSheetPage />, '/teacher/grades/:id')
  }
  const input = (name) => within(screen.getByRole('group', { name })).getByLabelText('Score')

  it('shows every student with their score and the average of the entered scores', async () => {
    open()
    expect(await screen.findByRole('heading', { name: 'Quiz 1' })).toBeInTheDocument()
    expect(input('Ahmad')).toHaveValue(18)
    expect(input('Layla')).toHaveValue(null)
    expect(screen.getByText('Entered: 2 / 3 · Average: 16 / 20')).toBeInTheDocument()
  })

  it('saves all scores, an empty box meaning not graded', async () => {
    open()
    api.put.mockResolvedValue({ data: { message: 'ok' } })
    await screen.findByRole('group', { name: 'Layla' })
    fireEvent.change(input('Layla'), { target: { value: '12.5' } })
    fireEvent.change(input('Ahmad'), { target: { value: '' } })
    fireEvent.change(within(screen.getByRole('group', { name: 'Yousef' })).getByLabelText('Remark'), { target: { value: 'Great' } })
    fireEvent.click(screen.getByRole('button', { name: 'Save' }))

    await waitFor(() => expect(api.put).toHaveBeenCalledWith('/assessments/11/scores', { scores: [
      { student_id: 21, score: null, remark: null },
      { student_id: 22, score: 12.5, remark: null },
      { student_id: 23, score: 14, remark: 'Great' },
    ] }))
    expect(await screen.findByText('Scores saved.')).toBeInTheDocument()
  })

  it('warns about a score above the maximum without calling the server', async () => {
    open()
    await screen.findByRole('group', { name: 'Layla' })
    fireEvent.change(input('Layla'), { target: { value: '25' } })
    fireEvent.click(screen.getByRole('button', { name: 'Save' }))
    expect(await screen.findByRole('alert')).toHaveTextContent('A score cannot be higher than 20.')
    expect(api.put).not.toHaveBeenCalled()
  })

  it('shows the server message when saving fails', async () => {
    open()
    api.put.mockRejectedValue({ response: { status: 422, data: { message: 'The given data was invalid.' } } })
    await screen.findByRole('group', { name: 'Layla' })
    fireEvent.click(screen.getByRole('button', { name: 'Save' }))
    expect(await screen.findByRole('alert')).toHaveTextContent('The given data was invalid.')
  })

  it('publishes the assessment from the sheet', async () => {
    open(false)
    api.post.mockResolvedValue({ data: { data: {} } })
    fireEvent.click(await screen.findByRole('button', { name: 'Publish' }))
    await waitFor(() => expect(api.post).toHaveBeenCalledWith('/assessments/11/publish'))
  })

  it('uses Arabic names', async () => {
    await i18n.changeLanguage('ar')
    open()
    expect(await screen.findByRole('group', { name: 'أحمد' })).toBeInTheDocument()
  })
})
