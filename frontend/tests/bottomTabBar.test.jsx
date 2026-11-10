import { render, screen, within } from '@testing-library/react'
import { MemoryRouter } from 'react-router-dom'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import i18n from '../src/i18n'
import BottomTabBar from '../src/components/shared/BottomTabBar'

const state = vi.hoisted(() => ({ user: { role: 'parent' }, chat: 0, news: 0 }))
vi.mock('../src/features/Auth/AuthProvider', () => ({ useAuth: () => ({ user: state.user }) }))
vi.mock('../src/features/Chat/hooks/useUnreadChat', () => ({ default: () => state.chat }))
vi.mock('../src/features/Announcements/hooks/useUnreadAnnouncements', () => ({ default: () => state.news }))

const at = (path) => render(<MemoryRouter initialEntries={[path]}><BottomTabBar /></MemoryRouter>)
beforeEach(async () => { state.user = { role: 'parent' }; state.chat = 0; state.news = 0; await i18n.changeLanguage('en') })

describe('BottomTabBar', () => {
  it('is a nav of four links, an icon and a label each', () => {
    at('/parent')
    const nav = screen.getByRole('navigation', { name: 'Main tabs' })
    expect(within(nav).getAllByRole('link').map((a) => a.textContent)).toEqual(['Home', 'Inbox', 'News', 'Feed'])
  })

  it('marks only the current page with aria-current', () => {
    at('/parent/chat')
    const nav = screen.getByRole('navigation', { name: 'Main tabs' })
    expect(within(nav).getByRole('link', { name: 'Inbox' })).toHaveAttribute('aria-current', 'page')
    expect(within(nav).getByRole('link', { name: 'Home' })).not.toHaveAttribute('aria-current')
  })

  it('Home is current only on the home page, not on every page under it', () => {
    at('/parent/announcements')
    expect(screen.getByRole('link', { name: 'Home' })).not.toHaveAttribute('aria-current')
    expect(screen.getByRole('link', { name: 'News' })).toHaveAttribute('aria-current', 'page')
  })

  it('shows a count badge on the inbox, 99+ when large, and none for zero', () => {
    state.chat = 3
    const { unmount } = at('/parent')
    expect(screen.getByLabelText('3 unread')).toHaveTextContent('3')
    unmount()
    state.chat = 250
    at('/parent')
    expect(screen.getByLabelText('250 unread')).toHaveTextContent('99+')
  })

  it('has no badge when nothing is unread, and links follow the role', () => {
    state.user = { role: 'admin' }
    at('/admin')
    expect(screen.queryByLabelText(/unread/)).not.toBeInTheDocument()
    expect(screen.getByRole('link', { name: 'Inbox' })).toHaveAttribute('href', '/admin/chat')
  })
})
