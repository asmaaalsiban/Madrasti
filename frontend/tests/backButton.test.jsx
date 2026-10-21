import { fireEvent, render, screen } from '@testing-library/react'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import i18n from '../src/i18n'
import BackButton from '../src/components/shared/BackButton'
import PasswordInput from '../src/components/shared/PasswordInput'

vi.mock('../src/features/Auth/AuthProvider', () => ({ useAuth: () => ({ user: { role: 'admin' } }) }))

const app = (entries, index) => (
  <MemoryRouter initialEntries={entries} initialIndex={index}>
    <Routes>
      <Route path="*" element={<><BackButton /><Where /></>} />
    </Routes>
  </MemoryRouter>
)
import { useLocation } from 'react-router-dom'
const Where = () => <p data-testid="where">{useLocation().pathname}</p>

beforeEach(async () => {
  await i18n.changeLanguage('en')
})

describe('BackButton', () => {
  it('goes back to the page before', () => {
    render(app(['/admin', '/admin/students', '/admin/staff'], 2))
    fireEvent.click(screen.getByRole('button', { name: 'Back' }))
    expect(screen.getByTestId('where')).toHaveTextContent('/admin/students')
  })

  it('is not shown on the home page of the role', () => {
    render(app(['/admin'], 0))
    expect(screen.queryByRole('button', { name: 'Back' })).not.toBeInTheDocument()
  })

  it('goes to the home page when the page was opened directly (nothing to go back to)', () => {
    render(app(['/admin/students'], 0))
    fireEvent.click(screen.getByRole('button', { name: 'Back' }))
    expect(screen.getByTestId('where')).toHaveTextContent('/admin')
    expect(screen.getByTestId('where')).not.toHaveTextContent('/admin/')
  })

  it('has an Arabic label', async () => {
    await i18n.changeLanguage('ar')
    render(app(['/admin', '/admin/students'], 1))
    expect(screen.getByRole('button', { name: 'رجوع' })).toBeInTheDocument()
  })
})

describe('PasswordInput position', () => {
  it('keeps the eye on the right in both languages, away from the left-to-right text', async () => {
    for (const lng of ['en', 'ar']) {
      await i18n.changeLanguage(lng)
      const { unmount } = render(<PasswordInput value="x" onChange={() => {}} />)
      const eye = screen.getByRole('button')
      expect(eye.className).toContain('right-0')
      expect(eye.className).not.toContain('end-0')
      expect(screen.getByDisplayValue('x').className).toContain('pr-10')
      unmount()
    }
  })
})
