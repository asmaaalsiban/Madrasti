import { fireEvent, render, screen, waitFor } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { MemoryRouter } from 'react-router-dom'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import i18n from '../src/i18n'
import { api } from '../src/api/client'
import TasksPage from '../src/features/Tasks/TasksPage'
import { AttachmentList } from '../src/components/shared/Attachments'

vi.mock('../src/api/client', () => ({ api: { get: vi.fn(), post: vi.fn(), put: vi.fn(), delete: vi.fn() } }))
const auth = { user: { role: 'admin' } }
vi.mock('../src/features/Auth/AuthProvider', () => ({ useAuth: () => ({ user: auth.user }) }))

const wrap = (ui) => render(<QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}><MemoryRouter>{ui}</MemoryRouter></QueryClientProvider>)
const file = (name, type) => new File(['x'], name, { type })

beforeEach(async () => { vi.resetAllMocks(); auth.user = { role: 'admin' }; await i18n.changeLanguage('en') })

describe('attachments', () => {
  it('a new task uploads the chosen files after it is saved', async () => {
    api.get.mockImplementation(async (url) => (url === '/tasks/given' ? { data: { data: [] } } : { data: { data: [{ id: 8, name: 'Sara', staff_type: 'teacher' }] } }))
    api.post.mockImplementation(async (url) => ({ data: { data: { id: 21 } } }))
    wrap(<TasksPage />)
    fireEvent.click(await screen.findByRole('button', { name: 'New task' }))
    fireEvent.change(screen.getByLabelText('Task'), { target: { value: 'Prepare' } })
    fireEvent.click(await screen.findByLabelText(/Sara/))
    fireEvent.change(screen.getByLabelText('Choose files'), { target: { files: [file('plan.pdf', 'application/pdf')] } })
    expect(screen.getByText('plan.pdf')).toBeInTheDocument()
    fireEvent.click(screen.getByRole('button', { name: 'Save' }))
    await waitFor(() => expect(api.post).toHaveBeenCalledWith('/attachments/task/21', expect.any(FormData)))
    expect(api.post.mock.calls[1][1].getAll('files[]')[0].name).toBe('plan.pdf')
  })

  it('an assignee hands in a file at once from their task', async () => {
    auth.user = { role: 'teacher', staff_type: 'teacher' }
    const task = { id: 4, title: 'Prepare', description: null, due_on: null, priority: 'normal', created_by: { id: 1, name: 'Principal' }, can_edit: false, overdue: false, my_status: 'todo', my_note: null, my_part_id: 77, attachments: [{ id: 5, name: 'brief.pdf', mime: 'application/pdf', size: 2048, is_image: false }], done_count: 0, total: 1, assignees: [{ id: 77, user_id: 8, name: 'Sara', status: 'todo', note: null, attachments: [] }] }
    api.get.mockResolvedValue({ data: { data: [task], open: 1 } })
    api.post.mockResolvedValue({ data: { data: [] } })
    wrap(<TasksPage />)
    expect(await screen.findByText('brief.pdf')).toBeInTheDocument()
    fireEvent.change(screen.getByLabelText('Choose files'), { target: { files: [file('answer.docx', 'application/msword')] } })
    await waitFor(() => expect(api.post).toHaveBeenCalledWith('/attachments/task_part/77', expect.any(FormData)))
  })

  it('saved files show names and can be removed', async () => {
    const onRemove = vi.fn()
    render(<QueryClientProvider client={new QueryClient()}><AttachmentList items={[{ id: 9, name: 'sheet.xlsx', mime: 'x', size: 4096, is_image: false }]} onRemove={onRemove} /></QueryClientProvider>)
    expect(screen.getByText('sheet.xlsx')).toBeInTheDocument()
    fireEvent.click(screen.getByRole('button', { name: 'Remove sheet.xlsx' }))
    expect(onRemove).toHaveBeenCalledWith(expect.objectContaining({ id: 9 }))
  })
})
