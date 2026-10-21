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
  it('shows a card per child with the sign-in email', () => {
    render(<Wrap><ParentHome /></Wrap>)
    expect(screen.getByRole('heading', { name: 'My children' })).toBeInTheDocument()
    expect(screen.getByText('Ahmad Khaled')).toBeInTheDocument()
    expect(screen.getByText('ahmad.d1001@school2.edu')).toBeInTheDocument()
    expect(screen.getByText('layla.d1002@school2.edu')).toBeInTheDocument()
    expect(screen.getByText('D-1001')).toBeInTheDocument()
    expect(screen.getByText('1-A')).toBeInTheDocument()
  })

  it('keeps emails left-to-right in Arabic and uses the Arabic name', async () => {
    await i18n.changeLanguage('ar')
    render(<Wrap><ParentHome /></Wrap>)
    expect(screen.getByRole('heading', { name: 'أبنائي' })).toBeInTheDocument()
    expect(screen.getByText('أحمد خالد')).toBeInTheDocument()
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
    fireEvent.click(screen.getAllByRole('button', { name: 'Show dates' })[0])
    expect(await screen.findByText('2026-10-18')).toBeInTheDocument()
    expect(api.get).toHaveBeenCalledWith('/attendance/students/1', { params: expect.any(Object) })
  })

  it('links every child to the grades page', () => {
    render(<Wrap><ParentHome /></Wrap>)
    expect(screen.getAllByRole('link', { name: 'Grades' })[0]).toHaveAttribute('href', '/parent/children/1/grades')
  })
})
