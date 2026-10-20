import { fireEvent, render, screen } from '@testing-library/react'
import { beforeEach, describe, expect, it } from 'vitest'
import i18n from '../src/i18n'
import PasswordInput from '../src/components/shared/PasswordInput'

beforeEach(async () => {
  await i18n.changeLanguage('en')
})

describe('PasswordInput', () => {
  it('hides the password by default and reveals it with the eye button', () => {
    render(<label>Password<PasswordInput value="secret123" onChange={() => {}} /></label>)
    const input = screen.getByDisplayValue('secret123')
    expect(input).toHaveAttribute('type', 'password')

    fireEvent.click(screen.getByRole('button', { name: 'Show password' }))
    expect(input).toHaveAttribute('type', 'text')
    expect(screen.getByRole('button', { name: 'Hide password' })).toHaveAttribute('aria-pressed', 'true')

    fireEvent.click(screen.getByRole('button', { name: 'Hide password' }))
    expect(input).toHaveAttribute('type', 'password')
  })

  it('does not submit a form when the eye is clicked and keeps the text left-to-right', () => {
    let submitted = false
    render(<form onSubmit={(e) => { e.preventDefault(); submitted = true }}><PasswordInput value="x" onChange={() => {}} /></form>)
    fireEvent.click(screen.getByRole('button', { name: 'Show password' }))
    expect(submitted).toBe(false)
    expect(screen.getByDisplayValue('x')).toHaveAttribute('dir', 'ltr')
  })

  it('has Arabic labels', async () => {
    await i18n.changeLanguage('ar')
    render(<PasswordInput value="x" onChange={() => {}} />)
    expect(screen.getByRole('button', { name: 'إظهار كلمة المرور' })).toBeInTheDocument()
  })
})
