import { render, screen, within } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { MemoryRouter } from 'react-router-dom'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { api } from '../src/api/client'
import i18n from '../src/i18n'
import DashboardLayout from '../src/layouts/DashboardLayout'

const auth = { user: { role: 'parent', name: 'P', children: [] } }
vi.mock('../src/features/Auth/AuthProvider', () => ({ useAuth: () => ({ user: auth.user, logout: () => {} }) }))
vi.mock('../src/api/client', () => ({ api: { get: vi.fn(() => Promise.resolve({ data: { data: { notifications: 4, announcements: 2, chat: 1, surveys: 0 } } })) } }))
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

  it('asks for the four header counters in ONE request, not four', async () => {
    api.get.mockClear()
    auth.user = { role: 'parent', name: 'P', children: [] }
    show()
    expect(await screen.findByRole('link', { name: 'Announcements (2)' })).toBeInTheDocument()
    expect(screen.getByRole('link', { name: 'Chat (1)' })).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Notifications (4)' })).toBeInTheDocument()
    const urls = api.get.mock.calls.map((c) => c[0])
    expect(urls).toEqual(['/badges'])
  })
})
