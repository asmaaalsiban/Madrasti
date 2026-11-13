import { render, screen } from '@testing-library/react'
import { MemoryRouter } from 'react-router-dom'
import { beforeEach, describe, expect, it } from 'vitest'
import i18n from '../src/i18n'
import NotFoundPage from '../src/components/shared/NotFoundPage'

const show = () => render(<MemoryRouter><NotFoundPage /></MemoryRouter>)

beforeEach(async () => {
  await i18n.changeLanguage('en')
})

describe('NotFoundPage', () => {
  it('shows the English texts and a link home', () => {
    show()
    expect(screen.getByRole('heading', { name: 'Page not found' })).toBeInTheDocument()
    expect(screen.getByRole('link', { name: /Back to home/ })).toHaveAttribute('href', '/')
  })

  it('shows the Arabic texts', async () => {
    await i18n.changeLanguage('ar')
    show()
    expect(screen.getByRole('heading', { name: 'الصفحة غير موجودة' })).toBeInTheDocument()
    expect(screen.getByRole('link', { name: /العودة للرئيسية/ })).toBeInTheDocument()
  })
})
