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
const hw = { id: 1, title: 'Page 10', description: 'Solve 1-5', due_on: '2026-11-01', classroom: { id: 3, name: '5-A' }, subject: math, teacher: { id: 9, name: 'Sara' } }

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

describe('homework pictures', () => {
  it('uploads the chosen picture after saving', async () => {
    api.get.mockImplementation((url) => Promise.resolve({ data: { data: url === '/homework' ? [] : { assignments: [{ classroom: { id: 3, name: '5-A' }, subject: math }] } } }))
    api.post.mockResolvedValue({ data: { data: { ...hw, id: 8 } } })
    wrap(<HomeworkPage />)
    fireEvent.click(await screen.findByRole('button', { name: 'Send homework' }))
    await screen.findByRole('option', { name: '5-A · Math' })
    fireEvent.change(screen.getByLabelText('Section and subject'), { target: { value: '3-5' } })
    fireEvent.change(screen.getByLabelText('Title'), { target: { value: 'With picture' } })
    fireEvent.change(screen.getByLabelText('Due date'), { target: { value: '2026-11-02' } })
    const file = new File(['x'], 'page.jpg', { type: 'image/jpeg' })
    fireEvent.change(screen.getByLabelText('Picture'), { target: { files: [file] } })
    fireEvent.click(screen.getByRole('button', { name: 'Save' }))
    await waitFor(() => expect(api.post).toHaveBeenCalledWith('/homework/8/image', expect.any(FormData)))
  })

  it('shows the picture to the student', async () => {
    URL.createObjectURL = vi.fn(() => 'blob:pic')
    api.get.mockImplementation((url) => Promise.resolve({ data: url === '/homework/me' ? { data: [{ ...hw, has_image: true }] } : new Blob(['x']) }))
    wrap(<StudentHomeworkPage />)
    expect(await screen.findByAltText('Homework picture')).toHaveAttribute('src', 'blob:pic')
    expect(api.get).toHaveBeenCalledWith('/homework/1/image', { responseType: 'blob' })
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
