import { fireEvent, render, screen, waitFor } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { MemoryRouter, Route, Routes, useLocation } from 'react-router-dom'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import i18n from '../src/i18n'
import { api } from '../src/api/client'
import NotificationBell from '../src/features/Notifications/NotificationBell'

vi.mock('../src/api/client', () => ({ api: { get: vi.fn(), post: vi.fn() } }))

const Where = () => <p data-testid="where">{useLocation().pathname}</p>
const renderBell = () => render(
  <QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}>
    <MemoryRouter><NotificationBell /><Routes><Route path="*" element={<Where />} /></Routes></MemoryRouter>
  </QueryClientProvider>,
)
// The counter comes from /badges, the list from /notifications (fetched when the bell opens).
const feed = (items, unread) => api.get.mockImplementation(async (url) => ({ data: { data: url === '/badges' ? { notifications: unread, announcements: 0, chat: 0, surveys: 0 } : { unread, items } } }))
const n = (id, kind, params, extra = {}) => ({ id, kind, params, link: '/student/homework', read: false, created_at: '2026-10-24T10:30:00+00:00', ...extra })

beforeEach(async () => {
  vi.resetAllMocks()
  await i18n.changeLanguage('en')
})

describe('NotificationBell', () => {
  it('shows the unread counter and the sentences in the reader language', async () => {
    feed([n('1', 'homework', { subject_ar: 'رياضيات', subject_en: 'Math', title: 'Page 10', child_ar: 'لينا', child_en: 'Lina' }), n('2', 'plan_reviewed', { decision: 'reject', title: 'Fractions', note: 'Add objectives' }, { read: true })], 1)
    renderBell()
    expect(await screen.findByLabelText('1 unread')).toBeInTheDocument()
    fireEvent.click(screen.getByRole('button', { name: 'Notifications' }))
    expect(await screen.findByText('New homework in Math: Page 10 (Lina)')).toBeInTheDocument()
    expect(await screen.findByText('Your lesson plan was rejected: Fractions — Add objectives')).toBeInTheDocument()
  })

  it('closes when you click anywhere else or press Escape, but not when you click inside', async () => {
    feed([n('1', 'homework', { subject_en: 'Math', title: 'Page 10' }, { read: true })], 0)
    renderBell()
    const open = () => fireEvent.click(screen.getByRole('button', { name: 'Notifications' }))
    await screen.findByRole('button', { name: 'Notifications' })
    open()
    fireEvent.mouseDown(screen.getByRole('dialog'))
    expect(screen.getByRole('dialog')).toBeInTheDocument()
    fireEvent.mouseDown(document.body)
    expect(screen.queryByRole('dialog')).not.toBeInTheDocument()
    open()
    fireEvent.keyDown(document, { key: 'Escape' })
    expect(screen.queryByRole('dialog')).not.toBeInTheDocument()
  })

  it('writes them in Arabic when the language is Arabic', async () => {
    await i18n.changeLanguage('ar')
    feed([n('1', 'grades', { subject_ar: 'رياضيات', subject_en: 'Math', title: 'اختبار 1' })], 1)
    renderBell()
    fireEvent.click(await screen.findByRole('button', { name: 'الإشعارات' }))
    expect(await screen.findByText('نُشرت علامات: رياضيات — اختبار 1')).toBeInTheDocument()
  })

  it('opens the page of a notification and marks it read', async () => {
    feed([n('abc', 'homework', { subject_en: 'Math', title: 'Page 10' })], 1)
    api.post.mockResolvedValue({ data: { data: { read: true } } })
    renderBell()
    fireEvent.click(await screen.findByRole('button', { name: 'Notifications' }))
    fireEvent.click(await screen.findByText(/New homework in Math/))
    await waitFor(() => expect(api.post).toHaveBeenCalledWith('/notifications/abc/read'))
    expect(screen.getByTestId('where')).toHaveTextContent('/student/homework')
  })

  it('marks everything as read', async () => {
    feed([n('1', 'summary', { subject_en: 'Math' }), n('2', 'summary', { subject_en: 'Math' })], 2)
    api.post.mockResolvedValue({ data: { data: { unread: 0 } } })
    renderBell()
    fireEvent.click(await screen.findByRole('button', { name: 'Notifications' }))
    fireEvent.click(screen.getByRole('button', { name: 'Mark all as read' }))
    await waitFor(() => expect(api.post).toHaveBeenCalledWith('/notifications/read-all'))
  })

  it('says when there are none, and survives an unknown kind', async () => {
    feed([], 0)
    renderBell()
    fireEvent.click(await screen.findByRole('button', { name: 'Notifications' }))
    expect(await screen.findByText('No notifications yet.')).toBeInTheDocument()
  })

  it('shows a generic sentence for a kind it does not know', async () => {
    feed([n('1', 'something_new', {})], 1)
    renderBell()
    fireEvent.click(await screen.findByRole('button', { name: 'Notifications' }))
    expect(await screen.findByText('New notification')).toBeInTheDocument()
  })
})
