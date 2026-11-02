import { fireEvent, render, screen, waitFor } from '@testing-library/react'
import { createMemoryRouter, RouterProvider } from 'react-router-dom'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import i18n from '../src/i18n'
import { RequireAuth } from '../src/app/guards'
import { api } from '../src/api/client'
import ChangePasswordPage from '../src/features/Auth/pages/ChangePasswordPage'
import LoginPage from '../src/features/Auth/pages/LoginPage'

const auth = vi.hoisted(() => ({ user: null, updateUser: vi.fn(), login: vi.fn() }))
vi.mock('../src/features/Auth/AuthProvider', () => ({
  useAuth: () => ({ user: auth.user, loading: false, updateUser: auth.updateUser, login: auth.login, logout: vi.fn() }),
}))
vi.mock('../src/api/client', () => ({ api: { post: vi.fn(), get: vi.fn() } }))
vi.mock('../src/tenant/useSchool', () => ({ useSchool: () => ({ data: { name: 'School Two' } }) }))
vi.mock('../src/components/shared/ChangeSchoolCodeButton', () => ({ default: () => null }))

const student = { id: 1, role: 'student', name: 'Sam', must_change_password: true }

function renderAt(path, routes) {
  render(<RouterProvider router={createMemoryRouter(routes, { initialEntries: [path] })} />)
}

const guarded = [
  { path: '/login', element: <p>login page</p> },
  {
    element: <RequireAuth />,
    children: [
      { path: '/student', element: <p>student home</p> },
      { path: '/change-password', element: <ChangePasswordPage /> },
    ],
  },
]

beforeEach(async () => {
  vi.resetAllMocks()
  auth.user = null
  await i18n.changeLanguage('en')
})

describe('forced password change', () => {
  it('sends a user who still has the default password to the change-password page from anywhere', () => {
    auth.user = student
    renderAt('/student', guarded)
    expect(screen.queryByText('student home')).not.toBeInTheDocument()
    expect(screen.getByRole('heading', { name: 'Change password' })).toBeInTheDocument()
    expect(screen.getByText('Your account uses the default password. Choose your own password to continue.')).toBeInTheDocument()
  })

  it('lets everyone else reach their pages, and the change-password page voluntarily', () => {
    auth.user = { ...student, must_change_password: false }
    renderAt('/student', guarded)
    expect(screen.getByText('student home')).toBeInTheDocument()
  })

  it('does not show the forced notice when the change is voluntary', () => {
    auth.user = { ...student, must_change_password: false }
    renderAt('/change-password', guarded)
    expect(screen.queryByText(/default password/)).not.toBeInTheDocument()
  })

  it('still sends guests to login', () => {
    renderAt('/change-password', guarded)
    expect(screen.getByText('login page')).toBeInTheDocument()
  })
})

describe('ChangePasswordPage', () => {
  const fill = (current = '123456789', next = 'MyOwnPass1', confirm = 'MyOwnPass1') => {
    fireEvent.change(screen.getByLabelText('Current password'), { target: { value: current } })
    fireEvent.change(screen.getByLabelText('New password'), { target: { value: next } })
    fireEvent.change(screen.getByLabelText('Confirm new password'), { target: { value: confirm } })
    fireEvent.click(screen.getByRole('button', { name: 'Change password' }))
  }

  it('changes the password, updates the signed-in user and opens their home page', async () => {
    auth.user = student
    api.post.mockResolvedValue({ data: { data: { ...student, must_change_password: false } } })
    renderAt('/change-password', guarded)
    fill()
    await waitFor(() => expect(api.post).toHaveBeenCalledWith('/auth/change-password', {
      current_password: '123456789', password: 'MyOwnPass1', password_confirmation: 'MyOwnPass1',
    }))
    await waitFor(() => expect(auth.updateUser).toHaveBeenCalledWith({ ...student, must_change_password: false }))
  })

  it('shows the server error under the field (wrong current password)', async () => {
    auth.user = student
    api.post.mockRejectedValue({ response: { status: 422, data: { errors: { current_password: ['The password is incorrect.'] } } } })
    renderAt('/change-password', guarded)
    fill('wrong')
    expect(await screen.findByText('The password is incorrect.')).toBeInTheDocument()
    expect(auth.updateUser).not.toHaveBeenCalled()
  })

  it('tells the user when they try too often', async () => {
    auth.user = student
    api.post.mockRejectedValue({ response: { status: 429, data: { message: 'Too many attempts.' } } })
    renderAt('/change-password', guarded)
    fill()
    expect(await screen.findByText('Too many attempts.')).toBeInTheDocument()
  })

  it('shows a generic error for other failures', async () => {
    auth.user = student
    api.post.mockRejectedValue({ response: { status: 500, data: { message: 'x' } } })
    renderAt('/change-password', guarded)
    fill()
    expect(await screen.findByText('Something went wrong, please try again.')).toBeInTheDocument()
  })

  it('has Arabic labels', async () => {
    await i18n.changeLanguage('ar')
    auth.user = student
    renderAt('/change-password', guarded)
    expect(screen.getByLabelText('كلمة المرور الحالية')).toBeInTheDocument()
    expect(screen.getByLabelText('كلمة المرور الجديدة')).toBeInTheDocument()
  })
})

describe('LoginPage', () => {
  it('goes straight to the change-password page when the account still has the default password', async () => {
    auth.login.mockResolvedValue(student)
    renderAt('/login', [
      { path: '/login', element: <LoginPage /> },
      { path: '/change-password', element: <p>change password page</p> },
      { path: '/student', element: <p>student home</p> },
    ])
    fireEvent.change(screen.getByLabelText('Email or phone number'), { target: { value: 'sam@school.test' } })
    fireEvent.change(screen.getByLabelText('Password'), { target: { value: '123456789' } })
    fireEvent.click(screen.getByRole('button', { name: 'Sign in' }))
    expect(await screen.findByText('change password page')).toBeInTheDocument()
  })

  it('opens the home page when the password was already changed', async () => {
    auth.login.mockResolvedValue({ ...student, must_change_password: false })
    renderAt('/login', [
      { path: '/login', element: <LoginPage /> },
      { path: '/change-password', element: <p>change password page</p> },
      { path: '/student', element: <p>student home</p> },
    ])
    fireEvent.change(screen.getByLabelText('Email or phone number'), { target: { value: 'sam@school.test' } })
    fireEvent.change(screen.getByLabelText('Password'), { target: { value: 'MyOwnPass1' } })
    fireEvent.click(screen.getByRole('button', { name: 'Sign in' }))
    expect(await screen.findByText('student home')).toBeInTheDocument()
  })
})

describe('a parent who signs in with a phone number', () => {
  it('is offered to add an email after choosing a password, and can skip it', async () => {
    auth.user = { id: 7, role: 'parent', name: 'Abu Ali', email: null, must_change_password: true }
    api.post.mockResolvedValue({ data: { data: { ...auth.user, must_change_password: false } } })
    renderAt('/change-password', [...guarded, { path: '/parent', element: <p>parent home</p> }, { element: <RequireAuth />, children: [] }])
    fireEvent.change(screen.getByLabelText('Current password'), { target: { value: '123456789' } })
    fireEvent.change(screen.getByLabelText('New password'), { target: { value: 'Chosen12345' } })
    fireEvent.change(screen.getByLabelText('Confirm new password'), { target: { value: 'Chosen12345' } })
    fireEvent.click(screen.getByRole('button', { name: 'Change password' }))

    expect(await screen.findByText('Add your email')).toBeInTheDocument()
    fireEvent.click(screen.getByRole('button', { name: 'Later' }))
    expect(await screen.findByText('parent home')).toBeInTheDocument()
  })
})
