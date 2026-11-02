import { fireEvent, render, screen, waitFor, within } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import i18n from '../src/i18n'
import { api } from '../src/api/client'
import MySurveysPage from '../src/features/Surveys/pages/MySurveysPage'
import SurveyResponsesPage from '../src/features/Surveys/pages/SurveyResponsesPage'
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
    api.get.mockImplementation((url) => Promise.resolve({ data: { data: url === '/behavior/classrooms' ? [{ id: 3, name: '5-A' }, { id: 4, name: '5-B' }] : url === '/grade-levels' ? [{ id: 11, name_ar: 'الرابع', name_en: 'Grade 4', sort_order: 4 }, { id: 12, name_ar: 'الخامس', name_en: 'Grade 5', sort_order: 5 }, { id: 13, name_ar: 'السادس', name_en: 'Grade 6', sort_order: 6 }] : list } }))
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
      type: 'poll', title: 'Which day?', audience: 'all',
      questions: [{ type: 'single_choice', text: 'Pick a day', is_required: true, options: ['Sunday', 'Monday'] }],
    })))
    expect(await screen.findByText('results page')).toBeInTheDocument()
  })

  it('sends to a range of grades: from one grade to another', async () => {
    api.post.mockResolvedValue({ data: { data: { id: 9 } } })
    wrap(<SurveysPage base="/admin" />)
    fireEvent.click(await screen.findByRole('button', { name: 'New' }))
    fireEvent.click(screen.getByLabelText('From grade … to grade …'))
    await screen.findAllByRole('option', { name: 'Grade 4' })
    fireEvent.change(screen.getByLabelText('From grade'), { target: { value: '11' } })
    fireEvent.change(screen.getByLabelText('To grade'), { target: { value: '13' } })
    fireEvent.change(screen.getByLabelText('Title'), { target: { value: 'Trip' } })
    fireEvent.change(screen.getByLabelText('Question'), { target: { value: 'Join?' } })
    fireEvent.change(screen.getByLabelText('Choices'), { target: { value: 'Yes\nNo' } })
    fireEvent.click(screen.getByRole('button', { name: 'Send to parents' }))
    await waitFor(() => expect(api.post).toHaveBeenCalledWith('/surveys', expect.objectContaining({ audience: 'grades', grade_from_id: 11, grade_to_id: 13 })))
    expect(api.post.mock.calls[0][1]).not.toHaveProperty('classroom_ids')
  })

  it('sends to several chosen sections, and not before one is chosen', async () => {
    api.post.mockResolvedValue({ data: { data: { id: 9 } } })
    wrap(<SurveysPage base="/admin" />)
    fireEvent.click(await screen.findByRole('button', { name: 'New' }))
    fireEvent.click(screen.getByLabelText('Choose sections'))
    expect(screen.getByRole('button', { name: 'Send to parents' })).toBeDisabled()
    fireEvent.click(await screen.findByLabelText('5-A'))
    fireEvent.click(screen.getByLabelText('5-B'))
    expect(screen.getByText('2 sections chosen')).toBeInTheDocument()
    fireEvent.change(screen.getByLabelText('Title'), { target: { value: 'Class trip' } })
    fireEvent.change(screen.getByLabelText('Question'), { target: { value: 'Join?' } })
    fireEvent.change(screen.getByLabelText('Choices'), { target: { value: 'Yes\nNo' } })
    fireEvent.click(screen.getByRole('button', { name: 'Send to parents' }))
    await waitFor(() => expect(api.post).toHaveBeenCalledWith('/surveys', expect.objectContaining({ audience: 'classrooms', classroom_ids: [3, 4] })))
  })

  it('selects all sections at once', async () => {
    wrap(<SurveysPage base="/admin" />)
    fireEvent.click(await screen.findByRole('button', { name: 'New' }))
    fireEvent.click(screen.getByLabelText('Choose sections'))
    await screen.findByLabelText('5-A')
    fireEvent.click(screen.getByLabelText('All', { selector: 'input[type=checkbox]' }))
    expect(screen.getByText('2 sections chosen')).toBeInTheDocument()
  })

  it('lets an approval have no questions, and a teacher only picks sections (no grades, no all parents)', async () => {
    api.post.mockResolvedValue({ data: { data: { id: 9 } } })
    wrap(<SurveysPage base="/teacher" />)
    fireEvent.click(await screen.findByRole('button', { name: 'New' }))
    expect(screen.queryByLabelText('All parents')).not.toBeInTheDocument()
    expect(screen.queryByLabelText('From grade … to grade …')).not.toBeInTheDocument()
    expect(api.get).not.toHaveBeenCalledWith('/grade-levels', expect.anything()) // a teacher cannot read the grade list
    fireEvent.change(screen.getByLabelText('Type'), { target: { value: 'approval' } })
    expect(screen.queryByLabelText('Question')).not.toBeInTheDocument()
    fireEvent.click(await screen.findByLabelText('5-A'))
    fireEvent.change(screen.getByLabelText('Title'), { target: { value: 'Museum trip' } })
    fireEvent.click(screen.getByRole('button', { name: 'Send to parents' }))
    await waitFor(() => expect(api.post).toHaveBeenCalledWith('/surveys', expect.objectContaining({ type: 'approval', audience: 'classrooms', classroom_ids: [3], questions: [] })))
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
    audience: 'grades', respondents: 'parents', audience_detail: { from: { name_ar: 'الرابع', name_en: 'Grade 4' }, to: { name_ar: 'السادس', name_en: 'Grade 6' } },
    recipients: [{ id: 1, name: 'Abu Khaled', role: 'parent', phone: '0790000003', student: null, state: 'pending' }, { id: 2, name: 'Abu Ali', role: 'parent', phone: null, student: null, state: 'answered' }], ...over,
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

  it('says who it was sent to and links to the table of answers', async () => {
    api.get.mockResolvedValue({ data: { data: data() } })
    wrap(<SurveyResultsPage base="/admin" />, '/s/1', '/s/:id')
    expect(await screen.findByText('Sent to: Grade 4 to Grade 6 · Answered by: Parents')).toBeInTheDocument()
    expect(screen.getByRole('link', { name: 'Open the table of answers' })).toHaveAttribute('href', '/admin/surveys/1/responses')
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

describe('SurveyResponsesPage', () => {
  const table = (over = {}) => ({
    id: 1, type: 'approval', title: 'Zoo trip', status: 'open', recipient_count: 4, response_count: 3, approved: 2, declined: 1,
    questions: [{ id: 5, type: 'text', text: 'Emergency phone', answered: 2, texts: [] }],
    recipients: [
      { id: 1, name: 'Abu Khaled', role: 'parent', phone: '0790000003', student: { id: 4, name_ar: 'خالد', name_en: 'Khaled' }, classroom: '5-B', state: 'pending', answered_at: null, answers: {} },
      { id: 2, name: 'Abu Ali', role: 'parent', phone: '0790000001', student: { id: 1, name_ar: 'علي', name_en: 'Ali' }, classroom: '5-A', state: 'approved', answered_at: '2026-11-02T10:30:00Z', answers: { 5: '0791111111' } },
      { id: 3, name: 'Abu Ali', role: 'parent', phone: '0790000001', student: { id: 2, name_ar: 'دانا', name_en: 'Dana' }, classroom: '5-B', state: 'declined', answered_at: '2026-11-02T10:31:00Z', answers: {} },
      { id: 4, name: 'Um Sami', role: 'parent', phone: null, student: { id: 3, name_ar: 'سامي', name_en: 'Sami' }, classroom: '5-A', state: 'approved', answered_at: '2026-11-02T11:00:00Z', answers: { 5: '0792222222' } },
    ], ...over,
  })
  const names = () => screen.getAllByRole('row').slice(1).map((r) => within(r).getAllByRole('cell')[0].textContent + '/' + within(r).getAllByRole('cell')[2].textContent)

  it('shows every parent with the child, section, phone, decision and the extra answers', async () => {
    api.get.mockResolvedValue({ data: { data: table() } })
    wrap(<SurveyResponsesPage base="/admin" />, '/s/1', '/s/:id')
    expect(await screen.findByText('Zoo trip')).toBeInTheDocument()
    const ali = screen.getByText('Ali').closest('tr')
    expect(within(ali).getByText('5-A')).toBeInTheDocument()
    expect(within(ali).getByText('0790000001')).toBeInTheDocument()
    expect(within(ali).getByText('Approved')).toBeInTheDocument()
    expect(within(ali).getByText('0791111111')).toBeInTheDocument()
    expect(within(screen.getByText('Dana').closest('tr')).getByText('Declined')).toBeInTheDocument()
    expect(screen.getByRole('columnheader', { name: 'Emergency phone' })).toBeInTheDocument()
  })

  it('filters to the ones who approved, the ones who declined, or the ones still waiting, with counts', async () => {
    api.get.mockResolvedValue({ data: { data: table() } })
    wrap(<SurveyResponsesPage base="/admin" />, '/s/1', '/s/:id')
    await screen.findByText('Zoo trip')
    expect(screen.getByRole('tab', { name: 'Approved (2)' })).toBeInTheDocument()
    fireEvent.click(screen.getByRole('tab', { name: 'Approved (2)' }))
    expect(names()).toEqual(['Abu Ali/Ali', 'Um Sami/Sami'])
    fireEvent.click(screen.getByRole('tab', { name: 'Declined (1)' }))
    expect(names()).toEqual(['Abu Ali/Dana'])
    fireEvent.click(screen.getByRole('tab', { name: 'Waiting (1)' }))
    expect(names()).toEqual(['Abu Khaled/Khaled'])
  })

  it('searches by parent, child, section or phone', async () => {
    api.get.mockResolvedValue({ data: { data: table() } })
    wrap(<SurveyResponsesPage base="/admin" />, '/s/1', '/s/:id')
    await screen.findByText('Zoo trip')
    fireEvent.change(screen.getByLabelText('Search'), { target: { value: '5-b' } })
    expect(names()).toEqual(['Abu Khaled/Khaled', 'Abu Ali/Dana'])
    fireEvent.change(screen.getByLabelText('Search'), { target: { value: '0790000001' } })
    expect(names()).toEqual(['Abu Ali/Ali', 'Abu Ali/Dana'])
    fireEvent.change(screen.getByLabelText('Search'), { target: { value: 'sami' } })
    expect(names()).toEqual(['Um Sami/Sami'])
    fireEvent.change(screen.getByLabelText('Search'), { target: { value: 'nobody' } })
    expect(screen.getByText('No one matches.')).toBeInTheDocument()
  })

  it('downloads the table as Excel, limited to the chosen filter', async () => {
    api.get.mockImplementation((url) => Promise.resolve(url === '/surveys/1/export' ? { data: new Blob(['x']), headers: {} } : { data: { data: table() } }))
    URL.createObjectURL = vi.fn(() => 'blob:x')
    URL.revokeObjectURL = vi.fn()
    vi.spyOn(HTMLAnchorElement.prototype, 'click').mockImplementation(() => {})
    wrap(<SurveyResponsesPage base="/admin" />, '/s/1', '/s/:id')
    await screen.findByText('Zoo trip')
    fireEvent.click(screen.getByRole('button', { name: 'Export to Excel' }))
    await waitFor(() => expect(api.get).toHaveBeenCalledWith('/surveys/1/export', { params: {}, responseType: 'blob' }))
    fireEvent.click(screen.getByRole('tab', { name: 'Declined (1)' }))
    fireEvent.click(screen.getByRole('button', { name: 'Export to Excel' }))
    await waitFor(() => expect(api.get).toHaveBeenCalledWith('/surveys/1/export', { params: { state: 'declined' }, responseType: 'blob' }))
  })

  it('has no child or section columns for a poll', async () => {
    api.get.mockResolvedValue({ data: { data: table({ type: 'poll', recipients: [{ id: 1, name: 'Abu Ali', role: 'parent', phone: null, student: null, classroom: null, state: 'answered', answered_at: null, answers: {} }] }) } })
    wrap(<SurveyResponsesPage base="/admin" />, '/s/1', '/s/:id')
    await screen.findByText('Zoo trip')
    expect(screen.queryByRole('columnheader', { name: 'Section' })).not.toBeInTheDocument()
    expect(screen.getByRole('tab', { name: 'Answered (1)' })).toBeInTheDocument()
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

describe('students answering', () => {
  const studentRow = { id: 21, type: 'poll', title: 'Lunch menu', description: null, open: true, state: 'pending', decision: null, student: { id: 1, name_ar: 'علي', name_en: 'Ali' }, closes_at: null, answers: {}, questions: [{ id: 9, type: 'single_choice', text: 'Favourite?', options: ['Pizza', 'Rice'], is_required: true }] }

  it('a student answers a poll with no child name and no approve/decline buttons', async () => {
    api.get.mockResolvedValue({ data: { data: [studentRow] } })
    api.post.mockResolvedValue({ data: { data: { state: 'answered' } } })
    wrap(<MySurveysPage />)
    const card = (await screen.findByText('Lunch menu')).closest('li')
    fireEvent.click(within(card).getByLabelText('Rice'))
    fireEvent.click(within(card).getByRole('button', { name: 'Send my answer' }))
    await waitFor(() => expect(api.post).toHaveBeenCalledWith('/my-surveys/21/respond', { decision: null, answers: { 9: 'Rice' } }))
    expect(within(card).queryByRole('button', { name: 'Approve' })).not.toBeInTheDocument()
  })

  it('the survey form says an approval is only for parents and offers students and both for the rest', async () => {
    api.get.mockImplementation((url) => Promise.resolve({ data: { data: url === '/behavior/classrooms' ? [] : [] } }))
    api.post.mockResolvedValue({ data: { data: { id: 9 } } })
    wrap(<SurveysPage base="/admin" />)
    fireEvent.click(await screen.findByRole('button', { name: 'New' }))
    expect(screen.getByLabelText('Students')).toBeInTheDocument()
    expect(screen.getByLabelText('Parents and students')).toBeInTheDocument()

    fireEvent.click(screen.getByLabelText('Parents and students'))
    fireEvent.change(screen.getByLabelText('Type'), { target: { value: 'approval' } })
    expect(screen.queryByLabelText('Students')).not.toBeInTheDocument()
    expect(screen.getByText('An approval is always answered by the parents.')).toBeInTheDocument()
    fireEvent.change(screen.getByLabelText('Title'), { target: { value: 'Trip' } })
    fireEvent.click(screen.getByRole('button', { name: 'Send to parents' }))
    await waitFor(() => expect(api.post).toHaveBeenCalledWith('/surveys', expect.objectContaining({ type: 'approval', respondents: 'parents' })))
  })

  it('sends a poll to students only', async () => {
    api.get.mockImplementation(() => Promise.resolve({ data: { data: [] } }))
    api.post.mockResolvedValue({ data: { data: { id: 9 } } })
    wrap(<SurveysPage base="/admin" />)
    fireEvent.click(await screen.findByRole('button', { name: 'New' }))
    fireEvent.click(screen.getByLabelText('Students'))
    fireEvent.change(screen.getByLabelText('Title'), { target: { value: 'Lunch' } })
    fireEvent.change(screen.getByLabelText('Question'), { target: { value: 'Favourite?' } })
    fireEvent.change(screen.getByLabelText('Choices'), { target: { value: 'Pizza\nRice' } })
    fireEvent.click(screen.getByRole('button', { name: 'Send to parents' }))
    await waitFor(() => expect(api.post).toHaveBeenCalledWith('/surveys', expect.objectContaining({ type: 'poll', respondents: 'students', audience: 'all' })))
  })

  it('the table tells students from parents', async () => {
    api.get.mockResolvedValue({ data: { data: {
      id: 1, type: 'poll', title: 'Lunch menu', status: 'open', recipient_count: 2, response_count: 1, respondents: 'both', approved: 0, declined: 0, questions: [],
      recipients: [
        { id: 1, name: 'Ali', role: 'student', phone: null, student: { id: 1, name_ar: 'علي', name_en: 'Ali' }, classroom: '5-A', state: 'answered', answered_at: null, answers: {} },
        { id: 2, name: 'Abu Ali', role: 'parent', phone: '0790000001', student: null, classroom: null, state: 'pending', answered_at: null, answers: {} },
      ] } } })
    wrap(<SurveyResponsesPage base="/admin" />, '/s/1', '/s/:id')
    await screen.findByText('Lunch menu')
    expect(within(screen.getByText('Abu Ali').closest('tr')).getByText('Parent')).toBeInTheDocument()
    const ali = screen.getAllByText('Ali')[0].closest('tr')
    expect(within(ali).getByText('Student')).toBeInTheDocument()
    expect(within(ali).getByText('5-A')).toBeInTheDocument()
  })
})

describe('surveys for the staff', () => {
  const staff = [
    { id: 1, name: 'Sara Hassan', staff_type: 'teacher', subject: { name_ar: 'الرياضيات', name_en: 'Math' } },
    { id: 2, name: 'Omar Nasser', staff_type: 'head_of_department', subject: { name_ar: 'العلوم', name_en: 'Science' } },
    { id: 3, name: 'Huda Ali', staff_type: 'teacher', subject: null },
    { id: 4, name: 'Principal Khaled', staff_type: 'principal', subject: null },
  ]
  beforeEach(() => api.get.mockImplementation((url) => Promise.resolve({ data: { data: url === '/surveys/staff' ? staff : url === '/my-surveys/pending' ? { count: 0 } : [] } })))
  const open = async (base = '/admin') => { wrap(<SurveysPage base={base} />); fireEvent.click(await screen.findByRole('button', { name: 'New' })) }
  const fillPoll = () => {
    fireEvent.change(screen.getByLabelText('Title'), { target: { value: 'Meeting day' } })
    fireEvent.change(screen.getByLabelText('Question'), { target: { value: 'Which day?' } })
    fireEvent.change(screen.getByLabelText('Choices'), { target: { value: 'Sunday\nMonday' } })
  }

  it('offers "Staff" only to the admin, and it hides the parents/grades/sections choices', async () => {
    await open()
    fireEvent.click(screen.getByLabelText('Staff'))
    expect(await screen.findByText('Choose the staff')).toBeInTheDocument()
    expect(screen.queryByLabelText('From grade … to grade …')).not.toBeInTheDocument()
    expect(await screen.findByLabelText('Sara Hassan')).toBeInTheDocument()
    expect(screen.getByText(/Teacher · Math/)).toBeInTheDocument()
  })

  it('a teacher is not offered staff', async () => {
    await open('/teacher')
    expect(screen.queryByLabelText('Staff')).not.toBeInTheDocument()
    expect(api.get).not.toHaveBeenCalledWith('/surveys/staff')
  })

  it('shows who to add: search, filter by position, tick one by one or everyone shown', async () => {
    await open()
    fireEvent.click(screen.getByLabelText('Staff'))
    await screen.findByLabelText('Sara Hassan')
    expect(screen.getByText('0 chosen')).toBeInTheDocument()

    fireEvent.click(screen.getByLabelText('Sara Hassan'))
    expect(screen.getByText('1 chosen')).toBeInTheDocument()

    fireEvent.change(screen.getByLabelText('All positions'), { target: { value: 'teacher' } })
    expect(screen.queryByLabelText('Omar Nasser')).not.toBeInTheDocument()
    fireEvent.click(screen.getByLabelText('Select all shown (2)'))
    expect(screen.getByText('2 chosen')).toBeInTheDocument() // Sara and Huda

    fireEvent.change(screen.getByLabelText('Search by name'), { target: { value: 'huda' } })
    expect(screen.getAllByRole('checkbox', { name: /Huda|Sara/ })).toHaveLength(1)
    fireEvent.click(screen.getByLabelText('Select all shown (1)')) // un-ticks the shown one only
    expect(screen.getByText('1 chosen')).toBeInTheDocument()

    fireEvent.change(screen.getByLabelText('Search by name'), { target: { value: 'zzz' } })
    expect(screen.getByText('No staff match.')).toBeInTheDocument()
  })

  it('cannot be sent before someone is chosen, then sends the chosen people', async () => {
    api.post.mockResolvedValue({ data: { data: { id: 9 } } })
    await open()
    fireEvent.click(screen.getByLabelText('Staff'))
    await screen.findByLabelText('Sara Hassan')
    fillPoll()
    expect(screen.getByRole('button', { name: 'Send to parents' })).toBeDisabled()
    fireEvent.click(screen.getByLabelText('Sara Hassan'))
    fireEvent.click(screen.getByLabelText('Principal Khaled'))
    fireEvent.click(screen.getByRole('button', { name: 'Send to parents' }))
    await waitFor(() => expect(api.post).toHaveBeenCalledWith('/surveys', expect.objectContaining({ type: 'poll', respondents: 'staff', staff_ids: [1, 4] })))
    expect(api.post.mock.calls[0][1]).not.toHaveProperty('audience')
    expect(api.post.mock.calls[0][1]).not.toHaveProperty('classroom_ids')
  })

  it('an approval goes back to parents even after choosing staff', async () => {
    await open()
    fireEvent.click(screen.getByLabelText('Staff'))
    await screen.findByText('Choose the staff')
    fireEvent.change(screen.getByLabelText('Type'), { target: { value: 'approval' } })
    expect(screen.queryByText('Choose the staff')).not.toBeInTheDocument()
    expect(screen.getByText('An approval is always answered by the parents.')).toBeInTheDocument()
  })

  it('has a link to the surveys addressed to me, with how many are waiting', async () => {
    api.get.mockImplementation((url) => Promise.resolve({ data: { data: url === '/my-surveys/pending' ? { count: 2 } : [] } }))
    wrap(<SurveysPage base="/teacher" />)
    const link = await screen.findByRole('link', { name: /Surveys for me/ })
    expect(link).toHaveAttribute('href', '/teacher/my-surveys')
    expect(await within(link).findByText('2')).toBeInTheDocument()
  })

  it('the results say it was sent to staff and the table shows their position', async () => {
    api.get.mockResolvedValue({ data: { data: {
      id: 1, type: 'poll', title: 'Meeting day', status: 'open', recipient_count: 2, response_count: 1, respondents: 'staff', audience: 'staff', audience_detail: null, approved: 0, declined: 0, questions: [],
      recipients: [{ id: 1, name: 'Sara Hassan', role: 'staff', position: 'head_of_department', phone: null, student: null, classroom: null, state: 'answered', answered_at: null, answers: {} }] } } })
    wrap(<SurveyResponsesPage base="/admin" />, '/s/1', '/s/:id')
    await screen.findByText('Meeting day')
    expect(within(screen.getByText('Sara Hassan').closest('tr')).getByText('Staff · Head of department')).toBeInTheDocument()
  })
})
