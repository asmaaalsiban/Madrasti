import { fireEvent, render, screen, waitFor, within } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import i18n from '../src/i18n'
import { api } from '../src/api/client'
import MySurveysPage from '../src/features/Surveys/pages/MySurveysPage'
import SurveyResultsPage from '../src/features/Surveys/pages/SurveyResultsPage'
import SurveysPage from '../src/features/Surveys/pages/SurveysPage'
import { notificationText } from '../src/features/Notifications/NotificationBell'

vi.mock('../src/api/client', () => ({ api: { get: vi.fn(), post: vi.fn(), put: vi.fn(), delete: vi.fn() } }))

const wrap = (ui, path = '/', route = '/') => render(
  <QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}><MemoryRouter initialEntries={[path]}><Routes><Route path={route} element={ui} /><Route path="/admin/surveys/:id" element={<p>results page</p>} /></Routes></MemoryRouter></QueryClientProvider>,
)

beforeEach(async () => {
  vi.resetAllMocks()
  await i18n.changeLanguage('en')
})

describe('SurveysPage', () => {
  const list = [
    { id: 1, type: 'approval', title: 'Zoo trip', status: 'open', recipient_count: 10, response_count: 4 },
    { id: 2, type: 'poll', title: 'Trip day', status: 'closed', recipient_count: 8, response_count: 8 },
  ]
  beforeEach(() => {
    api.get.mockImplementation((url) => Promise.resolve({ data: { data: url === '/behavior/classrooms' ? [{ id: 3, name: '5-A' }] : list } }))
  })

  it('lists what was sent with its type, state and how many answered', async () => {
    wrap(<SurveysPage base="/admin" />)
    const trip = (await screen.findByText('Zoo trip')).closest('a')
    expect(trip).toHaveAttribute('href', '/admin/surveys/1')
    expect(within(trip).getByText('Approval')).toBeInTheDocument()
    expect(within(trip).getByText('4 of 10 answered')).toBeInTheDocument()
    expect(within(screen.getByText('Trip day').closest('a')).getByText('Closed')).toBeInTheDocument()
  })

  it('sends a poll with its choices to all parents and opens the results', async () => {
    api.post.mockResolvedValue({ data: { data: { id: 9 } } })
    wrap(<SurveysPage base="/admin" />)
    fireEvent.click(await screen.findByRole('button', { name: 'New' }))
    fireEvent.change(screen.getByLabelText('Title'), { target: { value: 'Which day?' } })
    fireEvent.change(screen.getByLabelText('Question'), { target: { value: 'Pick a day' } })
    fireEvent.change(screen.getByLabelText('Choices'), { target: { value: 'Sunday\nMonday\n' } })
    fireEvent.click(screen.getByRole('button', { name: 'Send to parents' }))
    await waitFor(() => expect(api.post).toHaveBeenCalledWith('/surveys', expect.objectContaining({
      type: 'poll', title: 'Which day?', audience: 'all', classroom_id: null,
      questions: [{ type: 'single_choice', text: 'Pick a day', is_required: true, options: ['Sunday', 'Monday'] }],
    })))
    expect(await screen.findByText('results page')).toBeInTheDocument()
  })

  it('lets an approval have no questions and a teacher write only to a section', async () => {
    api.post.mockResolvedValue({ data: { data: { id: 9 } } })
    wrap(<SurveysPage base="/teacher" />)
    fireEvent.click(await screen.findByRole('button', { name: 'New' }))
    expect(screen.queryByLabelText('Send to')).not.toBeInTheDocument() // a teacher has no "all parents"
    fireEvent.change(screen.getByLabelText('Type'), { target: { value: 'approval' } })
    expect(screen.queryByLabelText('Question')).not.toBeInTheDocument()
    await screen.findByRole('option', { name: '5-A' })
    fireEvent.change(screen.getByLabelText('Classroom'), { target: { value: '3' } })
    fireEvent.change(screen.getByLabelText('Title'), { target: { value: 'Museum trip' } })
    fireEvent.click(screen.getByRole('button', { name: 'Send to parents' }))
    await waitFor(() => expect(api.post).toHaveBeenCalledWith('/surveys', expect.objectContaining({ type: 'approval', audience: 'classroom', classroom_id: 3, questions: [] })))
  })

  it('shows the server message when it is refused', async () => {
    api.post.mockRejectedValue({ response: { data: { errors: { questions: ['Add at least one question.'] } } } })
    wrap(<SurveysPage base="/admin" />)
    fireEvent.click(await screen.findByRole('button', { name: 'New' }))
    fireEvent.change(screen.getByLabelText('Title'), { target: { value: 'x' } })
    fireEvent.change(screen.getByLabelText('Question'), { target: { value: 'q' } })
    fireEvent.change(screen.getByLabelText('Choices'), { target: { value: 'a\nb' } })
    fireEvent.click(screen.getByRole('button', { name: 'Send to parents' }))
    expect(await screen.findByRole('alert')).toHaveTextContent('Add at least one question.')
  })
})

describe('SurveyResultsPage', () => {
  const data = (over = {}) => ({
    id: 1, type: 'poll', title: 'Trip day', description: 'Pick one', status: 'open', recipient_count: 3, response_count: 2, approved: 0, declined: 0,
    questions: [{ id: 5, type: 'single_choice', text: 'Which day?', answered: 2, counts: [{ option: 'Sunday', count: 2 }, { option: 'Monday', count: 0 }] }, { id: 6, type: 'text', text: 'Ideas?', answered: 1, texts: ['More sport'] }],
    recipients: [{ id: 1, parent: 'Abu Khaled', phone: '0790000003', student: null, state: 'pending' }, { id: 2, parent: 'Abu Ali', phone: null, student: null, state: 'answered' }], ...over,
  })

  it('shows the counts, the written answers, and who is still waiting first', async () => {
    api.get.mockResolvedValue({ data: { data: data() } })
    wrap(<SurveyResultsPage base="/admin" />, '/s/1', '/s/:id')
    expect(await screen.findByText('Trip day')).toBeInTheDocument()
    expect(screen.getByText('2 (100%)')).toBeInTheDocument()
    expect(screen.getByText('More sport')).toBeInTheDocument()
    const parents = screen.getAllByRole('listitem').map((li) => li.textContent)
    expect(parents.findIndex((x) => x.includes('Abu Khaled'))).toBeLessThan(parents.findIndex((x) => x.includes('Abu Ali')))
  })

  it('reminds those who have not answered, and closes', async () => {
    api.get.mockResolvedValue({ data: { data: data() } })
    api.post.mockImplementation((url) => Promise.resolve({ data: { data: url.endsWith('remind') ? { reminded: 1 } : {} } }))
    wrap(<SurveyResultsPage base="/admin" />, '/s/1', '/s/:id')
    fireEvent.click(await screen.findByRole('button', { name: 'Remind 1 who have not answered' }))
    expect(await screen.findByText('Reminder sent to 1 parents.')).toBeInTheDocument()
    expect(api.post).toHaveBeenCalledWith('/surveys/1/remind')
    fireEvent.click(screen.getByRole('button', { name: 'Close it' }))
    await waitFor(() => expect(api.post).toHaveBeenCalledWith('/surveys/1/close'))
  })

  it('counts approved, declined and waiting children of an approval and offers nothing once closed', async () => {
    api.get.mockResolvedValue({ data: { data: data({ type: 'approval', status: 'closed', approved: 5, declined: 2, questions: [] }) } })
    wrap(<SurveyResultsPage base="/admin" />, '/s/1', '/s/:id')
    expect(await screen.findByText('5')).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Close it' })).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: /Remind/ })).not.toBeInTheDocument()
  })

  it('does not delete a survey that has answers, and says why', async () => {
    api.get.mockResolvedValue({ data: { data: data() } })
    api.delete.mockRejectedValue({ response: { data: { errors: { survey: ['This survey already has answers: close it instead of deleting it.'] } } } })
    wrap(<SurveyResultsPage base="/admin" />, '/s/1', '/s/:id')
    fireEvent.click(await screen.findByRole('button', { name: 'Delete' }))
    fireEvent.click(within(await screen.findByRole('dialog')).getByRole('button', { name: /Delete|Confirm|Yes/ }))
    expect(await screen.findByText(/already has answers/)).toBeInTheDocument()
  })
})

describe('MySurveysPage', () => {
  const rows = [
    { id: 11, type: 'approval', title: 'Zoo trip', description: 'Friday 8am', open: true, state: 'pending', decision: null, student: { id: 1, name_ar: 'علي', name_en: 'Ali' }, closes_at: null, answers: {}, questions: [{ id: 5, type: 'text', text: 'Emergency phone', options: null, is_required: true }] },
    { id: 12, type: 'poll', title: 'Trip day', description: null, open: true, state: 'pending', decision: null, student: null, closes_at: '2026-12-01T10:00:00Z', answers: {}, questions: [{ id: 6, type: 'single_choice', text: 'Which day?', options: ['Sunday', 'Monday'], is_required: true }] },
    { id: 13, type: 'survey', title: 'Old survey', description: null, open: false, state: 'answered', decision: null, student: null, closes_at: null, answers: { 7: 'Great' }, questions: [{ id: 7, type: 'text', text: 'Comments', options: null, is_required: false }] },
  ]
  beforeEach(() => api.get.mockResolvedValue({ data: { data: rows } }))

  it('answers an approval for one child with the extra question', async () => {
    api.post.mockResolvedValue({ data: { data: { state: 'approved' } } })
    wrap(<MySurveysPage />)
    const card = (await screen.findByText('Zoo trip')).closest('li')
    expect(within(card).getByText(/Ali/)).toBeInTheDocument()
    fireEvent.change(within(card).getByLabelText('Emergency phone'), { target: { value: '0790000001' } })
    fireEvent.click(within(card).getByRole('button', { name: 'Approve' }))
    await waitFor(() => expect(api.post).toHaveBeenCalledWith('/my-surveys/11/respond', { decision: 'approved', answers: { 5: '0790000001' } }))
    expect(await within(card).findByText('Thank you, your answer was sent.')).toBeInTheDocument()
  })

  it('declines without filling anything', async () => {
    api.post.mockResolvedValue({ data: { data: { state: 'declined' } } })
    wrap(<MySurveysPage />)
    const card = (await screen.findByText('Zoo trip')).closest('li')
    fireEvent.click(within(card).getByRole('button', { name: 'Decline' }))
    await waitFor(() => expect(api.post).toHaveBeenCalledWith('/my-surveys/11/respond', { decision: 'declined', answers: {} }))
  })

  it('answers a poll and shows the validation message under the question', async () => {
    api.post.mockRejectedValueOnce({ response: { data: { errors: { 'answers.6': ['This question needs an answer.'] } } } })
    wrap(<MySurveysPage />)
    const card = (await screen.findByText('Trip day')).closest('li')
    fireEvent.click(within(card).getByRole('button', { name: 'Send my answer' }))
    expect(await within(card).findByRole('alert')).toHaveTextContent('This question needs an answer.')

    api.post.mockResolvedValueOnce({ data: { data: { state: 'answered' } } })
    fireEvent.click(within(card).getByLabelText('Monday'))
    fireEvent.click(within(card).getByRole('button', { name: 'Send my answer' }))
    await waitFor(() => expect(api.post).toHaveBeenLastCalledWith('/my-surveys/12/respond', { decision: null, answers: { 6: 'Monday' } }))
  })

  it('shows a closed survey as read-only', async () => {
    wrap(<MySurveysPage />)
    const card = (await screen.findByText('Old survey')).closest('li')
    expect(within(card).getByLabelText('Comments')).toBeDisabled()
    expect(within(card).queryByRole('button', { name: 'Send my answer' })).not.toBeInTheDocument()
    expect(within(card).queryByRole('button', { name: 'Change my answer' })).not.toBeInTheDocument()
  })
})

describe('notifications', () => {
  it('words the survey notifications by type in both languages', async () => {
    const n = (kind, type) => ({ kind, params: { title: 'Zoo trip', type } })
    expect(notificationText(i18n.t, 'en', n('survey_new', 'approval'))).toBe('Your approval is needed: Zoo trip')
    expect(notificationText(i18n.t, 'en', n('survey_new', 'poll'))).toBe('New poll from the school: Zoo trip')
    expect(notificationText(i18n.t, 'en', n('survey_reminder', 'poll'))).toBe('Reminder: please answer Zoo trip')
    await i18n.changeLanguage('ar')
    expect(notificationText(i18n.t, 'ar', n('survey_new', 'approval'))).toBe('مطلوب موافقتك: Zoo trip')
  })
})
