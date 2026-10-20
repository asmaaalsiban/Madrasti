import { fireEvent, render, screen } from '@testing-library/react'
import { MemoryRouter } from 'react-router-dom'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import i18n from '../src/i18n'
import LoginPage from '../src/features/Auth/pages/LoginPage'
import FindSchoolPage from '../src/features/Central/FindSchoolPage'
import { clearSchool, getSchool, goTo, setSchool } from '../src/tenant/school'

vi.mock('../src/features/Central/api', () => ({ schoolExists: vi.fn() }))
vi.mock('../src/tenant/school', async (orig) => ({ ...(await orig()), goTo: vi.fn() }))
vi.mock('../src/features/Auth/AuthProvider', () => ({ useAuth: () => ({ user: null, login: vi.fn() }) }))
vi.mock('../src/tenant/useSchool', () => ({ useSchool: () => ({ data: { name: 'School Two' } }) }))

beforeEach(async () => {
  vi.resetAllMocks()
  clearSchool()
  localStorage.clear()
  await i18n.changeLanguage('en')
})

describe('LoginPage back arrow', () => {
  it('shows which school this is and a back button to the code page', () => {
    setSchool('school2')
    render(<MemoryRouter><LoginPage /></MemoryRouter>)
    expect(screen.getByText('School Two')).toBeInTheDocument()
    expect(screen.getByText('school2')).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Change school code' })).toBeInTheDocument()
  })

  it('goes back to the code page with the current code filled in, and forgets the school', () => {
    setSchool('school2')
    render(<MemoryRouter><LoginPage /></MemoryRouter>)
    fireEvent.click(screen.getByRole('button', { name: 'Change school code' }))
    expect(getSchool()).toBeNull()
    expect(goTo).toHaveBeenCalledWith('/find-school?code=school2')
  })

  it('has an Arabic label', async () => {
    await i18n.changeLanguage('ar')
    setSchool('school2')
    render(<MemoryRouter><LoginPage /></MemoryRouter>)
    expect(screen.getByRole('button', { name: 'تغيير كود المدرسة' })).toBeInTheDocument()
  })
})

describe('FindSchoolPage', () => {
  it('fills in the code passed in the address so a typo can be fixed', () => {
    render(<MemoryRouter initialEntries={['/find-school?code=school2']}><FindSchoolPage /></MemoryRouter>)
    expect(screen.getByLabelText('School code')).toHaveValue('school2')
  })

  it('starts empty without a code and has a way back to the welcome page', () => {
    render(<MemoryRouter initialEntries={['/find-school']}><FindSchoolPage /></MemoryRouter>)
    expect(screen.getByLabelText('School code')).toHaveValue('')
    expect(screen.getByRole('link', { name: /Back/ })).toHaveAttribute('href', '/')
  })

  it('ignores a malformed code in the address', () => {
    render(<MemoryRouter initialEntries={['/find-school?code=<script>']}><FindSchoolPage /></MemoryRouter>)
    expect(screen.getByLabelText('School code')).toHaveValue('')
  })
})
