import { render, screen, fireEvent } from '@testing-library/react'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import GlassSettings, { normalizeGlass, useGlassSettings } from '../src/features/Academic/components/GlassSettings'

vi.mock('../src/features/Auth/AuthProvider', () => ({ useAuth: () => ({ user: { id: 7 } }) }))
vi.mock('../src/tenant/school', () => ({ getSchool: () => 'school2' }))

function Harness() {
  const glass = useGlassSettings()
  return <><div data-testid="stage" style={glass.style} /><GlassSettings glass={glass} /></>
}

describe('glass settings of the overview', () => {
  beforeEach(() => localStorage.clear())

  it('keeps every value inside its limits', () => {
    expect(normalizeGlass({ blur: 999, opacity: -1, saturation: 'x', tint: 'red' })).toEqual({ blur: 24, opacity: 0.04, saturation: 1, tint: '#ffffff' })
  })

  it('changes nothing until the person changes something', () => {
    render(<Harness />)
    expect(screen.getByTestId('stage').style.getPropertyValue('--g-blur')).toBe('')
  })

  it('applies a change, remembers it for this person, and resets', () => {
    render(<Harness />)
    fireEvent.click(screen.getByRole('button', { name: 'dashboard.glassSettings.open' }))
    fireEvent.change(screen.getByRole('slider', { name: 'dashboard.glassSettings.blur' }), { target: { value: '16' } })
    expect(screen.getByTestId('stage').style.getPropertyValue('--g-blur')).toBe('16px')
    expect(JSON.parse(localStorage.getItem('glass:school2:7')).blur).toBe(16)
    fireEvent.click(screen.getByRole('button', { name: 'dashboard.glassSettings.reset' }))
    expect(localStorage.getItem('glass:school2:7')).toBeNull()
    expect(screen.getByTestId('stage').style.getPropertyValue('--g-blur')).toBe('')
  })

  it('closes with Escape', () => {
    render(<Harness />)
    fireEvent.click(screen.getByRole('button', { name: 'dashboard.glassSettings.open' }))
    fireEvent.keyDown(screen.getByRole('dialog'), { key: 'Escape' })
    expect(screen.queryByRole('dialog')).toBeNull()
  })
})
