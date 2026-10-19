import { fireEvent, render, screen } from '@testing-library/react'
import { describe, expect, it, vi } from 'vitest'
import i18n from '../src/i18n'
import ConfirmDialog from '../src/components/shared/ConfirmDialog'
import DataTable from '../src/components/shared/DataTable'
import Modal from '../src/components/shared/Modal'

const columns = [{ key: 'name', label: 'Name' }]

describe('DataTable', () => {
  it('renders rows and fires callbacks', async () => {
    await i18n.changeLanguage('en')
    const onEdit = vi.fn()
    const onDelete = vi.fn()
    const onSearch = vi.fn()
    const rows = [{ id: 1, name: 'Math' }]
    render(<DataTable columns={columns} rows={rows} search="" onSearch={onSearch} onEdit={onEdit} onDelete={onDelete} />)
    expect(screen.getByText('Math')).toBeInTheDocument()
    fireEvent.click(screen.getByRole('button', { name: 'Edit' }))
    expect(onEdit).toHaveBeenCalledWith(rows[0])
    fireEvent.click(screen.getByRole('button', { name: 'Delete' }))
    expect(onDelete).toHaveBeenCalledWith(rows[0])
    fireEvent.change(screen.getByRole('searchbox'), { target: { value: 'x' } })
    expect(onSearch).toHaveBeenCalledWith('x')
  })

  it('shows an empty state for no rows', async () => {
    await i18n.changeLanguage('en')
    render(<DataTable columns={columns} rows={[]} search="" onSearch={() => {}} />)
    expect(screen.getByText('Nothing to show yet.')).toBeInTheDocument()
  })
})

describe('Modal', () => {
  it('closes on Escape', () => {
    const onClose = vi.fn()
    render(<Modal open title="T" onClose={onClose}>body</Modal>)
    expect(screen.getByRole('dialog')).toBeInTheDocument()
    fireEvent.keyDown(document, { key: 'Escape' })
    expect(onClose).toHaveBeenCalled()
  })

  it('renders nothing when closed', () => {
    render(<Modal open={false} title="T" onClose={() => {}}>body</Modal>)
    expect(screen.queryByRole('dialog')).not.toBeInTheDocument()
  })
})

describe('ConfirmDialog', () => {
  it('confirms and cancels', async () => {
    await i18n.changeLanguage('en')
    const onConfirm = vi.fn()
    const onCancel = vi.fn()
    render(<ConfirmDialog open message="Sure?" onConfirm={onConfirm} onCancel={onCancel} />)
    fireEvent.click(screen.getByRole('button', { name: 'Delete' }))
    expect(onConfirm).toHaveBeenCalled()
    fireEvent.click(screen.getByRole('button', { name: 'Cancel' }))
    expect(onCancel).toHaveBeenCalled()
  })
})
