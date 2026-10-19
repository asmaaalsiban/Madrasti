import { render, screen } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { describe, expect, it, vi } from 'vitest'
import i18n from '../src/i18n'
import LandingPage from '../src/features/Auth/pages/LandingPage'

const mockUser = vi.hoisted(() => ({ current: null }))
vi.mock('../src/features/Auth/AuthProvider', () => ({
  useAuth: () => ({ user: mockUser.current }),
}))
vi.mock('../src/tenant/useSchool', () => ({
  useSchool: () => ({ data: { name: 'مدرسة النور' } }),
}))

function renderLanding() {
  render(
    <QueryClientProvider client={new QueryClient()}>
      <MemoryRouter>
        <Routes>
          <Route path="/" element={<LandingPage />} />
          <Route path="/parent" element={<p>parent home</p>} />
        </Routes>
      </MemoryRouter>
    </QueryClientProvider>,
  )
}

describe('LandingPage', () => {
  it('shows the school landing to guests in Arabic', async () => {
    mockUser.current = null
    await i18n.changeLanguage('ar')
    renderLanding()
    expect(screen.getByRole('heading', { level: 1 })).toHaveTextContent('مدرستك كلها في مكان واحد')
    expect(screen.getAllByText('مدرسة النور').length).toBeGreaterThan(0)
  })

  it('renders in English with LTR', async () => {
    mockUser.current = null
    await i18n.changeLanguage('en')
    renderLanding()
    expect(screen.getByRole('heading', { level: 1 })).toHaveTextContent('Your whole school in one place')
    expect(document.documentElement.dir).toBe('ltr')
    await i18n.changeLanguage('ar')
  })

  it('redirects signed-in users to their home', () => {
    mockUser.current = { role: 'parent' }
    renderLanding()
    expect(screen.getByText('parent home')).toBeInTheDocument()
  })
})
