import { render, screen, waitFor } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { MemoryRouter } from 'react-router-dom'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import i18n from '../src/i18n'
import { api } from '../src/api/client'
import StaffAttendancePage from '../src/features/StaffAttendance/StaffAttendancePage'
import RulesPage from '../src/features/Payroll/pages/RulesPage'

vi.mock('../src/api/client', () => ({ api: { get: vi.fn(), post: vi.fn(), put: vi.fn() } }))
const auth = { user: { role: 'teacher', staff_type: 'teacher' } }
vi.mock('../src/features/Auth/AuthProvider', () => ({ useAuth: () => ({ user: auth.user }) }))
vi.mock('../src/features/StaffAttendance/CheckInCard', () => ({ default: () => <div>check-in-card</div> }))

const wrap = (ui) => render(<QueryClientProvider client={new QueryClient()}><MemoryRouter>{ui}</MemoryRouter></QueryClientProvider>)

beforeEach(async () => { vi.resetAllMocks(); await i18n.changeLanguage('en') })

describe('access for non-managers', () => {
  it('a regular teacher only sees the check-in card and never asks for the day table', async () => {
    wrap(<StaffAttendancePage />)
    expect(await screen.findByText('check-in-card')).toBeInTheDocument()
    expect(api.get).not.toHaveBeenCalled()
  })

  it('rules page shows a friendly message on 403', async () => {
    api.get.mockRejectedValue({ response: { status: 403 } })
    wrap(<RulesPage base="/teacher" />)
    await waitFor(() => expect(screen.getByRole('heading')).toBeInTheDocument())
    expect(screen.getByText('You do not have access to this page.')).toBeInTheDocument()
  })
})
