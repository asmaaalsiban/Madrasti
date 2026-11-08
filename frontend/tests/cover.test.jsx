import { fireEvent, render, screen, waitFor } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import i18n from '../src/i18n'
import { api } from '../src/api/client'
import CoverPage from '../src/features/Scheduling/pages/CoverPage'

vi.mock('../src/api/client', () => ({ api: { get: vi.fn(), post: vi.fn(), delete: vi.fn() } }))
const wrap = () => render(<QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}><CoverPage /></QueryClientProvider>)

const lesson = { period: { id: 11, number: 2, starts_at: '08:50', ends_at: '09:35' }, classroom: { id: 5, name: '7-A' }, subject: { id: 1, name_en: 'Math', name_ar: 'ر' }, cover: null,
  suggestions: [{ teacher: { id: 8, name: 'Omar' }, teaches_subject: true, teaches_grade: true, covers_today: 0, lessons_today: 3 }, { teacher: { id: 9, name: 'Lina' }, teaches_subject: false, teaches_grade: true, covers_today: 1, lessons_today: 4 }] }

beforeEach(async () => {
  vi.resetAllMocks()
  await i18n.changeLanguage('en')
  api.get.mockImplementation(async (url) => (url === '/cover/teachers' ? { data: { data: [{ id: 4, name: 'Sara', away: 'leave' }, { id: 8, name: 'Omar', away: null }] } } : { data: { data: [lesson] } }))
  api.post.mockResolvedValue({ data: { data: { id: 1 } } })
})

describe('CoverPage', () => {
  it('shows who is away, their lessons and the free teachers, and chooses one', async () => {
    wrap()
    fireEvent.click(await screen.findByRole('button', { name: 'Sara' })) // marked on leave: one click
    expect(await screen.findByText(/Period 2/)).toBeInTheDocument()
    expect(screen.getByText('Teaches this subject · 3 lessons that day')).toBeInTheDocument()
    fireEvent.click(screen.getByRole('button', { name: 'Choose Omar' }))
    await waitFor(() => expect(api.post).toHaveBeenCalledWith('/cover', expect.objectContaining({ period_id: 11, classroom_id: 5, cover_teacher_id: 8 })))
  })

  it('shows a lesson that is already covered with a way to cancel it', async () => {
    api.get.mockImplementation(async (url) => (url === '/cover/teachers' ? { data: { data: [{ id: 4, name: 'Sara', away: 'absent' }] } } : { data: { data: [{ ...lesson, cover: { id: 3, teacher: { id: 8, name: 'Omar' } }, suggestions: [] }] } }))
    api.delete.mockResolvedValue({})
    wrap()
    fireEvent.click(await screen.findByRole('button', { name: 'Sara' }))
    expect(await screen.findByText('Covered by Omar')).toBeInTheDocument()
    fireEvent.click(screen.getByRole('button', { name: 'Cancel' }))
    await waitFor(() => expect(api.delete).toHaveBeenCalledWith('/cover/3'))
  })
})
