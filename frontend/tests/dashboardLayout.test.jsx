import { render, screen, within } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { MemoryRouter } from 'react-router-dom'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import i18n from '../src/i18n'
import DashboardLayout from '../src/layouts/DashboardLayout'

const auth = { user: { role: 'parent', name: 'P', children: [] } }
vi.mock('../src/features/Auth/AuthProvider', () => ({ useAuth: () => ({ user: auth.user, logout: () => {} }) }))
vi.mock('../src/api/client', () => ({ api: { get: vi.fn(() => Promise.resolve({ data: { data: { count: 0 } } })) } }))
vi.mock('../src/tenant/useSchool', () => ({ useSchool: () => ({ data: { name: 'School' } }) }))

const show = () => render(<QueryClientProvider client={new QueryClient()}><MemoryRouter><DashboardLayout /></MemoryRouter></QueryClientProvider>)

beforeEach(async () => { await i18n.changeLanguage('en') })

describe('DashboardLayout header icons', () => {
  it('every account gets chat, announcements, class feed and notifications at the top', () => {
    for (const role of ['parent', 'student', 'teacher']) {
      auth.user = { role, name: 'X', children: [], hidden_features: [] }
      const { unmount } = show()
      const header = screen.getByRole('banner')
      expect(within(header).getByRole('link', { name: /^Class feed/ })).toHaveAttribute('href', `/${role}/stories`)
      expect(within(header).getByRole('link', { name: /^Chat/ })).toHaveAttribute('href', `/${role}/chat`)
      expect(within(header).getByRole('link', { name: /^Announcements/ })).toHaveAttribute('href', `/${role}/announcements`)
      unmount()
    }
  })

  it('a member of staff does not see an icon the principal switched off', () => {
    auth.user = { role: 'teacher', name: 'T', hidden_features: ['stories', 'chat'] }
    show()
    const header = screen.getByRole('banner')
    expect(within(header).queryByRole('link', { name: /^Class feed/ })).not.toBeInTheDocument()
    expect(within(header).queryByRole('link', { name: /^Chat/ })).not.toBeInTheDocument()
    expect(within(header).getByRole('link', { name: /^Announcements/ })).toBeInTheDocument()
  })
})
