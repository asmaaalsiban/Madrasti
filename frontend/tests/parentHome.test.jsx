import { render, screen } from '@testing-library/react'
import { MemoryRouter } from 'react-router-dom'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import i18n from '../src/i18n'
import ParentHome from '../src/features/Parent/pages/ParentHome'

const auth = vi.hoisted(() => ({ user: null }))
vi.mock('../src/features/Auth/AuthProvider', () => ({ useAuth: () => ({ user: auth.user }) }))

const children = [
  { id: 1, student_number: 'D-1001', name_ar: 'أحمد خالد', name_en: 'Ahmad Khaled', email: 'ahmad.d1001@school2.edu', classroom: '1-A' },
  { id: 2, student_number: 'D-1002', name_ar: 'ليلى خالد', name_en: 'Layla Khaled', email: 'layla.d1002@school2.edu', classroom: null },
]

beforeEach(async () => {
  auth.user = { id: 7, role: 'parent', name: 'Abu Ahmad', children }
  await i18n.changeLanguage('en')
})

describe('ParentHome', () => {
  it('shows a card per child with the sign-in email', () => {
    render(<MemoryRouter><ParentHome /></MemoryRouter>)
    expect(screen.getByRole('heading', { name: 'My children' })).toBeInTheDocument()
    expect(screen.getByText('Ahmad Khaled')).toBeInTheDocument()
    expect(screen.getByText('ahmad.d1001@school2.edu')).toBeInTheDocument()
    expect(screen.getByText('layla.d1002@school2.edu')).toBeInTheDocument()
    expect(screen.getByText('D-1001')).toBeInTheDocument()
    expect(screen.getByText('1-A')).toBeInTheDocument()
  })

  it('keeps emails left-to-right in Arabic and uses the Arabic name', async () => {
    await i18n.changeLanguage('ar')
    render(<MemoryRouter><ParentHome /></MemoryRouter>)
    expect(screen.getByRole('heading', { name: 'أبنائي' })).toBeInTheDocument()
    expect(screen.getByText('أحمد خالد')).toBeInTheDocument()
    expect(screen.getByText('ahmad.d1001@school2.edu')).toHaveAttribute('dir', 'ltr')
  })

  it('explains when no child is linked yet', () => {
    auth.user = { ...auth.user, children: [] }
    render(<MemoryRouter><ParentHome /></MemoryRouter>)
    expect(screen.getByText('No children are linked to your account yet.')).toBeInTheDocument()
  })
})
