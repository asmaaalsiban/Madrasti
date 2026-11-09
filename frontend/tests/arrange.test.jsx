import { fireEvent, render, screen, waitFor, within } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { MemoryRouter } from 'react-router-dom'
import { Star } from 'lucide-react'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import i18n from '../src/i18n'
import { api } from '../src/api/client'
import SectionTiles from '../src/components/shared/SectionTiles'
import AdminLayout from '../src/layouts/AdminLayout'
import { orderBy, swap } from '../src/features/Layout/arrange'

vi.mock('../src/api/client', () => ({ api: { get: vi.fn(() => Promise.resolve({ data: { data: { notifications: 0, announcements: 0, chat: 0, surveys: 0 } } })), put: vi.fn(() => Promise.resolve({ data: { data: {} } })) } }))
const auth = { user: { role: 'parent', name: 'P', layout: {} }, updateUser: vi.fn() }
vi.mock('../src/features/Auth/AuthProvider', () => ({ useAuth: () => ({ user: auth.user, updateUser: auth.updateUser, logout: () => {} }) }))
vi.mock('../src/tenant/useSchool', () => ({ useSchool: () => ({ data: { name: 'School' } }) }))

const wrap = (ui) => render(<QueryClientProvider client={new QueryClient()}><MemoryRouter>{ui}</MemoryRouter></QueryClientProvider>)
const tiles = [{ key: 'grades', to: '/p/grades', label: 'Grades', icon: Star }, { key: 'fees', to: '/p/fees', label: 'Fees', icon: Star }, { key: 'homework', to: '/p/homework', label: 'Homework', icon: Star }]
const labels = () => screen.getAllByRole('link').map((a) => a.textContent)

beforeEach(async () => { vi.clearAllMocks(); auth.user = { role: 'parent', name: 'P', layout: {} }; await i18n.changeLanguage('en') })

describe('arranging', () => {
  it('orders by the keys a person chose and keeps new items after them', () => {
    expect(orderBy([{ k: 'a' }, { k: 'b' }, { k: 'c' }], (x) => x.k, ['c', 'a']).map((x) => x.k)).toEqual(['c', 'a', 'b'])
    expect(orderBy([{ k: 'a' }, { k: 'b' }], (x) => x.k, []).map((x) => x.k)).toEqual(['a', 'b'])
    expect(swap(['a', 'b', 'c'], 'c', -1)).toEqual(['a', 'c', 'b'])
    expect(swap(['a', 'b', 'c'], 'a', -1)).toEqual(['a', 'b', 'c'])
  })

  it('shows the tiles in the saved order', () => {
    auth.user = { role: 'parent', name: 'P', layout: { parent_home: { groups: [], items: ['homework', 'grades'] } } }
    wrap(<SectionTiles tiles={tiles} surface="parent_home" />)
    expect(labels()).toEqual(['Homework', 'Grades', 'Fees'])
  })

  it('moves a tile earlier in arrange mode, saves it on the account and goes back to the default', async () => {
    wrap(<SectionTiles tiles={tiles} surface="parent_home" />)
    fireEvent.click(screen.getByRole('button', { name: 'Arrange' }))
    expect(screen.queryAllByRole('link')).toHaveLength(0) // tiles do not open while arranging
    fireEvent.click(screen.getByRole('button', { name: 'Move Fees earlier' }))
    await waitFor(() => expect(api.put).toHaveBeenCalledWith('/auth/layout', { surface: 'parent_home', order: { groups: [], items: ['fees', 'grades', 'homework'] } }))
    expect(auth.updateUser).toHaveBeenCalled()
    expect(screen.getByRole('button', { name: 'Move Grades earlier' })).toBeDisabled() // not before the first place
    fireEvent.click(screen.getByRole('button', { name: 'Done' }))
    expect(labels()).toHaveLength(3)
  })

  it('offers the way back to the default only after something was arranged', async () => {
    auth.user = { role: 'parent', name: 'P', layout: { parent_home: { groups: [], items: ['fees'] } } }
    wrap(<SectionTiles tiles={tiles} surface="parent_home" />)
    fireEvent.click(screen.getByRole('button', { name: 'Arrange' }))
    fireEvent.click(screen.getByRole('button', { name: 'Back to the default order' }))
    await waitFor(() => expect(api.put).toHaveBeenCalledWith('/auth/layout', { surface: 'parent_home', order: null }))
  })

  it('the principal\'s menu follows their order and groups can be moved', async () => {
    auth.user = { role: 'admin', name: 'A', layout: { admin_nav: { groups: ['school', 'money'], items: [] } } }
    wrap(<AdminLayout />)
    const heads = within(screen.getByRole('navigation', { name: 'Menu' })).getAllByText(/^(School setup|Money & staff|People|Learning|Schedules|System)$/i).map((x) => x.textContent)
    expect(heads.indexOf('School setup')).toBeLessThan(heads.indexOf('Money & staff'))
    fireEvent.click(screen.getByRole('button', { name: 'Arrange' }))
    fireEvent.click(screen.getAllByRole('button', { name: /Move Money & staff earlier/ })[0])
    await waitFor(() => expect(api.put).toHaveBeenCalledWith('/auth/layout', expect.objectContaining({ surface: 'admin_nav', order: expect.objectContaining({ groups: expect.arrayContaining(['money', 'school']) }) })))
  })
})

describe('icon size and colour', () => {
  it('the size of the icons is chosen in arrange mode and kept with the order', async () => {
    auth.user = { role: 'parent', name: 'P', layout: { parent_home: { groups: [], items: ['fees'] } } }
    wrap(<SectionTiles tiles={tiles} surface="parent_home" />)
    fireEvent.click(screen.getByRole('button', { name: 'Arrange' }))
    fireEvent.click(screen.getByRole('button', { name: 'Large' }))
    await waitFor(() => expect(api.put).toHaveBeenCalledWith('/auth/layout', { surface: 'parent_home', order: { groups: [], items: ['fees'], size: 'large' } }))
  })

  it('every icon gets its own colour, can go back to the default colour, and the icon is painted', async () => {
    auth.user = { role: 'parent', name: 'P', layout: { parent_home: { groups: [], items: [], colors: { fees: '#dc2626' } } } }
    wrap(<SectionTiles tiles={tiles} surface="parent_home" />)
    const paintedBefore = screen.getByRole('link', { name: 'Fees' }).querySelector('span[aria-hidden]')
    expect(paintedBefore.style.color).toBe('rgb(220, 38, 38)')
    fireEvent.click(screen.getByRole('button', { name: 'Arrange' }))
    fireEvent.click(screen.getByRole('button', { name: 'Colour of Grades' }))
    fireEvent.click(screen.getByRole('button', { name: '#16a34a' }))
    await waitFor(() => expect(api.put).toHaveBeenCalledWith('/auth/layout', { surface: 'parent_home', order: { groups: [], items: [], colors: { fees: '#dc2626', grades: '#16a34a' } } }))
    fireEvent.click(screen.getAllByRole('button', { name: 'Colour of Fees' })[0])
    fireEvent.click(screen.getAllByRole('button', { name: 'Default colour' })[0])
    await waitFor(() => expect(api.put).toHaveBeenLastCalledWith('/auth/layout', { surface: 'parent_home', order: { groups: [], items: [], colors: {} } }))
  })
})
