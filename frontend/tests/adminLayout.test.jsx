import { fireEvent, render, screen } from '@testing-library/react'
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
})
