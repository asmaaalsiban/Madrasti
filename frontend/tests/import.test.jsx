import { fireEvent, render, screen, waitFor } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import i18n from '../src/i18n'
import { api } from '../src/api/client'
import ImportPage from '../src/features/Import/pages/ImportPage'

vi.mock('../src/api/client', () => ({ api: { get: vi.fn(), post: vi.fn(), put: vi.fn(), delete: vi.fn() } }))

const renderPage = () => render(<QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}><ImportPage /></QueryClientProvider>)
const pick = () => fireEvent.change(screen.getByLabelText('File'), { target: { files: [new File(['x'], 'school.xlsx')] } })

const studentFields = [{ key: 'student_number', label: 'Student number', required: false }, { key: 'name_ar', label: 'Name (Arabic)', required: false }, { key: 'classroom', label: 'Section', required: false }]
const analysis = (extra = {}) => ({ data: { data: {
  headers: ['م', 'اسم الطالب', 'الصف'], sample: [['1', 'ليلى أحمد', '5-A'], ['2', 'سامي خالد', '5-A']], row_count: 2,
  suggested: { 0: null, 1: 'name_ar', 2: 'classroom' }, fields: studentFields, positions: null, ...extra,
} } })

beforeEach(async () => {
  vi.resetAllMocks()
  await i18n.changeLanguage('en')
})

describe('ImportPage', () => {
  it('reads the school file, shows the guessed columns, checks it, then imports and lists the new accounts', async () => {
    api.post
      .mockResolvedValueOnce(analysis())
      .mockResolvedValueOnce({ data: { data: { total: 2, valid: 1, created: null, problem_count: 1, problems: [{ line: 3, errors: ['There is no section named "9-Z" this year.'] }] } } })
      .mockResolvedValueOnce({ data: { data: { total: 2, valid: 1, created: [{ student_number: '1', name: 'ليلى', email: 'layla.1@school.edu', parent_login: '0790000001' }], problem_count: 1, problems: [{ line: 3, errors: ['No section'] }] } } })
    renderPage()
    expect(screen.getByRole('button', { name: 'Read the file' })).toBeDisabled()
    pick()
    fireEvent.click(screen.getByRole('button', { name: 'Read the file' }))

    expect(await screen.findByText('We found 2 rows. Is each column understood correctly?')).toBeInTheDocument()
    expect(screen.getByLabelText('اسم الطالب')).toHaveValue('name_ar')
    expect(screen.getByLabelText('الصف')).toHaveValue('classroom')
    expect(screen.getByLabelText('م')).toHaveValue('')
    expect(screen.getByText(/numbers are generated/)).toBeInTheDocument()

    fireEvent.click(screen.getByRole('button', { name: 'Check the file' }))
    expect(await screen.findByText('2 rows read: 1 are ready, 1 have problems.')).toBeInTheDocument()
    const body = api.post.mock.calls[1][1]
    expect(JSON.parse(body.get('mapping'))).toEqual({ 1: 'name_ar', 2: 'classroom' })
    expect(body.get('confirm')).toBe('0')
    expect(screen.getByText(/There is no section named "9-Z"/)).toBeInTheDocument()

    fireEvent.click(screen.getByRole('button', { name: 'Import 1 rows' }))
    expect(await screen.findByText('Done: 1 added, 1 skipped.')).toBeInTheDocument()
    expect(api.post.mock.calls[2][1].get('confirm')).toBe('1')
    expect(screen.getByText('layla.1@school.edu')).toBeInTheDocument()
    expect(screen.getByText('0790000001')).toBeInTheDocument() // a parent with no email signs in with the phone
  })

  it('lets the admin correct a wrong guess, and a field keeps one column only', async () => {
    api.post.mockResolvedValueOnce(analysis())
    renderPage()
    pick()
    fireEvent.click(screen.getByRole('button', { name: 'Read the file' }))
    await screen.findByLabelText('اسم الطالب')

    fireEvent.change(screen.getByLabelText('م'), { target: { value: 'name_ar' } }) // take the name field for the first column
    expect(screen.getByLabelText('م')).toHaveValue('name_ar')
    expect(screen.getByLabelText('اسم الطالب')).toHaveValue('') // the old column gave it up
  })

  it('asks for the name column when none is chosen', async () => {
    api.post.mockResolvedValueOnce(analysis({ suggested: { 0: null, 1: null, 2: null } }))
    renderPage()
    pick()
    fireEvent.click(screen.getByRole('button', { name: 'Read the file' }))
    expect(await screen.findByText('Choose which column holds the name.')).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Check the file' })).toBeDisabled()
  })

  it('staff without a position column gets one position for everybody, and emails are generated', async () => {
    api.post
      .mockResolvedValueOnce({ data: { data: { headers: ['الاسم'], sample: [['Sara']], row_count: 1, suggested: { 0: 'name' }, fields: [{ key: 'name', label: 'Name', required: true }, { key: 'email', label: 'Email', required: false }], positions: ['teacher', 'registrar'] } } })
      .mockResolvedValueOnce({ data: { data: { total: 1, valid: 1, created: null, problem_count: 0, problems: [] } } })
    renderPage()
    fireEvent.click(screen.getByRole('tab', { name: 'Staff' }))
    pick()
    fireEvent.click(screen.getByRole('button', { name: 'Read the file' }))
    expect(await screen.findByText(/login emails are generated from the names/)).toBeInTheDocument()
    fireEvent.change(screen.getByLabelText(/Position of everyone/), { target: { value: 'registrar' } })
    fireEvent.click(screen.getByRole('button', { name: 'Check the file' }))
    await waitFor(() => expect(api.post).toHaveBeenCalledTimes(2))
    expect(api.post.mock.calls[1][0]).toBe('/import/staff')
    expect(api.post.mock.calls[1][1].get('default_staff_type')).toBe('registrar')
  })

  it('shows the server message when the file cannot be read', async () => {
    api.post.mockRejectedValue({ response: { data: { errors: { file: ['The file is empty or has no data rows under the header row.'] } } } })
    renderPage()
    pick()
    fireEvent.click(screen.getByRole('button', { name: 'Read the file' }))
    expect(await screen.findByRole('alert')).toHaveTextContent('The file is empty')
  })

  it('still offers the template for schools that start from scratch', async () => {
    api.get.mockResolvedValue({ data: new Blob(['x']), headers: {} })
    URL.createObjectURL = vi.fn(() => 'blob:x')
    URL.revokeObjectURL = vi.fn()
    vi.spyOn(HTMLAnchorElement.prototype, 'click').mockImplementation(() => {})
    renderPage()
    fireEvent.click(screen.getByRole('button', { name: 'Download the template' }))
    await waitFor(() => expect(api.get).toHaveBeenCalledWith('/import/students/template', { params: {}, responseType: 'blob' }))
  })
})
