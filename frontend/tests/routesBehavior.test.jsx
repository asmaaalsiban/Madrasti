import { render, screen } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { createMemoryRouter, RouterProvider } from 'react-router-dom'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import i18n from '../src/i18n'
import { routes } from '../src/app/router'

vi.mock('../src/api/client', () => ({ api: { get: vi.fn(() => new Promise(() => {})) } }))
vi.mock('../src/tenant/useSchool', () => ({ useSchool: () => ({ data: { name: 'School' } }) }))
const auth = vi.hoisted(() => ({ user: null }))
vi.mock('../src/features/Auth/AuthProvider', () => ({ useAuth: () => ({ user: auth.user, loading: false, logout: () => {} }) }))

const at = (path) => {
  render(<QueryClientProvider client={new QueryClient()}><RouterProvider router={createMemoryRouter(routes, { initialEntries: [path] })} /></QueryClientProvider>)
}

describe('behaviour routes', () => {
  it('opens a parent\'s child behaviour page', () => {
    auth.user = { role: 'parent', name: 'Parent', children: [{ id: 17, name_en: 'Lina', name_ar: 'لينا' }] }
    at('/parent/children/17/behavior')
    expect(screen.queryByText(/404/)).not.toBeInTheDocument()
  })

  it('opens a student\'s own behaviour page', () => {
    auth.user = { role: 'student', name: 'Student', student_id: 17 }
    at('/student/behavior')
    expect(screen.queryByText(/404/)).not.toBeInTheDocument()
  })
})

describe('daily summary routes', () => {
  it('opens the summaries of a student, a parent\'s child and the teacher\'s page', () => {
    for (const [user, path] of [
      [{ role: 'student', name: 'S', student_id: 1 }, '/student/summaries'],
      [{ role: 'parent', name: 'P', children: [{ id: 17 }] }, '/parent/children/17/summaries'],
      [{ role: 'teacher', name: 'T' }, '/teacher/daily-summary'],
      [{ role: 'teacher', name: 'T' }, '/teacher/lesson-plans'],
      [{ role: 'teacher', name: 'T' }, '/teacher/announcements'],
      [{ role: 'student', name: 'S', student_id: 1 }, '/student/grades'],
      [{ role: 'student', name: 'S', student_id: 1 }, '/student/announcements'],
      [{ role: 'parent', name: 'P', children: [] }, '/parent/announcements'],
      [{ role: 'admin', name: 'A' }, '/admin/announcements'],
      [{ role: 'student', name: 'S', student_id: 1 }, '/student/lesson-plans'],
      [{ role: 'parent', name: 'P', children: [{ id: 17 }] }, '/parent/children/17/lesson-plans'],
      [{ role: 'teacher', name: 'H', staff_type: 'head_of_department' }, '/teacher/lesson-plans/review'],
    ]) {
      auth.user = user
      const { unmount } = render(<QueryClientProvider client={new QueryClient()}><RouterProvider router={createMemoryRouter(routes, { initialEntries: [path] })} /></QueryClientProvider>)
      expect(screen.queryByText(/404/)).not.toBeInTheDocument()
      unmount()
    }
  })
})

describe('the signed-in name in the header', () => {
  it('shows the role and the full name of a parent and of a student', async () => {
    auth.user = { role: 'parent', name: 'Mohammad Ali Hassan', children: [] }
    at('/parent')
    expect(await screen.findByText('Mohammad Ali Hassan')).toBeInTheDocument()
  })

  it('shows the student their own full name', async () => {
    auth.user = { role: 'student', name: 'Salma Ahmad Nasser', student_id: 17 }
    at('/student')
    expect(await screen.findByText('Salma Ahmad Nasser')).toBeInTheDocument()
  })
})

describe('who is signed in, shown in the header', () => {
  beforeEach(() => i18n.changeLanguage('en'))

  const header = async (user, text) => {
    auth.user = user
    at('/' + user.role)
    expect(await screen.findByText((_, node) => node?.tagName === 'SPAN' && node.textContent === text)).toBeInTheDocument()
  }

  it('shows the student their section', () => header({ role: 'student', name: 'Ahmad Khaled', classroom: '5-A', student_id: 1 }, 'Student: Ahmad Khaled · 5-A'))
  it('shows a teacher their subject', () => header({ role: 'teacher', name: 'Sara', staff_type: 'teacher', subject: { name_en: 'Math', name_ar: 'رياضيات' } }, 'Teacher: Sara · Math'))
  it('shows a head of department which department', () => header({ role: 'teacher', name: 'Omar', staff_type: 'head_of_department', subject: { name_en: 'Math', name_ar: 'رياضيات' } }, 'Head of department Math: Omar'))
  it('shows a parent only the name', () => header({ role: 'parent', name: 'Mohammad', children: [] }, 'Parent: Mohammad'))
})
