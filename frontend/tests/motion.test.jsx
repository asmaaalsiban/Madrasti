import { render, screen } from '@testing-library/react'
import { describe, expect, it } from 'vitest'
import { PageEnter, Reveal } from '../src/components/shared/motion'

describe('dashboard motion', () => {
  it('draws an instant block at once, with no hidden start and no animation style', () => {
    render(<Reveal instant className="menu"><p>menu</p></Reveal>)
    const block = screen.getByText('menu').parentElement
    expect(block).toHaveClass('menu')
    expect(block.getAttribute('style') ?? '').not.toMatch(/opacity|transform|filter/)
  })

  it('never uses a blur filter (it repaints a large area every frame)', () => {
    const { container } = render(<PageEnter><Reveal i={3}><p>card</p></Reveal></PageEnter>)
    expect(container.innerHTML).not.toMatch(/blur/)
  })
})
