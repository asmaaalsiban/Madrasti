import { fireEvent, render, screen, waitFor, within } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { MemoryRouter } from 'react-router-dom'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import i18n from '../src/i18n'
import { api } from '../src/api/client'
import GenerateClassroomsPage from '../src/features/Academic/pages/GenerateClassroomsPage'

vi.mock('../src/api/client', () => ({
  api: { get: vi.fn(), post: vi.fn(), put: vi.fn(), delete: vi.fn() },
}))

const renderPage = () =>
  render(
    <QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}>
      <MemoryRouter><GenerateClassroomsPage /></MemoryRouter>
    </QueryClientProvider>,
  )

const grades = [
  { id: 3, name_en: 'Grade 4', name_ar: 'الصف الرابع', sort_order: 7 },
  { id: 1, name_en: 'KG1', name_ar: 'الروضة الأولى', sort_order: 2 },
  { id: 2, name_en: 'Grade 1', name_ar: 'الصف الأول', sort_order: 4 },
]
const years = [{ id: 7, name: '2025/2026', is_current: false }, { id: 8, name: '2026/2027', is_current: true }]
const paged = (data) => ({ data: { data, meta: { current_page: 1, last_page: 1 } } })

const preview = {
  dry_run: true, created: 3, placed: 2, unplaced: ['4-C'],
  classrooms: [
    { name: '4-A', grade_level_id: 3, gender: 'male', room: { id: 1, name: '001', building: 'Boys building', floor: 'Ground' } },
    { name: '4-B', grade_level_id: 3, gender: 'male', room: { id: 2, name: '002', building: 'Boys building', floor: 'Ground' } },
    { name: '4-C', grade_level_id: 3, gender: 'female', room: null },
  ],
}

beforeEach(async () => {
  vi.resetAllMocks()
  await i18n.changeLanguage('en')
  api.get.mockImplementation((url) => {
    if (url === '/grade-levels') return Promise.resolve(paged(grades))
    if (url === '/academic-years') return Promise.resolve(paged(years))
    return Promise.resolve(paged([]))
  })
})

const row = (name) => screen.getByRole('row', { name: new RegExp(name) })
const fill = (grade, field, value) => fireEvent.change(within(row(grade)).getByLabelText(field), { target: { value } })

describe('GenerateClassroomsPage', () => {
  it('lists the grades in their order with a box for mixed, boys and girls sections and the current year picked', async () => {
    renderPage()
    expect(await screen.findByRole('row', { name: /KG1/ })).toBeInTheDocument()
    const names = screen.getAllByRole('row').slice(1, 4).map((r) => within(r).getAllByRole('cell')[0].textContent)
    expect(names).toEqual(['KG1', 'Grade 1', 'Grade 4'])
    expect(screen.getByLabelText('Academic year')).toHaveValue('8')
  })

  it('shows the total number of sections while typing', async () => {
    renderPage()
    await screen.findByRole('row', { name: /KG1/ })
    fill('KG1', 'Mixed', '10')
    fill('Grade 4', 'Boys', '2')
    fill('Grade 4', 'Girls', '1')
    expect(screen.getByText('Total: 13 sections')).toBeInTheDocument()
  })

  it('previews without saving, then creates after the confirmation', async () => {
    api.post.mockResolvedValueOnce({ data: { data: preview } }).mockResolvedValueOnce({ data: { data: { ...preview, dry_run: false } } })
    renderPage()
    await screen.findByRole('row', { name: /KG1/ })
    fill('Grade 4', 'Boys', '2')
    fill('Grade 4', 'Girls', '1')
    fireEvent.click(screen.getByRole('button', { name: 'Preview' }))

    await waitFor(() => expect(api.post).toHaveBeenCalledWith('/classrooms/generate', {
      academic_year_id: 8, dry_run: true, plan: [{ grade_level_id: 3, male: 2, female: 1, mixed: 0 }],
    }))
    expect(await screen.findByText('3 sections will be created, 2 of them placed in a room.')).toBeInTheDocument()
    expect(screen.getByText('Boys building · Ground · 001')).toBeInTheDocument()
    expect(screen.getByText('No room for: 4-C. You can place them later from the classrooms page.')).toBeInTheDocument()

    fireEvent.click(screen.getByRole('button', { name: 'Create classrooms' }))
    await waitFor(() => expect(api.post).toHaveBeenLastCalledWith('/classrooms/generate', {
      academic_year_id: 8, plan: [{ grade_level_id: 3, male: 2, female: 1, mixed: 0 }],
    }))
    expect(await screen.findByText('3 classrooms were created.')).toBeInTheDocument()
  })

  it('only sends the grades that have sections and asks for at least one', async () => {
    renderPage()
    await screen.findByRole('row', { name: /KG1/ })
    expect(screen.getByRole('button', { name: 'Preview' })).toBeDisabled()
    fill('KG1', 'Mixed', '4')
    expect(screen.getByRole('button', { name: 'Preview' })).not.toBeDisabled()
  })

  it('shows the server error', async () => {
    api.post.mockRejectedValue({ response: { status: 422, data: { message: 'A grade can have at most 26 sections.' } } })
    renderPage()
    await screen.findByRole('row', { name: /KG1/ })
    fill('KG1', 'Mixed', '10')
    fireEvent.click(screen.getByRole('button', { name: 'Preview' }))
    expect(await screen.findByRole('alert')).toHaveTextContent('A grade can have at most 26 sections.')
  })

  it('has Arabic labels and names', async () => {
    await i18n.changeLanguage('ar')
    renderPage()
    expect(await screen.findByRole('row', { name: /الروضة الأولى/ })).toBeInTheDocument()
    expect(screen.getAllByLabelText('مختلط').length).toBe(3)
    expect(screen.getByRole('heading', { name: 'توليد الشعب' })).toBeInTheDocument()
  })

  it('explains a preview that changes nothing and lists what already exists', async () => {
    api.post.mockResolvedValue({ data: { data: { dry_run: true, created: 0, placed: 0, unplaced: [], classrooms: [], existing: [{ grade_level_id: 3, mixed: 2, male: 0, female: 1 }] } } })
    renderPage()
    await screen.findByRole('row', { name: /KG1/ })
    fill('Grade 4', 'Boys', '2')
    fireEvent.click(screen.getByRole('button', { name: 'Preview' }))
    expect(await screen.findByText('Nothing new to create: these grades already have the sections you asked for.')).toBeInTheDocument()
    expect(screen.getByText('Grade 4: 2 mixed, 0 boys, 1 girls already exist')).toBeInTheDocument()
  })
})
