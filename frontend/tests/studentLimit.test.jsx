import { fireEvent, render, screen, waitFor, within } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { MemoryRouter } from 'react-router-dom'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import i18n from '../src/i18n'
import { api } from '../src/api/client'
import StudentsPage from '../src/features/People/pages/StudentsPage'
import Modal from '../src/components/shared/Modal'

vi.mock('../src/api/client', () => ({ api: { get: vi.fn(), post: vi.fn(), put: vi.fn(), delete: vi.fn() } }))

const renderPage = () => render(<QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}><MemoryRouter><StudentsPage /></MemoryRouter></QueryClientProvider>)

async function openFormAndSave() {
  renderPage()
  fireEvent.click(await screen.findByRole('button', { name: 'Add' }))
  const dialog = await screen.findByRole('dialog')
  fireEvent.change(within(dialog).getByLabelText('Student number'), { target: { value: 'S-9' } })
  fireEvent.change(within(dialog).getByLabelText('Name (Arabic)'), { target: { value: 'طالب' } })
  fireEvent.click(within(dialog).getByRole('button', { name: 'Save' }))
  return dialog
}

beforeEach(async () => {
  vi.resetAllMocks()
  await i18n.changeLanguage('en')
  api.get.mockResolvedValue({ data: { data: [], meta: { current_page: 1, last_page: 1 } } })
})

describe('adding a student at the school limit', () => {
  it('shows a friendly notice with the server message, not the generic error', async () => {
    const message = "You have reached your school's limit of 138 students. To add more students, please contact the platform owner."
    api.post.mockRejectedValue({ response: { status: 422, data: { message, code: 'student_limit_reached', limit: 138, current: 138, allowed: 0 } } })
    const dialog = await openFormAndSave()
    expect(await within(dialog).findByText('Student limit reached')).toBeInTheDocument()
    expect(within(dialog).getByText(message)).toBeInTheDocument()
    expect(within(dialog).queryByText(/Something went wrong/i)).not.toBeInTheDocument()
  })

  it('still shows the generic error for a real failure (500)', async () => {
    api.post.mockRejectedValue({ response: { status: 500, data: { message: 'Server Error' } } })
    const dialog = await openFormAndSave()
    await waitFor(() => expect(within(dialog).getByRole('alert')).toBeInTheDocument())
    expect(within(dialog).queryByText('Server Error')).not.toBeInTheDocument()
    expect(within(dialog).queryByText('Student limit reached')).not.toBeInTheDocument()
  })
})

describe('Modal', () => {
  it('locks the page scroll while open and gives it back on close', () => {
    const { rerender } = render(<Modal open title="T" onClose={() => {}}>x</Modal>)
    expect(document.body.style.overflow).toBe('hidden')
    rerender(<Modal open={false} title="T" onClose={() => {}}>x</Modal>)
    expect(document.body.style.overflow).not.toBe('hidden')
  })

  it('does not scroll inside the dialog on a big screen (the page behind the layer scrolls)', () => {
    render(<Modal open title="T" onClose={() => {}}>x</Modal>)
    const dialog = screen.getByRole('dialog')
    expect(dialog.className).toContain('sm:overflow-visible')
    expect(dialog.className).toContain('sm:max-h-none')
  })
})
