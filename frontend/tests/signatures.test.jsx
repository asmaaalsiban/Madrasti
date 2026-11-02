import { fireEvent, render, screen, waitFor, within } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { MemoryRouter } from 'react-router-dom'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import i18n from '../src/i18n'
import { api } from '../src/api/client'
import SignaturesPage from '../src/features/Reports/pages/SignaturesPage'

vi.mock('../src/api/client', () => ({ api: { get: vi.fn(), post: vi.fn(), put: vi.fn(), delete: vi.fn() } }))

const wrap = (ui) => render(<QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}><MemoryRouter>{ui}</MemoryRouter></QueryClientProvider>)
const PNG = 'data:image/png;base64,iVBORw0KGgo='
const school = { id: 1, scope: 'school', title: 'Principal', signer_name: 'Mr. Khaled', strength: 50, is_active: true, user: null, image: PNG }
const mine = { id: 2, scope: 'user', title: null, signer_name: 'Sara', strength: 40, is_active: true, user: { id: 7, name: 'Sara' }, image: PNG }
const picture = () => new File(['x'], 'signature.png', { type: 'image/png' })

beforeEach(async () => {
  vi.resetAllMocks()
  await i18n.changeLanguage('en')
})

describe('SignaturesPage (admin)', () => {
  beforeEach(() => api.get.mockResolvedValue({ data: { data: { school: [school], personal: [mine], homeroom_teachers: [{ id: 7, name: 'Sara', classrooms: ['5-A'] }, { id: 8, name: 'Omar', classrooms: ['5-B', '6-A'] }] } } }))

  it('shows the school signatures and the teachers\' as they will print', async () => {
    wrap(<SignaturesPage admin />)
    expect(await screen.findByText('School signatures')).toBeInTheDocument()
    expect(screen.getByAltText('Signature Mr. Khaled')).toHaveAttribute('src', PNG)
    expect(screen.getByDisplayValue('Principal')).toBeInTheDocument()
    expect(screen.getByText('Homeroom teachers')).toBeInTheDocument()
  })

  it('adds a school signature from a picture, with a title and a name', async () => {
    api.post.mockResolvedValue({ data: { data: {} } })
    wrap(<SignaturesPage admin />)
    await screen.findByText('School signatures')
    const form = screen.getByText('Add a school signature').closest('form')
    expect(within(form).getByRole('button', { name: 'Save the signature' })).toBeDisabled()
    fireEvent.change(within(form).getByLabelText('What it is for'), { target: { value: 'Vice principal' } })
    fireEvent.change(within(form).getByLabelText('Name under the signature'), { target: { value: 'Mrs. Huda' } })
    fireEvent.change(within(form).getByLabelText('Signature picture'), { target: { files: [picture()] } })
    expect(within(form).getByText('✔ Picture: signature.png')).toBeInTheDocument()
    fireEvent.click(within(form).getByRole('button', { name: 'Save the signature' }))
    await waitFor(() => expect(api.post).toHaveBeenCalledTimes(1))
    const [url, body] = api.post.mock.calls[0]
    expect(url).toBe('/signatures')
    expect([body.get('scope'), body.get('title'), body.get('signer_name'), body.get('image').name]).toEqual(['school', 'Vice principal', 'Mrs. Huda', 'signature.png'])
  })

  it('uploads for a chosen homeroom teacher', async () => {
    api.post.mockResolvedValue({ data: { data: {} } })
    wrap(<SignaturesPage admin />)
    await screen.findByText('Homeroom teachers')
    expect(screen.queryByText("Upload this teacher's signature")).not.toBeInTheDocument()
    fireEvent.change(screen.getByLabelText('Add or replace the signature of'), { target: { value: '8' } })
    const form = screen.getByText("Upload this teacher's signature").closest('form')
    fireEvent.change(within(form).getByLabelText('Signature picture'), { target: { files: [picture()] } })
    fireEvent.click(within(form).getByRole('button', { name: 'Save the signature' }))
    await waitFor(() => expect(api.post).toHaveBeenCalled())
    expect(api.post.mock.calls[0][1].get('scope')).toBe('user')
    expect(api.post.mock.calls[0][1].get('user_id')).toBe('8')
  })

  it('cleans the paper more or less with the slider, without uploading again', async () => {
    api.post.mockResolvedValue({ data: { data: {} } })
    wrap(<SignaturesPage admin />)
    const card = (await screen.findByDisplayValue('Principal')).closest('li')
    const slider = within(card).getByLabelText('Remove the paper')
    fireEvent.change(slider, { target: { value: '80' } })
    expect(within(card).getByText('80')).toBeInTheDocument()
    fireEvent.pointerUp(slider)
    await waitFor(() => expect(api.post).toHaveBeenCalledWith('/signatures/1/reprocess', { strength: 80 }))
  })

  it('renames, hides and deletes a signature', async () => {
    api.put.mockResolvedValue({ data: { data: {} } })
    api.delete.mockResolvedValue({})
    wrap(<SignaturesPage admin />)
    const card = (await screen.findByDisplayValue('Principal')).closest('li')
    const title = within(card).getByLabelText('What it is for')
    fireEvent.change(title, { target: { value: 'Headmaster' } })
    fireEvent.blur(title)
    await waitFor(() => expect(api.put).toHaveBeenCalledWith('/signatures/1', { title: 'Headmaster' }))
    fireEvent.click(within(card).getByLabelText('Print it on the report cards'))
    await waitFor(() => expect(api.put).toHaveBeenCalledWith('/signatures/1', { is_active: false }))
    fireEvent.click(within(card).getByRole('button', { name: 'Delete' }))
    await waitFor(() => expect(api.delete).toHaveBeenCalledWith('/signatures/1'))
  })

  it('shows why a picture was refused', async () => {
    api.post.mockRejectedValue({ response: { data: { errors: { image: ['No signature was found in the picture.'] } } } })
    wrap(<SignaturesPage admin />)
    await screen.findByText('School signatures')
    const form = screen.getByText('Add a school signature').closest('form')
    fireEvent.change(within(form).getByLabelText('What it is for'), { target: { value: 'Principal' } })
    fireEvent.change(within(form).getByLabelText('Signature picture'), { target: { files: [picture()] } })
    fireEvent.click(within(form).getByRole('button', { name: 'Save the signature' }))
    expect(await within(form).findByRole('alert')).toHaveTextContent('No signature was found in the picture.')
  })
})

describe('SignaturesPage (teacher)', () => {
  it('lets a teacher manage only their own signature', async () => {
    api.get.mockResolvedValue({ data: { data: { school: [], personal: [mine], homeroom_teachers: [] } } })
    api.post.mockResolvedValue({ data: { data: {} } })
    wrap(<SignaturesPage />)
    expect(await screen.findByText('My signature')).toBeInTheDocument()
    expect(screen.queryByText('School signatures')).not.toBeInTheDocument()
    const form = screen.getByText('Replace my signature').closest('form')
    expect(within(form).queryByLabelText('What it is for')).not.toBeInTheDocument()
    fireEvent.change(within(form).getByLabelText('Signature picture'), { target: { files: [picture()] } })
    fireEvent.click(within(form).getByRole('button', { name: 'Save the signature' }))
    await waitFor(() => expect(api.post.mock.calls[0][1].get('scope')).toBe('user'))
    expect(api.post.mock.calls[0][1].has('user_id')).toBe(false)
  })

  it('offers "Upload my signature" when there is none yet', async () => {
    api.get.mockResolvedValue({ data: { data: { school: [], personal: [], homeroom_teachers: [] } } })
    wrap(<SignaturesPage />)
    expect(await screen.findByText('Upload my signature')).toBeInTheDocument()
  })
})
