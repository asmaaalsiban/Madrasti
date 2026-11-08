import { fireEvent, render, screen } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { useState } from 'react'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import i18n from '../src/i18n'
import { api } from '../src/api/client'
import TeacherSubjectField from '../src/features/People/components/TeacherSubjectField'

vi.mock('../src/api/client', () => ({ api: { get: vi.fn() } }))

function Harness({ start }) {
  const [form, setForm] = useState(start)
  return <><TeacherSubjectField form={form} setForm={setForm} /><output data-testid="out">{JSON.stringify({ s: form.subject_id, c: form.is_class_teacher })}</output></>
}
const wrap = (start) => render(<QueryClientProvider client={new QueryClient()}><Harness start={start} /></QueryClientProvider>)

beforeEach(async () => {
  vi.resetAllMocks()
  await i18n.changeLanguage('en')
  api.get.mockResolvedValue({ data: { data: [{ id: 3, name_en: 'Math', name_ar: 'ر' }, { id: 4, name_en: 'English', name_ar: 'ا' }] } })
})

describe('TeacherSubjectField', () => {
  it('lets the admin choose "Class teacher" instead of a subject and back', async () => {
    wrap({ subject_id: 3, is_class_teacher: false })
    await screen.findByRole('option', { name: 'English' })
    const select = screen.getByLabelText('Subject')
    expect(select).toHaveValue('3')
    fireEvent.change(select, { target: { value: 'class' } })
    expect(JSON.parse(screen.getByTestId('out').textContent)).toEqual({ s: '', c: true })
    expect(screen.getByText(/Teaches all the subjects of their own section/)).toBeInTheDocument()
    fireEvent.change(select, { target: { value: '4' } })
    expect(JSON.parse(screen.getByTestId('out').textContent)).toEqual({ s: '4', c: false })
  })
})
