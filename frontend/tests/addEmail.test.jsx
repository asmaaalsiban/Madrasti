import { fireEvent, render, screen, waitFor } from '@testing-library/react'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import i18n from '../src/i18n'
import { api, setToken } from '../src/api/client'
import AddEmailForm from '../src/features/Auth/components/AddEmailForm'

const updateUser = vi.fn()
vi.mock('../src/api/client', () => ({ api: { get: vi.fn(), post: vi.fn() }, setToken: vi.fn() }))
vi.mock('../src/features/Auth/AuthProvider', () => ({ useAuth: () => ({ user: { role: 'parent' }, updateUser }) }))

beforeEach(async () => {
  vi.resetAllMocks()
  await i18n.changeLanguage('en')
})

const fill = (email) => {
  fireEvent.change(screen.getByLabelText('Your email'), { target: { value: email } })
  fireEvent.click(screen.getByRole('button', { name: 'Send the code' }))
}

describe('AddEmailForm', () => {
  it('sends a code to the email, then confirms it with the code', async () => {
    const onDone = vi.fn()
    api.post.mockResolvedValueOnce({ data: { sent: true } }).mockResolvedValueOnce({ data: { merged: false, token: null, user: { id: 5, email: 'dad@example.com' } } })
    render(<AddEmailForm onDone={onDone} />)
    fill('dad@example.com')
    expect(await screen.findByText('A code was sent to dad@example.com.')).toBeInTheDocument()
    expect(api.post).toHaveBeenCalledWith('/auth/email/request', { email: 'dad@example.com' })

    fireEvent.change(screen.getByLabelText('Code (6 digits)'), { target: { value: '123456' } })
    fireEvent.click(screen.getByRole('button', { name: 'Confirm' }))
    expect(await screen.findByText('Your email dad@example.com was saved.')).toBeInTheDocument()
    expect(api.post).toHaveBeenLastCalledWith('/auth/email/confirm', { email: 'dad@example.com', code: '123456' })
    expect(updateUser).toHaveBeenCalledWith({ id: 5, email: 'dad@example.com' })
    expect(setToken).not.toHaveBeenCalled()

    fireEvent.click(screen.getByRole('button', { name: 'Continue' }))
    expect(onDone).toHaveBeenCalled()
  })

  it('continues as the other account after the two accounts were merged', async () => {
    api.post.mockResolvedValueOnce({ data: { sent: true } }).mockResolvedValueOnce({ data: { merged: true, token: 'new-token', user: { id: 9, email: 'dad@example.com', children: [{}, {}] } } })
    render(<AddEmailForm onDone={() => {}} />)
    fill('dad@example.com')
    await screen.findByLabelText('Code (6 digits)')
    fireEvent.change(screen.getByLabelText('Code (6 digits)'), { target: { value: '123456' } })
    fireEvent.click(screen.getByRole('button', { name: 'Confirm' }))
    expect(await screen.findByText(/Your two accounts are now one/)).toBeInTheDocument()
    expect(setToken).toHaveBeenCalledWith('new-token')
    expect(updateUser).toHaveBeenCalledWith(expect.objectContaining({ id: 9 }))
  })

  it('shows a wrong code and lets the parent skip for later', async () => {
    const onSkip = vi.fn()
    api.post.mockResolvedValueOnce({ data: { sent: true } }).mockRejectedValueOnce({ response: { data: { errors: { code: ['The code is wrong or expired.'] } } } })
    render(<AddEmailForm onDone={() => {}} onSkip={onSkip} />)
    fireEvent.click(screen.getByRole('button', { name: 'Later' }))
    expect(onSkip).toHaveBeenCalled()

    fill('dad@example.com')
    fireEvent.change(await screen.findByLabelText('Code (6 digits)'), { target: { value: '000000' } })
    fireEvent.click(screen.getByRole('button', { name: 'Confirm' }))
    expect(await screen.findByRole('alert')).toHaveTextContent('The code is wrong or expired.')
    await waitFor(() => expect(setToken).not.toHaveBeenCalled())
  })
})
