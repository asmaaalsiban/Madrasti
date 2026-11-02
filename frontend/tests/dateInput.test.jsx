import { fireEvent, render, screen } from '@testing-library/react'
import { useState } from 'react'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import i18n from '../src/i18n'
import { DateInput, MonthInput } from '../src/components/shared/DateInput'

function Harness({ onValue, month }) {
  const [v, setV] = useState('')
  const Input = month ? MonthInput : DateInput
  return <Input value={v} onChange={(e) => { setV(e.target.value); onValue(e.target.value) }} />
}

beforeEach(async () => { await i18n.changeLanguage('en') })

describe('DateInput', () => {
  it('is the native date field in English', () => {
    const { container } = render(<DateInput value="2026-09-01" onChange={() => {}} />)
    expect(container.querySelector('input[type="date"]')).toHaveValue('2026-09-01')
  })

  it('shows day, month and year from right to left in Arabic and gives a normal date value', async () => {
    await i18n.changeLanguage('ar')
    const seen = vi.fn()
    const { container } = render(<Harness onValue={seen} />)
    expect(container.querySelector('[role="group"]')).toHaveAttribute('dir', 'rtl')
    fireEvent.change(screen.getByLabelText('اليوم'), { target: { value: '5' } })
    fireEvent.change(screen.getByLabelText('الشهر'), { target: { value: '9' } })
    expect(seen).toHaveBeenLastCalledWith('') // not complete yet
    fireEvent.change(screen.getByLabelText('السنة'), { target: { value: String(new Date().getFullYear()) } })
    expect(seen).toHaveBeenLastCalledWith(`${new Date().getFullYear()}-09-05`)
  })

  it('keeps the day inside the month and offers a month field without a day', async () => {
    await i18n.changeLanguage('ar')
    const seen = vi.fn()
    const { unmount } = render(<Harness onValue={seen} />)
    const year = String(new Date().getFullYear() + 1)
    fireEvent.change(screen.getByLabelText('اليوم'), { target: { value: '31' } })
    fireEvent.change(screen.getByLabelText('الشهر'), { target: { value: '2' } })
    fireEvent.change(screen.getByLabelText('السنة'), { target: { value: year } })
    expect(seen).toHaveBeenLastCalledWith(expect.stringMatching(new RegExp(`^${year}-02-(28|29)$`))) // no 31 February
    unmount()
    render(<Harness onValue={seen} month />)
    expect(screen.queryByLabelText('اليوم')).not.toBeInTheDocument()
    fireEvent.change(screen.getByLabelText('الشهر'), { target: { value: '7' } })
    fireEvent.change(screen.getByLabelText('السنة'), { target: { value: year } })
    expect(seen).toHaveBeenLastCalledWith(`${year}-07`)
  })
})
