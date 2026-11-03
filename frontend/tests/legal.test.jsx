import { render, screen, within } from '@testing-library/react'
import { MemoryRouter, RouterProvider, createMemoryRouter } from 'react-router-dom'
import { beforeEach, describe, expect, it } from 'vitest'
import i18n from '../src/i18n'
import { company } from '../src/config/company'
import LegalPage from '../src/features/Legal/LegalPage'
import LegalShell from '../src/features/Legal/LegalFooter'

beforeEach(async () => { await i18n.changeLanguage('en') })

describe('legal pages', () => {
  it('each page shows its sections, the draft notice and the business details', () => {
    for (const [doc, title, first] of [['privacy', 'Privacy policy', /Who is responsible/], ['terms', 'Terms of use', /The service/], ['cookies', 'Cookies and local storage', /What we store/]]) {
      const { unmount } = render(<MemoryRouter><LegalPage doc={doc} /></MemoryRouter>)
      expect(screen.getByRole('heading', { level: 1, name: title })).toBeInTheDocument()
      expect(screen.getByRole('heading', { name: first })).toBeInTheDocument()
      if (company.draft) expect(screen.getByRole('note')).toHaveTextContent(/not yet been reviewed by a lawyer/)
      expect(screen.getByRole('heading', { name: 'Business details' })).toBeInTheDocument()
      unmount()
    }
  })

  it('the privacy policy says the school is responsible for parents consent for photos, and that card numbers never reach us', () => {
    render(<MemoryRouter><LegalPage doc="privacy" /></MemoryRouter>)
    expect(screen.getByText(/responsible for obtaining the consent of parents or guardians/)).toBeInTheDocument()
    expect(screen.getByText(/never reach us/)).toBeInTheDocument()
  })

  it('works in Arabic too', async () => {
    await i18n.changeLanguage('ar')
    render(<MemoryRouter><LegalPage doc="privacy" /></MemoryRouter>)
    expect(screen.getByRole('heading', { level: 1, name: 'سياسة الخصوصية' })).toBeInTheDocument()
    expect(screen.getByText(/لا تصلنا أبداً/)).toBeInTheDocument()
  })

  it('every page ends with a footer linking to the three documents', () => {
    const router = createMemoryRouter([{ element: <LegalShell />, children: [{ path: '/', element: <p>Hello</p> }] }], { initialEntries: ['/'] })
    render(<RouterProvider router={router} />)
    const footer = screen.getByRole('navigation', { name: 'Legal' })
    expect(within(footer).getByRole('link', { name: 'Privacy policy' })).toHaveAttribute('href', '/privacy')
    expect(within(footer).getByRole('link', { name: 'Terms of use' })).toHaveAttribute('href', '/terms')
    expect(within(footer).getByRole('link', { name: 'Cookies and local storage' })).toHaveAttribute('href', '/cookies')
  })
})
