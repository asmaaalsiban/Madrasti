import { fireEvent, render, screen, waitFor } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import i18n from '../src/i18n'
import { api } from '../src/api/client'
import ClassTeacherGrades from '../src/features/Academic/components/ClassTeacherGrades'

vi.mock('../src/api/client', () => ({ api: { put: vi.fn() } }))

const grades = [{ id: 1, name_en: 'Grade 1', name_ar: 'أول', class_teacher_grade: false }, { id: 5, name_en: 'Grade 5', name_ar: 'خامس', class_teacher_grade: false }]
const subjects = [{ id: 10, name_en: 'Arabic', name_ar: 'ع' }, { id: 11, name_en: 'English', name_ar: 'ا' }, { id: 12, name_en: 'Computer', name_ar: 'ح' }]
const cells = [10, 11, 12].map((s) => ({ grade_level_id: 1, subject_id: s, weekly_periods: 3, specialist: false }))
const wrap = (onSaved = vi.fn()) => render(<QueryClientProvider client={new QueryClient()}><ClassTeacherGrades grades={grades} subjects={subjects} cells={cells} onSaved={onSaved} /></QueryClientProvider>)

beforeEach(async () => {
  vi.resetAllMocks()
  await i18n.changeLanguage('en')
  api.put.mockResolvedValue({ data: {} })
})

describe('ClassTeacherGrades', () => {
  it('switches a grade on and keeps English and Computer for specialist teachers', async () => {
    const onSaved = vi.fn()
    wrap(onSaved)
    fireEvent.click(screen.getByLabelText('Class-teacher grade Grade 1'))
    fireEvent.click(screen.getByLabelText('English specialist'))
    fireEvent.click(screen.getByLabelText('Computer specialist'))
    fireEvent.click(screen.getByRole('button', { name: 'Save Grade 1' }))
    await waitFor(() => expect(api.put).toHaveBeenCalledWith('/curriculum/class-teachers', { grade_level_id: 1, class_teacher_grade: true, specialist_subject_ids: [11, 12] }))
    await waitFor(() => expect(onSaved).toHaveBeenCalled())
  })

  it('shows the subjects only for a grade that is switched on', () => {
    wrap()
    expect(screen.queryByLabelText('English specialist')).toBeNull()
  })
})
