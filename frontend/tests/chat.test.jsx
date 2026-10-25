import { fireEvent, render, screen, waitFor, within } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { MemoryRouter } from 'react-router-dom'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import i18n from '../src/i18n'
import { api } from '../src/api/client'
import ChatPage from '../src/features/Chat/pages/ChatPage'

vi.mock('../src/api/client', () => ({ api: { get: vi.fn(), post: vi.fn(), delete: vi.fn() } }))
const auth = vi.hoisted(() => ({ user: null }))
vi.mock('../src/features/Auth/AuthProvider', () => ({ useAuth: () => ({ user: auth.user }) }))

const renderChat = (path = '/') => render(
  <QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}><MemoryRouter initialEntries={[path]}><ChatPage /></MemoryRouter></QueryClientProvider>,
)

const direct = { id: 1, type: 'direct', title: 'Teacher A', other: { id: 5, name: 'Teacher A', role: 'teacher', label: 'رياضيات' }, unread: 2, can_post: true, members: 2, last_message: { body: 'See you', has_image: false, mine: false, at: '2026-10-24T10:00:00+00:00' } }
const group = { id: 2, type: 'group', title: 'Math 5-A', other: null, unread: 0, can_post: false, members: 5, last_message: null }
const message = (id, body, extra = {}) => ({ id, body, has_image: false, created_at: '2026-10-24T10:05:00+00:00', sender: { id: 9, name: 'Parent' }, mine: false, read_by: 0, recipients: 1, read: false, ...extra })

const serve = (conversations, messages = []) => api.get.mockImplementation((url) => {
  if (url === '/chat/conversations') return Promise.resolve({ data: { data: conversations } })
  if (url.startsWith('/chat/conversations/') && url.endsWith('/messages')) return Promise.resolve({ data: { data: messages } })
  if (url === '/chat/contacts') return Promise.resolve({ data: { data: [{ id: 5, name: 'Teacher A', role: 'teacher', label: 'رياضيات' }, { id: 6, name: 'Admin', role: 'admin', label: null }] } })
  if (url === '/announcements/options') return Promise.resolve({ data: { data: { can_publish_all: false, classrooms: [{ id: 3, name: '5-A' }] } } })
  return Promise.resolve({ data: { data: { count: 0 } } })
})

beforeEach(async () => {
  vi.resetAllMocks()
  await i18n.changeLanguage('en')
  auth.user = { role: 'parent' }
})

describe('ChatPage', () => {
  it('lists the conversations with unread counters and the last message', async () => {
    serve([direct, group])
    renderChat()
    expect(await screen.findByText('Teacher A')).toBeInTheDocument()
    expect(screen.getByLabelText('2 unread')).toBeInTheDocument()
    expect(screen.getByText('See you')).toBeInTheDocument()
    expect(screen.getByText('Choose a conversation.')).toBeInTheDocument()
  })

  it('opens a conversation, shows the messages with read ticks and sends a text', async () => {
    serve([direct], [message(1, 'Hello', { sender: { id: 5, name: 'Teacher A' } }), message(2, 'Hi teacher', { mine: true, read: true, read_by: 1 })])
    api.post.mockResolvedValue({ data: { data: message(3, 'Thanks', { mine: true }) } })
    renderChat('/?c=1')
    expect(await screen.findByText('Hello')).toBeInTheDocument()
    expect(screen.getByLabelText('Read')).toBeInTheDocument() // double tick on my read message
    fireEvent.change(screen.getByLabelText('Write a message'), { target: { value: 'Thanks' } })
    fireEvent.click(screen.getByRole('button', { name: 'Send' }))
    await waitFor(() => expect(api.post).toHaveBeenCalledWith('/chat/conversations/1/messages', expect.any(FormData)))
    expect(api.post.mock.calls[0][1].get('body')).toBe('Thanks')
  })

  it('sends a photo and shows a sent message with one tick', async () => {
    URL.createObjectURL = vi.fn(() => 'blob:x')
    serve([direct], [message(2, 'Hi', { mine: true })])
    api.post.mockResolvedValue({ data: { data: message(3, null, { mine: true, has_image: true }) } })
    renderChat('/?c=1')
    await screen.findByLabelText('Sent')
    fireEvent.change(screen.getByLabelText('Photo'), { target: { files: [new File(['x'], 'page.jpg', { type: 'image/jpeg' })] } })
    expect(screen.getByText(/page\.jpg/)).toBeInTheDocument()
    fireEvent.click(screen.getByRole('button', { name: 'Send' }))
    await waitFor(() => expect(api.post).toHaveBeenCalled())
    expect(api.post.mock.calls[0][1].get('image').name).toBe('page.jpg')
  })

  it('shows the reason when a message is refused', async () => {
    serve([direct], [])
    api.post.mockRejectedValue({ response: { data: { message: 'You cannot message this person.' } } })
    renderChat('/?c=1')
    await screen.findByLabelText('Write a message')
    fireEvent.change(screen.getByLabelText('Write a message'), { target: { value: 'x' } })
    fireEvent.click(screen.getByRole('button', { name: 'Send' }))
    expect(await screen.findByRole('alert')).toHaveTextContent('cannot message')
  })

  it('shows a group with its read counts and hides the box when only teachers may write', async () => {
    serve([group], [message(1, 'Trip on Sunday', { sender: { id: 5, name: 'Teacher A' } }), message(2, 'Bring lunch', { mine: true, read_by: 3, recipients: 4 })])
    renderChat('/?c=2')
    expect(await screen.findByText('Trip on Sunday')).toBeInTheDocument()
    expect(screen.getByText('Teacher A', { selector: 'p' })).toBeInTheDocument() // sender names show in a group
    expect(screen.getByLabelText('Read by 3 of 4')).toBeInTheDocument()
    expect(screen.getByText('5 members')).toBeInTheDocument()
    expect(screen.getByText('Only the teachers can write in this group.')).toBeInTheDocument()
    expect(screen.queryByLabelText('Write a message')).not.toBeInTheDocument()
  })

  it('starts a chat with a person found in the contacts', async () => {
    serve([])
    api.post.mockResolvedValue({ data: { data: { ...direct, id: 9 } } })
    renderChat()
    fireEvent.click(await screen.findByRole('button', { name: 'New chat' }))
    fireEvent.click(await screen.findByText('Teacher A'))
    await waitFor(() => expect(api.post).toHaveBeenCalledWith('/chat/conversations', { user_id: 5 }))
  })

  it('lets a teacher create a section group and parents cannot', async () => {
    auth.user = { role: 'teacher' }
    serve([])
    api.post.mockResolvedValue({ data: { data: { ...group, id: 7 } } })
    renderChat()
    fireEvent.click(await screen.findByRole('button', { name: 'New group' }))
    await screen.findByRole('option', { name: '5-A' })
    fireEvent.change(screen.getByLabelText('Group name (optional)'), { target: { value: 'Math 5-A' } })
    fireEvent.click(screen.getByLabelText('Include the parents'))
    fireEvent.click(screen.getByLabelText('Only the teachers can write'))
    fireEvent.click(screen.getByRole('button', { name: 'Create the group' }))
    await waitFor(() => expect(api.post).toHaveBeenCalledWith('/chat/groups', { classroom_id: 3, title: 'Math 5-A', include_parents: true, members_can_post: false }))
  })

  it('does not offer groups to a parent', async () => {
    serve([])
    renderChat()
    await screen.findByRole('button', { name: 'New chat' })
    expect(screen.queryByRole('button', { name: 'New group' })).not.toBeInTheDocument()
  })

  it('deletes my own message', async () => {
    serve([direct], [message(2, 'Oops', { mine: true })])
    api.delete.mockResolvedValue({})
    renderChat('/?c=1')
    const bubble = (await screen.findByText('Oops')).closest('div')
    fireEvent.click(within(bubble).getByRole('button', { name: 'Delete' }))
    await waitFor(() => expect(api.delete).toHaveBeenCalledWith('/chat/messages/2'))
  })
})
