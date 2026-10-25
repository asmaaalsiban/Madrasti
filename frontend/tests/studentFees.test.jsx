import { fireEvent, render, screen, waitFor, within } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { MemoryRouter } from 'react-router-dom'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import i18n from '../src/i18n'
import { api } from '../src/api/client'
import StudentsPage from '../src/features/People/pages/StudentsPage'

vi.mock('../src/api/client', () => ({ api: { get: vi.fn(), post: vi.fn(), put: vi.fn(), delete: vi.fn() } }))

const renderPage = () => render(<QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}><MemoryRouter><StudentsPage /></MemoryRouter></QueryClientProvider>)
const student = (extra = {}) => ({ id: 7, student_number: 'S-1', name_ar: 'ليلى', name_en: 'Layla', email: 'l@x.test', user_id: 70, classroom: { id: 3, name: '1-A' }, transport_fee: '200.00', guardians: [], balance: { net: '700.00', paid: '175.00', remaining: '525.00', overdue: '0.00' }, ...extra })

beforeEach(async () => {
  vi.resetAllMocks()
  await i18n.changeLanguage('en')
  api.get.mockImplementation((url) => {
    if (url === '/students') return Promise.resolve({ data: { data: [student(), student({ id: 8, student_number: 'S-2', name_ar: 'سامي', balance: { net: '700.00', paid: '0.00', remaining: '700.00', overdue: '175.00' } })], meta: { current_page: 1, last_page: 1 } } })
    if (url === '/classrooms') return Promise.resolve({ data: { data: [{ id: 3, name: '1-A' }], meta: { current_page: 1, last_page: 1 } } })
    if (url === '/finance/settings') return Promise.resolve({ data: { data: { currency: 'JOD', can_manage: false } } })
    return Promise.resolve({ data: { data: [], meta: { current_page: 1, last_page: 1 } } })
  })
})

describe('StudentsPage fees', () => {
  it('shows what each student still owes, as a link to their account, and marks the overdue ones', async () => {
    renderPage()
    const ok = await screen.findByRole('link', { name: '525.00 JOD' })
    expect(ok).toHaveAttribute('href', '/admin/finance/students/7')
    expect(screen.getByRole('link', { name: '700.00 JOD' })).toHaveClass('text-red-700')
  })

  it('offers a transport fee and a discount only when adding a student with a section', async () => {
    renderPage()
    await screen.findByRole('link', { name: '525.00 JOD' })
    fireEvent.click(screen.getByRole('button', { name: 'Add' }))
    const dialog = await screen.findByRole('dialog')
    expect(within(dialog).queryByLabelText('Transport fee (optional)')).not.toBeInTheDocument() // no section yet
    fireEvent.change(within(dialog).getByLabelText('Classroom'), { target: { value: '3' } })
    expect(within(dialog).getByLabelText('Transport fee (optional)')).toBeInTheDocument()
    expect(within(dialog).getByLabelText('Discount (an amount, optional)')).toBeInTheDocument()
  })

  it('lets the transport fee be changed when editing, with the current amount, but not the discount', async () => {
    api.put.mockResolvedValue({ data: { data: student() } })
    renderPage()
    await screen.findByRole('link', { name: '525.00 JOD' })
    fireEvent.click(screen.getAllByRole('button', { name: 'Edit' })[0])
    const dialog = await screen.findByRole('dialog')
    expect(within(dialog).getByLabelText('Transport fee (optional)')).toHaveValue(200)
    expect(within(dialog).queryByLabelText('Discount (an amount, optional)')).not.toBeInTheDocument()
    fireEvent.change(within(dialog).getByLabelText('Transport fee (optional)'), { target: { value: '320' } })
    fireEvent.click(within(dialog).getByRole('button', { name: 'Save' }))
    await waitFor(() => expect(api.put).toHaveBeenCalledWith('/students/7', expect.objectContaining({ transport_fee: '320' })))
  })

  it('sends an empty transport fee as nothing when the student stops using the bus', async () => {
    api.put.mockResolvedValue({ data: { data: student() } })
    renderPage()
    await screen.findByRole('link', { name: '525.00 JOD' })
    fireEvent.click(screen.getAllByRole('button', { name: 'Edit' })[0])
    const dialog = await screen.findByRole('dialog')
    fireEvent.change(within(dialog).getByLabelText('Transport fee (optional)'), { target: { value: '' } })
    fireEvent.click(within(dialog).getByRole('button', { name: 'Save' }))
    await waitFor(() => expect(api.put).toHaveBeenCalledWith('/students/7', expect.objectContaining({ transport_fee: null })))
  })

  it('sends the transport fee and discount and shows the fees the student got', async () => {
    api.post.mockResolvedValue({ data: { data: { id: 9, account: { email: 'n@x.test', password: '123456789' }, created_fees: [{ description: 'Tuition', net: '600.00', installments: 4 }, { description: 'Transport', net: '200.00', installments: 4 }] } } })
    renderPage()
    await screen.findByRole('link', { name: '525.00 JOD' })
    fireEvent.click(screen.getByRole('button', { name: 'Add' }))
    const dialog = await screen.findByRole('dialog')
    fireEvent.change(within(dialog).getByLabelText('Student number'), { target: { value: 'S-9' } })
    fireEvent.change(within(dialog).getByLabelText('Name (Arabic)'), { target: { value: 'نور' } })
    fireEvent.change(within(dialog).getByLabelText('Classroom'), { target: { value: '3' } })
    fireEvent.change(within(dialog).getByLabelText('Transport fee (optional)'), { target: { value: '200' } })
    fireEvent.change(within(dialog).getByLabelText('Discount (an amount, optional)'), { target: { value: '100' } })
    fireEvent.click(within(dialog).getByRole('button', { name: 'Save' }))
    await waitFor(() => expect(api.post).toHaveBeenCalledWith('/students', expect.objectContaining({ student_number: 'S-9', classroom_id: '3', transport_fee: '200', fee_discount: '100' })))
    expect(await screen.findByText(/Tuition: /)).toBeInTheDocument()
    expect(screen.getByText(/Transport: /)).toBeInTheDocument()
    expect(screen.getAllByText(/4 installments/).length).toBeGreaterThan(0)
  })
})
