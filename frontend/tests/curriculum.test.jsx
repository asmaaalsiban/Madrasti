import { fireEvent, render, screen, waitFor, within } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { MemoryRouter } from 'react-router-dom'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import i18n from '../src/i18n'
import { api } from '../src/api/client'
import CurriculumPage from '../src/features/Academic/pages/CurriculumPage'

vi.mock('../src/api/client', () => ({
  api: { get: vi.fn(), post: vi.fn(), put: vi.fn(), delete: vi.fn() },
}))

const renderPage = () =>
  render(
    <QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}>
      <MemoryRouter><CurriculumPage /></MemoryRouter>
    </QueryClientProvider>,
  )

const state = {
  periods_per_week: 35,
  grades: [
    { id: 1, name_en: 'Grade 1', name_ar: 'الصف الأول', code: '1', sort_order: 4 },
    { id: 2, name_en: 'Grade 2', name_ar: 'الصف الثاني', code: '2', sort_order: 5 },
    { id: 7, name_en: 'Grade 7', name_ar: 'الصف السابع', code: '7', sort_order: 10 },
  ],
  subjects: [
    { id: 10, code: 'MTH', name_en: 'Math', name_ar: 'الرياضيات' },
    { id: 11, code: 'SCI', name_en: 'Science', name_ar: 'العلوم' },
    { id: 12, code: 'HIS', name_en: 'History', name_ar: 'التاريخ' },
  ],
  cells: [
    { grade_level_id: 1, subject_id: 10, weekly_periods: 30 },
    { grade_level_id: 1, subject_id: 11, weekly_periods: 5 },
    { grade_level_id: 2, subject_id: 10, weekly_periods: 20 },
    { grade_level_id: 7, subject_id: 12, weekly_periods: 38 },
  ],
  totals: { 1: 35, 2: 20, 7: 38 },
}

beforeEach(async () => {
  vi.resetAllMocks()
  await i18n.changeLanguage('en')
  api.get.mockResolvedValue({ data: { data: state } })
})

const cell = (subject, grade) => screen.getByLabelText(`${subject} / ${grade}`)

describe('CurriculumPage', () => {
  it('shows the subjects as rows, the grades as columns and the periods in the cells', async () => {
    renderPage()
    expect(await screen.findByLabelText('Math / Grade 1')).toHaveValue(30)
    expect(cell('Science', 'Grade 1')).toHaveValue(5)
    expect(cell('Math', 'Grade 7')).toHaveValue(null)
    expect(screen.getAllByRole('columnheader').map((h) => h.textContent)).toEqual(['Subject', 'Grade 1', 'Grade 2', 'Grade 7'])
  })

  it('totals every grade and compares it with the periods of the week', async () => {
    renderPage()
    await screen.findByLabelText('Math / Grade 1')
    const totals = screen.getByRole('row', { name: /Total periods/ })
    expect(within(totals).getByText('35')).toBeInTheDocument()
    expect(within(totals).getByText('Missing 15')).toBeInTheDocument()
    expect(within(totals).getByText('Over by 3')).toBeInTheDocument()
    expect(within(totals).getByText('Complete')).toBeInTheDocument()

    fireEvent.change(cell('Math', 'Grade 2'), { target: { value: '35' } })
    expect(within(totals).getAllByText('Complete')).toHaveLength(2)
  })

  it('saves only the cells that have periods, with the periods of the week', async () => {
    api.put.mockResolvedValue({ data: { data: state } })
    renderPage()
    await screen.findByLabelText('Math / Grade 1')
    expect(screen.getByRole('button', { name: 'Save' })).toBeDisabled()

    fireEvent.change(cell('Science', 'Grade 1'), { target: { value: '' } })
    fireEvent.change(cell('History', 'Grade 7'), { target: { value: '2' } })
    fireEvent.change(screen.getByLabelText('Periods per week'), { target: { value: '30' } })
    fireEvent.click(screen.getByRole('button', { name: 'Save' }))

    await waitFor(() => expect(api.put).toHaveBeenCalledWith('/curriculum', {
      periods_per_week: 30,
      cells: [
        { grade_level_id: 1, subject_id: 10, weekly_periods: 30 },
        { grade_level_id: 2, subject_id: 10, weekly_periods: 20 },
        { grade_level_id: 7, subject_id: 12, weekly_periods: 2 },
      ],
    }))
    expect(await screen.findByText('Curriculum saved.')).toBeInTheDocument()
  })

  it('applies the standard plan for grades 1 to 10 only after confirmation and reports missing grades', async () => {
    api.post.mockResolvedValue({ data: { data: { subjects_created: 12, applied: ['1', '2', '7'], missing_grades: ['3', '4'] } } })
    renderPage()
    await screen.findByLabelText('Math / Grade 1')
    fireEvent.click(screen.getByRole('button', { name: 'Apply the standard plan (grades 1-10)' }))
    expect(api.post).not.toHaveBeenCalled()
    fireEvent.click(within(screen.getByRole('dialog')).getByRole('button', { name: 'Apply' }))

    await waitFor(() => expect(api.post).toHaveBeenCalledWith('/curriculum/standard-plan'))
    expect(await screen.findByText('The standard plan was applied to 3 grades and 12 subjects were added.')).toBeInTheDocument()
    expect(screen.getByText('These grades do not exist yet: 3, 4. Add them, then apply the plan again.')).toBeInTheDocument()
  })

  it('copies the subjects of one grade to others', async () => {
    api.post.mockResolvedValue({ data: { data: { copied: 2 } } })
    renderPage()
    await screen.findByLabelText('Math / Grade 1')
    fireEvent.click(screen.getByRole('button', { name: 'Copy a grade' }))
    const dialog = screen.getByRole('dialog')
    fireEvent.change(within(dialog).getByLabelText('Copy from'), { target: { value: '1' } })
    fireEvent.click(within(dialog).getByRole('checkbox', { name: 'Grade 2' }))
    fireEvent.click(within(dialog).getByRole('checkbox', { name: 'Grade 7' }))
    fireEvent.click(within(dialog).getByRole('button', { name: 'Copy' }))

    await waitFor(() => expect(api.post).toHaveBeenCalledWith('/curriculum/copy', { from_grade_level_id: 1, to_grade_level_ids: [2, 7] }))
  })

  it('shows the server error', async () => {
    api.put.mockRejectedValue({ response: { status: 422, data: { message: 'The given data was invalid.' } } })
    renderPage()
    await screen.findByLabelText('Math / Grade 1')
    fireEvent.change(cell('Math', 'Grade 1'), { target: { value: '31' } })
    fireEvent.click(screen.getByRole('button', { name: 'Save' }))
    expect(await screen.findByRole('alert')).toHaveTextContent('The given data was invalid.')
  })

  it('has Arabic labels and names', async () => {
    await i18n.changeLanguage('ar')
    renderPage()
    expect(await screen.findByLabelText('الرياضيات / الصف الأول')).toBeInTheDocument()
    expect(screen.getByRole('heading', { name: 'المنهاج' })).toBeInTheDocument()
  })
})
