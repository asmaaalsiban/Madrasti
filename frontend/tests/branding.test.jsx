import { fireEvent, render, screen, waitFor } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import i18n from '../src/i18n'
import { api } from '../src/api/client'
import BrandingPage from '../src/features/Branding/BrandingPage'
import { paletteFromFile } from '../src/tenant/palette'

vi.mock('../src/api/client', () => ({ api: { get: vi.fn(), post: vi.fn(), put: vi.fn(), delete: vi.fn() } }))
vi.mock('../src/tenant/useSchool', () => ({ useSchool: () => ({ data: { name: 'Al Muntalaq' } }) }))
vi.mock('../src/tenant/palette', async (orig) => ({ ...(await orig()), paletteFromFile: vi.fn() }))
globalThis.URL.createObjectURL = vi.fn(() => 'blob:preview')
globalThis.URL.revokeObjectURL = vi.fn()

const wrap = () => render(<QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}><BrandingPage /></QueryClientProvider>)
const png = (size = 100) => new File([new Uint8Array(size)], 'logo.png', { type: 'image/png' })

beforeEach(async () => {
  vi.resetAllMocks()
  globalThis.URL.createObjectURL = vi.fn(() => 'blob:preview')
  await i18n.changeLanguage('en')
  api.get.mockResolvedValue({ data: { data: { logo_url: null, primary_color: null, dark_color: null } } })
})

describe('BrandingPage', () => {
  it('reads the colours of the chosen logo, lets the principal pick the main one and saves both colours with the file', async () => {
    paletteFromFile.mockResolvedValue({ primary: '#e0457b', dark: '#12285a', swatches: ['#e0457b', '#2a6fdb'] })
    api.post.mockResolvedValue({ data: { data: {} } })
    wrap()
    fireEvent.change(screen.getByLabelText('Choose a logo'), { target: { files: [png()] } })
    expect(await screen.findByRole('button', { name: '#2a6fdb' })).toBeInTheDocument()
    fireEvent.click(screen.getByRole('button', { name: '#2a6fdb' }))
    fireEvent.click(screen.getByRole('button', { name: 'Save' }))

    await waitFor(() => expect(api.post).toHaveBeenCalledWith('/branding', expect.any(FormData)))
    const body = api.post.mock.calls[0][1]
    expect(body.get('primary_color')).toBe('#2a6fdb')
    expect(body.get('dark_color')).toBe('#12285a')
    expect(body.get('logo').name).toBe('logo.png')
    expect(await screen.findByRole('status')).toHaveTextContent('saved')
  })

  it('refuses a wrong type or a big file before sending anything', async () => {
    wrap()
    fireEvent.change(screen.getByLabelText('Choose a logo'), { target: { files: [new File(['x'], 'a.svg', { type: 'image/svg+xml' })] } })
    expect(await screen.findByRole('alert')).toHaveTextContent('not supported')
    fireEvent.change(screen.getByLabelText('Choose a logo'), { target: { files: [png(3 * 1024 * 1024)] } })
    expect(await screen.findByText('The image is bigger than 2 MB.')).toBeInTheDocument()
    expect(api.post).not.toHaveBeenCalled()
  })

  it('says so when the logo has no colours, and still lets the logo be saved', async () => {
    paletteFromFile.mockResolvedValue(null)
    wrap()
    fireEvent.change(screen.getByLabelText('Choose a logo'), { target: { files: [png()] } })
    expect(await screen.findByRole('alert')).toHaveTextContent('no colours')
    expect(screen.getByRole('button', { name: 'Save' })).toBeEnabled()
  })

  it('goes back to the original look after a confirmation', async () => {
    api.get.mockResolvedValue({ data: { data: { logo_url: '/api/school/logo?v=1', primary_color: '#e0457b', dark_color: '#12285a' } } })
    api.delete.mockResolvedValue({})
    wrap()
    fireEvent.click(await screen.findByRole('button', { name: 'Back to the original look' }))
    fireEvent.click(await screen.findAllByRole('button', { name: 'Back to the original look' }).then((b) => b[b.length - 1]))
    await waitFor(() => expect(api.delete).toHaveBeenCalledWith('/branding'))
  })
})
