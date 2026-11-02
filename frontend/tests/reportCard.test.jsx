import { fireEvent, render, screen, within } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import i18n from '../src/i18n'
import { api } from '../src/api/client'
import ReportCardPage from '../src/features/Reports/pages/ReportCardPage'

vi.mock('../src/api/client', () => ({ api: { get: vi.fn() } }))
vi.mock('../src/features/Auth/AuthProvider', () => ({ useAuth: () => ({ user: { role: 'parent', student_id: null } }) }))
vi.mock('../src/tenant/useSchool', () => ({ useSchool: () => ({ data: { name: 'Bright School', logo_url: null } }) }))

const wrap = () => render(
  <QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}><MemoryRouter initialEntries={['/c/7']}><Routes><Route path="/c/:id" element={<ReportCardPage />} /></Routes></MemoryRouter></QueryClientProvider>,
)

const card = (over = {}) => ({
  student: { id: 7, student_number: 'S-1', name_ar: 'أحمد', name_en: 'Ahmad', classroom: '5-A', grade: { name_ar: 'الصف الخامس', name_en: 'Grade 5' } },
  year: { name: '2026/2027', starts_on: '2026-09-01', ends_on: '2027-06-30' }, pass_percent: 50,
  terms: [{ id: 1, name: 'First term', average: 65, absent: 2, late: 1, behavior: 4.6 }, { id: 2, name: 'Second term', average: 50, absent: 1, late: 0, behavior: null }],
  subjects: [
    { subject: { id: 1, name_ar: 'الرياضيات', name_en: 'Math' }, terms: { 1: { percent: 90, result: 'pass' }, 2: { percent: 70, result: 'pass' } }, annual: 80, annual_result: 'pass' },
    { subject: { id: 2, name_ar: 'العربية', name_en: 'Arabic' }, terms: { 1: { percent: 40, result: 'fail' }, 2: { percent: null, result: 'none' } }, annual: 40, annual_result: 'fail' },
  ],
  annual_average: 60, attendance: { absent: 3, late: 1, excused: 0, days: 40 }, behavior: 4.6, ...over,
})

beforeEach(async () => {
  vi.resetAllMocks()
  await i18n.changeLanguage('en')
  window.print = vi.fn()
})

describe('ReportCard', () => {
  it('shows the student, each subject by term with the annual result, the averages, attendance and behaviour', async () => {
    api.get.mockResolvedValue({ data: { data: card() } })
    wrap()
    expect(await screen.findByText('Bright School')).toBeInTheDocument()
    expect(api.get).toHaveBeenCalledWith('/students/7/report-card')
    expect(screen.getByText(/2026\/2027/)).toBeInTheDocument()
    expect(screen.getByText('Ahmad')).toBeInTheDocument()
    expect(screen.getByText('Grade 5 · 5-A')).toBeInTheDocument()

    const math = screen.getByText('Math').closest('tr')
    expect(within(math).getByText('90%')).toBeInTheDocument()
    expect(within(math).getByText('80%')).toBeInTheDocument()
    expect(within(math).getByText('Pass')).toBeInTheDocument()
    const arabic = screen.getByText('Arabic').closest('tr')
    expect(within(arabic).getByText('—')).toBeInTheDocument() // no second-term grade yet
    expect(within(arabic).getByText('Fail')).toBeInTheDocument()

    const average = screen.getByText('Average').closest('tr')
    expect(within(average).getByText('65%')).toBeInTheDocument()
    expect(within(average).getByText('60%')).toBeInTheDocument()
    expect(within(screen.getByText('Days absent').closest('tr')).getByText('3')).toBeInTheDocument()
    expect(screen.getByText('Behaviour').closest('tr')).toHaveTextContent('Excellent')
    expect(screen.getByText(/Pass mark: 50%/)).toBeInTheDocument()
    for (const who of ['Homeroom teacher', 'Principal', 'Parent']) expect(screen.getByText(who)).toBeInTheDocument()
  })

  it('prints with the browser dialog', async () => {
    api.get.mockResolvedValue({ data: { data: card() } })
    wrap()
    fireEvent.click(await screen.findByRole('button', { name: 'Print' }))
    expect(window.print).toHaveBeenCalled()
  })

  it('says so when there are no published grades yet', async () => {
    api.get.mockResolvedValue({ data: { data: card({ subjects: [], terms: [], annual_average: null, behavior: null }) } })
    wrap()
    expect(await screen.findByText('No published grades yet.')).toBeInTheDocument()
  })

  it('is written in Arabic when the language is Arabic', async () => {
    await i18n.changeLanguage('ar')
    api.get.mockResolvedValue({ data: { data: card() } })
    wrap()
    expect(await screen.findByLabelText('شهادة الطالب')).toBeInTheDocument()
    expect(screen.getByText('الرياضيات')).toBeInTheDocument()
    expect(screen.getByText('الصف الخامس · 5-A')).toBeInTheDocument()
  })
})
