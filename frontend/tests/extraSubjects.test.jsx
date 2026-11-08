import { fireEvent, render, screen } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { useState } from 'react'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import i18n from '../src/i18n'
import { api } from '../src/api/client'
import ExtraSubjects from '../src/features/People/components/ExtraSubjects'

vi.mock('../src/api/client', () => ({ api: { get: vi.fn() } }))

function Harness({ start = [] }) {
  const [rows, setRows] = useState(start)
  return <><ExtraSubjects value={rows} onChange={setRows} /><output data-testid="out">{JSON.stringify(rows)}</output></>
}
const wrap = (start) => render(<QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}><Harness start={start} /></QueryClientProvider>)

beforeEach(async () => {
  vi.resetAllMocks()
  await i18n.changeLanguage('en')
  api.get.mockImplementation(async (url) => (url === '/subjects' ? { data: { data: [{ id: 1, name_en: 'Math', name_ar: 'ر' }, { id: 2, name_en: 'Science', name_ar: 'ع' }] } } : { data: { data: [{ id: 5, name_en: 'Grade 5', name_ar: '5' }, { id: 9, name_en: 'Grade 9', name_ar: '9' }] } }))
})

describe('ExtraSubjects', () => {
  it('adds a subject with its own grades and removes it again', async () => {
    wrap()
    fireEvent.click(screen.getByRole('button', { name: /Add a subject/ }))
    await screen.findByRole('option', { name: 'Science' })
    fireEvent.change(screen.getByLabelText('Subject'), { target: { value: '2' } })
    await screen.findAllByRole('option', { name: 'Grade 9' })
    fireEvent.change(screen.getByLabelText('Teaches from grade'), { target: { value: '5' } })
    fireEvent.change(screen.getByLabelText('Teaches to grade'), { target: { value: '9' } })
    expect(JSON.parse(screen.getByTestId('out').textContent)).toEqual([{ subject_id: 2, grade_from_id: 5, grade_to_id: 9 }])
    fireEvent.click(screen.getByRole('button', { name: 'Delete' }))
    expect(JSON.parse(screen.getByTestId('out').textContent)).toEqual([])
  })
})
