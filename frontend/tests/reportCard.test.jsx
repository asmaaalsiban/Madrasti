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

    // The page holds the phone cards and the table (CSS shows one of them), so the table's rows are looked up inside the table.
    const table = within(screen.getByRole('table'))
    const math = table.getByText('Math').closest('tr')
    expect(within(math).getByText('90%')).toBeInTheDocument()
    expect(within(math).getByText('80%')).toBeInTheDocument()
    expect(within(math).getByText('Pass')).toBeInTheDocument()
    const arabic = table.getByText('Arabic').closest('tr')
    expect(within(arabic).getByText('—')).toBeInTheDocument() // no second-term grade yet
    expect(within(arabic).getByText('Fail')).toBeInTheDocument()

    const average = table.getByText('Average').closest('tr')
    expect(within(average).getByText('65%')).toBeInTheDocument()
    expect(within(average).getByText('60%')).toBeInTheDocument()
    expect(within(table.getByText('Days absent').closest('tr')).getByText('3')).toBeInTheDocument()
    expect(table.getByText('Behaviour').closest('tr')).toHaveTextContent('Excellent')
    expect(screen.getByText(/Pass mark: 50%/)).toBeInTheDocument()
    for (const who of ['Homeroom teacher', 'Principal', 'Parent']) expect(screen.getByText(who)).toBeInTheDocument()
  })

  it('prints the signatures over the signature lines: the homeroom teacher and the school\'s', async () => {
    const png = 'data:image/png;base64,iVBORw0KGgo='
    api.get.mockResolvedValue({ data: { data: card({ signatures: { homeroom: { name: 'Sara Hassan', image: png }, school: [{ id: 1, title: 'Principal', name: 'Mr. Khaled', image: png }, { id: 2, title: 'Vice principal', name: null, image: null }] } }) } })
    wrap()
    await screen.findByText('Bright School')
    const sheet = within(screen.getByLabelText('Report card'))
    expect(sheet.getByText('Sara Hassan')).toBeInTheDocument()
    expect(sheet.getByText('Mr. Khaled')).toBeInTheDocument()
    expect(sheet.getByText('Vice principal')).toBeInTheDocument()
    expect(sheet.getByText('Parent')).toBeInTheDocument()
    expect(screen.getByLabelText('Report card').querySelectorAll('img[src^="data:image/png"]')).toHaveLength(2) // two have a picture
    expect(sheet.getAllByText('Principal')).toHaveLength(1) // the default "Principal" line is not added when the school has its own
  })

  it('still has the plain signature lines when no signature was uploaded', async () => {
    api.get.mockResolvedValue({ data: { data: card({ signatures: { homeroom: null, school: [] } }) } })
    wrap()
    await screen.findByText('Bright School')
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

  it('has a card per subject for a phone: the marks, the result and a bar coloured by the annual mark', async () => {
    await i18n.changeLanguage('en')
    api.get.mockResolvedValue({ data: { data: card() } })
    wrap()
    await screen.findByText('Bright School')
    const cards = within(screen.getByTestId('report-cards'))
    const math = cards.getByText('Math').closest('section')
    expect(within(math).getByText('90%')).toBeInTheDocument()
    expect(within(math).getByText('Pass')).toBeInTheDocument()
    expect(within(math).getByRole('progressbar', { name: 'Math' })).toHaveAttribute('aria-valuenow', '80')
    expect(within(math).getByRole('progressbar').firstChild).toHaveClass('bg-amber-400') // a pass, not yet 85
    const arabic = cards.getByText('Arabic').closest('section')
    expect(within(arabic).getByText('Fail')).toBeInTheDocument()
    expect(within(arabic).getByRole('progressbar').firstChild).toHaveClass('bg-red-500') // under the pass mark
    expect(cards.getByText('Average')).toBeInTheDocument()
  })

  it('is written in Arabic when the language is Arabic', async () => {
    await i18n.changeLanguage('ar')
    api.get.mockResolvedValue({ data: { data: card() } })
    wrap()
    expect(await screen.findByLabelText('شهادة الطالب')).toBeInTheDocument()
    expect(within(screen.getByRole('table')).getByText('الرياضيات')).toBeInTheDocument()
    expect(screen.getByText('الصف الخامس · 5-A')).toBeInTheDocument()
  })
})
