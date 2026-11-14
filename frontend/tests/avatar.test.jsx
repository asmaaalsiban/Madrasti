import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { fireEvent, render, screen, waitFor } from '@testing-library/react'
import { useEffect } from 'react'
import { MemoryRouter } from 'react-router-dom'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import i18n from '../src/i18n'
import { api } from '../src/api/client'
import Avatar from '../src/components/shared/Avatar'
import ProfilePage from '../src/features/Profile/ProfilePage'

vi.mock('../src/api/client', () => ({ api: { get: vi.fn(), post: vi.fn(), put: vi.fn(), delete: vi.fn() } }))
// jsdom has no canvas or layout, so the cropper widget and the cut itself are replaced; the real ones are checked in a browser.
vi.mock('react-easy-crop', () => ({ default: function FakeCropper({ onCropComplete }) { useEffect(() => { onCropComplete({}, { x: 1, y: 2, width: 30, height: 30 }) }, []); return <div data-testid="cropper" /> } }))
const cut = vi.hoisted(() => new File(['cut'], 'avatar.jpg', { type: 'image/jpeg' }))
vi.mock('../src/features/Profile/cropImage', () => ({ default: vi.fn(async () => cut) }))
const auth = vi.hoisted(() => ({ user: null, updateUser: vi.fn() }))
vi.mock('../src/features/Auth/AuthProvider', () => ({ useAuth: () => ({ user: auth.user, updateUser: auth.updateUser }) }))

const Providers = ({ children }) => <QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}><MemoryRouter>{children}</MemoryRouter></QueryClientProvider>
const wrap = (ui) => render(ui, { wrapper: Providers })

beforeEach(async () => {
  vi.resetAllMocks()
  URL.createObjectURL = vi.fn(() => 'blob:photo')
  await i18n.changeLanguage('en')
})

describe('Avatar', () => {
  it('shows the initials of the name when there is no picture, in Arabic and English', () => {
    const { container, rerender } = wrap(<Avatar user={{ name: 'Layla Ahmad' }} />)
    expect(container.textContent).toBe('LA')
    rerender(<Avatar user={{ name: 'ليلى أحمد' }} />)
    expect(container.textContent).toBe('لأ')
    rerender(<Avatar user={{ name: 'Sami' }} />)
    expect(container.textContent).toBe('S')
    rerender(<Avatar user={{ name: '' }} />)
    expect(container.textContent).toBe('?')
  })

  it('keeps the same colour for the same name', () => {
    const { container, rerender } = wrap(<Avatar user={{ name: 'Layla Ahmad' }} />)
    const first = container.querySelector('span span span').style.background
    rerender(<Avatar user={{ name: 'Layla Ahmad' }} />)
    expect(container.querySelector('span span span').style.background).toBe(first)
  })

  it('draws a ready-made character from its key', () => {
    const { container } = wrap(<Avatar user={{ name: 'x', avatar: { type: 'preset', key: 'a07' } }} />)
    expect(container.querySelector('img')).toHaveAttribute('src', '/avatars/a07.svg')
  })

  it('fetches a private photo with the sign-in and shows it', async () => {
    api.get.mockResolvedValue({ data: new Blob(['x']) })
    const { container } = wrap(<Avatar user={{ name: 'x', avatar: { type: 'photo', url: '/users/5/avatar', v: 'abc12345' } }} />)
    await waitFor(() => expect(container.querySelector('img')).toHaveAttribute('src', 'blob:photo'))
    expect(api.get).toHaveBeenCalledWith('/users/5/avatar', { responseType: 'blob' })
  })

  it('shows the initials while the photo is not there yet', () => {
    api.get.mockReturnValue(new Promise(() => {}))
    const { container } = wrap(<Avatar user={{ name: 'Sami Ali', avatar: { type: 'photo', url: '/users/5/avatar', v: 'abc12345' } }} />)
    expect(container.textContent).toBe('SA')
  })

  it('can have a ring and a corner badge, and is named for screen readers only when asked', () => {
    const { container, rerender } = wrap(<Avatar user={{ name: 'Sami' }} ring badge="verified" />)
    expect(container.querySelector('.ring-brand')).not.toBeNull()
    expect(container.querySelector('svg')).not.toBeNull()
    expect(container.firstChild).toHaveAttribute('aria-hidden', 'true')
    rerender(<Avatar user={{ name: 'Sami' }} name="Sami" badge="online" />)
    expect(screen.getByRole('img', { name: 'Sami' })).toBeInTheDocument()
    expect(container.querySelector('.bg-green-500')).not.toBeNull()
  })
})

describe('ProfilePage', () => {
  const teacher = { id: 1, name: 'Sami Ali', role: 'teacher', avatar: null }
  const showAs = (user) => { auth.user = user; return wrap(<ProfilePage />) }

  it('lets a teacher choose a photo, cut it in the cropper, and sends the cut file', async () => {
    api.post.mockResolvedValue({ data: { data: { ...teacher, avatar: { type: 'photo', url: '/users/1/avatar', v: 'z' } } } })
    showAs(teacher)
    fireEvent.change(screen.getByLabelText('Choose a photo'), { target: { files: [new File(['x'], 'me.png', { type: 'image/png' })] } })
    expect(api.post).not.toHaveBeenCalled()
    expect(await screen.findByTestId('cropper')).toBeInTheDocument()
    fireEvent.click(screen.getByRole('button', { name: 'Save photo' }))
    await waitFor(() => expect(api.post).toHaveBeenCalled())
    expect(api.post.mock.calls[0][0]).toBe('/profile/avatar')
    expect(api.post.mock.calls[0][1].get('photo')).toBe(cut)
    await waitFor(() => expect(auth.updateUser).toHaveBeenCalledWith(expect.objectContaining({ avatar: expect.objectContaining({ type: 'photo' }) })))
    await waitFor(() => expect(screen.queryByTestId('cropper')).not.toBeInTheDocument())
  })

  it('sends nothing when the cropper is cancelled', async () => {
    showAs(teacher)
    fireEvent.change(screen.getByLabelText('Choose a photo'), { target: { files: [new File(['x'], 'me.png', { type: 'image/png' })] } })
    fireEvent.click(await screen.findByRole('button', { name: 'Cancel' }))
    expect(screen.queryByTestId('cropper')).not.toBeInTheDocument()
    expect(api.post).not.toHaveBeenCalled()
  })

  it('shows no photo upload to a student, only the characters', () => {
    showAs({ id: 2, name: 'Layla', role: 'student', avatar: null })
    expect(screen.queryByLabelText('Choose a photo')).not.toBeInTheDocument()
    expect(screen.getByText(/real photos of students are not kept/)).toBeInTheDocument()
    expect(screen.getAllByRole('button', { name: /^Character \d+$/ })).toHaveLength(24)
  })

  it('picks a character, marks the chosen one and sends its key', async () => {
    api.put.mockResolvedValue({ data: { data: { ...teacher, avatar: { type: 'preset', key: 'a07' } } } })
    showAs(teacher)
    fireEvent.click(screen.getByRole('button', { name: 'Character 7' }))
    await waitFor(() => expect(api.put).toHaveBeenCalledWith('/profile/avatar/preset', { key: 'a07' }))
    await waitFor(() => expect(auth.updateUser).toHaveBeenCalled())
  })

  it('marks the character that is the picture now', () => {
    showAs({ ...teacher, avatar: { type: 'preset', key: 'a03' } })
    expect(screen.getByRole('button', { name: 'Character 3' })).toHaveAttribute('aria-pressed', 'true')
    expect(screen.getByRole('button', { name: 'Character 4' })).toHaveAttribute('aria-pressed', 'false')
  })

  it('removes the picture only when there is one', async () => {
    api.delete.mockResolvedValue({ data: { data: { ...teacher, avatar: null } } })
    const { unmount } = showAs(teacher)
    expect(screen.queryByRole('button', { name: /Delete my picture/ })).not.toBeInTheDocument()
    unmount()
    showAs({ ...teacher, avatar: { type: 'preset', key: 'a03' } })
    fireEvent.click(screen.getByRole('button', { name: /Delete my picture/ }))
    await waitFor(() => expect(api.delete).toHaveBeenCalledWith('/profile/avatar'))
  })

  it('lets a student delete the character they chose', async () => {
    api.delete.mockResolvedValue({ data: { data: { id: 2, name: 'Layla', role: 'student', avatar: null } } })
    showAs({ id: 2, name: 'Layla', role: 'student', avatar: { type: 'preset', key: 'a03' } })
    fireEvent.click(screen.getByRole('button', { name: 'Delete my picture' }))
    await waitFor(() => expect(api.delete).toHaveBeenCalledWith('/profile/avatar'))
  })

  it('shows the server message when the photo is refused', async () => {
    api.post.mockRejectedValue({ response: { status: 422, data: { errors: { photo: ['The photo must be at least 64 pixels wide.'] } } } })
    showAs(teacher)
    fireEvent.change(screen.getByLabelText('Choose a photo'), { target: { files: [new File(['x'], 'tiny.png', { type: 'image/png' })] } })
    fireEvent.click(await screen.findByRole('button', { name: 'Save photo' }))
    expect(await screen.findByRole('alert')).toHaveTextContent('at least 64 pixels')
  })

  it('works in Arabic', async () => {
    await i18n.changeLanguage('ar')
    showAs(teacher)
    expect(screen.getByRole('heading', { name: 'ملفي الشخصي' })).toBeInTheDocument()
    expect(screen.getByLabelText('اختر صورة')).toBeInTheDocument()
  })
})
