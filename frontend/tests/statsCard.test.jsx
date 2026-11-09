import { render, screen } from '@testing-library/react'
import { describe, expect, it } from 'vitest'
import { Users } from 'lucide-react'
import StatsCard from '../src/components/shared/StatsCard'

describe('StatsCard', () => {
  it('shows the title, the value and the change', () => {
    render(<StatsCard title="Students" value="120" change={4.5} icon={Users} />)
    expect(screen.getByText('Students')).toBeInTheDocument()
    expect(screen.getByRole('heading', { name: '120' })).toBeInTheDocument()
    expect(screen.getByText('+4.5%')).toBeInTheDocument()
  })
  it('shows a drop without a plus sign, and no change when there is none to compare', () => {
    const { rerender } = render(<StatsCard title="Rate" value="90%" change={-2} />)
    expect(screen.getByText('-2%')).toBeInTheDocument()
    rerender(<StatsCard title="Rate" value="90%" change={null} />)
    expect(screen.queryByText(/%$/, { selector: 'span' })).not.toBeInTheDocument()
  })
})
