import { fireEvent, render, screen, waitFor } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { MemoryRouter } from 'react-router-dom'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import i18n from '../src/i18n'
import { api } from '../src/api/client'
import StudentFilesPage from '../src/features/StudentFiles/StudentFilesPage'
import StudentsPage from '../src/features/People/pages/StudentsPage'

vi.mock('../src/api/client', () => ({ api: { get: vi.fn(), post: vi.fn(), put: vi.fn(), delete: vi.fn() } }))
vi.mock('../src/features/Auth/AuthProvider', () => ({ useAuth: () => ({ user: { role: 'admin' } }) }))

const wrap = (ui) => render(<QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}><MemoryRouter>{ui}</MemoryRouter></QueryClientProvider>)
const sara = { id: 5, student_number: '1001', name_ar: 'سارة', name_en: 'Sara', classroom: { id: 1, name: '1-A' }, files: [{ id: 9, name: 'birth.pdf', mime: 'application/pdf', size: 2048, is_image: false }] }

beforeEach(async () => { vi.resetAllMocks(); await i18n.changeLanguage('en') })

describe('student files page', () => {
  it('finds a student by name, shows the files and uploads a new one for that student', async () => {
    api.get.mockResolvedValue({ data: { data: [sara] } })
    api.post.mockResolvedValue({ data: { data: [] } })
    wrap(<StudentFilesPage />)
    fireEvent.change(screen.getByLabelText('Student name or number'), { target: { value: 'Sara' } })
    fireEvent.click(await screen.findByRole('button', { name: /Sara/ }))
    expect(await screen.findByText('birth.pdf')).toBeInTheDocument()
    expect(api.get).toHaveBeenCalledWith('/students', { params: { search: 'Sara', per_page: 8 } })

    fireEvent.change(screen.getByLabelText('Choose files'), { target: { files: [new File(['x'], 'records.pdf', { type: 'application/pdf' })] } })
    await waitFor(() => expect(api.post).toHaveBeenCalledWith('/attachments/student/5', expect.any(FormData)))
  })

  it('says so when no student matches', async () => {
    api.get.mockResolvedValue({ data: { data: [] } })
    wrap(<StudentFilesPage />)
    fireEvent.change(screen.getByLabelText('Student name or number'), { target: { value: 'zzz' } })
    expect(await screen.findByText('No student with this name or number.')).toBeInTheDocument()
  })
})

describe('students form', () => {
  it('uploads the chosen file after a new student is saved, and does not send it as data', async () => {
    api.get.mockResolvedValue({ data: { data: [], meta: { current_page: 1, last_page: 1 } } })
    api.post.mockImplementation(async () => ({ data: { data: { id: 77, account: { email: 'a@b.c', password: 'x' } } } }))
    wrap(<StudentsPage />)
    fireEvent.click(await screen.findByRole('button', { name: 'Add' }))
    fireEvent.change(await screen.findByLabelText('Student number'), { target: { value: 'S-9' } })
    fireEvent.change(screen.getByLabelText('Choose files'), { target: { files: [new File(['x'], 'cert.pdf', { type: 'application/pdf' })] } })
    expect(screen.getByText('cert.pdf')).toBeInTheDocument()
    fireEvent.click(screen.getByRole('button', { name: 'Save' }))
    await waitFor(() => expect(api.post).toHaveBeenCalledWith('/attachments/student/77', expect.any(FormData)))
    const body = api.post.mock.calls.find((c) => c[0] === '/students')[1]
    expect(Object.keys(body).some((k) => k.startsWith('_'))).toBe(false)
  })
})
