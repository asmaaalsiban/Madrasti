import { fireEvent, render, screen, waitFor } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { MemoryRouter } from 'react-router-dom'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import i18n from '../src/i18n'
import { api } from '../src/api/client'
import ForgotPasswordPage from '../src/features/Auth/pages/ForgotPasswordPage'

vi.mock('../src/api/client', () => ({ api: { get: vi.fn(), post: vi.fn() } }))
vi.mock('../src/tenant/useSchool', () => ({ useSchool: () => ({ data: { name: 'School' } }) }))

const renderPage = () => render(<QueryClientProvider client={new QueryClient()}><MemoryRouter><ForgotPasswordPage /></MemoryRouter></QueryClientProvider>)

beforeEach(async () => {
  vi.resetAllMocks()
  await i18n.changeLanguage('en')
})

describe('ForgotPasswordPage', () => {
  it('asks for the code, then sets the new password', async () => {
    api.post.mockResolvedValueOnce({ data: { sent: true, message: 'If the account exists, a code was sent.' } }).mockResolvedValueOnce({ data: { message: 'Your password was changed.' } })
    renderPage()
    fireEvent.change(screen.getByLabelText('Email or phone number'), { target: { value: 'ahmad@school.test' } })
    fireEvent.click(screen.getByRole('button', { name: 'Send the code' }))
    await waitFor(() => expect(api.post).toHaveBeenCalledWith('/auth/forgot-password', { login: 'ahmad@school.test' }))
    expect(await screen.findByRole('status')).toHaveTextContent('a code was sent')

    fireEvent.change(screen.getByLabelText('Code (6 digits)'), { target: { value: '123456' } })
    fireEvent.change(screen.getByLabelText('New password'), { target: { value: 'BrandNewPass1' } })
    fireEvent.change(screen.getByLabelText('Confirm new password'), { target: { value: 'BrandNewPass1' } })
    fireEvent.click(screen.getByRole('button', { name: 'Set the new password' }))
    await waitFor(() => expect(api.post).toHaveBeenLastCalledWith('/auth/reset-password', { login: 'ahmad@school.test', code: '123456', password: 'BrandNewPass1', password_confirmation: 'BrandNewPass1' }))
    expect(await screen.findByText('Your password was changed.')).toBeInTheDocument()
    expect(screen.getByRole('link', { name: 'Sign in' })).toHaveAttribute('href', '/login')
  })

  it('shows the reason when the code is wrong and can send a new code', async () => {
    api.post.mockResolvedValueOnce({ data: { message: 'sent' } })
      .mockRejectedValueOnce({ response: { data: { errors: { code: ['The code is wrong or expired. Ask for a new one.'] } } } })
      .mockResolvedValueOnce({ data: { message: 'sent again' } })
    renderPage()
    fireEvent.change(screen.getByLabelText('Email or phone number'), { target: { value: 'sara@school.test' } })
    fireEvent.click(screen.getByRole('button', { name: 'Send the code' }))
    await screen.findByLabelText('Code (6 digits)')
    fireEvent.change(screen.getByLabelText('Code (6 digits)'), { target: { value: '000000' } })
    fireEvent.change(screen.getByLabelText('New password'), { target: { value: 'BrandNewPass1' } })
    fireEvent.change(screen.getByLabelText('Confirm new password'), { target: { value: 'BrandNewPass1' } })
    fireEvent.click(screen.getByRole('button', { name: 'Set the new password' }))
    expect(await screen.findByRole('alert')).toHaveTextContent('wrong or expired')
    fireEvent.click(screen.getByRole('button', { name: 'Send a new code' }))
    await waitFor(() => expect(api.post).toHaveBeenCalledTimes(3))
    expect(await screen.findByRole('status')).toHaveTextContent('sent again')
  })
})
