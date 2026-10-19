import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import ConfirmDialog from '../../../components/shared/ConfirmDialog'
import DataTable from '../../../components/shared/DataTable'
import FormField from '../../../components/shared/FormField'
import Modal from '../../../components/shared/Modal'
import { useResource } from '../hooks/useResource'

const inputClass = 'w-full rounded border p-2'

function SelectField({ field, value, onChange }) {
  const { t, i18n } = useTranslation()
  const { data } = useResource(field.optionsFrom).useList({ per_page: 100 })
  const label = (o) => o[field.optionLabel] ?? o[`name_${i18n.language}`] ?? o.name
  return (
    <select className={inputClass} aria-label={t(field.label)} value={value ?? ''} onChange={(e) => onChange(e.target.value)}>
      <option value="">{t('academic.fields.select')}</option>
      {(data?.data ?? []).map((o) => <option key={o.id} value={o.id}>{label(o)}</option>)}
    </select>
  )
}

// Config-driven list + create/edit modal + delete confirmation for one resource.
export default function ResourcePage({ path, title, columns, fields, empty = {} }) {
  const { t } = useTranslation()
  const { useList, save, remove } = useResource(path)
  const [search, setSearch] = useState('')
  const [page, setPage] = useState(1)
  const [editing, setEditing] = useState(null) // row | {} (new) | null
  const [form, setForm] = useState({})
  const [errors, setErrors] = useState({})
  const [toDelete, setToDelete] = useState(null)
  const [notice, setNotice] = useState('')

  const { data, isLoading } = useList({ search: search || undefined, page })

  const openForm = (row) => {
    setErrors({})
    setForm(row?.id ? Object.fromEntries(fields.map((f) => [f.name, row[f.name] ?? ''])) : { ...empty })
    setEditing(row ?? {})
  }

  const submit = (e) => {
    e.preventDefault()
    const body = Object.fromEntries(Object.entries(form).map(([k, v]) => [k, v === '' ? null : v]))
    save.mutate(
      { id: editing.id, body },
      {
        onSuccess: () => setEditing(null),
        onError: (err) => setErrors(err.response?.data?.errors ?? {}),
      },
    )
  }

  const confirmDelete = () => {
    remove.mutate(toDelete.id, {
      onError: (err) => setNotice(err.response?.data?.message ?? ''),
      onSettled: () => setToDelete(null),
    })
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
        onDelete={(row) => { setNotice(''); setToDelete(row) }}
      />
      <Modal open={editing !== null} title={t(title)} onClose={() => setEditing(null)}>
        <form onSubmit={submit} className="space-y-3">
          {fields.map((f) => (
            <FormField key={f.name} label={t(f.label)} error={errors[f.name]?.[0]}>
              {f.type === 'select' ? (
                <SelectField field={f} value={form[f.name]} onChange={(v) => setForm({ ...form, [f.name]: v })} />
              ) : f.type === 'checkbox' ? (
                <input type="checkbox" checked={Boolean(form[f.name])} onChange={(e) => setForm({ ...form, [f.name]: e.target.checked })} />
              ) : (
                <input
                  className={inputClass}
                  type={f.type ?? 'text'}
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
    </section>
  )
}
