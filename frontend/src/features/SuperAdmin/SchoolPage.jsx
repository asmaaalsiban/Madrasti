import { useQueryClient } from '@tanstack/react-query'
import { useEffect, useState } from 'react'
import { Link, useParams } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import ConfirmDialog from '../../components/shared/ConfirmDialog'
import { DateInput } from '../../components/shared/DateInput'
import FormField from '../../components/shared/FormField'
import { StateBadge } from './SchoolsPage'
import { dayOf, formatDay, formatWhen } from './format'
import { downloadExitFile, errorOf, fieldErrors, useExitSteps, useExtendExit, useExtendSchool, useOpenExit, useRetryExitStep, useReactivateSchool, useSchoolAudit, useSchoolByCode, useSuspendSchool, useUpdateSchool } from './useSuperAdmin'
import { InteractiveHoverButton } from '../../components/ui/interactive-hover-button'

const inputClass = 'w-full rounded border p-2'
const Card = ({ title, children }) => <section className="space-y-3 rounded-lg border bg-white p-4"><h2 className="font-bold">{title}</h2>{children}</section>
const Message = ({ ok, children }) => (children ? <p role={ok ? 'status' : 'alert'} className={`rounded p-2 text-sm ${ok ? 'bg-green-50 text-green-800' : 'bg-red-50 text-red-700'}`}>{children}</p> : null)

function EditCard({ school }) {
  const { t } = useTranslation()
  const update = useUpdateSchool(school.id)
  const [form, setForm] = useState({ name: school.name, max_students: school.max_students ?? '', default_locale: school.default_locale ?? 'ar' })
  const [done, setDone] = useState(false)
  const errors = update.isError ? fieldErrors(update.error) : {}
  const set = (name) => (e) => { setDone(false); setForm({ ...form, [name]: e.target.value }) }
  const save = (e) => {
    e.preventDefault()
    update.mutate({ name: form.name, default_locale: form.default_locale, max_students: form.max_students === '' ? null : Number(form.max_students) }, { onSuccess: () => setDone(true) })
  }
  return (
    <Card title={t('superAdmin.details')}>
      <form onSubmit={save} className="space-y-3">
        <FormField label={t('superAdmin.schoolName')} error={errors.name?.[0]}><input required className={inputClass} value={form.name} onChange={set('name')} /></FormField>
        <FormField label={t('superAdmin.maxStudents')} hint={t('superAdmin.maxStudentsHint')} error={errors.max_students?.[0]}>
          <input type="number" min="1" dir="ltr" className={inputClass} value={form.max_students} onChange={set('max_students')} />
        </FormField>
        <FormField label={t('superAdmin.defaultLocale')} error={errors.default_locale?.[0]}>
          <select className={inputClass} value={form.default_locale} onChange={set('default_locale')}><option value="ar">{t('superAdmin.arabic')}</option><option value="en">{t('superAdmin.english')}</option></select>
        </FormField>
        {update.isError && !Object.keys(errors).length && <Message>{errorOf(update.error, t('superAdmin.genericError'))}</Message>}
        <Message ok>{done && t('superAdmin.saved')}</Message>
        <InteractiveHoverButton disabled={update.isPending} className="px-4 py-2 font-semibold disabled:opacity-60">{t('admin.actions.save')}</InteractiveHoverButton>
      </form>
    </Card>
  )
}

function ExtendCard({ school }) {
  const { t, i18n } = useTranslation()
  const extend = useExtendSchool(school.id)
  const [date, setDate] = useState('')
  const [done, setDone] = useState(false)
  const submit = (e) => {
    e.preventDefault()
    extend.mutate(date, { onSuccess: () => { setDone(true); setDate('') } })
  }
  return (
    <Card title={t('superAdmin.subscription')}>
      <p className="text-sm">{t('superAdmin.currentEnd')}: <b>{school.subscription_ends_at ? formatDay(school.subscription_ends_at, i18n.language) : t('superAdmin.noEndDate')}</b></p>
      <form onSubmit={submit} className="space-y-3">
        <FormField label={t('superAdmin.newEndDate')}><DateInput required className={inputClass} value={date} min={dayOf(school.subscription_ends_at) || undefined} onChange={(e) => { setDone(false); setDate(e.target.value) }} /></FormField>
        {extend.isError && <Message>{errorOf(extend.error, t('superAdmin.genericError'))}</Message>}
        <Message ok>{done && t('superAdmin.extended')}</Message>
        <InteractiveHoverButton disabled={!date || extend.isPending} className="px-4 py-2 font-semibold disabled:opacity-60">{t('superAdmin.extend')}</InteractiveHoverButton>
      </form>
    </Card>
  )
}

function StatusCard({ school }) {
  const { t, i18n } = useTranslation()
  const suspend = useSuspendSchool(school.id)
  const reactivate = useReactivateSchool(school.id)
  const [confirm, setConfirm] = useState(false)
  // A school in its exit window or closed still has is_active = true, but only "reactivate" can bring it back to normal.
  const blocked = ['suspended', 'exit_window', 'closed'].includes(school.state)
  const action = blocked ? reactivate : suspend
  const key = blocked ? 'reactivate' : 'suspend'
  const run = () => action.mutate(undefined, { onSettled: () => setConfirm(false) })
  return (
    <Card title={t('superAdmin.status')}>
      <p><StateBadge state={school.state} /></p>
      <dl className="grid grid-cols-2 gap-2 text-sm">
        <dt className="text-slate-500">{t('superAdmin.students')}</dt><dd dir="ltr" className="text-end">{school.students_count ?? '—'} / {school.max_students ?? t('superAdmin.unlimited')}</dd>
        <dt className="text-slate-500">{t('superAdmin.staff')}</dt><dd dir="ltr" className="text-end">{school.staff_count ?? '—'}</dd>
        <dt className="text-slate-500">{t('superAdmin.lastBackup')}</dt><dd className="text-end">{school.last_backup_at ? formatWhen(school.last_backup_at, i18n.language) : t('superAdmin.never')}</dd>
        {school.exit_window_ends_at && <><dt className="text-slate-500">{t('superAdmin.exitWindowEnds')}</dt><dd className="text-end">{formatWhen(school.exit_window_ends_at, i18n.language)}</dd></>}
        {school.closed_at && <><dt className="text-slate-500">{t('superAdmin.closedAt')}</dt><dd className="text-end">{formatWhen(school.closed_at, i18n.language)}</dd></>}
      </dl>
      {action.isError && <Message>{errorOf(action.error, t('superAdmin.genericError'))}</Message>}
      <button type="button" className={`rounded px-4 py-2 font-semibold ${blocked ? 'bg-brand text-white' : 'border border-red-300 text-red-700'}`} onClick={() => setConfirm(true)}>{t(`superAdmin.${key}`)}</button>
      <ConfirmDialog open={confirm} danger={!blocked} busy={action.isPending} message={t(`superAdmin.${key}Question`)} description={t(`superAdmin.${key}Description`)} confirmLabel={t(`superAdmin.${key}Confirm`)} onConfirm={run} onCancel={() => setConfirm(false)} />
    </Card>
  )
}

const STEP_STYLE = { pending: 'bg-slate-100 text-slate-700', running: 'bg-sky-100 text-sky-800', done: 'bg-green-100 text-green-800', failed: 'bg-red-100 text-red-700' }

function ExitCard({ school }) {
  const { t } = useTranslation()
  const { data: steps = [], isError } = useExitSteps(school.id)
  const open = useOpenExit(school.id)
  const extend = useExtendExit(school.id)
  const retry = useRetryExitStep(school.id)
  const [openDays, setOpenDays] = useState('14')
  const [extendDays, setExtendDays] = useState('7')
  const [done, setDone] = useState(false)
  const [downloadError, setDownloadError] = useState(false)
  const [confirmOpen, setConfirmOpen] = useState(false)
  const client = useQueryClient()
  const inWindow = school.state === 'exit_window'
  const preparing = steps.some((s) => s.status === 'pending' || s.status === 'running')
  // The job opened the window by itself: reload the school so the page shows the window (and the extend form) without a manual reload.
  const allDone = steps.length > 0 && steps.every((s) => s.status === 'done')
  useEffect(() => { if (allDone && !inWindow) client.invalidateQueries({ queryKey: ['sa'] }) }, [allDone, inWindow, client])
  const download = async (step) => {
    setDownloadError(false)
    try { await downloadExitFile(school.id, step) } catch { setDownloadError(true) }
  }
  const submitOpen = (e) => { e.preventDefault(); setConfirmOpen(true) }
  const confirmOpenNow = () => open.mutate(Number(openDays), { onSettled: () => setConfirmOpen(false) })
  const submitExtend = (e) => { e.preventDefault(); setDone(false); extend.mutate(Number(extendDays), { onSuccess: () => setDone(true) }) }
  return (
    <Card title={t('superAdmin.exit.title')}>
      {isError && <Message>{t('superAdmin.genericError')}</Message>}
      {steps.length > 0 && (
        <ul className="divide-y text-sm">
          {steps.map((s) => (
            <li key={s.step} className="space-y-1 py-2">
              <div className="flex flex-wrap items-center justify-between gap-2">
                <span className="min-w-0 break-words font-semibold">{t(`superAdmin.exit.steps.${s.step}`)}</span>
                <span className={`rounded-full px-2 py-0.5 text-xs ${STEP_STYLE[s.status] ?? ''}`}>{t(`superAdmin.exit.status.${s.status}`)}</span>
              </div>
              {(s.status === 'failed' || s.stale) && (
                <>
                  {s.error && <p className="break-words text-xs text-red-700" dir="auto">{s.error}</p>}
                  <button type="button" disabled={retry.isPending} className="rounded border px-3 py-1 text-sm font-semibold disabled:opacity-60" onClick={() => retry.mutate(s.step)}>{t(s.stale && s.status !== 'failed' ? 'superAdmin.exit.retryStuck' : 'superAdmin.exit.retry')}</button>
                </>
              )}
              {s.status === 'done' && (
                <button type="button" className="rounded border px-3 py-1 text-sm font-semibold" onClick={() => download(s.step)}>{t(`superAdmin.exit.download.${s.step}`)}</button>
              )}
            </li>
          ))}
        </ul>
      )}
      {downloadError && <Message>{t('superAdmin.exit.downloadFailed')}</Message>}
      {retry.isError && <Message>{errorOf(retry.error, t('superAdmin.genericError'))}</Message>}
      {inWindow ? (
        <form onSubmit={submitExtend} className="space-y-3">
          <FormField label={t('superAdmin.exit.extendDays')}>
            <input required type="number" min="1" max="365" dir="ltr" className={inputClass} value={extendDays} onChange={(e) => { setDone(false); setExtendDays(e.target.value) }} />
          </FormField>
          {extend.isError && <Message>{errorOf(extend.error, t('superAdmin.genericError'))}</Message>}
          <Message ok>{done && t('superAdmin.exit.extended')}</Message>
          <InteractiveHoverButton disabled={extend.isPending} className="px-4 py-2 font-semibold disabled:opacity-60">{t('superAdmin.exit.extend')}</InteractiveHoverButton>
        </form>
      ) : (
        <form onSubmit={submitOpen} className="space-y-3">
          <p className="text-sm text-slate-600">{t('superAdmin.exit.hint')}</p>
          <FormField label={t('superAdmin.exit.days')}>
            <input required type="number" min="1" max="365" dir="ltr" className={inputClass} value={openDays} onChange={(e) => setOpenDays(e.target.value)} />
          </FormField>
          {open.isError && <Message>{errorOf(open.error, t('superAdmin.genericError'))}</Message>}
          <InteractiveHoverButton disabled={open.isPending || preparing} className="px-4 py-2 font-semibold disabled:opacity-60">{t('superAdmin.exit.open')}</InteractiveHoverButton>
        </form>
      )}
      <ConfirmDialog open={confirmOpen} danger busy={open.isPending} message={t('superAdmin.exit.openQuestion')} description={t('superAdmin.exit.openDescription')} confirmLabel={t('superAdmin.exit.openConfirm')} onConfirm={confirmOpenNow} onCancel={() => setConfirmOpen(false)} />
    </Card>
  )
}

function AuditCard({ code }) {
  const { t, i18n } = useTranslation()
  const { data, isLoading } = useSchoolAudit(code)
  return (
    <Card title={t('superAdmin.audit.title')}>
      {isLoading && <p className="text-slate-500">{t('common.loading')}</p>}
      {data?.length === 0 && <p className="text-slate-500">{t('superAdmin.audit.none')}</p>}
      <ul className="divide-y text-sm">
        {data?.map((a) => (
          <li key={a.id} className="flex flex-wrap items-baseline justify-between gap-x-3 gap-y-1 py-2">
            <span className="min-w-0 break-words font-semibold">
              {t(`superAdmin.audit.actions.${a.action.replace('.', '_')}`, { defaultValue: a.action })}
              {a.action === 'school.extend' && a.meta?.to && <span className="font-normal text-slate-600" dir="ltr"> {a.meta.from ?? '—'} → {a.meta.to}</span>}
            </span>
            <span className="text-xs text-slate-500">{a.actor ?? '—'} · {formatWhen(a.created_at, i18n.language)}</span>
          </li>
        ))}
      </ul>
    </Card>
  )
}

// One school: its numbers, the editable fields, Extend, Suspend / Reactivate (with a confirmation) and the owner's audit of it.
export default function SchoolPage() {
  const { t } = useTranslation()
  const { id } = useParams()
  const { data: school, isLoading, isError } = useSchoolByCode(id)

  return (
    <section className="space-y-4">
      <Link to="/super-admin/schools" className="text-sm underline">{t('superAdmin.backToSchools')}</Link>
      {isLoading && <p className="text-slate-500">{t('common.loading')}</p>}
      {isError && <p role="alert" className="text-red-700">{t('superAdmin.genericError')}</p>}
      {school === null && <p role="alert" className="text-red-700">{t('superAdmin.notFound')}</p>}
      {school && (
        <>
          <h1 className="break-words text-xl font-bold">{school.name} <span className="text-sm font-normal text-slate-500" dir="ltr">{school.id}</span></h1>
          <div className="grid gap-4 md:grid-cols-2">
            <StatusCard school={school} />
            <ExtendCard school={school} />
            <EditCard school={school} />
            <ExitCard school={school} />
            <AuditCard code={school.id} />
          </div>
        </>
      )}
    </section>
  )
}
