import { fireEvent, render, screen, waitFor } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { MemoryRouter } from 'react-router-dom'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import i18n from '../src/i18n'
import { api } from '../src/api/client'
import TasksPage from '../src/features/Tasks/TasksPage'

vi.mock('../src/api/client', () => ({ api: { get: vi.fn(), post: vi.fn(), put: vi.fn(), delete: vi.fn() } }))
const auth = { user: { role: 'teacher', staff_type: 'teacher' } }
vi.mock('../src/features/Auth/AuthProvider', () => ({ useAuth: () => ({ user: auth.user }) }))

const wrap = (ui) => render(<QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}><MemoryRouter>{ui}</MemoryRouter></QueryClientProvider>)

const task = { id: 4, title: 'Prepare the exam', description: 'Math, grade 5', due_on: '2026-11-20', priority: 'high', created_by: { id: 1, name: 'Principal' }, can_edit: true, overdue: false, my_status: 'todo', my_note: null, done_count: 1, total: 2, assignees: [{ user_id: 8, name: 'Sara', status: 'done', note: 'Done early' }, { user_id: 9, name: 'Omar', status: 'todo', note: null }] }

beforeEach(async () => { vi.resetAllMocks(); auth.user = { role: 'teacher', staff_type: 'teacher' }; await i18n.changeLanguage('en') })

describe('tasks', () => {
  it('a teacher sees their tasks and moves one to done with a note', async () => {
    api.get.mockResolvedValue({ data: { data: [task], open: 1 } })
    api.put.mockResolvedValue({ data: {} })
    wrap(<TasksPage />)
    expect(await screen.findByText('Prepare the exam')).toBeInTheDocument()
    expect(screen.getByText('From Principal')).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'New task' })).not.toBeInTheDocument()
    fireEvent.change(screen.getByLabelText('Note (optional)'), { target: { value: 'Finished' } })
    fireEvent.click(screen.getByRole('button', { name: 'Done' }))
    await waitFor(() => expect(api.put).toHaveBeenCalledWith('/tasks/4/status', { status: 'done', note: 'Finished' }))
  })

  it('a head of department gives a task to chosen teachers and follows progress', async () => {
    auth.user = { role: 'teacher', staff_type: 'head_of_department' }
    api.get.mockImplementation(async (url) => {
      if (url === '/tasks/mine') return { data: { data: [], open: 0 } }
      if (url === '/tasks/given') return { data: { data: [task] } }
      return { data: { data: [{ id: 8, name: 'Sara', staff_type: 'teacher' }, { id: 9, name: 'Omar', staff_type: 'accountant' }] } }
    })
    api.post.mockResolvedValue({ data: {} })
    wrap(<TasksPage />)
    fireEvent.click(await screen.findByRole('tab', { name: 'Tasks I gave' }))
    expect(await screen.findByText('1 of 2 done')).toBeInTheDocument()
    expect(screen.getByText(/Sara/)).toBeInTheDocument()

    fireEvent.click(screen.getByRole('button', { name: 'New task' }))
    fireEvent.change(screen.getByLabelText('Task'), { target: { value: 'Marking' } })
    fireEvent.click(await screen.findByLabelText(/Omar/))
    fireEvent.click(screen.getByRole('button', { name: 'Save' }))
    await waitFor(() => expect(api.post).toHaveBeenCalledWith('/tasks', expect.objectContaining({ title: 'Marking', user_ids: [9], priority: 'normal' })))
  })

  it('management only has the given tab and can select everybody', async () => {
    auth.user = { role: 'admin' }
    api.get.mockImplementation(async (url) => (url === '/tasks/given' ? { data: { data: [] } } : { data: { data: [{ id: 8, name: 'Sara', staff_type: 'teacher' }, { id: 9, name: 'Omar', staff_type: 'accountant' }] } }))
    wrap(<TasksPage />)
    expect(await screen.findByText('You have not given any task yet.')).toBeInTheDocument()
    expect(screen.queryByRole('tab', { name: 'My tasks' })).not.toBeInTheDocument()
    fireEvent.click(screen.getByRole('button', { name: 'New task' }))
    await screen.findByLabelText(/Omar/)
    fireEvent.click(screen.getByRole('button', { name: 'Select all' }))
    expect(screen.getByRole('button', { name: 'Clear' })).toBeInTheDocument()
  })

  it('the people list can be searched and filtered by job, and select all only takes who is shown', async () => {
    auth.user = { role: 'admin' }
    api.get.mockImplementation(async (url) => (url === '/tasks/given' ? { data: { data: [] } } : { data: { data: [{ id: 8, name: 'Sara', staff_type: 'teacher' }, { id: 9, name: 'Omar', staff_type: 'accountant' }, { id: 10, name: 'Salem', staff_type: 'teacher' }] } }))
    api.post.mockResolvedValue({ data: {} })
    wrap(<TasksPage />)
    fireEvent.click(await screen.findByRole('button', { name: 'New task' }))
    await screen.findByLabelText(/Omar/)
    fireEvent.change(screen.getByLabelText('Filter by job'), { target: { value: 'teacher' } })
    expect(screen.queryByLabelText(/Omar/)).not.toBeInTheDocument()
    fireEvent.change(screen.getByLabelText('Search'), { target: { value: 'sar' } })
    expect(screen.queryByLabelText(/Salem/)).not.toBeInTheDocument()
    expect(screen.getByLabelText(/Sara/)).toBeInTheDocument()
    fireEvent.click(screen.getByRole('button', { name: 'Select all' }))
    fireEvent.change(screen.getByLabelText('Task'), { target: { value: 'Report' } })
    fireEvent.click(screen.getByRole('button', { name: 'Save' }))
    await waitFor(() => expect(api.post).toHaveBeenCalledWith('/tasks', expect.objectContaining({ user_ids: [8] })))
  })
})
