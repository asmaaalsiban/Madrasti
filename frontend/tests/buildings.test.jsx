import { fireEvent, render, screen, waitFor, within } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { MemoryRouter } from 'react-router-dom'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import i18n from '../src/i18n'
import { api } from '../src/api/client'
import BuildingsPage from '../src/features/Academic/pages/BuildingsPage'

vi.mock('../src/api/client', () => ({
  api: { get: vi.fn(), post: vi.fn(), put: vi.fn(), delete: vi.fn() },
}))

const renderPage = () =>
  render(
    <QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}>
      <MemoryRouter><BuildingsPage /></MemoryRouter>
    </QueryClientProvider>,
  )

const g = (id, en, ar, sort) => ({ id, name_en: en, name_ar: ar, sort_order: sort })
const grades = [g(6, 'Grade 6', 'الصف السادس', 6), g(1, 'Grade 1', 'الصف الأول', 1), g(10, 'Grade 10', 'الصف العاشر', 10)]
const floor = {
  id: 5, building_id: 2, name: 'Ground floor', level: 0, gender: null, effective_gender: 'male',
  grade_from: { id: 6, name_en: 'Grade 6', name_ar: 'الصف السادس' }, grade_to: { id: 10, name_en: 'Grade 10', name_ar: 'الصف العاشر' },
  counts: { classroom: 12, science_lab: 2, computer_lab: 1 }, rooms: [],
}
const building = { id: 2, name: 'Boys building', gender: 'male', floors: [floor] }

const serve = (buildings = [building]) => {
  api.get.mockImplementation((url) => {
    if (url === '/buildings') return Promise.resolve({ data: { data: buildings } })
    if (url === '/grade-levels') return Promise.resolve({ data: { data: grades, meta: { current_page: 1, last_page: 1 } } })
    return Promise.resolve({ data: { data: [] } })
  })
}

beforeEach(async () => {
  vi.resetAllMocks()
  await i18n.changeLanguage('en')
})

describe('BuildingsPage', () => {
  it('lists the buildings with their floors, grade range and room counts', async () => {
    serve()
    renderPage()
    expect(await screen.findByRole('heading', { name: 'Boys building' })).toBeInTheDocument()
    const row = screen.getByRole('row', { name: /Ground floor/ })
    expect(within(row).getByText('Grade 6 → Grade 10')).toBeInTheDocument()
    expect(within(row).getByText('12')).toBeInTheDocument()
    expect(within(row).getByText('Boys')).toBeInTheDocument()
  })

  it('explains an empty school', async () => {
    serve([])
    renderPage()
    expect(await screen.findByText('No buildings yet. Add the first building of the school.')).toBeInTheDocument()
  })

  it('adds a building with its gender', async () => {
    serve([])
    api.post.mockResolvedValue({ data: { data: { id: 3 } } })
    renderPage()
    await screen.findByText(/No buildings yet/)
    fireEvent.click(screen.getByRole('button', { name: 'Add building' }))
    fireEvent.change(screen.getByLabelText('Name'), { target: { value: 'Girls building' } })
    fireEvent.change(screen.getByLabelText('Students'), { target: { value: 'female' } })
    fireEvent.click(screen.getByRole('button', { name: 'Save' }))
    await waitFor(() => expect(api.post).toHaveBeenCalledWith('/buildings', { name: 'Girls building', gender: 'female' }))
  })

  it('adds a floor with the grades it serves and how many classrooms and labs it has', async () => {
    serve()
    api.post.mockResolvedValue({ data: { data: { id: 9 } } })
    renderPage()
    fireEvent.click(await screen.findByRole('button', { name: 'Add floor' }))
    const dialog = screen.getByRole('dialog')
    await within(within(dialog).getByLabelText('From grade')).findByRole('option', { name: 'Grade 10' })

    expect(within(dialog).getByLabelText('Level')).toHaveValue(1) // the next free level after the ground floor
    fireEvent.change(within(dialog).getByLabelText('Name'), { target: { value: 'First floor' } })
    fireEvent.change(within(dialog).getByLabelText('From grade'), { target: { value: '1' } })
    fireEvent.change(within(dialog).getByLabelText('To grade'), { target: { value: '6' } })
    fireEvent.change(within(dialog).getByLabelText('Classrooms'), { target: { value: '10' } })
    fireEvent.change(within(dialog).getByLabelText('Science labs'), { target: { value: '1' } })
    fireEvent.change(within(dialog).getByLabelText('Computer labs'), { target: { value: '2' } })
    fireEvent.click(within(dialog).getByRole('button', { name: 'Save' }))

    await waitFor(() => expect(api.post).toHaveBeenCalledWith('/floors', {
      building_id: 2, name: 'First floor', level: 1, gender: null, grade_from_id: 1, grade_to_id: 6, classrooms: 10, science_labs: 1, computer_labs: 2,
    }))
  })

  it('orders the grades by their sort order in the pickers', async () => {
    serve()
    renderPage()
    fireEvent.click(await screen.findByRole('button', { name: 'Add floor' }))
    const dialog = screen.getByRole('dialog')
    await within(within(dialog).getByLabelText('From grade')).findByRole('option', { name: 'Grade 10' })
    const options = within(within(dialog).getByLabelText('From grade')).getAllByRole('option').map((o) => o.textContent)
    expect(options.filter((x) => x.startsWith('Grade'))).toEqual(['Grade 1', 'Grade 6', 'Grade 10'])
  })

  it('edits a floor with its current values', async () => {
    serve()
    api.put.mockResolvedValue({ data: { data: { id: 5 } } })
    renderPage()
    fireEvent.click(await within(await screen.findByRole('row', { name: /Ground floor/ })).findByRole('button', { name: 'Edit' }))
    const dialog = screen.getByRole('dialog')
    expect(within(dialog).getByLabelText('Classrooms')).toHaveValue(12)
    expect(within(dialog).getByLabelText('Name')).toHaveValue('Ground floor')
    fireEvent.change(within(dialog).getByLabelText('Classrooms'), { target: { value: '14' } })
    fireEvent.click(within(dialog).getByRole('button', { name: 'Save' }))
    await waitFor(() => expect(api.put).toHaveBeenCalledWith('/floors/5', expect.objectContaining({ building_id: 2, classrooms: 14, science_labs: 2, grade_from_id: 6, grade_to_id: 10 })))
  })

  it('shows the server error when rooms in use cannot be removed', async () => {
    serve()
    api.put.mockRejectedValue({ response: { status: 422, data: { errors: { classrooms: ['3 room(s) in use cannot be removed.'] } } } })
    renderPage()
    fireEvent.click(await within(await screen.findByRole('row', { name: /Ground floor/ })).findByRole('button', { name: 'Edit' }))
    fireEvent.click(within(screen.getByRole('dialog')).getByRole('button', { name: 'Save' }))
    expect(await screen.findByText('3 room(s) in use cannot be removed.')).toBeInTheDocument()
  })

  it('deletes a floor after confirmation and shows the reason when it is in use', async () => {
    serve()
    api.delete.mockRejectedValue({ response: { status: 422, data: { message: 'This floor is in use and cannot be deleted.' } } })
    renderPage()
    fireEvent.click(await within(await screen.findByRole('row', { name: /Ground floor/ })).findByRole('button', { name: 'Delete' }))
    expect(api.delete).not.toHaveBeenCalled()
    fireEvent.click(within(screen.getByRole('dialog')).getByRole('button', { name: 'Delete' }))
    await waitFor(() => expect(api.delete).toHaveBeenCalledWith('/floors/5'))
    expect(await screen.findByRole('alert')).toHaveTextContent('This floor is in use and cannot be deleted.')
  })

  it('has Arabic labels', async () => {
    await i18n.changeLanguage('ar')
    serve()
    renderPage()
    expect(await screen.findByRole('button', { name: 'إضافة مبنى' })).toBeInTheDocument()
    expect(await screen.findByText('الصف السادس ← الصف العاشر')).toBeInTheDocument()
  })
})
