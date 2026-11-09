import { fireEvent, render, screen, waitFor, within } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { MemoryRouter } from 'react-router-dom'
import { describe, expect, it, vi } from 'vitest'
import i18n from '../src/i18n'
import AdminLayout from '../src/layouts/AdminLayout'

vi.mock('../src/features/Auth/AuthProvider', () => ({
  useAuth: () => ({ user: { role: 'admin', name: 'A' }, logout: () => {} }),
}))
vi.mock('../src/api/client', () => ({ api: { get: vi.fn((url) => Promise.resolve({ data: { data: url === '/badges' ? { notifications: 0, announcements: 3, chat: 0, surveys: 0 } : { count: 0 } } })) } }))
vi.mock('../src/tenant/useSchool', () => ({ useSchool: () => ({ data: { name: 'مدرسة' } }) }))

describe('AdminLayout', () => {
  it('shows the five nav links in Arabic', async () => {
    await i18n.changeLanguage('ar')
    render(<QueryClientProvider client={new QueryClient()}><MemoryRouter initialEntries={['/admin/classrooms']}><AdminLayout /></MemoryRouter></QueryClientProvider>)
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
    await waitFor(() => expect(within(screen.getByRole('banner')).getByText('3')).toBeInTheDocument()) // in the header; the bottom tab bar shows it too
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
    expect(within(screen.getByRole('navigation', { name: 'Menu' })).queryByRole('link', { name: /Class feed/ })).not.toBeInTheDocument()
    expect(within(screen.getByRole('navigation', { name: 'Menu' })).queryByRole('link', { name: /Chat/ })).not.toBeInTheDocument()
  })

  it('keeps the menu groups closed except the one of the current page, and opens and closes them', async () => {
    await i18n.changeLanguage('en')
    localStorage.clear()
    render(<QueryClientProvider client={new QueryClient()}><MemoryRouter initialEntries={['/admin/students']}><AdminLayout /></MemoryRouter></QueryClientProvider>)
    const people = screen.getByRole('button', { name: 'People' })
    const school = screen.getByRole('button', { name: 'School setup' })
    expect(people).toHaveAttribute('aria-expanded', 'true')
    expect(school).toHaveAttribute('aria-expanded', 'false')
    expect(screen.getByRole('link', { name: 'Students' })).toBeInTheDocument()
    expect(screen.queryByRole('link', { name: 'Buildings' })).toBeNull() // inside the closed group

    fireEvent.click(school)
    expect(school).toHaveAttribute('aria-expanded', 'true')
    expect(screen.getByRole('link', { name: 'Buildings' })).toBeInTheDocument()
    fireEvent.click(people)
    expect(screen.queryByRole('link', { name: 'Students' })).toBeNull()
  })

  it('remembers which groups were opened', async () => {
    await i18n.changeLanguage('en')
    localStorage.setItem('admin_nav_open', JSON.stringify({ money: true }))
    render(<QueryClientProvider client={new QueryClient()}><MemoryRouter initialEntries={['/admin']}><AdminLayout /></MemoryRouter></QueryClientProvider>)
    expect(screen.getByRole('button', { name: 'Money & staff' })).toHaveAttribute('aria-expanded', 'true')
    expect(screen.getByRole('button', { name: 'People' })).toHaveAttribute('aria-expanded', 'false')
    localStorage.clear()
  })
})
