import { render, screen } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { MemoryRouter } from 'react-router-dom'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import i18n from '../src/i18n'
import { api } from '../src/api/client'
import SubjectsPage from '../src/features/Academic/pages/SubjectsPage'

vi.mock('../src/api/client', () => ({ api: { get: vi.fn(), post: vi.fn(), put: vi.fn(), delete: vi.fn() } }))

const at = (url) => render(<QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}><MemoryRouter initialEntries={[url]}><SubjectsPage /></MemoryRouter></QueryClientProvider>)

beforeEach(async () => { vi.resetAllMocks(); await i18n.changeLanguage('en'); api.get.mockResolvedValue({ data: { data: [], meta: { current_page: 1, last_page: 1 } } }) })

describe('the "+" of the overview', () => {
  it('opens the add form at once with ?new=1', async () => {
    at('/admin/subjects?new=1')
    expect(await screen.findByRole('dialog')).toBeInTheDocument()
  })
  it('opens nothing without it', async () => {
    at('/admin/subjects')
    await screen.findByRole('button', { name: 'Add' })
    expect(screen.queryByRole('dialog')).not.toBeInTheDocument()
  })
})
