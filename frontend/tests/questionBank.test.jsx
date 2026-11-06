import { fireEvent, render, screen, waitFor, within } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import i18n from '../src/i18n'
import { api } from '../src/api/client'
import QuestionBankPage from '../src/features/QuestionBank/pages/QuestionBankPage'
import QuizzesPage from '../src/features/QuestionBank/pages/QuizzesPage'
import QuizRunPage from '../src/features/QuestionBank/pages/QuizRunPage'

vi.mock('../src/api/client', () => ({ api: { get: vi.fn(), post: vi.fn(), put: vi.fn(), delete: vi.fn() } }))
const client = () => new QueryClient({ defaultOptions: { queries: { retry: false } } })

beforeEach(async () => {
  vi.resetAllMocks()
  await i18n.changeLanguage('en')
})

describe('QuestionBankPage', () => {
  it('lets a teacher add a lesson and a question with exactly one correct option', async () => {
    api.get.mockImplementation(async (url) => {
      if (url === '/question-bank/scope') return { data: { data: [{ grade_level_id: 1, grade_en: 'Grade 5', grade_ar: 'x', subject_id: 2, subject_en: 'Math', subject_ar: 'y' }] } }
      if (url === '/question-bank/lessons') return { data: { data: [{ id: 7, title: 'Fractions', questions: 0 }] } }
      return { data: { data: [] } }
    })
    api.post.mockResolvedValue({ data: { data: { id: 1 } } })
    render(<QueryClientProvider client={client()}><MemoryRouter><QuestionBankPage /></MemoryRouter></QueryClientProvider>)
    fireEvent.click(await screen.findByRole('button', { name: /Fractions/ }))
    fireEvent.click(await screen.findByRole('button', { name: 'New question' }))
    const dialog = await screen.findByRole('dialog', { name: 'New question' })
    fireEvent.change(within(dialog).getByLabelText(/^Question/), { target: { value: '1+1?' } })
    fireEvent.change(within(dialog).getByLabelText('Option 1'), { target: { value: '2' } })
    fireEvent.change(within(dialog).getByLabelText('Option 2'), { target: { value: '3' } })
    fireEvent.change(within(dialog).getByLabelText('Option 3'), { target: { value: '4' } })
    fireEvent.click(within(dialog).getByRole('button', { name: 'Save' }))
    await waitFor(() => expect(api.post).toHaveBeenCalledWith('/question-bank/lessons/7/questions', { body: '1+1?', explanation: null, options: [{ body: '2', is_correct: true }, { body: '3', is_correct: false }, { body: '4', is_correct: false }] }))
  })
})

describe('Quizzes', () => {
  it('a student picks lessons, is warned when few questions exist, and generates a quiz', async () => {
    api.get.mockImplementation(async (url) => (url === '/quizzes/options' ? { data: { data: [{ id: 2, name_en: 'Math', name_ar: 'x', mistakes: 0, lessons: [{ id: 7, title: 'Fractions', questions: 3 }] }], mistakes: 0 } } : { data: { data: [] } }))
    api.post.mockResolvedValue({ data: { data: { id: 5 }, reduced: true, available: 3 } })
    render(<QueryClientProvider client={client()}><MemoryRouter><QuizzesPage /></MemoryRouter></QueryClientProvider>)
    await screen.findByRole('option', { name: 'Math' })
    fireEvent.change(screen.getByLabelText('Subject'), { target: { value: '2' } })
    fireEvent.click(await screen.findByLabelText(/Fractions/))
    expect(screen.getByText(/Only 3 questions are available/)).toBeInTheDocument()
    fireEvent.click(screen.getByRole('button', { name: 'Generate' }))
    await waitFor(() => expect(api.post).toHaveBeenCalledWith('/quizzes', { subject_id: 2, lesson_ids: [7], count: 10 }))
  })

  const quiz = { id: 5, finished: false, correct: 0, total: 2, questions: [
    { id: 11, position: 1, body: 'Q one', answered: false, options: [{ id: 1, body: 'right' }, { id: 2, body: 'wrong' }] },
    { id: 12, position: 2, body: 'Q two', answered: false, options: [{ id: 3, body: 'right' }, { id: 4, body: 'wrong' }] }] }
  const run = () => render(<QueryClientProvider client={client()}><MemoryRouter initialEntries={['/student/quizzes/5']}><Routes><Route path="/student/quizzes/:id" element={<QuizRunPage />} /></Routes></MemoryRouter></QueryClientProvider>)

  it('a right answer moves on at once; a wrong one shows the solution, then the result', async () => {
    api.get.mockResolvedValue({ data: { data: quiz } })
    api.post.mockResolvedValueOnce({ data: { correct: true, finished: false, score: null } })
      .mockResolvedValueOnce({ data: { correct: false, correct_option_id: 3, explanation: 'Because maths.', finished: true, score: { correct: 1, total: 2, answered: 2 } } })
    run()
    expect(await screen.findByText('Q one')).toBeInTheDocument()
    fireEvent.click(screen.getByRole('button', { name: 'right' }))
    expect(await screen.findByText('Q two')).toBeInTheDocument()
    expect(screen.queryByText('Because maths.')).toBeNull()

    fireEvent.click(screen.getByRole('button', { name: 'wrong' }))
    expect(await screen.findByText('Because maths.')).toBeInTheDocument()
    fireEvent.click(screen.getByRole('button', { name: 'Next' }))
    expect(await screen.findByText('Quiz finished')).toBeInTheDocument()
    expect(screen.getByText('1 / 2')).toBeInTheDocument()
  })

  it('an optional timer is sent only when it is ticked', async () => {
    api.get.mockImplementation(async (url) => (url === '/quizzes/options' ? { data: { data: [{ id: 2, name_en: 'Math', name_ar: 'x', mistakes: 0, lessons: [{ id: 7, title: 'Fractions', questions: 3 }] }], mistakes: 0 } } : { data: { data: [] } }))
    api.post.mockResolvedValue({ data: { data: { id: 5 }, reduced: false, available: 3 } })
    render(<QueryClientProvider client={client()}><MemoryRouter><QuizzesPage /></MemoryRouter></QueryClientProvider>)
    await screen.findByRole('option', { name: 'Math' })
    fireEvent.change(screen.getByLabelText('Subject'), { target: { value: '2' } })
    fireEvent.click(await screen.findByLabelText(/Fractions/))
    fireEvent.click(screen.getByLabelText('Use a timer'))
    fireEvent.change(screen.getByLabelText('Minutes'), { target: { value: '15' } })
    fireEvent.click(screen.getByRole('button', { name: 'Generate' }))
    await waitFor(() => expect(api.post).toHaveBeenCalledWith('/quizzes', { subject_id: 2, lesson_ids: [7], count: 10, minutes: 15 }))
  })

  it('a student with mistakes can make a quiz of only those', async () => {
    api.get.mockImplementation(async (url) => (url === '/quizzes/options' ? { data: { data: [{ id: 2, name_en: 'Math', name_ar: 'x', mistakes: 50, lessons: [] }], mistakes: 50 } } : { data: { data: [] } }))
    api.post.mockResolvedValue({ data: { data: { id: 8 } } })
    render(<QueryClientProvider client={client()}><MemoryRouter><QuizzesPage /></MemoryRouter></QueryClientProvider>)
    expect(await screen.findByText(/You still get 50 questions wrong/)).toBeInTheDocument()
    fireEvent.click(screen.getByRole('button', { name: 'Practise my mistakes' }))
    await waitFor(() => expect(api.post).toHaveBeenCalledWith('/quizzes', { mode: 'mistakes' }))
  })

  it('a finished quiz offers a retake, and an equation is drawn', async () => {
    const done = { id: 5, finished: true, correct: 1, wrong: 1, answered: 2, total: 2, timed_out: false, seconds_left: null, questions: [
      { id: 11, position: 1, body: 'Solve $x^2$', answered: true, is_correct: true, options: [] }, { id: 12, position: 2, body: 'B', answered: true, is_correct: false, options: [] }] }
    api.get.mockResolvedValue({ data: { data: done } })
    api.post.mockResolvedValue({ data: { data: { id: 9 } } })
    render(<QueryClientProvider client={client()}><MemoryRouter initialEntries={['/student/quizzes/5']}><Routes><Route path="/student/quizzes/:id" element={<QuizRunPage />} /></Routes></MemoryRouter></QueryClientProvider>)
    expect(await screen.findByRole('button', { name: 'Practise my mistakes' })).toBeInTheDocument()
    fireEvent.click(screen.getByRole('button', { name: 'Retake' }))
    await waitFor(() => expect(api.post).toHaveBeenCalledWith('/quizzes/5/retake'))
  })

  it('shows a countdown for a timed quiz', async () => {
    const timed = { id: 6, finished: false, correct: 0, wrong: 0, answered: 0, total: 1, timed_out: false, seconds_left: 125, questions: [{ id: 21, position: 1, body: 'Solve $x^2$', answered: false, options: [{ id: 1, body: 'a' }] }] }
    api.get.mockResolvedValue({ data: { data: timed } })
    render(<QueryClientProvider client={client()}><MemoryRouter initialEntries={['/student/quizzes/6']}><Routes><Route path="/student/quizzes/:id" element={<QuizRunPage />} /></Routes></MemoryRouter></QueryClientProvider>)
    expect(await screen.findByRole('timer')).toHaveTextContent(/2:0[0-5]/)
    expect(document.querySelector('.katex')).not.toBeNull()
  })
})
