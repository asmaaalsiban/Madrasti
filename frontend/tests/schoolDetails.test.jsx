import { fireEvent, render, screen, waitFor } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import i18n from '../src/i18n'
import { api } from '../src/api/client'
import SchoolDetailsCard from '../src/features/Branding/SchoolDetailsCard'

vi.mock('../src/api/client', () => ({ api: { get: vi.fn(), put: vi.fn() } }))

beforeEach(async () => {
  vi.resetAllMocks()
  await i18n.changeLanguage('en')
  api.get.mockResolvedValue({ data: { data: { directorate: '', district: '' } } })
  api.put.mockResolvedValue({ data: { data: {} } })
})

describe('SchoolDetailsCard', () => {
  it('saves the directorate and the area', async () => {
    render(<QueryClientProvider client={new QueryClient()}><SchoolDetailsCard /></QueryClientProvider>)
    fireEvent.change(await screen.findByLabelText('Directorate of Education'), { target: { value: 'Ain Al-Basha' } })
    fireEvent.change(screen.getByLabelText('Area'), { target: { value: 'Umm Al-Dananeer' } })
    fireEvent.click(screen.getByRole('button', { name: 'Save' }))
    await waitFor(() => expect(api.put).toHaveBeenCalledWith('/school-details', { directorate: 'Ain Al-Basha', district: 'Umm Al-Dananeer' }))
    expect(await screen.findByRole('status')).toHaveTextContent('Saved')
  })
})
