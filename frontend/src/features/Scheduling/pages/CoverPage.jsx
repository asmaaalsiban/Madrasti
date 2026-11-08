import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { api } from '../../../api/client'
import { DateInput } from '../../../components/shared/DateInput'

const today = () => new Date().toISOString().slice(0, 10)
const errorOf = (err, fallback) => Object.values(err.response?.data?.errors ?? {})[0]?.[0] || err.response?.data?.message || fallback

// A teacher is away: their lessons that day, and for each one the free teachers who could cover it, the best first.
export default function CoverPage() {
  const { t, i18n } = useTranslation()
  const client = useQueryClient()
  const lang = i18n.language
  const [date, setDate] = useState(today())
  const [teacherId, setTeacherId] = useState('')
  const [error, setError] = useState('')
  const teachers = useQuery({ queryKey: ['cover', 'teachers', date], queryFn: async () => (await api.get('/cover/teachers', { params: { date } })).data.data })
  const plan = useQuery({
    queryKey: ['cover', 'plan', teacherId, date], enabled: Boolean(teacherId && date),
    queryFn: async () => (await api.get('/cover/plan', { params: { teacher_id: teacherId, date } })).data.data,
  })
  const refresh = () => client.invalidateQueries({ queryKey: ['cover', 'plan'] })
  const choose = useMutation({
    mutationFn: ({ lesson, teacher }) => api.post('/cover', { date, period_id: lesson.period.id, classroom_id: lesson.classroom.id, cover_teacher_id: teacher.id }),
    onSuccess: () => { setError(''); refresh() },
    onError: (err) => { setError(errorOf(err, t('admin.actions.error'))); refresh() },
  })
  const cancel = useMutation({ mutationFn: (id) => api.delete(`/cover/${id}`), onSuccess: refresh })
  const away = (teachers.data ?? []).filter((x) => x.away)
  const subject = (s) => s[`name_${lang}`] || s.name_ar

  return (
    <section className="space-y-4">
      <h1 className="text-xl font-bold">{t('cover.title')}</h1>
      <p className="text-sm text-slate-600">{t('cover.intro')}</p>
      <div className="flex flex-wrap items-end gap-3">
        <label className="text-sm">{t('cover.date')}<DateInput className="mt-1 block rounded border p-1.5" value={date} onChange={(e) => { setDate(e.target.value); setTeacherId('') }} /></label>
        <label className="text-sm">{t('cover.absentTeacher')}
          <select className="mt-1 block min-w-56 rounded border p-1.5" value={teacherId} onChange={(e) => setTeacherId(e.target.value)}>
            <option value="">—</option>
            {(teachers.data ?? []).map((x) => <option key={x.id} value={x.id}>{x.name}{x.away ? ` · ${t(`cover.away.${x.away}`)}` : ''}</option>)}
          </select>
        </label>
      </div>
      {away.length > 0 && !teacherId && (
        <p className="text-sm">{t('cover.awayToday')} {away.map((x, i) => <button key={x.id} type="button" className="me-2 text-brand underline" onClick={() => setTeacherId(String(x.id))}>{x.name}{i < away.length - 1 ? '،' : ''}</button>)}</p>
      )}
      {error && <p role="alert" className="rounded bg-red-50 p-2 text-sm text-red-700">{error}</p>}
      {teacherId && (!plan.data ? <p className="text-slate-500">{t('common.loading')}</p> : plan.data.length === 0 ? <p className="rounded-lg border bg-white p-6 text-center text-slate-500">{t('cover.noLessons')}</p> : (
        <ul className="space-y-3">
          {plan.data.map((l) => (
            <li key={`${l.period.id}-${l.classroom.id}`} className="space-y-2 rounded-lg border bg-white p-3">
              <div className="flex flex-wrap items-center justify-between gap-2">
                <p className="font-bold">{t('cover.period', { n: l.period.number })} <span dir="ltr" className="text-sm font-normal text-slate-500">{l.period.starts_at}–{l.period.ends_at}</span> · {l.classroom.name} · {subject(l.subject)}</p>
                {l.cover && (
                  <span className="flex items-center gap-3 rounded-full bg-green-50 px-3 py-1 text-sm text-green-800">
                    {t('cover.coveredBy', { name: l.cover.teacher.name })}
                    <button type="button" className="text-red-700 underline" onClick={() => cancel.mutate(l.cover.id)}>{t('cover.cancel')}</button>
                  </span>
                )}
              </div>
              {!l.cover && (l.suggestions.length === 0 ? <p className="text-sm text-amber-700">{t('cover.nobody')}</p> : (
                <ul className="grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
                  {l.suggestions.map((s) => (
                    <li key={s.teacher.id} className="flex items-center justify-between gap-2 rounded border p-2 text-sm">
                      <span>
                        <b>{s.teacher.name}</b>
                        <span className="block text-xs text-slate-500">
                          {s.teaches_subject ? t('cover.teachesSubject') : s.teaches_grade ? t('cover.teachesGrade') : t('cover.otherGrade')} · {t('cover.lessonsToday', { n: s.lessons_today })}{s.covers_today > 0 ? ` · ${t('cover.coversToday', { n: s.covers_today })}` : ''}
                        </span>
                      </span>
                      <button type="button" disabled={choose.isPending} className="shrink-0 rounded bg-brand px-3 py-1 text-white disabled:opacity-50" aria-label={`${t('cover.choose')} ${s.teacher.name}`} onClick={() => choose.mutate({ lesson: l, teacher: s.teacher })}>{t('cover.choose')}</button>
                    </li>
                  ))}
                </ul>
              ))}
            </li>
          ))}
        </ul>
      ))}
    </section>
  )
}
