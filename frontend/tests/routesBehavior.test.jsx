import { render, screen } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { createMemoryRouter, RouterProvider } from 'react-router-dom'
import { describe, expect, it, vi } from 'vitest'
import '../src/i18n'
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
