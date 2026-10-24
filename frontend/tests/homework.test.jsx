import { fireEvent, render, screen, waitFor } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import i18n from '../src/i18n'
import { api } from '../src/api/client'
import HomeworkPage from '../src/features/Homework/pages/HomeworkPage'
import StudentHomeworkPage from '../src/features/Homework/pages/StudentHomeworkPage'
import ChildHomeworkPage from '../src/features/Homework/pages/ChildHomeworkPage'

vi.mock('../src/api/client', () => ({ api: { get: vi.fn(), post: vi.fn(), put: vi.fn(), delete: vi.fn() } }))
vi.mock('../src/features/Auth/AuthProvider', () => ({ useAuth: () => ({ user: { children: [{ id: 7, name_en: 'Lina', name_ar: 'لينا' }] } }) }))

const wrap = (ui, path = '/', route = '/') =>
  render(<QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}><MemoryRouter initialEntries={[path]}><Routes><Route path={route} element={ui} /></Routes></MemoryRouter></QueryClientProvider>)

const math = { id: 5, name_en: 'Math', name_ar: 'رياضيات' }
const hw = { id: 1, title: 'Page 10', description: 'Solve 1-5', due_on: '2026-11-01', classroom: { id: 3, name: '5-A' }, subject: math, teacher: { id: 9, name: 'Sara' }, images: [] }

beforeEach(async () => {
  vi.resetAllMocks()
  await i18n.changeLanguage('en')
})

describe('HomeworkPage (teacher)', () => {
  beforeEach(() => api.get.mockImplementation((url) => Promise.resolve({ data: { data: url === '/homework' ? [hw] : { assignments: [{ classroom: { id: 3, name: '5-A' }, subject: math }] } } })))

  it('lists the homework and sends a new one', async () => {
    api.post.mockResolvedValue({ data: { data: hw } })
    wrap(<HomeworkPage />)
    expect(await screen.findByText('Page 10')).toBeInTheDocument()
    fireEvent.click(screen.getByRole('button', { name: 'Send homework' }))
    await screen.findByRole('option', { name: '5-A · Math' })
    fireEvent.change(screen.getByLabelText('Section and subject'), { target: { value: '3-5' } })
    fireEvent.change(screen.getByLabelText('Title'), { target: { value: 'Page 11' } })
    fireEvent.change(screen.getByLabelText('Due date'), { target: { value: '2026-11-02' } })
    fireEvent.click(screen.getByRole('button', { name: 'Save' }))
    await waitFor(() => expect(api.post).toHaveBeenCalledWith('/homework', { classroom_id: 3, subject_id: 5, title: 'Page 11', description: null, due_on: '2026-11-02' }))
  })

  it('edits and deletes', async () => {
    api.put.mockResolvedValue({ data: { data: hw } })
    api.delete.mockResolvedValue({})
    wrap(<HomeworkPage />)
    await screen.findByText('Page 10')
    fireEvent.click(screen.getByRole('button', { name: 'Edit' }))
    fireEvent.change(screen.getByLabelText('Title'), { target: { value: 'Page 12' } })
    fireEvent.click(screen.getByRole('button', { name: 'Save' }))
    await waitFor(() => expect(api.put).toHaveBeenCalledWith('/homework/1', expect.objectContaining({ title: 'Page 12', classroom_id: 3, subject_id: 5 })))
    fireEvent.click(await screen.findByRole('button', { name: 'Delete' }))
    fireEvent.click(screen.getAllByRole('button', { name: 'Delete' }).at(-1))
    await waitFor(() => expect(api.delete).toHaveBeenCalledWith('/homework/1'))
  })

  it('shows the reason when refused', async () => {
    api.post.mockRejectedValue({ response: { data: { errors: { classroom_id: ['You do not teach this subject in this section.'] } } } })
    wrap(<HomeworkPage />)
    await screen.findByText('Page 10')
    fireEvent.click(screen.getByRole('button', { name: 'Send homework' }))
    await screen.findByRole('option', { name: '5-A · Math' })
    fireEvent.change(screen.getByLabelText('Section and subject'), { target: { value: '3-5' } })
    fireEvent.change(screen.getByLabelText('Title'), { target: { value: 'x' } })
    fireEvent.change(screen.getByLabelText('Due date'), { target: { value: '2026-11-02' } })
    fireEvent.click(screen.getByRole('button', { name: 'Save' }))
    expect(await screen.findByRole('alert')).toHaveTextContent('do not teach')
  })
})

describe('photos and answers', () => {
  const photo = (name) => new File(['x'], name, { type: 'image/jpeg' })
  beforeEach(() => {
    URL.createObjectURL = vi.fn((f) => `blob:${f.name ?? 'saved'}`)
    URL.revokeObjectURL = vi.fn()
  })

  it('shows a camera button and adds each pick to the earlier ones, never replacing them', async () => {
    api.get.mockImplementation((url) => Promise.resolve({ data: { data: url === '/homework' ? [] : { assignments: [{ classroom: { id: 3, name: '5-A' }, subject: math }] } } }))
    api.post.mockResolvedValue({ data: { data: { ...hw, id: 8 } } })
    wrap(<HomeworkPage />)
    fireEvent.click(await screen.findByRole('button', { name: 'Send homework' }))
    expect(screen.getByRole('button', { name: /Add a photo/ })).toBeInTheDocument()
    await screen.findByRole('option', { name: '5-A · Math' })
    fireEvent.change(screen.getByLabelText('Section and subject'), { target: { value: '3-5' } })
    fireEvent.change(screen.getByLabelText('Title'), { target: { value: 'With pictures' } })
    fireEvent.change(screen.getByLabelText('Due date'), { target: { value: '2026-11-02' } })
    fireEvent.change(screen.getByLabelText('Picture'), { target: { files: [photo('1.jpg')] } })
    fireEvent.change(screen.getByLabelText('Picture'), { target: { files: [photo('2.jpg'), photo('3.jpg')] } })
    expect(screen.getByText('3 of 10 photos')).toBeInTheDocument()
    fireEvent.click(screen.getByRole('button', { name: 'Remove page 2' }))
    fireEvent.click(screen.getByRole('button', { name: 'Save' }))
    await waitFor(() => expect(api.post).toHaveBeenCalledWith('/homework/8/images', expect.any(FormData)))
    expect(api.post.mock.calls[1][1].getAll('images[]').map((f) => f.name)).toEqual(['1.jpg', '3.jpg'])
  })

  it('removes a saved photo of a homework while keeping the others', async () => {
    api.get.mockImplementation((url) => Promise.resolve(url.startsWith('/homework/files') ? { data: new Blob(['x']) } : { data: { data: url === '/homework' ? [{ ...hw, images: [11, 12] }] : { assignments: [{ classroom: { id: 3, name: '5-A' }, subject: math }] } } }))
    api.put.mockResolvedValue({ data: { data: hw } })
    api.delete.mockResolvedValue({})
    wrap(<HomeworkPage />)
    fireEvent.click(await screen.findByRole('button', { name: 'Edit' }))
    fireEvent.click(await screen.findByRole('button', { name: 'Remove page 1' }))
    fireEvent.click(screen.getByRole('button', { name: 'Save' }))
    await waitFor(() => expect(api.delete).toHaveBeenCalledWith('/homework/files/11'))
    expect(api.delete).not.toHaveBeenCalledWith('/homework/files/12')
  })

  it('shows the student all the pages of a homework', async () => {
    api.get.mockImplementation((url) => Promise.resolve(url.startsWith('/homework/files') ? { data: new Blob(['x']) } : { data: { data: [{ ...hw, images: [21, 22] }] } }))
    wrap(<StudentHomeworkPage />)
    expect(await screen.findByAltText('Page 1')).toBeInTheDocument()
    expect(await screen.findByAltText('Page 2')).toBeInTheDocument()
    expect(api.get).toHaveBeenCalledWith('/homework/files/21', { responseType: 'blob' })
  })

  it('lets the student add page 2 later without losing page 1', async () => {
    api.get.mockImplementation((url) => Promise.resolve(url.startsWith('/homework/files') ? { data: new Blob(['x']) } : { data: { data: [{ ...hw, submission: { id: 4, body: 'Page 1', images: [31], submitted_at: '2026-10-30T10:00:00+00:00', late: false } }] } }))
    api.post.mockResolvedValue({ data: { data: {} } })
    wrap(<StudentHomeworkPage />)
    fireEvent.click(await screen.findByRole('button', { name: 'Edit my answer' }))
    expect(await screen.findByRole('button', { name: 'Remove page 1' })).toBeInTheDocument()
    fireEvent.change(screen.getByLabelText('Picture'), { target: { files: [photo('p2.jpg')] } })
    fireEvent.click(screen.getByRole('button', { name: 'Send' }))
    await waitFor(() => expect(api.post).toHaveBeenCalledWith('/homework/1/submission', expect.any(FormData)))
    const sent = api.post.mock.calls[0][1]
    expect(sent.getAll('images[]').map((f) => f.name)).toEqual(['p2.jpg'])
    expect(sent.getAll('remove_file_ids[]')).toEqual([])
  })

  it('removes a chosen saved page of the answer', async () => {
    api.get.mockImplementation((url) => Promise.resolve(url.startsWith('/homework/files') ? { data: new Blob(['x']) } : { data: { data: [{ ...hw, submission: { id: 4, body: 'x', images: [31, 32], submitted_at: '2026-10-30T10:00:00+00:00', late: false } }] } }))
    api.post.mockResolvedValue({ data: { data: {} } })
    wrap(<StudentHomeworkPage />)
    fireEvent.click(await screen.findByRole('button', { name: 'Edit my answer' }))
    fireEvent.click(await screen.findByRole('button', { name: 'Remove page 2' }))
    fireEvent.click(screen.getByRole('button', { name: 'Send' }))
    await waitFor(() => expect(api.post).toHaveBeenCalled())
    expect(api.post.mock.calls[0][1].getAll('remove_file_ids[]')).toEqual(['32'])
  })

  it('lets the student send a first answer and shows the parent the status only', async () => {
    api.get.mockResolvedValue({ data: { data: [{ ...hw, submission: null }] } })
    api.post.mockResolvedValue({ data: { data: {} } })
    wrap(<StudentHomeworkPage />)
    expect(await screen.findByText('Not sent yet')).toBeInTheDocument()
    fireEvent.click(screen.getByRole('button', { name: 'Send my answer' }))
    fireEvent.change(screen.getByLabelText('Your answer'), { target: { value: 'Done' } })
    fireEvent.click(screen.getByRole('button', { name: 'Send' }))
    await waitFor(() => expect(api.post).toHaveBeenCalledWith('/homework/1/submission', expect.any(FormData)))
    expect(api.post.mock.calls[0][1].get('body')).toBe('Done')
  })

  it('shows the parent whether the child sent the answer, without a send button', async () => {
    api.get.mockResolvedValue({ data: { data: [{ ...hw, submission: { id: 4, body: 'x', images: [], submitted_at: '2026-10-30T10:00:00+00:00', late: false } }] } })
    wrap(<ChildHomeworkPage />, '/c/7', '/c/:id')
    expect(await screen.findByText('Sent 2026-10-30')).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: /answer/i })).not.toBeInTheDocument()
  })

  it('shows the teacher who answered and who did not, with the text and every page', async () => {
    api.get.mockImplementation((url) => {
      if (url === '/homework') return Promise.resolve({ data: { data: [hw] } })
      if (url === '/homework/1/submissions') return Promise.resolve({ data: { data: [
        { student: { id: 1, name_ar: 'سلمى', name_en: 'Salma' }, submission: { id: 6, body: 'My answer', images: [41, 42, 43], submitted_at: '2026-10-30T10:00:00+00:00', late: true } },
        { student: { id: 2, name_ar: 'ليث', name_en: 'Layth' }, submission: null },
      ] } })
      if (url.startsWith('/homework/files')) return Promise.resolve({ data: new Blob(['x']) })
      return Promise.resolve({ data: { data: { assignments: [] } } })
    })
    wrap(<HomeworkPage />)
    fireEvent.click(await screen.findByRole('button', { name: 'Answers' }))
    expect(await screen.findByText('1 of 2 students sent an answer.')).toBeInTheDocument()
    expect(screen.getByText('Not sent')).toBeInTheDocument()
    fireEvent.click(screen.getByRole('button', { name: 'Sent 2026-10-30 · late' }))
    expect(screen.getByText('My answer')).toBeInTheDocument()
    expect(await screen.findByAltText('Page 3')).toBeInTheDocument()
  })
})

describe('homework for students and parents', () => {
  it('shows the student their homework', async () => {
    api.get.mockResolvedValue({ data: { data: [hw] } })
    wrap(<StudentHomeworkPage />)
    expect(await screen.findByText('Page 10')).toBeInTheDocument()
    expect(api.get).toHaveBeenCalledWith('/homework/me')
    expect(screen.getByText('Due 2026-11-01 · Sara')).toBeInTheDocument()
  })

  it('shows the parent the homework of one child, in Arabic too', async () => {
    api.get.mockResolvedValue({ data: { data: [hw] } })
    await i18n.changeLanguage('ar')
    wrap(<ChildHomeworkPage />, '/c/7', '/c/:id')
    expect(await screen.findByText('رياضيات')).toBeInTheDocument()
    expect(api.get).toHaveBeenCalledWith('/students/7/homework')
    expect(screen.getByRole('heading')).toHaveTextContent('لينا')
  })

  it('says when there is none', async () => {
    api.get.mockResolvedValue({ data: { data: [] } })
    wrap(<StudentHomeworkPage />)
    expect(await screen.findByText('No homework.')).toBeInTheDocument()
  })
})
