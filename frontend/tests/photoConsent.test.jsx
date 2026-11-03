import { fireEvent, render, screen, waitFor } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { MemoryRouter } from 'react-router-dom'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import i18n from '../src/i18n'
import { api } from '../src/api/client'
import StoryComposer from '../src/features/Stories/StoryComposer'
import PrivacyPage from '../src/features/Privacy/PrivacyPage'
import PhotoConsentPage from '../src/features/Privacy/PhotoConsentPage'

vi.mock('../src/api/client', () => ({ api: { get: vi.fn(), post: vi.fn(), put: vi.fn() } }))
vi.mock('../src/features/Auth/AuthProvider', () => ({ useAuth: () => ({ user: { id: 2, name: 'Sara Ali', role: 'teacher' } }) }))
const wrap = (ui) => render(<QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}><MemoryRouter>{ui}</MemoryRouter></QueryClientProvider>)
const targets = { classrooms: [{ id: 3, name: '5-A' }], grades: [], school: false }

beforeEach(async () => { vi.resetAllMocks(); URL.createObjectURL = vi.fn(() => 'blob:x'); URL.revokeObjectURL = vi.fn(); await i18n.changeLanguage('en') })

const composer = (mode, missingCount = 2) => {
  api.get.mockImplementation(async (url) => (url === '/stories/targets' ? { data: { data: targets } } : { data: { mode, missing: [{ id: 1, name_ar: 'علي', name_en: 'Ali' }, { id: 2, name_ar: 'سامي', name_en: 'Sami' }].slice(0, missingCount), missing_count: missingCount } }))
  wrap(<StoryComposer />)
}
const addPhoto = async () => fireEvent.change(await screen.findByLabelText('Add photos'), { target: { files: [new File(['x'], 'a.jpg', { type: 'image/jpeg' })] } })

describe('photo consent', () => {
  it('warns the teacher who has not allowed photos, and still lets them post', async () => {
    composer('warn')
    await addPhoto()
    expect(await screen.findByRole('status')).toHaveTextContent(/parents of 2 students in this group have not allowed photos/)
    expect(screen.getByRole('status')).toHaveTextContent('Ali، Sami')
    expect(screen.getByRole('button', { name: 'Post' })).toBeEnabled()
  })

  it('in required mode the photo cannot be posted until everybody allowed it', async () => {
    composer('required', 1)
    await addPhoto()
    expect(await screen.findByRole('alert')).toHaveTextContent(/Photos cannot be posted: the parents of 1 student/)
    expect(screen.getByRole('button', { name: 'Post' })).toBeDisabled()
  })

  it('shows nothing when the school does not check', async () => {
    composer('off')
    await addPhoto()
    await waitFor(() => expect(api.get).toHaveBeenCalledWith('/stories/consent-check', expect.anything()))
    expect(screen.queryByRole('status')).not.toBeInTheDocument()
  })

  it('a parent allows or refuses photos of each child', async () => {
    api.get.mockResolvedValue({ data: { data: [], children: [{ id: 7, name_ar: 'لينا', name_en: 'Lina', photo_consent: null }] } })
    api.put.mockResolvedValue({ data: {} })
    wrap(<PrivacyPage />)
    expect(await screen.findByText('No answer yet')).toBeInTheDocument()
    fireEvent.click(screen.getByRole('button', { name: 'Allow photos of Lina' }))
    await waitFor(() => expect(api.put).toHaveBeenCalledWith('/privacy/photo-consent', { student_id: 7, allowed: true }))
    fireEvent.click(screen.getByRole('button', { name: 'Do not allow photos of Lina' }))
    await waitFor(() => expect(api.put).toHaveBeenCalledWith('/privacy/photo-consent', { student_id: 7, allowed: false }))
  })

  it('the principal picks the mode and records a paper answer', async () => {
    api.get.mockImplementation(async (url) => (url === '/classrooms' ? { data: { data: [{ id: 3, name: '5-A' }] } } : { data: { mode: 'warn', missing: 1, data: [{ id: 7, name_ar: 'لينا', name_en: 'Lina', photo_consent: null }] } }))
    api.put.mockResolvedValue({ data: {} })
    wrap(<PhotoConsentPage />)
    fireEvent.click(await screen.findByLabelText(/Only with consent/))
    await waitFor(() => expect(api.put).toHaveBeenCalledWith('/privacy/photo-consent-mode', { mode: 'required' }))
    fireEvent.click(await screen.findByRole('button', { name: 'Allow photos of Lina' }))
    await waitFor(() => expect(api.put).toHaveBeenCalledWith('/privacy/photo-consent', { student_id: 7, allowed: true }))
  })
})
