import { fireEvent, render, screen, waitFor, within } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { MemoryRouter } from 'react-router-dom'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import i18n from '../src/i18n'
import { api } from '../src/api/client'
import ClassroomsPage from '../src/features/Academic/pages/ClassroomsPage'
import StudentsPage from '../src/features/People/pages/StudentsPage'
import TeachersPage from '../src/features/People/pages/TeachersPage'

vi.mock('../src/api/client', () => ({
  api: { get: vi.fn(), post: vi.fn(), put: vi.fn(), delete: vi.fn() },
}))

const paged = (data) => ({ data: { data, meta: { current_page: 1, last_page: 1 } } })
const renderPage = (ui) =>
  render(
    <QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}>
      <MemoryRouter>{ui}</MemoryRouter>
    </QueryClientProvider>,
  )

const teacher = { id: 5, name: 'Sara Khaled', email: 'sara@school.test', phone: '0599000001', is_active: true }
const student = {
  id: 9, user_id: 21, student_number: 'S-1001', name_ar: 'محمد', name_en: 'Mohammad', birth_date: '2015-04-01', gender: 'male',
  email: 'mohammad@school.test', classroom: { id: 3, name: '1-A' }, guardians: [{ id: 30, name: 'Abu Mohammad', email: 'dad@mail.test' }],
}

beforeEach(async () => {
  vi.resetAllMocks()
  await i18n.changeLanguage('en')
})

describe('TeachersPage', () => {
  it('lists teachers with their email', async () => {
    api.get.mockResolvedValue(paged([teacher]))
    renderPage(<TeachersPage />)
    expect(await screen.findByText('sara@school.test')).toBeInTheDocument()
    expect(screen.getByRole('heading', { name: 'Teachers' })).toBeInTheDocument()
  })

  it('creates a teacher (the password is never typed: the account gets the default one)', async () => {
    api.get.mockResolvedValue(paged([]))
    api.post.mockResolvedValue({ data: { data: { id: 6 } } })
    renderPage(<TeachersPage />)
    await screen.findByText('Nothing to show yet.')
    fireEvent.click(screen.getByRole('button', { name: 'Add' }))
    expect(screen.queryByLabelText('Password')).not.toBeInTheDocument()
    fireEvent.change(screen.getByLabelText('Name'), { target: { value: 'Omar' } })
    fireEvent.change(screen.getByLabelText('Email'), { target: { value: 'omar@school.test' } })
    fireEvent.click(screen.getByRole('button', { name: 'Save' }))
    await waitFor(() => expect(api.post).toHaveBeenCalledWith('/teachers', { name: 'Omar', email: 'omar@school.test', phone: null, is_active: true }))
  })

  it('shows a field error from the server', async () => {
    api.get.mockResolvedValue(paged([]))
    api.post.mockRejectedValue({ response: { status: 422, data: { errors: { email: ['The email has already been taken.'] } } } })
    renderPage(<TeachersPage />)
    await screen.findByText('Nothing to show yet.')
    fireEvent.click(screen.getByRole('button', { name: 'Add' }))
    fireEvent.click(screen.getByRole('button', { name: 'Save' }))
    expect(await screen.findByText('The email has already been taken.')).toBeInTheDocument()
  })

  it('resets a password to the default only after confirmation', async () => {
    api.get.mockResolvedValue(paged([teacher]))
    api.post.mockResolvedValue({ data: { message: 'ok' } })
    renderPage(<TeachersPage />)
    await screen.findByText('sara@school.test')
    fireEvent.click(screen.getByRole('button', { name: 'Reset password' }))
    expect(api.post).not.toHaveBeenCalled()
    const dialog = screen.getByRole('dialog')
    fireEvent.click(within(dialog).getByRole('button', { name: 'Reset password' }))
    await waitFor(() => expect(api.post).toHaveBeenCalledWith('/users/5/reset-password'))
    expect(await screen.findByText('The password was reset to the default one.')).toBeInTheDocument()
  })

  it('has Arabic labels', async () => {
    await i18n.changeLanguage('ar')
    api.get.mockResolvedValue(paged([]))
    renderPage(<TeachersPage />)
    expect(await screen.findByRole('heading', { name: 'المعلمون' })).toBeInTheDocument()
  })
})

describe('StudentsPage', () => {
  it('lists students with their email, classroom and parent', async () => {
    api.get.mockResolvedValue(paged([student]))
    renderPage(<StudentsPage />)
    expect(await screen.findByText('S-1001')).toBeInTheDocument()
    expect(screen.getByText('mohammad@school.test')).toBeInTheDocument()
    expect(screen.getByText('1-A')).toBeInTheDocument()
    expect(screen.getByText('dad@mail.test')).toBeInTheDocument()
  })

  it('registers a student with the student and parent emails', async () => {
    api.get.mockImplementation((url) => Promise.resolve(url === '/classrooms' ? paged([{ id: 3, name: '1-A' }]) : paged([])))
    api.post.mockResolvedValue({ data: { data: { id: 10 } } })
    renderPage(<StudentsPage />)
    await screen.findByText('Nothing to show yet.')
    fireEvent.click(screen.getByRole('button', { name: 'Add' }))
    fireEvent.change(screen.getByLabelText('Student number'), { target: { value: 'S-2' } })
    fireEvent.change(screen.getByLabelText('Name (Arabic)'), { target: { value: 'سارة' } })
    fireEvent.change(screen.getByLabelText('Student email'), { target: { value: 'sara@school.test' } })
    fireEvent.change(screen.getByLabelText('Parent email'), { target: { value: 'mum@mail.test' } })
    fireEvent.change(screen.getByLabelText('Parent name'), { target: { value: 'Mum' } })
    await screen.findByRole('option', { name: '1-A' })
    fireEvent.change(screen.getByLabelText('Classroom'), { target: { value: '3' } })
    fireEvent.change(screen.getByLabelText('Gender'), { target: { value: 'female' } })
    fireEvent.click(screen.getByRole('button', { name: 'Save' }))
    await waitFor(() => expect(api.post).toHaveBeenCalledWith('/students', expect.objectContaining({
      student_number: 'S-2', name_ar: 'سارة', student_email: 'sara@school.test', guardian_email: 'mum@mail.test',
      guardian_name: 'Mum', classroom_id: '3', gender: 'female', name_en: null, birth_date: null,
    })))
  })

  it('pre-fills the form when editing, including the student email, classroom and parent', async () => {
    api.get.mockImplementation((url) => Promise.resolve(url === '/classrooms' ? paged([{ id: 3, name: '1-A' }]) : paged([student])))
    renderPage(<StudentsPage />)
    await screen.findByText('S-1001')
    fireEvent.click(screen.getByRole('button', { name: 'Edit' }))
    expect(screen.getByLabelText('Student email')).toHaveValue('mohammad@school.test')
    expect(screen.getByLabelText('Parent email')).toHaveValue('dad@mail.test')
    expect(screen.getByLabelText('Parent name')).toHaveValue('Abu Mohammad')
    expect(screen.getByLabelText('Student number')).toHaveValue('S-1001')
  })

  it('resets the student password and the parent password separately', async () => {
    api.get.mockResolvedValue(paged([student]))
    api.post.mockResolvedValue({ data: { message: 'ok' } })
    renderPage(<StudentsPage />)
    await screen.findByText('S-1001')

    fireEvent.click(screen.getByRole('button', { name: 'Reset password' }))
    fireEvent.click(within(screen.getByRole('dialog')).getByRole('button', { name: 'Reset password' }))
    await waitFor(() => expect(api.post).toHaveBeenCalledWith('/users/21/reset-password'))

    fireEvent.click(screen.getByRole('button', { name: 'Reset parent password' }))
    fireEvent.click(within(screen.getByRole('dialog')).getByRole('button', { name: 'Reset parent password' }))
    await waitFor(() => expect(api.post).toHaveBeenCalledWith('/users/30/reset-password'))
  })

  it('hides the parent reset when the student has no parent', async () => {
    api.get.mockResolvedValue(paged([{ ...student, guardians: [] }]))
    renderPage(<StudentsPage />)
    await screen.findByText('S-1001')
    expect(screen.queryByRole('button', { name: 'Reset parent password' })).not.toBeInTheDocument()
  })

  it('has Arabic labels', async () => {
    await i18n.changeLanguage('ar')
    api.get.mockResolvedValue(paged([]))
    renderPage(<StudentsPage />)
    expect(await screen.findByRole('heading', { name: 'الطلاب' })).toBeInTheDocument()
  })
})

describe('ClassroomsPage homeroom teacher', () => {
  it('lets the admin pick the homeroom teacher from the teachers list', async () => {
    api.get.mockImplementation((url) => {
      if (url === '/teachers') return Promise.resolve(paged([teacher]))
      if (url === '/classrooms') return Promise.resolve(paged([]))
      return Promise.resolve(paged([]))
    })
    api.post.mockResolvedValue({ data: { data: { id: 1 } } })
    renderPage(<ClassroomsPage />)
    await screen.findByText('Nothing to show yet.')
    fireEvent.click(screen.getByRole('button', { name: 'Add' }))
    await screen.findByRole('option', { name: 'Sara Khaled' })
    fireEvent.change(screen.getByLabelText('Name'), { target: { value: '1-A' } })
    fireEvent.change(screen.getByLabelText('Homeroom teacher'), { target: { value: '5' } })
    fireEvent.click(screen.getByRole('button', { name: 'Save' }))
    await waitFor(() => expect(api.post).toHaveBeenCalledWith('/classrooms', expect.objectContaining({ name: '1-A', homeroom_teacher_id: '5' })))
  })
})
