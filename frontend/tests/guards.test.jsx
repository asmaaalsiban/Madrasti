import { render, screen } from '@testing-library/react'
import { createMemoryRouter, RouterProvider } from 'react-router-dom'
import { describe, expect, it, vi } from 'vitest'
import '../src/i18n'
import { RequireAuth, RequireRole } from '../src/app/guards'

const mockUser = vi.hoisted(() => ({ current: null }))
vi.mock('../src/features/Auth/AuthProvider', () => ({
  useAuth: () => ({ user: mockUser.current, loading: false }),
}))

function renderAt(path) {
  const router = createMemoryRouter(
    [
      { path: '/login', element: <p>login page</p> },
      {
        element: <RequireAuth />,
        children: [{ element: <RequireRole role="admin" />, children: [{ path: '/admin', element: <p>admin area</p> }] }],
      },
    ],
    { initialEntries: [path] },
  )
  render(<RouterProvider router={router} />)
}

describe('route guards', () => {
  it('redirects guests to login', () => {
    mockUser.current = null
    renderAt('/admin')
    expect(screen.getByText('login page')).toBeInTheDocument()
  })

  it('blocks other roles', () => {
    mockUser.current = { role: 'teacher' }
    renderAt('/admin')
    expect(screen.queryByText('admin area')).not.toBeInTheDocument()
    expect(screen.getByText('غير مسموح لك بالدخول لهذه الصفحة.')).toBeInTheDocument()
  })

  it('allows the right role', () => {
    mockUser.current = { role: 'admin' }
    renderAt('/admin')
    expect(screen.getByText('admin area')).toBeInTheDocument()
  })
})
