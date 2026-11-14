import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useEffect, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { api } from '../../../api/client'
import FormField from '../../../components/shared/FormField'
import { InteractiveHoverButton } from '../../../components/ui/interactive-hover-button'

const inputClass = 'w-full rounded border p-2'
const pad = (n) => String(n).padStart(2, '0')
const clock = (minutes) => `${pad(Math.floor(minutes / 60))}:${pad(minutes % 60)}`

// The times of one school day, worked out like the server does (so the admin sees them before saving).
function bells(form) {
  const [h, m] = (form.starts_at || '00:00').split(':').map(Number)
  const rows = []
  let minute = h * 60 + m
  for (let n = 1; n <= Number(form.periods_per_day || 0); n++) {
    const end = minute + Number(form.period_minutes || 0)
    rows.push({ number: n, text: `${clock(minute)} - ${clock(end)}` })
    minute = end + (form.long_break_after !== '' && Number(form.long_break_after) === n ? Number(form.long_break_minutes || 0) : Number(form.gap_minutes || 0))
  }
  return rows
}

// The bells of the school week: school days, periods a day, their length and the breaks.
export default function SchoolDayPage() {
  const { t, i18n } = useTranslation()
  const client = useQueryClient()
  const [form, setForm] = useState(null)
  const [errors, setErrors] = useState({})
  const [message, setMessage] = useState('')
  const [formError, setFormError] = useState('')
  const [gradePeriods, setGradePeriods] = useState(null) // gradeId -> text
  const [gradeMessage, setGradeMessage] = useState('')
  const [gradeError, setGradeError] = useState('')

  const { data } = useQuery({ queryKey: ['periods'], queryFn: async () => (await api.get('/periods')).data.data })
  useEffect(() => {
    if (!data || form) return
    setGradePeriods(Object.fromEntries((data.grades ?? []).map((g) => [g.id, g.periods_per_day === null ? '' : String(g.periods_per_day)])))
    const s = data.setup
    setForm({ ...s, long_break_after: s.long_break_after ?? '', long_break_minutes: s.long_break_minutes ?? '' })
  }, [data, form])

  const save = useMutation({
    mutationFn: () => api.put('/periods/setup', {
      days: [...form.days].sort((a, b) => a - b), periods_per_day: Number(form.periods_per_day), starts_at: form.starts_at,
      period_minutes: Number(form.period_minutes), gap_minutes: Number(form.gap_minutes),
      long_break_after: form.long_break_after === '' ? null : Number(form.long_break_after),
      long_break_minutes: form.long_break_after === '' ? null : Number(form.long_break_minutes),
    }),
    onSuccess: () => { setErrors({}); setFormError(''); setMessage(t('schoolDay.saved')); client.invalidateQueries({ queryKey: ['periods'] }); client.invalidateQueries({ queryKey: ['timetable'] }) },
    onError: (err) => {
      setMessage('')
      const fieldErrors = err.response?.data?.errors
      setErrors(fieldErrors ?? {})
      setFormError(fieldErrors ? '' : err.response?.data?.message || t('admin.actions.error'))
    },
  })

  const saveGrades = useMutation({
    mutationFn: () => api.put('/periods/grades', { grades: data.grades.map((g) => ({ grade_level_id: g.id, periods_per_day: gradePeriods[g.id] === '' ? null : Number(gradePeriods[g.id]) })) }),
    onSuccess: () => { setGradeError(''); setGradeMessage(t('schoolDay.gradesSaved')); client.invalidateQueries({ queryKey: ['periods'] }); client.invalidateQueries({ queryKey: ['curriculum'] }) },
    onError: (err) => { setGradeMessage(''); setGradeError(Object.values(err.response?.data?.errors ?? {})[0]?.[0] || err.response?.data?.message || t('admin.actions.error')) },
  })

  if (!form || !gradePeriods) return <p className="text-slate-500">{t('common.loading')}</p>

  const set = (name) => (e) => { setMessage(''); setForm({ ...form, [name]: e.target.value }) }
  const toggle = (day) => (e) => { setMessage(''); setForm({ ...form, days: e.target.checked ? [...form.days, day] : form.days.filter((d) => d !== day) }) }

  return (
    <section className="space-y-4">
      <h1 className="text-xl font-bold">{t('schoolDay.title')}</h1>
      <p className="text-sm text-slate-600">{t('schoolDay.help')}</p>
      {message && <p role="status" className="rounded bg-green-50 p-3 text-green-800">{message}</p>}
      {formError && <p role="alert" className="rounded bg-red-50 p-3 text-red-700">{formError}</p>}

      <div className="grid gap-6 md:grid-cols-2">
        <form onSubmit={(e) => { e.preventDefault(); save.mutate() }} className="space-y-3 rounded-lg bg-white p-4 shadow-sm">
          <fieldset>
            <legend className="mb-1 text-sm">{t('schoolDay.days')}</legend>
            <div className="flex flex-wrap gap-3 text-sm">
              {[0, 1, 2, 3, 4, 5, 6].map((d) => (
                <label key={d} className="flex items-center gap-1.5">
                  <input type="checkbox" checked={form.days.includes(d)} onChange={toggle(d)} />
                  {t(`days.${d}`)}
                </label>
              ))}
            </div>
            {errors.days && <span role="alert" className="block text-sm text-red-600">{errors.days[0]}</span>}
          </fieldset>
          <FormField label={t('schoolDay.periodsPerDay')} error={errors.periods_per_day?.[0]}>
            <input className={inputClass} type="number" min="1" max="12" dir="ltr" value={form.periods_per_day} onChange={set('periods_per_day')} />
          </FormField>
          <FormField label={t('schoolDay.startsAt')} error={errors.starts_at?.[0]}>
            <input className={inputClass} type="time" value={form.starts_at} onChange={set('starts_at')} />
          </FormField>
          <FormField label={t('schoolDay.periodMinutes')} error={errors.period_minutes?.[0]}>
            <input className={inputClass} type="number" min="20" max="120" dir="ltr" value={form.period_minutes} onChange={set('period_minutes')} />
          </FormField>
          <FormField label={t('schoolDay.gapMinutes')} error={errors.gap_minutes?.[0]}>
            <input className={inputClass} type="number" min="0" max="30" dir="ltr" value={form.gap_minutes} onChange={set('gap_minutes')} />
          </FormField>
          <FormField label={t('schoolDay.longBreakAfter')} error={errors.long_break_after?.[0]} hint={t('schoolDay.longBreakHint')}>
            <input className={inputClass} type="number" min="1" dir="ltr" value={form.long_break_after} onChange={set('long_break_after')} />
          </FormField>
          {form.long_break_after !== '' && (
            <FormField label={t('schoolDay.longBreakMinutes')} error={errors.long_break_minutes?.[0]}>
              <input className={inputClass} type="number" min="5" max="90" dir="ltr" value={form.long_break_minutes} onChange={set('long_break_minutes')} />
            </FormField>
          )}
          <InteractiveHoverButton type="submit" disabled={save.isPending} className="px-5 py-2 disabled:opacity-60">{t('admin.actions.save')}</InteractiveHoverButton>
        </form>

        <div className="space-y-4">
        <div className="rounded-lg bg-white p-4 shadow-sm">
          <h2 className="mb-2 font-bold">{t('schoolDay.preview')}</h2>
          <ul className="divide-y text-sm">
            {bells(form).map((b) => (
              <li key={b.number} className="flex justify-between py-1.5">
                <span>{t('schoolDay.period', { number: b.number })}</span>
                <span dir="ltr">{b.text}</span>
              </li>
            ))}
          </ul>
        </div>

        <div className="space-y-3 rounded-lg bg-white p-4 shadow-sm">
          <h2 className="font-bold">{t('schoolDay.gradesTitle')}</h2>
          <p className="text-sm text-slate-600">{t('schoolDay.gradesHelp', { count: form.periods_per_day })}</p>
          {gradeMessage && <p role="status" className="rounded bg-green-50 p-2 text-sm text-green-800">{gradeMessage}</p>}
          {gradeError && <p role="alert" className="rounded bg-red-50 p-2 text-sm text-red-700">{gradeError}</p>}
          <ul className="divide-y text-sm">
            {data.grades.map((g) => (
              <li key={g.id} className="flex items-center justify-between gap-3 py-1.5">
                <label htmlFor={`grade-${g.id}`}>{g[`name_${i18n.language}`] || g.name_ar}</label>
                <input
                  id={`grade-${g.id}`} type="number" min="1" max={form.periods_per_day} dir="ltr" className="w-20 rounded border p-1.5"
                  placeholder={String(form.periods_per_day)} value={gradePeriods[g.id]}
                  onChange={(e) => { setGradeMessage(''); setGradePeriods({ ...gradePeriods, [g.id]: e.target.value }) }}
                />
              </li>
            ))}
          </ul>
          <InteractiveHoverButton variant="outline" type="button" disabled={saveGrades.isPending} className="px-4 py-1.5 disabled:opacity-60" onClick={() => saveGrades.mutate()}>{t('schoolDay.saveGrades')}</InteractiveHoverButton>
        </div>
        </div>
      </div>
    </section>
  )
}
