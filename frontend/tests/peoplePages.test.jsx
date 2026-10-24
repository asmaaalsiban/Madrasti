import { fireEvent, render, screen, waitFor, within } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { MemoryRouter } from 'react-router-dom'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import i18n from '../src/i18n'
import { api } from '../src/api/client'
import ClassroomsPage from '../src/features/Academic/pages/ClassroomsPage'
import StaffPage from '../src/features/People/pages/StaffPage'
import StudentsPage from '../src/features/People/pages/StudentsPage'

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

const types = [
  { value: 'teacher', category: 'teaching', needs_subject: true, needs_floor: false },
  { value: 'head_of_department', category: 'teaching', needs_subject: true, needs_floor: false },
  { value: 'principal', category: 'administrative', needs_subject: false, needs_floor: false },
  { value: 'floor_supervisor', category: 'student_affairs', needs_subject: false, needs_floor: true },
  { value: 'nurse', category: 'health', needs_subject: false, needs_floor: false },
].map((t) => ({ grants_admin_access: false, can_be_homeroom: false, ...t }))

const sara = {
  id: 5, name: 'Sara Khaled', email: 'sara@school.test', phone: '0599000001', is_active: true,
  staff_type: 'teacher', category: 'teaching', subject: { id: 3, name_ar: 'الرياضيات', name_en: 'Math' }, floor: null,
}
const student = {
  id: 9, user_id: 21, student_number: 'S-1001', name_ar: 'محمد', name_en: 'Mohammad', birth_date: '2015-04-01', gender: 'male',
  email: 'mohammad.s1001@school2.edu', classroom: { id: 3, name: '1-A' }, guardians: [{ id: 30, name: 'Abu Mohammad', email: 'dad@mail.test', phone: '0599123456' }],
}

// Answers the endpoints the pages call.
const guardianGrades = () => {
  const base = api.get.getMockImplementation()
  api.get.mockImplementation((url, c) => (url === '/grade-levels' ? Promise.resolve(paged([{ id: 6, name_en: 'Grade 6', name_ar: 'السادس' }, { id: 10, name_en: 'Grade 10', name_ar: 'العاشر' }])) : base(url, c)))
}
const dad = { id: 30, name: 'Abu Mohammad', email: 'dad@mail.test', phone: '0599123456', children: [{ id: 9, name_ar: 'محمد', name_en: 'Mohammad' }] }
let guardians = []

const serve = ({ staff = [], students = [], classrooms = [], parents = [] } = {}) => {
  guardians = parents
  api.get.mockImplementation((url) => {
    if (url === '/staff-types') return Promise.resolve({ data: { data: types } })
    if (url === '/staff') return Promise.resolve(paged(staff))
    if (url === '/students') return Promise.resolve(paged(students))
    if (url === '/classrooms') return Promise.resolve(paged(classrooms))
    if (url === '/guardians') return Promise.resolve({ data: { data: guardians } })
    if (url === '/subjects') return Promise.resolve(paged([{ id: 3, code: 'MTH', name_ar: 'الرياضيات', name_en: 'Math' }]))
    return Promise.resolve(paged([]))
  })
}

beforeEach(async () => {
  vi.resetAllMocks()
  await i18n.changeLanguage('en')
})

describe('StaffPage', () => {
  it('lists staff with their type and subject', async () => {
    serve({ staff: [sara] })
    renderPage(<StaffPage />)
    expect(await screen.findByText('sara@school.test')).toBeInTheDocument()
    expect(screen.getByRole('heading', { name: 'Staff' })).toBeInTheDocument()
    expect(screen.getAllByText('Teacher').length).toBeGreaterThan(0)
    expect(screen.getByText('Math')).toBeInTheDocument()
  })

  it('shows one tab per category and filters the list by the open tab', async () => {
    serve({ staff: [sara] })
    renderPage(<StaffPage />)
    await screen.findByText('sara@school.test')
    for (const name of ['All', 'Teaching', 'Administrative', 'Student affairs', 'Health', 'Technical', 'Services']) {
      expect(screen.getByRole('tab', { name })).toBeInTheDocument()
    }
    expect(screen.getByRole('tab', { name: 'All' })).toHaveAttribute('aria-selected', 'true')

    fireEvent.click(screen.getByRole('tab', { name: 'Health' }))
    await waitFor(() => expect(api.get).toHaveBeenCalledWith('/staff', { params: expect.objectContaining({ category: 'health' }) }))
    expect(screen.getByRole('tab', { name: 'Health' })).toHaveAttribute('aria-selected', 'true')
  })

  it('asks for the subject only for teaching types and the floor only for floor supervisors', async () => {
    serve()
    renderPage(<StaffPage />)
    await screen.findByText('Nothing to show yet.')
    fireEvent.click(screen.getByRole('button', { name: 'Add' }))
    await screen.findByRole('option', { name: 'Nurse' })

    expect(screen.getByLabelText('Subject')).toBeInTheDocument() // the default type is Teacher
    fireEvent.change(screen.getByLabelText('Type'), { target: { value: 'nurse' } })
    expect(screen.queryByLabelText('Subject')).not.toBeInTheDocument()
    expect(screen.queryByLabelText('Floor')).not.toBeInTheDocument()
    fireEvent.change(screen.getByLabelText('Type'), { target: { value: 'floor_supervisor' } })
    expect(screen.getByLabelText('Floor')).toBeInTheDocument()
    expect(screen.queryByLabelText('Subject')).not.toBeInTheDocument()
  })

  it('saves whom a teacher teaches: a range of grades and boys or girls only', async () => {
    serve()
    guardianGrades()
    api.post.mockResolvedValue({ data: { data: { id: 6 } } })
    renderPage(<StaffPage />)
    await screen.findByText('Nothing to show yet.')
    fireEvent.click(screen.getByRole('button', { name: 'Add' }))
    await within(screen.getByLabelText('Teaches from grade')).findByRole('option', { name: 'Grade 6' })
    fireEvent.change(screen.getByLabelText('Name'), { target: { value: 'Omar' } })
    fireEvent.change(screen.getByLabelText('Email'), { target: { value: 'omar@school.test' } })
    fireEvent.change(screen.getByLabelText('Teaches from grade'), { target: { value: '6' } })
    fireEvent.change(screen.getByLabelText('Teaches to grade'), { target: { value: '10' } })
    fireEvent.change(screen.getByLabelText('Teaches students'), { target: { value: 'female' } })
    fireEvent.click(screen.getByRole('button', { name: 'Save' }))
    await waitFor(() => expect(api.post).toHaveBeenCalledWith('/staff', expect.objectContaining({ grade_from_id: '6', grade_to_id: '10', teaches_gender: 'female' })))
  })

  it('chooses the sections a teacher teaches', async () => {
    serve({ classrooms: [{ id: 3, name: '5-A' }, { id: 4, name: '5-B' }, { id: 5, name: '6-A' }] })
    api.post.mockResolvedValue({ data: { data: { id: 6 } } })
    renderPage(<StaffPage />)
    await screen.findByText('Nothing to show yet.')
    fireEvent.click(screen.getByRole('button', { name: 'Add' }))
    fireEvent.click(await screen.findByRole('checkbox', { name: '5-B' }))
    fireEvent.click(screen.getByRole('checkbox', { name: '6-A' }))
    expect(screen.getByText('Sections the teacher teaches (2)')).toBeInTheDocument()
    fireEvent.change(screen.getByPlaceholderText('Search sections'), { target: { value: '5-' } })
    expect(screen.queryByRole('checkbox', { name: '6-A' })).not.toBeInTheDocument()
    fireEvent.change(screen.getByLabelText('Name'), { target: { value: 'Head' } })
    fireEvent.change(screen.getByLabelText('Email'), { target: { value: 'head@school.test' } })
    fireEvent.click(screen.getByRole('button', { name: 'Save' }))
    await waitFor(() => expect(api.post).toHaveBeenCalledWith('/staff', expect.objectContaining({ classroom_ids: [4, 5] })))
  })

  it('shows the sections already chosen for a teacher when editing', async () => {
    serve({ staff: [{ ...sara, classroom_ids: [3], classrooms: [{ id: 3, name: '5-A' }] }], classrooms: [{ id: 3, name: '5-A' }, { id: 4, name: '5-B' }] })
    renderPage(<StaffPage />)
    fireEvent.click(await screen.findByRole('button', { name: 'Edit' }))
    expect(await screen.findByRole('checkbox', { name: '5-A' })).toBeChecked()
    expect(screen.getByRole('checkbox', { name: '5-B' })).not.toBeChecked()
  })

  it('does not ask a nurse whom they teach', async () => {
    serve()
    renderPage(<StaffPage />)
    await screen.findByText('Nothing to show yet.')
    fireEvent.click(screen.getByRole('button', { name: 'Add' }))
    await screen.findByRole('option', { name: 'Nurse' })
    fireEvent.change(screen.getByLabelText('Type'), { target: { value: 'nurse' } })
    expect(screen.queryByLabelText('Teaches from grade')).not.toBeInTheDocument()
    expect(screen.queryByLabelText('Teaches students')).not.toBeInTheDocument()
  })

  it('creates a teacher with a subject (no password is typed)', async () => {
    serve()
    api.post.mockResolvedValue({ data: { data: { id: 6 } } })
    renderPage(<StaffPage />)
    await screen.findByText('Nothing to show yet.')
    fireEvent.click(screen.getByRole('button', { name: 'Add' }))
    await screen.findByRole('option', { name: 'Math' })
    expect(screen.queryByLabelText('Password')).not.toBeInTheDocument()
    fireEvent.change(screen.getByLabelText('Name'), { target: { value: 'Omar' } })
    fireEvent.change(screen.getByLabelText('Email'), { target: { value: 'omar@school.test' } })
    fireEvent.change(screen.getByLabelText('Subject'), { target: { value: '3' } })
    fireEvent.click(screen.getByRole('button', { name: 'Save' }))
    await waitFor(() => expect(api.post).toHaveBeenCalledWith('/staff', expect.objectContaining({
      name: 'Omar', email: 'omar@school.test', staff_type: 'teacher', subject_id: '3', is_active: true,
    })))
  })

  it('opens the form on the first type of the open tab', async () => {
    serve()
    renderPage(<StaffPage />)
    await screen.findByText('Nothing to show yet.')
    fireEvent.click(screen.getByRole('tab', { name: 'Health' }))
    await screen.findByText('Nothing to show yet.')
    fireEvent.click(screen.getByRole('button', { name: 'Add' }))
    await screen.findByRole('option', { name: 'Nurse' })
    expect(screen.getByLabelText('Type')).toHaveValue('nurse')
  })

  it('shows a field error from the server', async () => {
    serve()
    api.post.mockRejectedValue({ response: { status: 422, data: { errors: { email: ['The email has already been taken.'] } } } })
    renderPage(<StaffPage />)
    await screen.findByText('Nothing to show yet.')
    fireEvent.click(screen.getByRole('button', { name: 'Add' }))
    fireEvent.click(screen.getByRole('button', { name: 'Save' }))
    expect(await screen.findByText('The email has already been taken.')).toBeInTheDocument()
  })

  it('resets a password to the default only after confirmation', async () => {
    serve({ staff: [sara] })
    api.post.mockResolvedValue({ data: { message: 'ok' } })
    renderPage(<StaffPage />)
    await screen.findByText('sara@school.test')
    fireEvent.click(screen.getByRole('button', { name: 'Reset password' }))
    expect(api.post).not.toHaveBeenCalled()
    fireEvent.click(within(screen.getByRole('dialog')).getByRole('button', { name: 'Reset password' }))
    await waitFor(() => expect(api.post).toHaveBeenCalledWith('/users/5/reset-password'))
    expect(await screen.findByText('The password was reset to the default one.')).toBeInTheDocument()
  })

  it('has Arabic labels for the page, the tabs and the types', async () => {
    await i18n.changeLanguage('ar')
    serve({ staff: [sara] })
    renderPage(<StaffPage />)
    expect(await screen.findByRole('heading', { name: 'الكادر' })).toBeInTheDocument()
    expect(screen.getByRole('tab', { name: 'التدريس' })).toBeInTheDocument()
    expect(screen.getByRole('tab', { name: 'الإداريون' })).toBeInTheDocument()
    expect((await screen.findAllByText('معلم')).length).toBeGreaterThan(0)
  })
})

describe('StudentsPage', () => {
  it('lists students with their generated email, classroom and parent', async () => {
    serve({ students: [student] })
    renderPage(<StudentsPage />)
    expect(await screen.findByText('S-1001')).toBeInTheDocument()
    expect(screen.getByText('mohammad.s1001@school2.edu')).toBeInTheDocument()
    expect(screen.getByText('1-A')).toBeInTheDocument()
    expect(screen.getByText('dad@mail.test')).toBeInTheDocument()
  })

  it('has no student email field (it is generated) but asks for the parent phone', async () => {
    serve()
    renderPage(<StudentsPage />)
    await screen.findByText('Nothing to show yet.')
    fireEvent.click(screen.getByRole('button', { name: 'Add' }))
    expect(screen.queryByLabelText('Student email')).not.toBeInTheDocument()
    expect(screen.getByLabelText('Parent phone')).toBeInTheDocument()
    expect(screen.getByLabelText('Parent email')).toBeInTheDocument()
  })

  it('shows only the students of the classroom picked in the filter', async () => {
    serve({ students: [student], classrooms: [{ id: 3, name: '6-A' }, { id: 4, name: '6-B' }] })
    renderPage(<StudentsPage />)
    await screen.findByText('S-1001')
    expect(api.get).toHaveBeenCalledWith('/students', { params: expect.not.objectContaining({ classroom_id: expect.anything() }) })

    await screen.findByRole('option', { name: '6-A' })
    fireEvent.change(screen.getByLabelText('Filter by classroom'), { target: { value: '3' } })
    await waitFor(() => expect(api.get).toHaveBeenCalledWith('/students', { params: expect.objectContaining({ classroom_id: '3' }) }))

    fireEvent.change(screen.getByLabelText('Filter by classroom'), { target: { value: '' } })
    await waitFor(() => expect(api.get).toHaveBeenLastCalledWith('/students', { params: expect.not.objectContaining({ classroom_id: expect.anything() }) }))
  })

  it('fills the parent fields from an existing parent found by search', async () => {
    serve({ parents: [dad] })
    renderPage(<StudentsPage />)
    await screen.findByText('Nothing to show yet.')
    fireEvent.click(screen.getByRole('button', { name: 'Add' }))
    fireEvent.change(screen.getByLabelText('Find an existing parent'), { target: { value: 'Abu' } })
    fireEvent.click(await screen.findByRole('button', { name: /Abu Mohammad/ }))

    expect(api.get).toHaveBeenCalledWith('/guardians', { params: expect.objectContaining({ search: 'Abu' }) })
    expect(screen.getByLabelText('Parent email')).toHaveValue('dad@mail.test')
    expect(screen.getByLabelText('Parent name')).toHaveValue('Abu Mohammad')
    expect(screen.getByLabelText('Parent phone')).toHaveValue('0599123456')
    expect(screen.queryByRole('button', { name: /Abu Mohammad/ })).not.toBeInTheDocument()
  })

  it('does not search for fewer than two letters and says when nobody matches', async () => {
    serve()
    renderPage(<StudentsPage />)
    await screen.findByText('Nothing to show yet.')
    fireEvent.click(screen.getByRole('button', { name: 'Add' }))
    fireEvent.change(screen.getByLabelText('Find an existing parent'), { target: { value: 'A' } })
    expect(api.get).not.toHaveBeenCalledWith('/guardians', expect.anything())
    fireEvent.change(screen.getByLabelText('Find an existing parent'), { target: { value: 'Zed' } })
    expect(await screen.findByText('No parent found. Type the email below to create a new one.')).toBeInTheDocument()
  })

  it('registers a student and shows the generated sign-in details once', async () => {
    serve({ classrooms: [{ id: 3, name: '1-A' }] })
    api.post.mockResolvedValue({ data: { data: { id: 10, account: { email: 'sara.s2@school2.edu', password: '123456789' } } } })
    renderPage(<StudentsPage />)
    await screen.findByText('Nothing to show yet.')
    fireEvent.click(screen.getByRole('button', { name: 'Add' }))
    fireEvent.change(screen.getByLabelText('Student number'), { target: { value: 'S-2' } })
    fireEvent.change(screen.getByLabelText('Name (Arabic)'), { target: { value: 'سارة' } })
    fireEvent.change(screen.getByLabelText('Name (English)'), { target: { value: 'Sara' } })
    fireEvent.change(screen.getByLabelText('Parent email'), { target: { value: 'mum@mail.test' } })
    fireEvent.change(screen.getByLabelText('Parent phone'), { target: { value: '0599555555' } })
    await within(screen.getByRole('dialog')).findByRole('option', { name: '1-A' })
    fireEvent.change(screen.getByLabelText('Classroom'), { target: { value: '3' } })
    fireEvent.click(screen.getByRole('button', { name: 'Save' }))

    await waitFor(() => expect(api.post).toHaveBeenCalledWith('/students', expect.objectContaining({
      student_number: 'S-2', name_ar: 'سارة', name_en: 'Sara', guardian_email: 'mum@mail.test', guardian_phone: '0599555555', classroom_id: '3',
    })))
    const body = api.post.mock.calls[0][1]
    expect(body).not.toHaveProperty('student_email')

    const dialog = await screen.findByRole('dialog', { name: 'Student account created' })
    expect(within(dialog).getByText('sara.s2@school2.edu')).toBeInTheDocument()
    expect(within(dialog).getByText('123456789')).toBeInTheDocument()
    fireEvent.click(within(dialog).getByRole('button', { name: 'Close' }))
    expect(screen.queryByRole('dialog', { name: 'Student account created' })).not.toBeInTheDocument()
  })

  it('does not show a sign-in dialog after editing a student', async () => {
    serve({ students: [student] })
    api.put.mockResolvedValue({ data: { data: { id: 9 } } })
    renderPage(<StudentsPage />)
    await screen.findByText('S-1001')
    fireEvent.click(screen.getByRole('button', { name: 'Edit' }))
    fireEvent.click(screen.getByRole('button', { name: 'Save' }))
    await waitFor(() => expect(api.put).toHaveBeenCalled())
    expect(screen.queryByRole('dialog', { name: 'Student account created' })).not.toBeInTheDocument()
  })

  it('pre-fills the form when editing, including the classroom and the parent', async () => {
    serve({ students: [student], classrooms: [{ id: 3, name: '1-A' }] })
    renderPage(<StudentsPage />)
    await screen.findByText('S-1001')
    fireEvent.click(screen.getByRole('button', { name: 'Edit' }))
    expect(screen.getByLabelText('Parent email')).toHaveValue('dad@mail.test')
    expect(screen.getByLabelText('Parent name')).toHaveValue('Abu Mohammad')
    expect(screen.getByLabelText('Parent phone')).toHaveValue('0599123456')
    expect(screen.getByLabelText('Student number')).toHaveValue('S-1001')
  })

  it('resets the student password and the parent password separately', async () => {
    serve({ students: [student] })
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
    serve({ students: [{ ...student, guardians: [] }] })
    renderPage(<StudentsPage />)
    await screen.findByText('S-1001')
    expect(screen.queryByRole('button', { name: 'Reset parent password' })).not.toBeInTheDocument()
  })

  it('has Arabic labels', async () => {
    await i18n.changeLanguage('ar')
    serve()
    renderPage(<StudentsPage />)
    expect(await screen.findByRole('heading', { name: 'الطلاب' })).toBeInTheDocument()
  })
})

describe('ClassroomsPage homeroom teacher', () => {
  it('lets the admin pick the homeroom teacher among the staff who may be one', async () => {
    serve({ staff: [sara] })
    api.post.mockResolvedValue({ data: { data: { id: 1 } } })
    renderPage(<ClassroomsPage />)
    await screen.findByText('Nothing to show yet.')
    fireEvent.click(screen.getByRole('button', { name: 'Add' }))
    await screen.findByRole('option', { name: 'Sara Khaled' })
    expect(api.get).toHaveBeenCalledWith('/staff', { params: expect.objectContaining({ homeroom: 1 }) })
    fireEvent.change(screen.getByLabelText('Name'), { target: { value: '1-A' } })
    fireEvent.change(screen.getByLabelText('Homeroom teacher'), { target: { value: '5' } })
    fireEvent.click(screen.getByRole('button', { name: 'Save' }))
    await waitFor(() => expect(api.post).toHaveBeenCalledWith('/classrooms', expect.objectContaining({ name: '1-A', homeroom_teacher_id: '5' })))
  })
})
