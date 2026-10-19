import { fireEvent, render, screen } from '@testing-library/react'
import { MemoryRouter } from 'react-router-dom'
import { describe, expect, it, vi } from 'vitest'
import i18n from '../src/i18n'
import AdminLayout from '../src/layouts/AdminLayout'

vi.mock('../src/features/Auth/AuthProvider', () => ({
  useAuth: () => ({ user: { role: 'admin', name: 'A' }, logout: () => {} }),
}))
vi.mock('../src/tenant/useSchool', () => ({ useSchool: () => ({ data: { name: 'مدرسة' } }) }))

describe('AdminLayout', () => {
  it('shows the five nav links in Arabic', async () => {
    await i18n.changeLanguage('ar')
    render(<MemoryRouter><AdminLayout /></MemoryRouter>)
    for (const label of ['نظرة عامة', 'السنوات الدراسية', 'الصفوف', 'الشعب', 'المواد']) {
      expect(screen.getByRole('link', { name: label })).toBeInTheDocument()
    }
  })

  it('toggles the mobile menu', async () => {
    await i18n.changeLanguage('en')
    render(<MemoryRouter><AdminLayout /></MemoryRouter>)
    const toggle = screen.getByRole('button', { name: 'Menu' })
    expect(toggle).toHaveAttribute('aria-expanded', 'false')
    fireEvent.click(toggle)
    expect(toggle).toHaveAttribute('aria-expanded', 'true')
  })
})
