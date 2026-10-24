import { fireEvent, render, screen, waitFor } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import i18n from '../src/i18n'
import { api } from '../src/api/client'
import DailySummaryPage from '../src/features/DailySummary/pages/DailySummaryPage'
import { ChildSummariesPage, StudentSummariesPage } from '../src/features/DailySummary/pages/SummariesPages'

vi.mock('../src/api/client', () => ({ api: { get: vi.fn(), post: vi.fn(), put: vi.fn(), delete: vi.fn() } }))
vi.mock('../src/features/Auth/AuthProvider', () => ({ useAuth: () => ({ user: { children: [{ id: 7, name_en: 'Lina', name_ar: 'لينا' }] } }) }))

const wrap = (ui, path = '/', route = '/') =>
  render(<QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}><MemoryRouter initialEntries={[path]}><Routes><Route path={route} element={ui} /></Routes></MemoryRouter></QueryClientProvider>)

const math = { id: 5, name_en: 'Math', name_ar: 'رياضيات' }
const row = (id, date, summary) => ({ id, date, summary, classroom: { id: 3, name: '5-A' }, subject: math, teacher: { id: 9, name: 'Sara' } })

beforeEach(async () => {
  vi.resetAllMocks()
  await i18n.changeLanguage('en')
})

describe('DailySummaryPage', () => {
  const serve = (mine = []) => api.get.mockImplementation((url) => Promise.resolve({ data: { data: url === '/daily-summaries' ? mine : { assignments: [{ classroom: { id: 3, name: '5-A' }, subject: math }] } } }))

  it('saves the summary of a lesson for a day', async () => {
    serve()
    api.put.mockResolvedValue({ data: { data: {} } })
    wrap(<DailySummaryPage />)
    await screen.findByRole('option', { name: '5-A · Math' })
    fireEvent.change(screen.getByLabelText('Date'), { target: { value: '2026-10-20' } })
    fireEvent.change(screen.getByLabelText('What the lesson covered'), { target: { value: 'Fractions' } })
    fireEvent.click(screen.getByRole('button', { name: 'Save' }))
    await waitFor(() => expect(api.put).toHaveBeenCalledWith('/daily-summaries', { classroom_id: 3, subject_id: 5, date: '2026-10-20', summary: 'Fractions' }))
    expect(await screen.findByRole('status')).toHaveTextContent('Saved')
  })

  it('loads the saved text of that day so it can be corrected', async () => {
    serve([row(1, '2026-10-20', 'Fractions')])
    wrap(<DailySummaryPage />)
    await screen.findByRole('option', { name: '5-A · Math' })
    fireEvent.change(screen.getByLabelText('Date'), { target: { value: '2026-10-20' } })
    expect(screen.getByLabelText('What the lesson covered')).toHaveValue('Fractions')
    expect(screen.getByText('There is already a summary for this day: saving replaces it.')).toBeInTheDocument()
  })

  it('deletes a previous summary and shows the reason when refused', async () => {
    serve([row(1, '2026-10-20', 'Fractions')])
    api.delete.mockResolvedValue({})
    api.put.mockRejectedValue({ response: { data: { errors: { classroom_id: ['You do not teach this subject in this section.'] } } } })
    wrap(<DailySummaryPage />)
    await screen.findByRole('option', { name: '5-A · Math' })
    fireEvent.change(screen.getByLabelText('What the lesson covered'), { target: { value: 'x' } })
    fireEvent.click(screen.getByRole('button', { name: 'Save' }))
    expect(await screen.findByRole('alert')).toHaveTextContent('do not teach')
    fireEvent.click(screen.getByRole('button', { name: 'Delete' }))
    fireEvent.click(screen.getAllByRole('button', { name: 'Delete' }).at(-1))
    await waitFor(() => expect(api.delete).toHaveBeenCalledWith('/daily-summaries/1'))
  })
})

describe('summaries for students and parents', () => {
  it('shows the student the summaries grouped by day', async () => {
    api.get.mockResolvedValue({ data: { data: [row(1, '2026-10-21', 'Decimals'), row(2, '2026-10-20', 'Fractions')] } })
    wrap(<StudentSummariesPage />)
    expect(await screen.findByText('Decimals')).toBeInTheDocument()
    expect(api.get).toHaveBeenCalledWith('/daily-summaries/me')
    expect(screen.getByText('2026-10-20')).toBeInTheDocument()
  })

  it('shows the parent the summaries of one child', async () => {
    api.get.mockResolvedValue({ data: { data: [row(1, '2026-10-21', 'Decimals')] } })
    wrap(<ChildSummariesPage />, '/c/7', '/c/:id')
    expect(await screen.findByText('Decimals')).toBeInTheDocument()
    expect(api.get).toHaveBeenCalledWith('/students/7/daily-summaries')
    expect(screen.getByRole('heading', { level: 1 })).toHaveTextContent('Lina')
  })

  it('says when there are none', async () => {
    api.get.mockResolvedValue({ data: { data: [] } })
    wrap(<StudentSummariesPage />)
    expect(await screen.findByText('No summaries yet.')).toBeInTheDocument()
  })
})
