import { useQueryClient } from '@tanstack/react-query'
import { useEffect, useState } from 'react'
import { useSearchParams } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import ConfirmDialog from '../../../components/shared/ConfirmDialog'
import DataTable from '../../../components/shared/DataTable'
import FormField from '../../../components/shared/FormField'
import Modal from '../../../components/shared/Modal'
import { uploadAttachments } from '../../../api/attachments'
import { useResource } from '../hooks/useResource'
import { InteractiveHoverButton } from '../../../components/ui/interactive-hover-button'

const inputClass = 'w-full rounded border p-2'

// A select whose options are either static (field.options: [{ value, label }]) or loaded from another resource.
function SelectField({ field, value, onChange, form }) {
  const { t, i18n } = useTranslation()
  const remote = useResource(field.optionsFrom ?? 'unused').useList({ per_page: 100, ...(typeof field.optionsParams === 'function' ? field.optionsParams(form) : field.optionsParams) }, { enabled: Boolean(field.optionsFrom) })
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
// fields: [{ name, label, type? ('custom' = render({ form, setForm }) draws it, no value), options?, optionsFrom?, optionsParams? (object, or a function of the form), optionLabel?, getValue?(row), hint?, visibleWhen?(form), requiredWhen?(form) }]
// rowActions: [{ key, label, confirm, done, run(row), when?(row) }]: extra per-row buttons that ask for confirmation first.
// filters: extra query parameters of the list (remount the page with a new key when they change).
// onSaved(response, isNew): called after a successful save.
// actions: extra buttons / links next to the Add button.
// uploadsTo: owner type of the files chosen in the form (form key `_files`); they are uploaded after the save. Keys that start with `_` are never sent as data.
// A custom field takes the whole row unless it has colSpan (a class string, e.g. 'col-span-full md:col-span-1').
// formColumns: 2 (default) or 3 — a wide form in 3 columns on a computer screen so a long form fits without scrolling.
export default function ResourcePage({ path, title, columns, formColumns = 2, fields, empty = {}, rowActions = [], filters = {}, onSaved, actions = null, uploadsTo = null }) {
  const { t } = useTranslation()
  const client = useQueryClient()
  const { useList, save, remove } = useResource(path)
  const [search, setSearch] = useState('')
  const [page, setPage] = useState(1)
  const [editing, setEditing] = useState(null) // row | {} (new) | null
  const [form, setForm] = useState({})
  const [errors, setErrors] = useState({})
  const [formError, setFormError] = useState('')
  const [limitReached, setLimitReached] = useState(false) // the school hit its student limit: shown as a friendly notice, not an error
  const [toDelete, setToDelete] = useState(null)
  const [pending, setPending] = useState(null) // { action, row }
  const [notice, setNotice] = useState('')
  const [status, setStatus] = useState('')

  const { data, isLoading } = useList({ search: search || undefined, page, ...filters })

  // `?new=1` (the "+" button of the overview) opens the add form at once.
  const [searchParams, setSearchParams] = useSearchParams()
  useEffect(() => {
    if (searchParams.get('new') === '1') {
      setErrors({}); setFormError(''); setLimitReached(false); setForm({ ...empty }); setEditing({})
      const next = new URLSearchParams(searchParams); next.delete('new'); setSearchParams(next, { replace: true })
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [])

  const openForm = (row) => {
    setErrors({})
    setFormError('')
    setLimitReached(false)
    setForm(row?.id ? Object.fromEntries(fields.filter((f) => f.type !== 'custom' || f.getValue).map((f) => [f.name, (f.getValue ? f.getValue(row) : row[f.name]) ?? ''])) : { ...empty })
    setEditing(row ?? {})
  }

  const submit = (e) => {
    e.preventDefault()
    setFormError('')
    const body = Object.fromEntries(Object.entries(form).filter(([k]) => !k.startsWith('_')).map(([k, v]) => [k, v === '' ? null : v]))
    const isNew = !editing.id
    save.mutate(
      { id: editing.id, body },
      {
        onSuccess: async (response) => {
          setEditing(null)
          if (uploadsTo && form._files?.length) {
            try {
              await uploadAttachments(uploadsTo, response?.data?.id ?? editing.id, form._files)
              client.invalidateQueries({ queryKey: [path] })
            } catch (err) {
              setNotice(Object.values(err.response?.data?.errors ?? {})[0]?.[0] || err.response?.data?.message || t('admin.actions.error'))
            }
          }
          onSaved?.(response, isNew)
        },
        onError: (err) => {
          const fieldErrors = err.response?.data?.errors
          setErrors(fieldErrors ?? {})
          // A refusal the server explains (HTTP 4xx with a translated message, e.g. the student limit) is shown as it is; only a real failure gets the generic text.
          const status = err.response?.status
          const message = status && status < 500 ? err.response?.data?.message : null
          setLimitReached(err.response?.data?.code === 'student_limit_reached')
          if (!fieldErrors) setFormError(message || t('admin.actions.error'))
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
      <div className="flex flex-wrap items-center justify-between gap-2">
        <h1 className="text-xl font-bold">{t(title)}</h1>
        <div className="flex flex-wrap items-center gap-2">
          {actions}
          <InteractiveHoverButton type="button" className="px-4 py-1.5" onClick={() => openForm(null)}>
            {t('admin.actions.add')}
          </InteractiveHoverButton>
        </div>
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
      <Modal open={editing !== null} title={t(title)} onClose={() => setEditing(null)} size={formColumns === 3 ? 'xl' : fields.length > 6 ? 'lg' : 'md'}>
        <form onSubmit={submit} className="space-y-3">
          {formError && limitReached && (
            <div role="alert" className="rounded-lg border border-amber-300 bg-amber-50 p-3 text-amber-900">
              <p className="font-bold">{t('people.limitReachedTitle')}</p>
              <p className="mt-1 text-sm">{formError}</p>
            </div>
          )}
          {formError && !limitReached && <p role="alert" className="rounded bg-red-50 p-2 text-sm text-red-700">{formError}</p>}
          <div className={`grid gap-x-3 gap-y-2 ${formColumns === 3 ? 'md:grid-cols-2 lg:grid-cols-3' : 'md:grid-cols-2'}`}>
          {fields.filter((f) => !f.visibleWhen || f.visibleWhen(form)).map((f) => f.type === 'custom' ? (
            <div key={f.name} className={f.colSpan ?? 'col-span-full'}>{f.render({ form, setForm })}</div>
          ) : (
            <FormField key={f.name} label={t(f.label)} error={errors[f.name]?.[0]} hint={f.hint ? t(f.hint) : undefined}>
              {f.type === 'select' ? (
                <SelectField field={f} form={form} value={form[f.name]} onChange={(v) => setForm({ ...form, [f.name]: v })} />
              ) : f.type === 'checkbox' ? (
                <input type="checkbox" checked={Boolean(form[f.name])} onChange={(e) => setForm({ ...form, [f.name]: e.target.checked })} />
              ) : (
                <input
                  className={inputClass}
                  type={f.type ?? 'text'}
                  dir={f.type === 'email' || f.type === 'tel' ? 'ltr' : undefined}
                  required={Boolean(f.requiredWhen?.(form))}
                  value={form[f.name] ?? ''}
                  onChange={(e) => setForm({ ...form, [f.name]: e.target.value })}
                />
              )}
            </FormField>
          ))}
          </div>
          <div className="sticky bottom-0 -mx-1 flex justify-end gap-2 bg-white px-1 pt-2">
            <button type="button" className="rounded border px-3 py-1.5" onClick={() => setEditing(null)}>{t('admin.actions.cancel')}</button>
            <InteractiveHoverButton type="submit" disabled={save.isPending} className="px-3 py-1.5 disabled:opacity-60">{t('admin.actions.save')}</InteractiveHoverButton>
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
