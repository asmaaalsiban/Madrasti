import { render, screen, within } from '@testing-library/react'
import { createMemoryRouter, RouterProvider } from 'react-router-dom'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import i18n from '../src/i18n'
import LegalShell from '../src/features/Legal/LegalFooter'

const registration = vi.hoisted(() => ({ on: true }))
vi.mock('../src/features/Central/useRegistration', () => ({ useRegistration: () => ({ enabled: registration.on, loading: false }) }))

const school = vi.hoisted(() => ({ code: null }))
const companyMock = vi.hoisted(() => ({ company: { name: 'Madrasati', legalName: '', email: '', phone: '', address: '' } }))
vi.mock('../src/tenant/school', () => ({ getSchool: () => school.code }))
vi.mock('../src/config/company', () => companyMock)

const at = (path) => {
  const router = createMemoryRouter([{ element: <LegalShell />, children: [{ path: '*', element: <p>page</p> }] }], { initialEntries: [path] })
  render(<RouterProvider router={router} />)
}

beforeEach(async () => {
  school.code = null
  registration.on = true
  Object.assign(companyMock.company, { name: 'Madrasati', legalName: '', email: '', phone: '', address: '' })
  await i18n.changeLanguage('en')
})

describe('the site footer', () => {
  it('is the full footer on a public page: the platform, three link columns, the copyright and the legal links', () => {
    at('/')
    const footer = screen.getByRole('contentinfo')
    expect(within(footer).getByText('Madrasati')).toBeInTheDocument()
    expect(within(footer).getByText(/One platform for the whole school/)).toBeInTheDocument()
    expect(within(footer).getByRole('heading', { name: 'Platform' })).toBeInTheDocument()
    expect(within(footer).getByRole('heading', { name: 'Legal' })).toBeInTheDocument()
    expect(within(footer).getByText(new RegExp(`© ${new Date().getFullYear()} Madrasati. All rights reserved.`))).toBeInTheDocument()
    const legal = within(footer).getByRole('navigation', { name: 'Legal' })
    expect(within(legal).getByRole('link', { name: 'Privacy policy' })).toHaveAttribute('href', '/privacy')
    expect(within(legal).getByRole('link', { name: 'Terms of use' })).toHaveAttribute('href', '/terms')
    expect(within(legal).getByRole('link', { name: 'Cookies and local storage' })).toHaveAttribute('href', '/cookies')
  })

  it('offers registration and the school lookup before a school is chosen, and sign-in once it is', () => {
    at('/')
    expect(screen.getByRole('link', { name: 'Register a school' })).toHaveAttribute('href', '/register')
    expect(screen.getByRole('link', { name: 'Find your school' })).toHaveAttribute('href', '/find-school')
    expect(screen.queryByRole('link', { name: 'Sign in' })).not.toBeInTheDocument()
  })

  it('hides the register link when public registration is off', () => {
    registration.on = false
    at('/')
    expect(screen.queryByRole('link', { name: 'Register a school' })).not.toBeInTheDocument()
    expect(screen.getByRole('link', { name: 'Find your school' })).toHaveAttribute('href', '/find-school')
  })

  it('offers sign-in (not registration) when a school is chosen', () => {
    school.code = 'alnoor'
    at('/')
    expect(screen.getByRole('link', { name: 'Sign in' })).toHaveAttribute('href', '/login')
    expect(screen.queryByRole('link', { name: 'Register a school' })).not.toBeInTheDocument()
  })

  it('shows only the contact details that are set, and no contact column when none is', () => {
    at('/')
    expect(screen.queryByRole('heading', { name: 'Contact' })).not.toBeInTheDocument()
  })

  it('lists the contact details that are set', () => {
    Object.assign(companyMock.company, { email: 'hello@madrasati.test', phone: '+962 6 000 0000' })
    at('/')
    const contact = screen.getByRole('heading', { name: 'Contact' }).closest('div')
    expect(within(contact).getByRole('link', { name: 'hello@madrasati.test' })).toHaveAttribute('href', 'mailto:hello@madrasati.test')
    expect(within(contact).getByRole('link', { name: '+962 6 000 0000' })).toHaveAttribute('href', 'tel:+962 6 000 0000')
    expect(within(contact).queryByText(/Address/i)).not.toBeInTheDocument()
  })

  it('stays a slim bar of legal links inside the app', () => {
    at('/admin/students')
    expect(screen.queryByRole('contentinfo')).not.toBeInTheDocument()
    expect(screen.queryByRole('heading', { name: 'Platform' })).not.toBeInTheDocument()
    expect(within(screen.getByRole('navigation', { name: 'Legal' })).getByRole('link', { name: 'Privacy policy' })).toBeInTheDocument()
  })

  it('works in Arabic', async () => {
    await i18n.changeLanguage('ar')
    at('/login')
    const footer = screen.getByRole('contentinfo')
    expect(within(footer).getByRole('heading', { name: 'المنصة' })).toBeInTheDocument()
    expect(within(footer).getByRole('heading', { name: 'قانوني' })).toBeInTheDocument()
    expect(within(footer).getByText(/جميع الحقوق محفوظة/)).toBeInTheDocument()
  })
})
