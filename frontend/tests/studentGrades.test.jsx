import { fireEvent, render, screen, within } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import i18n from '../src/i18n'
import { api } from '../src/api/client'
import ChildGradesPage from '../src/features/Parent/pages/ChildGradesPage'
import StudentHome from '../src/features/Student/pages/StudentHome'

vi.mock('../src/api/client', () => ({ api: { get: vi.fn() } }))
const auth = vi.hoisted(() => ({ user: null }))
vi.mock('../src/features/Auth/AuthProvider', () => ({ useAuth: () => ({ user: auth.user }) }))

const renderAt = (path, ui, route) =>
  render(
    <QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}>
      <MemoryRouter initialEntries={[path]}><Routes><Route path={route} element={ui} /></Routes></MemoryRouter>
    </QueryClientProvider>,
  )

const sub = (en, ar) => ({ id: en.length, name_en: en, name_ar: ar })
const a = (over) => ({ id: 1, title: 'Quiz', type: 'quiz', max_score: 20, weight: 20, held_on: '2026-10-05', score: 18, first_score: null, status: null, percent: 90, ...over })
const grades = {
  student: { id: 9, student_number: 'S-1', name_ar: 'أحمد', name_en: 'Ahmad', classroom: '5-A' },
  pass_percent: 50,
  terms: [
    {
      id: 1, name: 'First term', starts_on: '2026-09-01', ends_on: '2027-01-31',
      subjects: [
        { subject: sub('Arabic', 'العربية'), percent: 72, result: 'pass', assessments: [a({ id: 1, title: 'Quiz' }), a({ id: 2, title: 'Final exam', max_score: 100, score: 60, percent: 60 })] },
        { subject: sub('Math', 'الرياضيات'), percent: 31, result: 'fail', assessments: [a({ id: 3, title: 'Math exam', score: 6, percent: 30 })] },
        { subject: sub('Science', 'العلوم'), percent: 90, result: 'makeup', assessments: [a({ id: 4, title: 'Science exam', score: null, status: 'absent', percent: null })] },
        { subject: sub('Art', 'الفنية'), percent: null, result: 'retake', assessments: [a({ id: 5, title: 'Art exam', max_score: 100, score: null, first_score: 38, status: 'retake', percent: null })] },
        { subject: sub('PE', 'الرياضة'), percent: null, result: 'none', assessments: [a({ id: 6, title: 'PE quiz', score: null, percent: null })] },
      ],
    },
    { id: 2, name: 'Second term', starts_on: '2027-02-01', ends_on: '2027-06-30', subjects: [] },
  ],
}

beforeEach(async () => {
  vi.resetAllMocks()
  api.get.mockResolvedValue({ data: { data: grades } })
  auth.user = { id: 1, role: 'student', name: 'Ahmad', student_id: 9 }
  await i18n.changeLanguage('en')
})

describe('StudentHome (grades)', () => {
  it('loads the grades of the student and shows the result of every subject', async () => {
    renderAt('/student', <StudentHome />, '/student')
    const card = (name) => screen.findByRole('group', { name })
    expect(await card('Arabic')).toBeInTheDocument()
    expect(api.get).toHaveBeenCalledWith('/students/9/grades')
    expect(within(await card('Arabic')).getByText('Pass')).toBeInTheDocument()
    expect(within(await card('Arabic')).getByText('72%')).toBeInTheDocument()
    expect(within(await card('Math')).getByText('Fail')).toBeInTheDocument()
    expect(within(await card('Science')).getByText('Waiting for the makeup exam (you were absent)')).toBeInTheDocument()
    expect(within(await card('Art')).getByText('Waiting for the retake exam')).toBeInTheDocument()
    expect(within(await card('PE')).getByText('No grades yet')).toBeInTheDocument()
  })

  it('shows the assessments of a subject on demand, with the first attempt of a retake', async () => {
    renderAt('/student', <StudentHome />, '/student')
    const art = await screen.findByRole('group', { name: 'Art' })
    expect(within(art).queryByText('Art exam')).not.toBeInTheDocument()
    fireEvent.click(within(art).getByRole('button', { name: 'Show details' }))
    expect(within(art).getByText('Art exam')).toBeInTheDocument()
    expect(within(art).getByText('First attempt: 38')).toBeInTheDocument()
    expect(within(art).getByText('Retake exam')).toBeInTheDocument()

    const arabic = screen.getByRole('group', { name: 'Arabic' })
    fireEvent.click(within(arabic).getByRole('button', { name: 'Show details' }))
    expect(within(arabic).getByText('18 / 20')).toBeInTheDocument()
    expect(within(arabic).getByText('60 / 100')).toBeInTheDocument()
  })

  it('switches between the terms and explains an empty one', async () => {
    renderAt('/student', <StudentHome />, '/student')
    await screen.findByRole('group', { name: 'Arabic' })
    fireEvent.click(screen.getByRole('tab', { name: 'Second term' }))
    expect(screen.queryByRole('group', { name: 'Arabic' })).not.toBeInTheDocument()
    expect(screen.getByText('No grades have been published for this term yet.')).toBeInTheDocument()
  })

  it('uses Arabic names and labels', async () => {
    await i18n.changeLanguage('ar')
    renderAt('/student', <StudentHome />, '/student')
    const card = await screen.findByRole('group', { name: 'العربية' })
    expect(within(card).getByText('ناجح')).toBeInTheDocument()
    expect(within(await screen.findByRole('group', { name: 'الرياضيات' })).getByText('راسب')).toBeInTheDocument()
  })

  it('says so when the student record is missing', async () => {
    auth.user = { id: 1, role: 'student', name: 'Ahmad' }
    renderAt('/student', <StudentHome />, '/student')
    expect(await screen.findByText('No student record is linked to your account.')).toBeInTheDocument()
    expect(api.get).not.toHaveBeenCalled()
  })
})

describe('ChildGradesPage', () => {
  it('shows the grades of the child in the address', async () => {
    auth.user = { id: 2, role: 'parent', name: 'Dad', children: [{ id: 9, name_ar: 'أحمد', name_en: 'Ahmad' }] }
    renderAt('/parent/children/9/grades', <ChildGradesPage />, '/parent/children/:id/grades')
    expect(await screen.findByRole('heading', { name: 'Grades: Ahmad' })).toBeInTheDocument()
    expect(api.get).toHaveBeenCalledWith('/students/9/grades')
    expect(within(await screen.findByRole('group', { name: 'Math' })).getByText('Fail')).toBeInTheDocument()
  })
})
