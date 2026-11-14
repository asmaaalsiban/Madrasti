import { fireEvent, render, screen } from '@testing-library/react'
import { MemoryRouter } from 'react-router-dom'
import { describe, expect, it, vi } from 'vitest'
import { Pricing } from '../src/components/ui/pricing'

// jsdom has no IntersectionObserver (framer-motion's whileInView needs one).
vi.stubGlobal('IntersectionObserver', class { observe() {} unobserve() {} disconnect() {} })
vi.mock('canvas-confetti', () => ({ default: vi.fn() }))
vi.mock('@number-flow/react', () => ({ default: ({ value }) => <span data-testid="price">{value}</span> }))

const plans = [
  { name: 'BASIC', price: 50, yearlyPrice: 40, period: 'month', features: ['A', 'B'], description: 'small', buttonText: 'Choose', href: '/a', isPopular: false },
  { name: 'STANDARD', price: 99, yearlyPrice: 79, period: 'month', features: ['C'], description: 'mid', buttonText: 'Choose', href: '/b', isPopular: true },
]
const labels = { monthly: 'Monthly', annual: 'Annual', save: 'save 20%', popular: 'Popular', billedMonthly: 'billed monthly', billedAnnually: 'billed annually' }

describe('Pricing', () => {
  const show = () => render(<MemoryRouter><Pricing plans={plans} title="Plans" labels={labels} /></MemoryRouter>)

  it('shows every plan with its features, and marks the popular one', () => {
    show()
    expect(screen.getByText('BASIC')).toBeInTheDocument()
    expect(screen.getByText('STANDARD')).toBeInTheDocument()
    expect(screen.getByText('Popular')).toBeInTheDocument()
    expect(screen.getAllByRole('link', { name: 'Choose' }).map((a) => a.getAttribute('href'))).toEqual(['/a', '/b'])
  })

  it('switches between monthly and annual prices', () => {
    show()
    expect(screen.getAllByTestId('price').map((p) => p.textContent)).toEqual(['50', '99'])
    expect(screen.getAllByText('billed monthly')).toHaveLength(2)
    fireEvent.click(screen.getByRole('switch', { name: 'Annual' }))
    expect(screen.getAllByTestId('price').map((p) => p.textContent)).toEqual(['40', '79'])
    expect(screen.getByRole('switch')).toHaveAttribute('aria-checked', 'true')
    expect(screen.getAllByText('billed annually')).toHaveLength(2)
  })
})
