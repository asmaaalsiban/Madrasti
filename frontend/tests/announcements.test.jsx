import { fireEvent, render, screen, waitFor } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { MemoryRouter } from 'react-router-dom'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import i18n from '../src/i18n'
import { api } from '../src/api/client'
import AnnouncementsPage from '../src/features/Announcements/pages/AnnouncementsPage'

vi.mock('../src/api/client', () => ({ api: { get: vi.fn(), post: vi.fn(), put: vi.fn(), delete: vi.fn() } }))
const auth = vi.hoisted(() => ({ user: null }))
vi.mock('../src/features/Auth/AuthProvider', () => ({ useAuth: () => ({ user: auth.user }) }))

const renderPage = () => render(<QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}><MemoryRouter><AnnouncementsPage /></MemoryRouter></QueryClientProvider>)
const item = (id, extra = {}) => ({ id, title: `Notice ${id}`, body: `Body ${id}`, audience: 'all', audience_role: null, classroom: null, is_pinned: false, expires_at: null, created_at: '2026-10-24T10:00:00+00:00', author: { id: 1, name: 'Admin' }, can_edit: false, read: false, ...extra })

beforeEach(async () => {
  vi.resetAllMocks()
  await i18n.changeLanguage('en')
})

describe('AnnouncementsPage', () => {
  it('shows unread ones marked and marks one read when it is opened', async () => {
    auth.user = { role: 'parent' }
    api.get.mockResolvedValue({ data: { data: [item(1), item(2, { read: true, is_pinned: true })] } })
    api.post.mockResolvedValue({ data: { data: { read: true } } })
    renderPage()
    expect(await screen.findByText('Notice 1')).toBeInTheDocument()
    expect(screen.getAllByText('New')).toHaveLength(1)
    expect(screen.queryByRole('button', { name: 'New announcement' })).not.toBeInTheDocument() // parents cannot publish
    fireEvent.click(screen.getByRole('button', { name: /Notice 1/ }))
    expect(screen.getByText('Body 1')).toBeInTheDocument()
    await waitFor(() => expect(api.post).toHaveBeenCalledWith('/announcements/1/read'))
  })

  it('lets the admin publish to a section with a pin', async () => {
    auth.user = { role: 'admin' }
    api.get.mockImplementation((url) => Promise.resolve({ data: { data: url === '/announcements/options' ? { can_publish_all: true, classrooms: [{ id: 3, name: '5-A' }] } : [] } }))
    api.post.mockResolvedValue({ data: { data: {} } })
    renderPage()
    fireEvent.click(await screen.findByRole('button', { name: 'New announcement' }))
    fireEvent.change(screen.getByLabelText('Send to'), { target: { value: 'classroom' } })
    fireEvent.change(screen.getByLabelText('Title'), { target: { value: 'Trip' } })
    fireEvent.change(screen.getByLabelText('Text'), { target: { value: 'Bring lunch' } })
    fireEvent.click(screen.getByLabelText('Pin to the top'))
    fireEvent.click(screen.getByRole('button', { name: 'Publish' }))
    await waitFor(() => expect(api.post).toHaveBeenCalledWith('/announcements', { title: 'Trip', body: 'Bring lunch', audience: 'classroom', is_pinned: true, expires_at: null, classroom_id: 3 }))
  })

  it('gives a teacher only their sections and no pin, and shows the reason when refused', async () => {
    auth.user = { role: 'teacher' }
    api.get.mockImplementation((url) => Promise.resolve({ data: { data: url === '/announcements/options' ? { can_publish_all: false, classrooms: [{ id: 3, name: '5-A' }] } : [] } }))
    api.post.mockRejectedValue({ response: { data: { errors: { classroom_id: ['You do not teach this subject in this section.'] } } } })
    renderPage()
    fireEvent.click(await screen.findByRole('button', { name: 'New announcement' }))
    expect(screen.queryByLabelText('Send to')).not.toBeInTheDocument()
    expect(screen.queryByLabelText('Pin to the top')).not.toBeInTheDocument()
    fireEvent.change(screen.getByLabelText('Title'), { target: { value: 'Quiz' } })
    fireEvent.change(screen.getByLabelText('Text'), { target: { value: 'Tomorrow' } })
    fireEvent.click(screen.getByRole('button', { name: 'Publish' }))
    expect(await screen.findByRole('alert')).toHaveTextContent('do not teach')
  })

  it('lets the author edit and delete', async () => {
    auth.user = { role: 'teacher' }
    api.get.mockImplementation((url) => Promise.resolve({ data: { data: url === '/announcements/options' ? { can_publish_all: false, classrooms: [{ id: 3, name: '5-A' }] } : [item(1, { can_edit: true, read: true, audience: 'classroom', classroom: { id: 3, name: '5-A' } })] } }))
    api.delete.mockResolvedValue({})
    renderPage()
    await screen.findByText('Notice 1')
    expect(screen.getByText('Admin · 5-A')).toBeInTheDocument()
    fireEvent.click(screen.getByRole('button', { name: 'Delete' }))
    fireEvent.click(screen.getAllByRole('button', { name: 'Delete' }).at(-1))
    await waitFor(() => expect(api.delete).toHaveBeenCalledWith('/announcements/1'))
  })
})
