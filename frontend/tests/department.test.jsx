import { render, screen, within } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { MemoryRouter } from 'react-router-dom'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import i18n from '../src/i18n'
import { api } from '../src/api/client'
import DepartmentPage from '../src/features/Teacher/pages/DepartmentPage'
import TeacherHome from '../src/features/Teacher/pages/TeacherHome'

vi.mock('../src/api/client', () => ({ api: { get: vi.fn() } }))
const auth = vi.hoisted(() => ({ user: null }))
vi.mock('../src/features/Auth/AuthProvider', () => ({ useAuth: () => ({ user: auth.user }) }))

const renderPage = (ui) =>
  render(
    <QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}>
      <MemoryRouter>{ui}</MemoryRouter>
    </QueryClientProvider>,
  )

const department = {
  subject: { id: 3, name_ar: 'الرياضيات', name_en: 'Math' },
  teachers: [
    { id: 5, name: 'Sara Khaled', email: 'sara@school.test', phone: '0599000001', staff_type: 'teacher', homeroom: '5-A', classrooms: ['5-A', '5-B'], attendance: { recorded_today: true, days_last_30: 18 } },
    { id: 6, name: 'Omar Ali', email: 'omar@school.test', phone: null, staff_type: 'teacher', homeroom: '6-A', classrooms: ['6-A'], attendance: { recorded_today: false, days_last_30: 9 } },
    { id: 7, name: 'Rana Said', email: 'rana@school.test', phone: null, staff_type: 'teacher', homeroom: null, classrooms: [], attendance: null },
  ],
}

beforeEach(async () => {
  vi.resetAllMocks()
  auth.user = { id: 1, role: 'teacher', name: 'Head', staff_type: 'head_of_department' }
  api.get.mockResolvedValue({ data: { data: department } })
  await i18n.changeLanguage('en')
})

describe('DepartmentPage', () => {
  it('lists the teachers of the department with their classrooms and attendance activity', async () => {
    renderPage(<DepartmentPage />)
    expect(await screen.findByRole('heading', { name: 'Department: Math' })).toBeInTheDocument()
    const sara = screen.getByRole('row', { name: /Sara Khaled/ })
    expect(within(sara).getByText('5-A, 5-B')).toBeInTheDocument()
    expect(within(sara).getByText('Recorded')).toBeInTheDocument()
    expect(within(sara).getByText('18')).toBeInTheDocument()
    expect(within(screen.getByRole('row', { name: /Omar Ali/ })).getByText('Not yet')).toBeInTheDocument()
    expect(within(screen.getByRole('row', { name: /Rana Said/ })).getAllByText('—').length).toBeGreaterThan(0)
    expect(api.get).toHaveBeenCalledWith('/department')
  })

  it('uses the Arabic subject name', async () => {
    await i18n.changeLanguage('ar')
    renderPage(<DepartmentPage />)
    expect(await screen.findByRole('heading', { name: 'القسم: الرياضيات' })).toBeInTheDocument()
  })

  it('explains an empty department', async () => {
    api.get.mockResolvedValue({ data: { data: { subject: null, teachers: [] } } })
    renderPage(<DepartmentPage />)
    expect(await screen.findByText('No teachers in your department yet.')).toBeInTheDocument()
  })
})

describe('TeacherHome', () => {
  it('shows the department card only for a head of department', () => {
    renderPage(<TeacherHome />)
    expect(screen.getByRole('link', { name: /My department/ })).toHaveAttribute('href', '/teacher/department')
  })

  it('hides it for an ordinary teacher', () => {
    auth.user = { id: 2, role: 'teacher', name: 'Sara', staff_type: 'teacher' }
    renderPage(<TeacherHome />)
    expect(screen.queryByRole('link', { name: /My department/ })).not.toBeInTheDocument()
    expect(screen.getByRole('link', { name: /Take attendance/ })).toBeInTheDocument()
  })
})
