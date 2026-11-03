import { fireEvent, render, screen, waitFor } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { MemoryRouter } from 'react-router-dom'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import i18n from '../src/i18n'
import { api } from '../src/api/client'
import DutiesAdminPage from '../src/features/Duties/DutiesAdminPage'
import MyDutiesPage from '../src/features/Duties/MyDutiesPage'

vi.mock('../src/api/client', () => ({ api: { get: vi.fn(), post: vi.fn(), put: vi.fn(), delete: vi.fn() } }))
const wrap = (ui) => render(<QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}><MemoryRouter>{ui}</MemoryRouter></QueryClientProvider>)

const post = { id: 1, name: 'Morning gate', starts_at: '07:15', ends_at: '07:40', staff_count: 2, days: null, is_active: true }
const duty = (id, date, extra = {}) => ({ id, date, starts_at: '07:15', ends_at: '07:40', location: 'Morning gate', post_id: 1, is_locked: false, user: { id: 9, name: 'Sara Ali' }, ...extra })

beforeEach(async () => {
  vi.resetAllMocks()
  await i18n.changeLanguage('en')
})

describe('DutiesAdminPage', () => {
  beforeEach(() => {
    api.get.mockImplementation((url) => Promise.resolve({ data: url === '/duties/posts' ? { data: [post] } : url === '/payroll/staff' ? { data: [{ id: 9, name: 'Sara Ali', staff_type: 'teacher' }, { id: 10, name: 'Omar', staff_type: 'accountant' }] } : { from: '2027-01-01', to: '2027-01-31', data: [duty(1, '2027-01-03'), duty(2, '2027-01-04', { is_locked: true })] } }))
  })

  it('lists the posts and the roster by day', async () => {
    wrap(<DutiesAdminPage />)
    expect(await screen.findByText('2027-01-03')).toBeInTheDocument()
    expect(screen.getByText('2027-01-04')).toBeInTheDocument()
    expect(screen.getByText('set by hand')).toBeInTheDocument()
    expect(screen.getAllByText('Morning gate').length).toBeGreaterThan(2)
  })

  it('adds a post with its days', async () => {
    api.post.mockResolvedValue({ data: {} })
    wrap(<DutiesAdminPage />)
    fireEvent.click(await screen.findByRole('button', { name: 'New post' }))
    fireEvent.change(await screen.findByLabelText('Name'), { target: { value: 'Yard at break' } })
    fireEvent.click(screen.getByText('Sun'))
    fireEvent.click(screen.getByText('Tue'))
    fireEvent.click(screen.getByRole('button', { name: 'Save' }))
    await waitFor(() => expect(api.post).toHaveBeenCalledWith('/duties/posts', expect.objectContaining({ name: 'Yard at break', staff_count: 1, days: [0, 2] })))
  })

  it('previews then publishes the roster of a period with the days off', async () => {
    api.post.mockImplementation((url, body) => Promise.resolve({ data: { data: { dry_run: body.dry_run, duties: 30, days: 10, posts: 2, warnings: ['Morning gate on 2027-01-05 has 1 of 2 people'] } } }))
    wrap(<DutiesAdminPage />)
    await screen.findByText('2027-01-03')
    fireEvent.change(screen.getAllByLabelText('From date')[0], { target: { value: '2027-01-03' } })
    fireEvent.change(screen.getAllByLabelText('To date')[0], { target: { value: '2027-01-14' } })
    fireEvent.change(screen.getByLabelText('Days off'), { target: { value: '2027-01-06' } })
    fireEvent.click(screen.getByRole('button', { name: 'Add day off' }))
    fireEvent.click(screen.getByRole('button', { name: 'Preview' }))
    await waitFor(() => expect(api.post).toHaveBeenCalledWith('/duties/generate', { from: '2027-01-03', to: '2027-01-14', exclude_dates: ['2027-01-06'], post_ids: null, dry_run: true }))
    expect(await screen.findByText('Preview: 30 duties over 10 days (nothing saved yet).')).toBeInTheDocument()
    expect(screen.getByText(/has 1 of 2 people/)).toBeInTheDocument()
    fireEvent.click(screen.getByRole('button', { name: 'Publish' }))
    await waitFor(() => expect(api.post).toHaveBeenLastCalledWith('/duties/generate', expect.objectContaining({ dry_run: false })))
  })

  it('changes who is on duty by hand and only offers teachers', async () => {
    api.put.mockResolvedValue({ data: {} })
    wrap(<DutiesAdminPage />)
    fireEvent.click((await screen.findAllByRole('button', { name: 'Edit' })).at(-1)) // the second duty
    const person = await screen.findByLabelText('Person')
    await waitFor(() => expect(screen.getByRole('option', { name: 'Sara Ali' })).toBeInTheDocument())
    expect(screen.queryByRole('option', { name: 'Omar' })).not.toBeInTheDocument()
    fireEvent.change(person, { target: { value: '9' } })
    fireEvent.click(screen.getByRole('button', { name: 'Save' }))
    await waitFor(() => expect(api.put).toHaveBeenCalledWith('/duties/2', { user_id: 9, date: '2027-01-04', starts_at: '07:15', ends_at: '07:40' }))
  })
})

describe('MyDutiesPage', () => {
  it('shows what is coming and the last month', async () => {
    api.get.mockResolvedValue({ data: { data: { upcoming: [duty(1, '2027-01-05'), duty(2, '2027-01-06', { location: 'Yard at break' })], past: [duty(3, '2026-12-30')], total: 7 } } })
    wrap(<MyDutiesPage />)
    expect(await screen.findByText('Coming up')).toBeInTheDocument()
    expect(screen.getByText('Yard at break')).toBeInTheDocument()
    expect(screen.getByText('Last 30 days')).toBeInTheDocument()
    expect(screen.getByText('7 duties in total.')).toBeInTheDocument()
  })

  it('says so when nothing is coming up', async () => {
    api.get.mockResolvedValue({ data: { data: { upcoming: [], past: [], total: 0 } } })
    wrap(<MyDutiesPage />)
    expect(await screen.findByText('You have no duties coming up.')).toBeInTheDocument()
  })
})
