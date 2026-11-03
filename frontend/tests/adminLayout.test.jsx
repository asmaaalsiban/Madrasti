import { fireEvent, render, screen, within } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { MemoryRouter } from 'react-router-dom'
import { describe, expect, it, vi } from 'vitest'
import i18n from '../src/i18n'
import AdminLayout from '../src/layouts/AdminLayout'

vi.mock('../src/features/Auth/AuthProvider', () => ({
  useAuth: () => ({ user: { role: 'admin', name: 'A' }, logout: () => {} }),
}))
vi.mock('../src/api/client', () => ({ api: { get: vi.fn((url) => Promise.resolve({ data: { data: url.startsWith('/chat') ? { count: 0 } : { count: 3 } } })) } }))
vi.mock('../src/tenant/useSchool', () => ({ useSchool: () => ({ data: { name: 'مدرسة' } }) }))

describe('AdminLayout', () => {
  it('shows the five nav links in Arabic', async () => {
    await i18n.changeLanguage('ar')
    render(<QueryClientProvider client={new QueryClient()}><MemoryRouter><AdminLayout /></MemoryRouter></QueryClientProvider>)
    for (const label of ['نظرة عامة', 'السنوات الدراسية', 'الصفوف', 'الشعب', 'المواد']) {
      expect(screen.getByRole('link', { name: label })).toBeInTheDocument()
    }
  })

  it('toggles the mobile menu', async () => {
    await i18n.changeLanguage('en')
    render(<QueryClientProvider client={new QueryClient()}><MemoryRouter><AdminLayout /></MemoryRouter></QueryClientProvider>)
    const toggle = screen.getByRole('button', { name: 'Menu' })
    expect(toggle).toHaveAttribute('aria-expanded', 'false')
    fireEvent.click(toggle)
    expect(toggle).toHaveAttribute('aria-expanded', 'true')
  })
  it('shows how many announcements are unread', async () => {
    await i18n.changeLanguage('en')
    render(<QueryClientProvider client={new QueryClient()}><MemoryRouter><AdminLayout /></MemoryRouter></QueryClientProvider>)
    expect(await screen.findByText('3')).toBeInTheDocument()
  })

  it('puts chat, announcements, class feed and notifications as icons at the top, not in the menu', async () => {
    await i18n.changeLanguage('en')
    render(<QueryClientProvider client={new QueryClient()}><MemoryRouter><AdminLayout /></MemoryRouter></QueryClientProvider>)
    const header = screen.getByRole('banner')
    for (const [name, href] of [[/^Chat/, '/admin/chat'], [/^Announcements/, '/admin/announcements'], [/^Class feed/, '/admin/stories']]) {
      const link = within(header).getByRole('link', { name })
      expect(link).toHaveAttribute('href', href)
    }
    expect(within(header).getByRole('button', { name: /Notifications/ })).toBeInTheDocument()
    expect(within(screen.getByRole('navigation')).queryByRole('link', { name: /Class feed/ })).not.toBeInTheDocument()
    expect(within(screen.getByRole('navigation')).queryByRole('link', { name: /Chat/ })).not.toBeInTheDocument()
  })
})
