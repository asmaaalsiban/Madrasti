import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { fireEvent, render, screen, waitFor, within } from '@testing-library/react'
import { MemoryRouter } from 'react-router-dom'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import i18n from '../src/i18n'
import PlansPage from '../src/features/SuperAdmin/PlansPage'
import saApi from '../src/features/SuperAdmin/saApi'

vi.mock('../src/features/SuperAdmin/saApi', () => ({ default: { get: vi.fn(), post: vi.fn(), put: vi.fn(), delete: vi.fn() }, getSaToken: () => 't', setSaToken: vi.fn() }))

const plan = (extra = {}) => ({ id: 1, name_ar: 'الأساسية', name_en: 'Basic', price_monthly: 50, price_yearly: 40, currency: 'JOD', max_students: 200, description_ar: null, description_en: null, features_ar: ['الحضور'], features_en: ['Attendance', 'Grades'], is_popular: true, is_active: true, sort_order: 1, ...extra })
const show = () => render(<QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}><MemoryRouter><PlansPage /></MemoryRouter></QueryClientProvider>)

beforeEach(async () => {
  vi.resetAllMocks()
  await i18n.changeLanguage('en')
  saApi.get.mockResolvedValue({ data: { data: [plan()] } })
})

describe('Super Admin plans', () => {
  it('lists the plans with prices, limit and whether they are shown', async () => {
    show()
    const row = (await screen.findByText('Basic')).closest('tr')
    expect(within(row).getByText('50 JOD')).toBeInTheDocument()
    expect(within(row).getByText('40 JOD')).toBeInTheDocument()
    expect(within(row).getByText('200')).toBeInTheDocument()
    expect(within(row).getByText('Yes')).toBeInTheDocument()
  })

  it('adds a plan: features go one per line, numbers are sent as numbers, an empty limit is no limit', async () => {
    saApi.post.mockResolvedValue({ data: { data: plan({ id: 2 }) } })
    show()
    fireEvent.click(await screen.findByRole('button', { name: /Add a plan/ }))
    fireEvent.change(screen.getByLabelText('Name (Arabic)'), { target: { value: 'المتوسطة' } })
    fireEvent.change(screen.getByLabelText('Name (English)'), { target: { value: 'Standard' } })
    fireEvent.change(screen.getByLabelText('Monthly price'), { target: { value: '99' } })
    fireEvent.change(screen.getByLabelText('Monthly price when paid yearly'), { target: { value: '79' } })
    fireEvent.change(screen.getByLabelText('Features, Arabic (one per line)'), { target: { value: 'الحضور\n\n  العلامات  ' } })
    fireEvent.change(screen.getByLabelText('Features, English (one per line)'), { target: { value: 'Attendance\nGrades' } })
    fireEvent.click(screen.getByLabelText('The popular plan'))
    fireEvent.click(screen.getByRole('button', { name: 'Save' }))
    await waitFor(() => expect(saApi.post).toHaveBeenCalled())
    expect(saApi.post.mock.calls[0][0]).toBe('/admin/plans')
    expect(saApi.post.mock.calls[0][1]).toMatchObject({ name_en: 'Standard', price_monthly: 99, price_yearly: 79, currency: 'JOD', max_students: null, features_ar: ['الحضور', 'العلامات'], features_en: ['Attendance', 'Grades'], is_popular: true, is_active: true })
  })

  it('edits a plan with its values filled in, and updates it by its id', async () => {
    saApi.put.mockResolvedValue({ data: { data: plan() } })
    show()
    fireEvent.click(await screen.findByRole('button', { name: 'Edit' }))
    expect(screen.getByLabelText('Features, English (one per line)')).toHaveValue('Attendance\nGrades')
    fireEvent.change(screen.getByLabelText('Monthly price'), { target: { value: '55' } })
    fireEvent.click(screen.getByRole('button', { name: 'Save' }))
    await waitFor(() => expect(saApi.put).toHaveBeenCalled())
    expect(saApi.put.mock.calls[0][0]).toBe('/admin/plans/1')
    expect(saApi.put.mock.calls[0][1].price_monthly).toBe(55)
  })

  it('asks before deleting a plan', async () => {
    saApi.delete.mockResolvedValue({})
    show()
    fireEvent.click(await screen.findByRole('button', { name: 'Delete' }))
    expect(screen.getByText('Delete the plan "Basic"?')).toBeInTheDocument()
    expect(saApi.delete).not.toHaveBeenCalled()
    fireEvent.click(screen.getAllByRole('button', { name: 'Delete' }).at(-1))
    await waitFor(() => expect(saApi.delete).toHaveBeenCalledWith('/admin/plans/1'))
  })

  it('shows the server message when a plan is refused', async () => {
    saApi.post.mockRejectedValue({ response: { status: 422, data: { errors: { price_monthly: ['The price must be at least 0.'] } } } })
    show()
    fireEvent.click(await screen.findByRole('button', { name: /Add a plan/ }))
    for (const [label, value] of [['Name (Arabic)', 'ا'], ['Name (English)', 'A'], ['Monthly price', '1'], ['Monthly price when paid yearly', '1'], ['Features, Arabic (one per line)', 'ا'], ['Features, English (one per line)', 'A']]) fireEvent.change(screen.getByLabelText(label), { target: { value } })
    fireEvent.click(screen.getByRole('button', { name: 'Save' }))
    expect(await screen.findByRole('alert')).toHaveTextContent('The price must be at least 0.')
  })
})
