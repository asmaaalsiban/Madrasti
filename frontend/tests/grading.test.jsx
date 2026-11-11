import { fireEvent, render, screen, waitFor, within } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import i18n from '../src/i18n'
import { api } from '../src/api/client'
import AssessmentsPage from '../src/features/Grading/pages/AssessmentsPage'
import ResultsPage from '../src/features/Grading/pages/ResultsPage'
import ScoreSheetPage from '../src/features/Grading/pages/ScoreSheetPage'

vi.mock('../src/api/client', () => ({
  api: { get: vi.fn(), post: vi.fn(), put: vi.fn(), delete: vi.fn() },
}))

const renderAt = (path, ui, route = path.split('?')[0]) =>
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
    { classroom: { id: 3, name: '5-A' }, subject: { id: 8, name_ar: 'العلوم', name_en: 'Science' } },
    { classroom: { id: 4, name: '5-B' }, subject: { id: 7, name_ar: 'الرياضيات', name_en: 'Math' } },
    { classroom: { id: 4, name: '5-B' }, subject: { id: 9, name_ar: 'الفنية', name_en: 'Art' } },
  ],
  terms: [{ id: 1, name: 'First term' }, { id: 2, name: 'Second term' }],
}
const quiz = { id: 11, title: 'Quiz 1', type: 'quiz', max_score: 20, weight: 10, held_on: '2026-10-05', is_published: false, scored: 2, students: 3, absent: 1 }
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

  it('has a card per assessment for a phone: title, type, status, the details, and the actions at the bottom', async () => {
    serve()
    api.post.mockResolvedValue({ data: { data: {} } })
    renderAt('/teacher/grades', <AssessmentsPage />)
    const cards = within(screen.getByTestId('assessment-cards'))
    const quizCard = (await cards.findByRole('heading', { name: 'Quiz 1' })).closest('li')
    expect(within(quizCard).getByText('Quiz')).toBeInTheDocument()
    expect(within(quizCard).getByText('Draft')).toBeInTheDocument()
    expect(within(quizCard).getByText('2 / 3')).toBeInTheDocument()
    expect(within(quizCard).getByText('10%')).toBeInTheDocument()
    expect(within(quizCard).getByText('2026-10-05')).toBeInTheDocument()
    expect(within(quizCard).getByRole('link', { name: 'Enter scores' })).toHaveAttribute('href', '/teacher/grades/11')
    expect(within(quizCard).getByRole('button', { name: 'Delete' })).toBeInTheDocument() // only a draft can be deleted
    const examCard = cards.getByRole('heading', { name: 'Midterm' }).closest('li')
    expect(within(examCard).getByText('Published')).toBeInTheDocument()
    expect(within(examCard).queryByRole('button', { name: 'Delete' })).not.toBeInTheDocument()
    fireEvent.click(within(examCard).getByRole('button', { name: 'Unpublish' }))
    await waitFor(() => expect(api.post).toHaveBeenCalledWith('/assessments/12/unpublish'))
  })

  it('picks the classroom first, then one of the subjects taught in it', async () => {
    serve()
    renderAt('/teacher/grades', <AssessmentsPage />)
    await screen.findByRole('row', { name: /Quiz 1/ })
    const subjectOptions = () => within(screen.getByLabelText('Subject')).getAllByRole('option').map((o) => o.textContent)
    expect(subjectOptions()).toEqual(['Math', 'Science'])

    fireEvent.change(screen.getByLabelText('Subject'), { target: { value: '8' } })
    await waitFor(() => expect(api.get).toHaveBeenCalledWith('/assessments', { params: { classroom_id: 3, subject_id: 8, term_id: 1 } }))

    fireEvent.change(screen.getByLabelText('Classroom'), { target: { value: '4' } })
    await waitFor(() => expect(subjectOptions()).toEqual(['Math', 'Art']))
    await waitFor(() => expect(api.get).toHaveBeenCalledWith('/assessments', { params: { classroom_id: 4, subject_id: 7, term_id: 1 } }))

    fireEvent.change(screen.getByLabelText('Term'), { target: { value: '2' } })
    await waitFor(() => expect(api.get).toHaveBeenCalledWith('/assessments', { params: { classroom_id: 4, subject_id: 7, term_id: 2 } }))
  })

  it('keeps the choice in the address, so coming back from a score sheet lands on the same classroom and subject', async () => {
    serve()
    renderAt('/teacher/grades?classroom=4&subject=9&term=2', <AssessmentsPage />)
    await waitFor(() => expect(api.get).toHaveBeenCalledWith('/assessments', { params: { classroom_id: 4, subject_id: 9, term_id: 2 } }))
    expect(screen.getByLabelText('Classroom')).toHaveValue('4')
    expect(screen.getByLabelText('Subject')).toHaveValue('9')
    expect(screen.getByLabelText('Term')).toHaveValue('2')
  })

  it('falls back to the first choice when the address names something the teacher does not teach', async () => {
    serve()
    renderAt('/teacher/grades?classroom=4&subject=8&term=99', <AssessmentsPage />)
    await waitFor(() => expect(api.get).toHaveBeenCalledWith('/assessments', { params: { classroom_id: 4, subject_id: 7, term_id: 1 } }))
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
    pass_percent: 50,
    students: [
      { id: 21, student_number: 'S-1', name_ar: 'أحمد', name_en: 'Ahmad', score: 18, status: null, was_absent: false, remark: null },
      { id: 22, student_number: 'S-2', name_ar: 'ليلى', name_en: 'Layla', score: null, status: null, was_absent: false, remark: null },
      { id: 23, student_number: 'S-3', name_ar: 'يوسف', name_en: 'Yousef', score: 14, status: null, was_absent: false, remark: 'Good' },
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
      { student_id: 21, score: null, status: null, remark: null },
      { student_id: 22, score: 12.5, status: null, remark: null },
      { student_id: 23, score: 14, status: null, remark: 'Great' },
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

describe('ScoreSheetPage pass / fail and makeup exams', () => {
  const data = (over = {}) => ({
    assessment: { ...quiz, classroom: { id: 3, name: '5-A' }, subject: { id: 7, name_ar: 'الرياضيات', name_en: 'Math' }, term: { id: 1, name: 'First term' } },
    pass_percent: 50,
    students: [
      { id: 21, student_number: 'S-1', name_ar: 'أحمد', name_en: 'Ahmad', score: 18, status: null, was_absent: false, remark: null },
      { id: 22, student_number: 'S-2', name_ar: 'ليلى', name_en: 'Layla', score: 9, status: null, was_absent: false, remark: null },
      { id: 23, student_number: 'S-3', name_ar: 'يوسف', name_en: 'Yousef', score: null, status: 'absent', was_absent: true, remark: 'Sick' },
      { id: 24, student_number: 'S-4', name_ar: 'مريم', name_en: 'Mariam', score: null, status: null, was_absent: true, remark: null },
    ],
    ...over,
  })
  const open = () => {
    api.get.mockResolvedValue({ data: { data: data() } })
    renderAt('/teacher/grades/11', <ScoreSheetPage />, '/teacher/grades/:id')
  }
  const row = (name) => screen.getByRole('group', { name })

  it('shows pass or fail next to each score (50% of the maximum) and pending for absent students', async () => {
    open()
    await screen.findByRole('group', { name: 'Ahmad' })
    expect(within(row('Ahmad')).getByText('Pass')).toBeInTheDocument() // 18 / 20
    expect(within(row('Layla')).getByText('Fail')).toBeInTheDocument() // 9 / 20 = 45%
    expect(within(row('Yousef')).getByText('Makeup pending')).toBeInTheDocument()
    expect(within(row('Mariam')).queryByText('Pass')).not.toBeInTheDocument()
    expect(within(row('Mariam')).queryByText('Fail')).not.toBeInTheDocument()
  })

  it('updates the result while typing, and exactly 50% passes', async () => {
    open()
    await screen.findByRole('group', { name: 'Ahmad' })
    fireEvent.change(within(row('Layla')).getByLabelText('Score'), { target: { value: '10' } })
    expect(within(row('Layla')).getByText('Pass')).toBeInTheDocument()
    fireEvent.change(within(row('Layla')).getByLabelText('Score'), { target: { value: '9.9' } })
    expect(within(row('Layla')).getByText('Fail')).toBeInTheDocument()
  })

  it('an absent student has no score box and the status can be changed to a makeup exam', async () => {
    open()
    api.put.mockResolvedValue({ data: { message: 'ok' } })
    await screen.findByRole('group', { name: 'Ahmad' })
    expect(within(row('Yousef')).getByLabelText('Score')).toBeDisabled()

    fireEvent.change(within(row('Yousef')).getByLabelText('Status'), { target: { value: 'makeup' } })
    expect(within(row('Yousef')).getByLabelText('Score')).not.toBeDisabled()
    fireEvent.change(within(row('Yousef')).getByLabelText('Score'), { target: { value: '15' } })
    expect(within(row('Yousef')).getByText('Pass')).toBeInTheDocument()
    fireEvent.click(screen.getByRole('button', { name: 'Save' }))
    await waitFor(() => expect(api.put).toHaveBeenCalledWith('/assessments/11/scores', { scores: expect.arrayContaining([
      { student_id: 23, score: 15, status: 'makeup', remark: 'Sick' },
    ]) }))
  })

  it('choosing absent clears the score and saves it without one', async () => {
    open()
    api.put.mockResolvedValue({ data: { message: 'ok' } })
    await screen.findByRole('group', { name: 'Ahmad' })
    fireEvent.change(within(row('Layla')).getByLabelText('Status'), { target: { value: 'absent' } })
    expect(within(row('Layla')).getByLabelText('Score')).toHaveValue(null)
    fireEvent.click(screen.getByRole('button', { name: 'Save' }))
    await waitFor(() => expect(api.put).toHaveBeenCalledWith('/assessments/11/scores', { scores: expect.arrayContaining([
      { student_id: 22, score: null, status: 'absent', remark: null },
    ]) }))
  })

  it('hints that the attendance says the student was absent that day, with a one-click mark', async () => {
    open()
    await screen.findByRole('group', { name: 'Mariam' })
    expect(within(row('Yousef')).queryByRole('button', { name: 'Mark as absent' })).not.toBeInTheDocument() // already marked
    expect(within(row('Ahmad')).queryByText('Absent that day')).not.toBeInTheDocument()
    expect(within(row('Mariam')).getByText('Absent that day')).toBeInTheDocument()
    fireEvent.click(within(row('Mariam')).getByRole('button', { name: 'Mark as absent' }))
    expect(within(row('Mariam')).getByText('Makeup pending')).toBeInTheDocument()
  })

  it('says how many makeup exams are pending in the summary', async () => {
    open()
    expect(await screen.findByText(/Waiting for another exam: 1/)).toBeInTheDocument()
  })
})

describe('AssessmentsPage makeup reminder and results link', () => {
  it('shows how many students wait for a makeup exam and links to the student results', async () => {
    serve()
    renderAt('/teacher/grades?classroom=3&subject=7&term=1', <AssessmentsPage />)
    const row = await screen.findByRole('row', { name: /Quiz 1/ })
    expect(within(row).getByText('Waiting for another exam: 1')).toBeInTheDocument()
    expect(within(screen.getByRole('row', { name: /Midterm/ })).queryByText(/Waiting for another exam/)).not.toBeInTheDocument()
    expect(screen.getByRole('link', { name: 'Student results' })).toHaveAttribute('href', '/teacher/grades/results?classroom=3&subject=7&term=1')
  })
})

describe('ResultsPage', () => {
  const results = {
    pass_percent: 50,
    students: [
      { student: { id: 21, student_number: 'S-1', name_ar: 'أحمد', name_en: 'Ahmad' }, percent: 52, passed: true, graded: 3, pending: 0 },
      { student: { id: 22, student_number: 'S-2', name_ar: 'ليلى', name_en: 'Layla' }, percent: 30.5, passed: false, graded: 3, pending: 0 },
      { student: { id: 23, student_number: 'S-3', name_ar: 'يوسف', name_en: 'Yousef' }, percent: 90, passed: true, graded: 1, pending: 1 },
      { student: { id: 24, student_number: 'S-4', name_ar: 'مريم', name_en: 'Mariam' }, percent: null, passed: null, graded: 0, pending: 0 },
    ],
  }
  beforeEach(() => {
    api.get.mockImplementation((url) => {
      if (url === '/grading/options') return Promise.resolve({ data: { data: options } })
      if (url === '/grading/results') return Promise.resolve({ data: { data: results } })
      return Promise.resolve({ data: { data: [] } })
    })
  })

  it('lists every student with the percentage and pass or fail', async () => {
    renderAt('/teacher/grades/results?classroom=3&subject=7&term=1', <ResultsPage />)
    expect(await screen.findByRole('row', { name: /Ahmad/ })).toBeInTheDocument()
    expect(api.get).toHaveBeenCalledWith('/grading/results', { params: { classroom_id: 3, subject_id: 7, term_id: 1 } })
    expect(within(screen.getByRole('row', { name: /Ahmad/ })).getByText('Pass')).toBeInTheDocument()
    expect(within(screen.getByRole('row', { name: /Ahmad/ })).getByText('52%')).toBeInTheDocument()
    expect(within(screen.getByRole('row', { name: /Layla/ })).getByText('Fail')).toBeInTheDocument()
    expect(within(screen.getByRole('row', { name: /Layla/ })).getByText('30.5%')).toBeInTheDocument()
    expect(within(screen.getByRole('row', { name: /Yousef/ })).getByText('Makeup pending')).toBeInTheDocument()
    expect(within(screen.getByRole('row', { name: /Mariam/ })).getByText('Not graded yet')).toBeInTheDocument()
  })

  it('counts passes and fails and shows the pass mark', async () => {
    renderAt('/teacher/grades/results?classroom=3&subject=7&term=1', <ResultsPage />)
    expect(await screen.findByText('Passed: 2 · Failed: 1')).toBeInTheDocument()
    expect(screen.getByText('Pass mark: 50%')).toBeInTheDocument()
    expect(screen.getByRole('heading', { name: 'Student results: 5-A · Math' })).toBeInTheDocument()
  })

  it('has Arabic labels', async () => {
    await i18n.changeLanguage('ar')
    renderAt('/teacher/grades/results?classroom=3&subject=7&term=1', <ResultsPage />)
    expect((await screen.findAllByText('ناجح')).length).toBeGreaterThan(0)
    expect(screen.getAllByText('راسب').length).toBeGreaterThan(0)
  })
})

describe('ScoreSheetPage retake after failing', () => {
  const sheet = {
    assessment: { ...quiz, type: 'exam', max_score: 100, classroom: { id: 3, name: '5-A' }, subject: { id: 7, name_ar: 'الرياضيات', name_en: 'Math' }, term: { id: 1, name: 'First term' } },
    pass_percent: 50,
    students: [
      { id: 21, student_number: 'S-1', name_ar: 'أحمد', name_en: 'Ahmad', score: 38, first_score: null, status: null, was_absent: false, remark: null },
      { id: 22, student_number: 'S-2', name_ar: 'ليلى', name_en: 'Layla', score: 52, first_score: 41, status: 'retake', was_absent: false, remark: null },
      { id: 23, student_number: 'S-3', name_ar: 'يوسف', name_en: 'Yousef', score: null, first_score: 30, status: 'retake', was_absent: false, remark: null },
    ],
  }
  const open = () => {
    api.get.mockResolvedValue({ data: { data: sheet } })
    renderAt('/teacher/grades/11', <ScoreSheetPage />, '/teacher/grades/:id')
  }
  const row = (name) => screen.getByRole('group', { name })

  it('offers a retake option, keeps the failing score as the first attempt and waits for the new score', async () => {
    open()
    api.put.mockResolvedValue({ data: { message: 'ok' } })
    await screen.findByRole('group', { name: 'Ahmad' })
    expect(within(row('Ahmad')).getByText('Fail')).toBeInTheDocument() // 38 / 100

    fireEvent.change(within(row('Ahmad')).getByLabelText('Status'), { target: { value: 'retake' } })
    expect(within(row('Ahmad')).getByLabelText('Score')).toHaveValue(null)
    expect(within(row('Ahmad')).getByText('Retake pending')).toBeInTheDocument()
    expect(within(row('Ahmad')).getByText('First attempt: 38')).toBeInTheDocument()

    fireEvent.click(screen.getByRole('button', { name: 'Save' }))
    await waitFor(() => expect(api.put).toHaveBeenCalledWith('/assessments/11/scores', { scores: expect.arrayContaining([
      { student_id: 21, score: null, status: 'retake', remark: null },
    ]) }))
  })

  it('shows the retake score with its own result and the first attempt, and a retake still waiting', async () => {
    open()
    await screen.findByRole('group', { name: 'Layla' })
    expect(within(row('Layla')).getByText('Pass')).toBeInTheDocument() // 52 after the retake
    expect(within(row('Layla')).getByText('First attempt: 41')).toBeInTheDocument()
    expect(within(row('Yousef')).getByText('Retake pending')).toBeInTheDocument()
    expect(within(row('Yousef')).getByLabelText('Score')).not.toBeDisabled()
    expect(screen.getByText(/Waiting for another exam: 1/)).toBeInTheDocument()
  })
})
