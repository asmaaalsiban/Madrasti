import { fireEvent, render, screen, waitFor } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { MemoryRouter } from 'react-router-dom'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import i18n from '../src/i18n'
import { api } from '../src/api/client'
import StaffAccessPage from '../src/features/StaffAccess/StaffAccessPage'
import { featureOfPath, isHidden } from '../src/features/StaffAccess/staffFeatures'

vi.mock('../src/api/client', () => ({ api: { get: vi.fn(), put: vi.fn() } }))

const wrap = (ui) => render(<QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}><MemoryRouter>{ui}</MemoryRouter></QueryClientProvider>)
const staff = { data: { features: [], data: [{ id: 1, name: 'Sara', staff_type: 'teacher', hidden: ['grades'] }, { id: 2, name: 'Omar', staff_type: 'accountant', hidden: [] }] } }

beforeEach(async () => { vi.resetAllMocks(); await i18n.changeLanguage('en') })

describe('staff access', () => {
  it('matches addresses to features, the longest path first', () => {
    expect(featureOfPath('/teacher/attendance')).toBe('attendance')
    expect(featureOfPath('/teacher/attendance/report')).toBe('attendance_report')
    expect(featureOfPath('/teacher/lesson-plans/review')).toBe('lesson_plan_review')
    expect(featureOfPath('/teacher/finance/accounts')).toBe('finance')
    expect(featureOfPath('/teacher/my-surveys')).toBe('surveys')
    expect(featureOfPath('/teacher')).toBeNull()
  })

  it('hides only from staff and only what was switched off', () => {
    const teacher = { role: 'teacher', hidden_features: ['grades', 'chat'] }
    expect(isHidden(teacher, '/teacher/grades')).toBe(true)
    expect(isHidden(teacher, '/teacher/chat')).toBe(true)
    expect(isHidden(teacher, '/teacher/homework')).toBe(false)
    expect(isHidden({ role: 'parent', hidden_features: ['grades'] }, '/parent/grades')).toBe(false)
    expect(isHidden({ role: 'teacher' }, '/teacher/grades')).toBe(false)
  })

  it('the principal unticks a screen for one person and saves', async () => {
    api.get.mockResolvedValue(staff)
    api.put.mockResolvedValue({ data: { updated: 1 } })
    wrap(<StaffAccessPage />)
    expect(await screen.findByText('1 screen hidden')).toBeInTheDocument()
    fireEvent.click(screen.getAllByRole('button', { name: 'Edit' })[1])
    expect(screen.getByRole('dialog', { name: 'What Omar sees' })).toBeInTheDocument()
    fireEvent.click(screen.getByLabelText('Purchase requests'))
    fireEvent.click(screen.getByLabelText('Chat'))
    fireEvent.click(screen.getByRole('button', { name: 'Save' }))
    await waitFor(() => expect(api.put).toHaveBeenCalledWith('/staff-visibility', { user_ids: [2], hidden: ['procurement', 'chat'].sort(() => 0) }))
  })

  it('several people can be set at once, and the job filter narrows the list', async () => {
    api.get.mockResolvedValue(staff)
    api.put.mockResolvedValue({ data: { updated: 2 } })
    wrap(<StaffAccessPage />)
    await screen.findByText('Sara')
    fireEvent.change(screen.getByLabelText('Filter by job'), { target: { value: 'accountant' } })
    expect(screen.queryByText('Sara')).not.toBeInTheDocument()
    fireEvent.change(screen.getByLabelText('Filter by job'), { target: { value: '' } })
    fireEvent.click(screen.getByLabelText('Select all'))
    fireEvent.click(screen.getByRole('button', { name: 'Edit selected (2)' }))
    expect(screen.getByText('This replaces what these people had before.')).toBeInTheDocument()
    fireEvent.click(screen.getByRole('button', { name: 'Hide all' }))
    fireEvent.click(screen.getByLabelText('Tasks'))
    fireEvent.click(screen.getByRole('button', { name: 'Save' }))
    await waitFor(() => expect(api.put).toHaveBeenCalled())
    const body = api.put.mock.calls[0][1]
    expect(body.user_ids).toEqual([1, 2])
    expect(body.hidden).not.toContain('tasks')
    expect(body.hidden).toContain('grades')
  })
})
