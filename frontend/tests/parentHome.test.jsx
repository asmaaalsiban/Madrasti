import { fireEvent, render, screen } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { MemoryRouter } from 'react-router-dom'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import i18n from '../src/i18n'
import { api } from '../src/api/client'
import ParentHome from '../src/features/Parent/pages/ParentHome'

vi.mock('../src/api/client', () => ({ api: { get: vi.fn() } }))
const auth = vi.hoisted(() => ({ user: null }))
vi.mock('../src/features/Auth/AuthProvider', () => ({ useAuth: () => ({ user: auth.user }) }))

const Wrap = ({ children }) => (
  <QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}><MemoryRouter>{children}</MemoryRouter></QueryClientProvider>
)

const children = [
  { id: 1, student_number: 'D-1001', name_ar: 'أحمد خالد', name_en: 'Ahmad Khaled', email: 'ahmad.d1001@school2.edu', classroom: '1-A' },
  { id: 2, student_number: 'D-1002', name_ar: 'ليلى خالد', name_en: 'Layla Khaled', email: 'layla.d1002@school2.edu', classroom: null },
]

beforeEach(async () => {
  api.get.mockReset()
  api.get.mockImplementation((url) => Promise.resolve({ data: { data: url.endsWith('/1') ? { summary: { present: 18, late: 1, absent: 3 }, records: [{ date: '2026-10-18', status: 'absent' }, { date: '2026-10-12', status: 'late' }] } : { summary: { present: 0, late: 0, absent: 0 }, records: [] } } }))
  auth.user = { id: 7, role: 'parent', name: 'Abu Ahmad', children }
  await i18n.changeLanguage('en')
})

describe('ParentHome', () => {
  it('reminds a parent without an email to add one, and not a parent who has one', () => {
    render(<Wrap><ParentHome /></Wrap>)
    expect(screen.getByText(/You have not added an email yet/)).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Add my email' })).toBeInTheDocument()
  })

  it('does not remind a parent who already has an email', () => {
    auth.user = { ...auth.user, email: 'abu@example.com' }
    render(<Wrap><ParentHome /></Wrap>)
    expect(screen.queryByText(/You have not added an email yet/)).not.toBeInTheDocument()
  })

  it('shows a tab per child and the chosen child with the sign-in email', () => {
    render(<Wrap><ParentHome /></Wrap>)
    expect(screen.getByRole('heading', { level: 1, name: 'My children' })).toBeInTheDocument()
    expect(screen.getByRole('tab', { name: /Ahmad Khaled/ })).toHaveAttribute('aria-selected', 'true')
    expect(screen.getByRole('tab', { name: /Layla Khaled/ })).toHaveAttribute('aria-selected', 'false')
    expect(screen.getByRole('heading', { level: 2, name: 'Ahmad Khaled' })).toBeInTheDocument()
    expect(screen.getByText('ahmad.d1001@school2.edu')).toBeInTheDocument()
    expect(screen.getByText('D-1001')).toBeInTheDocument()
    expect(screen.getByText('1-A')).toBeInTheDocument()
    fireEvent.click(screen.getByRole('tab', { name: /Layla Khaled/ }))
    expect(screen.getByText('layla.d1002@school2.edu')).toBeInTheDocument()
    expect(screen.getByRole('link', { name: 'Grades' })).toHaveAttribute('href', '/parent/children/2/grades')
  })

  it('keeps emails left-to-right in Arabic and uses the Arabic name', async () => {
    await i18n.changeLanguage('ar')
    render(<Wrap><ParentHome /></Wrap>)
    expect(screen.getByRole('heading', { level: 1, name: 'أبنائي' })).toBeInTheDocument()
    expect(screen.getByRole('heading', { level: 2, name: 'أحمد خالد' })).toBeInTheDocument()
    expect(screen.getByText('ahmad.d1001@school2.edu')).toHaveAttribute('dir', 'ltr')
  })

  it('explains when no child is linked yet', () => {
    auth.user = { ...auth.user, children: [] }
    render(<Wrap><ParentHome /></Wrap>)
    expect(screen.getByText('No children are linked to your account yet.')).toBeInTheDocument()
  })

  it('shows each child\'s attendance and the dates of absence on demand', async () => {
    render(<Wrap><ParentHome /></Wrap>)
    expect(await screen.findByText('Absent: 3 · Late: 1')).toBeInTheDocument()
    fireEvent.click(screen.getByRole('button', { name: 'Show dates' }))
    expect(await screen.findByText('2026-10-18')).toBeInTheDocument()
    expect(api.get).toHaveBeenCalledWith('/attendance/students/1', { params: expect.any(Object) })
  })

  it('links every child to the grades page', () => {
    render(<Wrap><ParentHome /></Wrap>)
    expect(screen.getByRole('link', { name: 'Grades' })).toHaveAttribute('href', '/parent/children/1/grades')
  })
})
