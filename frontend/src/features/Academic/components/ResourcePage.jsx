import { useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import ConfirmDialog from '../../../components/shared/ConfirmDialog'
import DataTable from '../../../components/shared/DataTable'
import FormField from '../../../components/shared/FormField'
import Modal from '../../../components/shared/Modal'
import { useResource } from '../hooks/useResource'

const inputClass = 'w-full rounded border p-2'

// A select whose options are either static (field.options: [{ value, label }]) or loaded from another resource.
function SelectField({ field, value, onChange }) {
  const { t, i18n } = useTranslation()
  const remote = useResource(field.optionsFrom ?? 'unused').useList({ per_page: 100, ...field.optionsParams }, { enabled: Boolean(field.optionsFrom) })
  const label = (o) => o[field.optionLabel] ?? o[`name_${i18n.language}`] ?? o.name
  const options = field.options
    ? field.options.map((o) => ({ value: o.value, label: t(o.label) }))
    : (remote.data?.data ?? []).map((o) => ({ value: o.id, label: label(o) }))
  return (
    <select className={inputClass} aria-label={t(field.label)} value={value ?? ''} onChange={(e) => onChange(e.target.value)}>
      <option value="">{t('academic.fields.select')}</option>
      {options.map((o) => <option key={o.value} value={o.value}>{o.label}</option>)}
    </select>
  )
}

// Config-driven list + create/edit modal + delete confirmation for one resource.
// fields: [{ name, label, type?, options?, optionsFrom?, optionsParams?, optionLabel?, getValue?(row), hint?, visibleWhen?(form) }]
// rowActions: [{ key, label, confirm, done, run(row), when?(row) }]: extra per-row buttons that ask for confirmation first.
// filters: extra query parameters of the list (remount the page with a new key when they change).
// onSaved(response, isNew): called after a successful save.
export default function ResourcePage({ path, title, columns, fields, empty = {}, rowActions = [], filters = {}, onSaved }) {
  const { t } = useTranslation()
  const client = useQueryClient()
  const { useList, save, remove } = useResource(path)
  const [search, setSearch] = useState('')
  const [page, setPage] = useState(1)
  const [editing, setEditing] = useState(null) // row | {} (new) | null
  const [form, setForm] = useState({})
  const [errors, setErrors] = useState({})
  const [formError, setFormError] = useState('')
  const [toDelete, setToDelete] = useState(null)
  const [pending, setPending] = useState(null) // { action, row }
  const [notice, setNotice] = useState('')
  const [status, setStatus] = useState('')

  const { data, isLoading } = useList({ search: search || undefined, page, ...filters })

  const openForm = (row) => {
    setErrors({})
    setFormError('')
    setForm(row?.id ? Object.fromEntries(fields.map((f) => [f.name, (f.getValue ? f.getValue(row) : row[f.name]) ?? ''])) : { ...empty })
    setEditing(row ?? {})
  }

  const submit = (e) => {
    e.preventDefault()
    setFormError('')
    const body = Object.fromEntries(Object.entries(form).map(([k, v]) => [k, v === '' ? null : v]))
    const isNew = !editing.id
    save.mutate(
      { id: editing.id, body },
      {
        onSuccess: (response) => {
          setEditing(null)
          onSaved?.(response, isNew)
        },
        onError: (err) => {
          const fieldErrors = err.response?.data?.errors
          setErrors(fieldErrors ?? {})
          if (!fieldErrors) setFormError(t('admin.actions.error'))
        },
      },
    )
  }

  const confirmDelete = () => {
    remove.mutate(toDelete.id, {
      onError: (err) => setNotice(err.response?.data?.message ?? ''),
      onSettled: () => setToDelete(null),
    })
  }

  const runAction = async () => {
    const { action, row } = pending
    setPending(null)
    try {
      await action.run(row)
      setStatus(t(action.done))
      client.invalidateQueries({ queryKey: [path] })
    } catch (err) {
      setNotice(err.response?.data?.message || t('admin.actions.error'))
    }
  }

  return (
    <section className="space-y-4">
      <div className="flex items-center justify-between">
        <h1 className="text-xl font-bold">{t(title)}</h1>
        <button type="button" className="rounded bg-brand px-4 py-1.5 text-white" onClick={() => openForm(null)}>
          {t('admin.actions.add')}
        </button>
      </div>
      {notice && <p role="alert" className="rounded bg-red-50 p-3 text-red-700">{notice}</p>}
      {status && <p role="status" className="rounded bg-green-50 p-3 text-green-800">{status}</p>}
      <DataTable
        columns={columns.map((c) => ({ ...c, label: t(c.label) }))}
        rows={data?.data ?? []}
        loading={isLoading}
        search={search}
        onSearch={(v) => { setSearch(v); setPage(1) }}
        page={data?.meta?.current_page ?? 1}
        lastPage={data?.meta?.last_page ?? 1}
        onPage={setPage}
        onEdit={openForm}
        extraActions={rowActions.map((a) => ({ key: a.key, label: t(a.label), when: a.when, onClick: (row) => { setNotice(''); setStatus(''); setPending({ action: a, row }) } }))}
        onDelete={(row) => { setNotice(''); setStatus(''); setToDelete(row) }}
      />
      <Modal open={editing !== null} title={t(title)} onClose={() => setEditing(null)}>
        <form onSubmit={submit} className="space-y-3">
          {formError && <p role="alert" className="rounded bg-red-50 p-2 text-sm text-red-700">{formError}</p>}
          {fields.filter((f) => !f.visibleWhen || f.visibleWhen(form)).map((f) => (
            <FormField key={f.name} label={t(f.label)} error={errors[f.name]?.[0]} hint={f.hint ? t(f.hint) : undefined}>
              {f.type === 'select' ? (
                <SelectField field={f} value={form[f.name]} onChange={(v) => setForm({ ...form, [f.name]: v })} />
              ) : f.type === 'checkbox' ? (
                <input type="checkbox" checked={Boolean(form[f.name])} onChange={(e) => setForm({ ...form, [f.name]: e.target.checked })} />
              ) : (
                <input
                  className={inputClass}
                  type={f.type ?? 'text'}
                  dir={f.type === 'email' ? 'ltr' : undefined}
                  value={form[f.name] ?? ''}
                  onChange={(e) => setForm({ ...form, [f.name]: e.target.value })}
                />
              )}
            </FormField>
          ))}
          <div className="flex justify-end gap-2 pt-2">
            <button type="button" className="rounded border px-3 py-1.5" onClick={() => setEditing(null)}>{t('admin.actions.cancel')}</button>
            <button type="submit" disabled={save.isPending} className="rounded bg-brand px-3 py-1.5 text-white disabled:opacity-60">{t('admin.actions.save')}</button>
          </div>
        </form>
      </Modal>
      <ConfirmDialog open={toDelete !== null} message={t('admin.actions.confirmDelete')} onConfirm={confirmDelete} onCancel={() => setToDelete(null)} />
      <ConfirmDialog
        open={pending !== null}
        message={pending ? t(pending.action.confirm) : ''}
        confirmLabel={pending ? t(pending.action.label) : undefined}
        danger={false}
        onConfirm={runAction}
        onCancel={() => setPending(null)}
      />
    </section>
  )
}
