import { fireEvent, render, screen, waitFor, within } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { MemoryRouter } from 'react-router-dom'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import i18n from '../src/i18n'
import { api } from '../src/api/client'
import StoriesPage from '../src/features/Stories/StoriesPage'

vi.mock('../src/api/client', () => ({ api: { get: vi.fn(), post: vi.fn(), put: vi.fn(), delete: vi.fn() } }))
const auth = { user: { id: 2, name: 'Sara Ali', role: 'teacher' } }
vi.mock('../src/features/Auth/AuthProvider', () => ({ useAuth: () => ({ user: auth.user }) }))
const wrap = () => render(<QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}><MemoryRouter><StoriesPage /></MemoryRouter></QueryClientProvider>)

const story = (id, extra = {}) => ({ id, audience: 'classroom', created_at: new Date().toISOString(), body: `Post ${id}`, is_pinned: false, comments_enabled: true, author: { id: 2, name: 'Sara Ali', role: 'teacher', staff_type: 'teacher' }, target: { name_ar: '5-A', name_en: '5-A' }, media: [], reactions_count: 0, liked: false, comments_count: 0, can_manage: false, ...extra })
const feed = (data, extra = {}) => ({ data, meta: { current_page: 1, last_page: 1 }, can_post: false, ...extra })

beforeEach(async () => {
  vi.resetAllMocks()
  URL.createObjectURL = vi.fn(() => 'blob:x')
  URL.revokeObjectURL = vi.fn()
  auth.user = { id: 2, name: 'Sara Ali', role: 'teacher' }
  await i18n.changeLanguage('en')
})

describe('StoriesPage as a reader', () => {
  it('shows the posts with who, where, the text and the counts, and no composer', async () => {
    api.get.mockResolvedValue({ data: feed([story(1, { reactions_count: 3, comments_count: 2 }), story(2, { audience: 'school', target: null, is_pinned: true })]) })
    wrap()
    expect(await screen.findByText('Post 1')).toBeInTheDocument()
    expect(screen.getByText('Post 2')).toBeInTheDocument()
    expect(screen.getByText('The whole school')).toBeInTheDocument()
    expect(screen.getByText('5-A')).toBeInTheDocument()
    expect(screen.queryByRole('form', { name: 'Share something' })).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Delete' })).not.toBeInTheDocument() // not the author
  })

  it('says so when there is nothing yet', async () => {
    api.get.mockResolvedValue({ data: feed([]) })
    wrap()
    expect(await screen.findByText(/No posts yet/)).toBeInTheDocument()
  })

  it('hearts a post and opens the comments to write one', async () => {
    api.get.mockImplementation((url) => Promise.resolve({ data: url.endsWith('/comments') ? { data: [{ id: 5, body: 'Lovely!', created_at: new Date().toISOString(), user: { id: 9, name: 'Abu Ali', role: 'parent' }, can_delete: false }] } : feed([story(1)]) }))
    api.post.mockResolvedValue({ data: { data: {} } })
    wrap()
    await screen.findByText('Post 1')
    fireEvent.click(screen.getByRole('button', { name: 'Like' }))
    await waitFor(() => expect(api.post).toHaveBeenCalledWith('/stories/1/react'))
    fireEvent.click(screen.getByRole('button', { name: /Comments/ }))
    expect(await screen.findByText('Lovely!')).toBeInTheDocument()
    fireEvent.change(screen.getByLabelText('Write a comment...'), { target: { value: 'Great!' } })
    fireEvent.click(screen.getByRole('button', { name: 'Send' }))
    await waitFor(() => expect(api.post).toHaveBeenCalledWith('/stories/1/comments', { body: 'Great!' }))
  })

  it('shows that comments are closed', async () => {
    api.get.mockImplementation((url) => Promise.resolve({ data: url.endsWith('/comments') ? { data: [] } : feed([story(1, { comments_enabled: false })]) }))
    wrap()
    await screen.findByText('Post 1')
    fireEvent.click(screen.getByRole('button', { name: /Comments/ }))
    expect(await screen.findByText('Comments are closed on this post.')).toBeInTheDocument()
    expect(screen.queryByLabelText('Write a comment...')).not.toBeInTheDocument()
  })

  it('loads more posts', async () => {
    api.get.mockImplementation((url, cfg) => Promise.resolve({ data: { data: [story(cfg.params.page)], meta: { current_page: cfg.params.page, last_page: 2 }, can_post: false } }))
    wrap()
    await screen.findByText('Post 1')
    fireEvent.click(screen.getByRole('button', { name: 'Show more' }))
    expect(await screen.findByText('Post 2')).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Show more' })).not.toBeInTheDocument()
  })
})

describe('StoriesPage as the author', () => {
  const targets = { classrooms: [{ id: 3, name: '5-A' }, { id: 4, name: '5-B' }], grades: [], school: false }
  beforeEach(() => {
    api.get.mockImplementation((url) => Promise.resolve({ data: url === '/stories/targets' ? { data: targets } : feed([story(1, { can_manage: true })], { can_post: true }) }))
  })

  it('posts text and photos to a chosen class', async () => {
    api.post.mockResolvedValue({ data: { data: {} } })
    wrap()
    const form = await screen.findByRole('form', { name: 'Share something' })
    fireEvent.change(within(form).getByLabelText("What's new in the class?"), { target: { value: 'Science fair!' } })
    fireEvent.change(await within(form).findByRole('combobox'), { target: { value: 'classroom:4' } })
    const photo = new File(['x'], 'a.jpg', { type: 'image/jpeg' })
    fireEvent.change(within(form).getByLabelText('Add photos'), { target: { files: [photo] } })
    expect(within(form).getByAltText('New photo 1')).toBeInTheDocument()
    fireEvent.click(within(form).getByRole('button', { name: 'Post' }))
    await waitFor(() => expect(api.post).toHaveBeenCalled())
    const [url, data] = api.post.mock.calls[0]
    expect(url).toBe('/stories')
    expect(data.get('audience')).toBe('classroom')
    expect(data.get('classroom_id')).toBe('4')
    expect(data.get('body')).toBe('Science fair!')
    expect(data.getAll('images[]')).toHaveLength(1)
  })

  it('cannot post an empty message', async () => {
    wrap()
    const form = await screen.findByRole('form', { name: 'Share something' })
    expect(within(form).getByRole('button', { name: 'Post' })).toBeDisabled()
  })

  it('pins, closes comments, and deletes after the styled confirmation', async () => {
    api.put.mockResolvedValue({ data: { data: {} } })
    api.delete.mockResolvedValue({ data: {} })
    wrap()
    await screen.findByText('Post 1')
    fireEvent.click(screen.getByRole('button', { name: 'Pin to the top' }))
    await waitFor(() => expect(api.put).toHaveBeenCalledWith('/stories/1', { is_pinned: true }))
    fireEvent.click(screen.getByRole('button', { name: 'Close comments' }))
    await waitFor(() => expect(api.put).toHaveBeenCalledWith('/stories/1', { comments_enabled: false }))
    fireEvent.click(screen.getByRole('button', { name: 'Delete' }))
    const ask = await screen.findByRole('dialog', { name: 'Delete this post?' })
    expect(api.delete).not.toHaveBeenCalled()
    fireEvent.click(within(ask).getByRole('button', { name: 'Delete' }))
    await waitFor(() => expect(api.delete).toHaveBeenCalledWith('/stories/1'))
  })

  it('offers the principal the whole school and grades too', async () => {
    api.get.mockImplementation((url) => Promise.resolve({ data: url === '/stories/targets' ? { data: { classrooms: [{ id: 3, name: '5-A' }], grades: [{ id: 5, name_ar: 'الخامس', name_en: 'Grade 5' }], school: true } } : feed([], { can_post: true }) }))
    auth.user = { id: 1, name: 'Admin', role: 'admin' }
    wrap()
    const select = await screen.findByRole('combobox')
    expect(within(select).getAllByRole('option').map((o) => o.textContent)).toEqual(['The whole school', 'All of Grade 5', '5-A'])
  })
})
