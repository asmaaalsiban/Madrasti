import { fireEvent, render, screen, waitFor } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import i18n from '../src/i18n'
import { api } from '../src/api/client'
import MotivationPage from '../src/features/Motivation/pages/MotivationPage'
import MotivationSettingsPage from '../src/features/Motivation/pages/MotivationSettingsPage'
import StudentMotivationPage from '../src/features/Motivation/pages/StudentMotivationPage'

vi.mock('../src/api/client', () => ({ api: { get: vi.fn(), post: vi.fn(), put: vi.fn(), delete: vi.fn() } }))
vi.mock('../src/features/Auth/AuthProvider', () => ({ useAuth: () => ({ user: { children: [{ id: 7, name_en: 'Lina', name_ar: 'لينا' }] } }) }))

const wrap = (ui, path = '/', route = '/') =>
  render(<QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}><MemoryRouter initialEntries={[path]}><Routes><Route path={route} element={ui} /></Routes></MemoryRouter></QueryClientProvider>)

const roster = {
  students: [
    { id: 1, name_ar: 'سلمى', name_en: 'Salma', total: 12, week: 4, is_star: true, is_leader: true },
    { id: 2, name_ar: 'عمر', name_en: 'Omar', total: 3, week: 4, is_star: false, is_leader: true },
  ],
  categories: [{ id: 5, name_ar: 'مساعدة', name_en: 'Helping', points: 2, is_active: true }, { id: 6, name_ar: 'تأخر', name_en: 'Late', points: -1, is_active: true }],
  badges: [{ id: 8, name_ar: 'مبدع', name_en: 'Creative', icon: '🎨' }],
}

beforeEach(async () => {
  vi.resetAllMocks()
  await i18n.changeLanguage('en')
})

describe('MotivationPage', () => {
  beforeEach(() => api.get.mockImplementation((url) => {
    if (url === '/behavior/classrooms') return Promise.resolve({ data: { data: [{ id: 3, name: '5-A' }] } })
    if (url === '/motivation/classroom') return Promise.resolve({ data: { data: roster } })
    return Promise.resolve({ data: { data: [] } })
  }))

  it('shows points, the star and the leaders', async () => {
    wrap(<MotivationPage base="/teacher" />)
    expect(await screen.findByText('Salma')).toBeInTheDocument()
    expect(screen.getByText(/Star of the week/)).toBeInTheDocument()
    expect(screen.getByText(/Leading/)).toBeInTheDocument()
    expect(screen.getByText('12')).toBeInTheDocument()
  })

  it('gives points by category to the ticked students only', async () => {
    api.post.mockResolvedValue({ data: { data: {} } })
    wrap(<MotivationPage base="/teacher" />)
    await screen.findByText('Salma')
    expect(screen.getByRole('button', { name: /Helping/ })).toBeDisabled()
    fireEvent.click(screen.getByRole('checkbox', { name: 'Omar' }))
    fireEvent.click(screen.getByRole('button', { name: /Helping/ }))
    await waitFor(() => expect(api.post).toHaveBeenCalledWith('/motivation/awards', { student_ids: [2], point_category_id: 5 }))
  })

  it('gives a free amount with a reason, a badge, and crowns the star', async () => {
    api.post.mockResolvedValue({ data: { data: {} } })
    api.put.mockResolvedValue({ data: { data: {} } })
    wrap(<MotivationPage base="/teacher" />)
    await screen.findByText('Salma')
    fireEvent.click(screen.getByLabelText('Select all'))
    fireEvent.change(screen.getByLabelText('Points'), { target: { value: '-2' } })
    fireEvent.change(screen.getByLabelText('Reason'), { target: { value: 'Shouting' } })
    fireEvent.click(screen.getByRole('button', { name: 'Give' }))
    await waitFor(() => expect(api.post).toHaveBeenCalledWith('/motivation/awards', { student_ids: [1, 2], points: -2, note: 'Shouting' }))

    fireEvent.click(await screen.findByRole('checkbox', { name: 'Omar' }))
    fireEvent.change(screen.getByLabelText('Badge'), { target: { value: '8' } })
    fireEvent.click(screen.getByRole('button', { name: 'Give badge' }))
    await waitFor(() => expect(api.post).toHaveBeenCalledWith('/motivation/students/2/badges', { badge_id: 8 }))

    fireEvent.click(screen.getAllByRole('button', { name: 'Make star' })[1])
    await waitFor(() => expect(api.put).toHaveBeenCalledWith('/motivation/star', { classroom_id: 3, student_id: 2 }))
  })

  it('links the admin to the settings', async () => {
    wrap(<MotivationPage base="/admin" />)
    expect(await screen.findByRole('link', { name: 'Points & badges settings' })).toHaveAttribute('href', '/admin/motivation-settings')
  })
})

describe('StudentMotivationPage', () => {
  const data = {
    total: 12, week: 4, is_star: true, next_badge: { name_ar: 'متفوق', name_en: 'High achiever', icon: '🏆', points_required: 100 },
    badges: [{ id: 1, name_ar: 'نجم', name_en: 'Rising star', icon: '🌟' }],
    awards: [{ id: 9, note: 'Helped Omar', points: 2, awarded_on: '2026-11-02', teacher: 'Sara', mine: true }, { id: 10, note: 'Late', points: -1, awarded_on: '2026-11-01', teacher: 'Dina', mine: false }],
  }
  beforeEach(() => { api.get.mockResolvedValue({ data: { data } }) })

  it("shows a parent the child's points, badges and what is next, without undo", async () => {
    wrap(<StudentMotivationPage />, '/children/7', '/children/:id')
    expect(await screen.findByText('Points & badges: Lina')).toBeInTheDocument()
    expect(await screen.findByText('Rising star')).toBeInTheDocument()
    expect(screen.getByText('88 more points to earn 🏆 High achiever')).toBeInTheDocument()
    expect(screen.getByText('Helped Omar')).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Undo' })).not.toBeInTheDocument()
  })

  it('lets staff undo only their own awards', async () => {
    api.delete.mockResolvedValue({})
    wrap(<StudentMotivationPage staff />, '/students/7', '/students/:id')
    fireEvent.click(await screen.findByRole('button', { name: 'Undo' }))
    await waitFor(() => expect(api.delete).toHaveBeenCalledWith('/motivation/awards/9'))
    expect(screen.getAllByRole('button', { name: 'Undo' })).toHaveLength(1)
  })
})

describe('MotivationSettingsPage', () => {
  beforeEach(() => { api.get.mockImplementation((url) => Promise.resolve({ data: { data: url.endsWith('badges') ? [{ id: 1, name_ar: 'نجم', name_en: 'Rising star', icon: '🌟', points_required: 25, is_active: true }] : [{ id: 2, name_ar: 'مساعدة', name_en: 'Helping', points: 2, is_active: true }] } })) })

  it('lists categories and badges and adds a category', async () => {
    api.post.mockResolvedValue({ data: { data: {} } })
    wrap(<MotivationSettingsPage />)
    expect(await screen.findByText(/at 25 points/)).toBeInTheDocument()
    const form = screen.getAllByRole('button', { name: 'Add' })[0].closest('form')
    const inputs = form.querySelectorAll('input')
    fireEvent.change(inputs[0], { target: { value: 'قراءة' } })
    fireEvent.change(inputs[1], { target: { value: 'Reading' } })
    fireEvent.change(inputs[2], { target: { value: '3' } })
    fireEvent.click(screen.getAllByRole('button', { name: 'Add' })[0])
    await waitFor(() => expect(api.post).toHaveBeenCalledWith('/motivation/categories', { name_ar: 'قراءة', name_en: 'Reading', points: '3' }))
  })

  it('hides and removes a badge', async () => {
    api.put.mockResolvedValue({ data: { data: {} } })
    api.delete.mockResolvedValue({})
    wrap(<MotivationSettingsPage />)
    await screen.findByText(/at 25 points/)
    fireEvent.click(screen.getAllByRole('button', { name: 'Hide' })[1])
    await waitFor(() => expect(api.put).toHaveBeenCalledWith('/motivation/badges/1', expect.objectContaining({ is_active: false, points_required: 25 })))
    fireEvent.click(screen.getAllByRole('button', { name: 'Delete' })[1])
    await waitFor(() => expect(api.delete).toHaveBeenCalledWith('/motivation/badges/1'))
  })
})

describe('notification sentences', () => {
  it('writes the badge and star notifications in the reader language', async () => {
    const { notificationText } = await import('../src/features/Notifications/NotificationBell')
    const badge = { kind: 'badge_earned', params: { student_ar: 'سلمى', student_en: 'Salma', badge_ar: 'متفوق', badge_en: 'High achiever', icon: '🏆' } }
    expect(notificationText(i18n.t, 'en', badge)).toBe('Salma earned the badge 🏆 High achiever')
    expect(notificationText(i18n.t, 'en', { kind: 'star_of_week', params: { student_ar: 'سلمى', student_en: 'Salma' } })).toBe('Salma is the star of the week!')
    await i18n.changeLanguage('ar')
    expect(notificationText(i18n.t, 'ar', badge)).toBe('سلمى حصل على الشارة 🏆 متفوق')
  })
})
