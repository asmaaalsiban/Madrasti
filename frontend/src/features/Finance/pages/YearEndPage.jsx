import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { Link } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { api } from '../../../api/client'
import ConfirmDialog from '../../../components/shared/ConfirmDialog'
import { DateInput } from '../../../components/shared/DateInput'
import Money from '../components/Money'

const errorOf = (err, fallback) => Object.values(err.response?.data?.errors ?? {})[0]?.[0] || err.response?.data?.message || fallback

// Year-end: who still owes for the year that closes, and carrying that balance to the next year in installments.
// The old year is then closed (nothing left to pay), the new year shows the balance, and every move can be undone until a payment is taken on it.
export default function YearEndPage({ base }) {
  const { t, i18n } = useTranslation()
  const client = useQueryClient()
  const [from, setFrom] = useState(null)
  const [toPicked, setToPicked] = useState('')
  const [duePicked, setDuePicked] = useState('')
  const [count, setCount] = useState(1)
  const [skipMap, setSkipMap] = useState({}) // the students left out of the move, per year being closed
  const [confirm, setConfirm] = useState(false)
  const [undoing, setUndoing] = useState(null)
  const [message, setMessage] = useState(null)

  const { data } = useQuery({ queryKey: ['finance', 'year-end', from], queryFn: async () => (await api.get('/finance/year-end', { params: from ? { from } : {} })).data.data })
  const years = data?.years ?? []
  const nameOf = (s) => s?.[`name_${i18n.language}`] || s?.name_ar
  const sourceId = from ?? data?.from_year_id
  // The default target is the year that starts right after the one being closed.
  const nextYear = years.filter((y) => y.starts_on > (years.find((x) => x.id === sourceId)?.starts_on ?? '9999')).sort((a, b) => a.starts_on.localeCompare(b.starts_on))[0]
  // Until the person chooses, the new year is the one that follows and the first installment falls on its first day.
  const to = toPicked || (nextYear ? String(nextYear.id) : '')
  const firstDue = duePicked || (years.find((y) => String(y.id) === to)?.starts_on ?? '')
  const skipped = new Set(skipMap[sourceId] ?? [])

  const chosen = (data?.students ?? []).filter((s) => !skipped.has(s.student_id))
  const total = chosen.reduce((sum, s) => sum + Number(s.remaining), 0)
  const toggle = (id) => setSkipMap((prev) => { const next = new Set(prev[sourceId] ?? []); if (next.has(id)) next.delete(id); else next.add(id); return { ...prev, [sourceId]: [...next] } })
  const done = () => { client.invalidateQueries({ queryKey: ['finance'] }) }

  const run = useMutation({
    mutationFn: () => api.post('/finance/year-end', { from_year_id: sourceId, to_year_id: Number(to), first_due: firstDue, installments: Number(count), student_ids: chosen.map((s) => s.student_id) }),
    onSuccess: (res) => { setMessage({ ok: true, text: t('yearEnd.done', { count: res.data.data.carried }) }); done() },
    onError: (err) => setMessage({ ok: false, text: errorOf(err, t('admin.actions.error')) }),
    onSettled: () => setConfirm(false),
  })
  const undo = useMutation({
    mutationFn: (id) => api.post(`/finance/year-end/${id}/undo`),
    onSuccess: () => { setMessage({ ok: true, text: t('yearEnd.undone') }); done() },
    onError: (err) => setMessage({ ok: false, text: errorOf(err, t('admin.actions.error')) }),
    onSettled: () => setUndoing(null),
  })

  const c = data?.currency
  const canRun = data?.can_manage && chosen.length > 0 && to && firstDue && String(sourceId) !== String(to)
  return (
    <section className="space-y-5">
      <h1 className="text-xl font-bold">{t('yearEnd.title')}</h1>
      <p className="max-w-3xl text-sm text-slate-600">{t('yearEnd.help')}</p>
      {message && <p role={message.ok ? 'status' : 'alert'} className={`rounded p-3 text-sm ${message.ok ? 'bg-green-50 text-green-800' : 'bg-red-50 text-red-700'}`}>{message.text}</p>}

      <div className="grid gap-3 rounded-lg border bg-white p-4 sm:grid-cols-2 lg:grid-cols-4">
        <label className="block text-sm">{t('yearEnd.from')}
          <select className="mt-1 w-full rounded border p-2" value={sourceId ?? ''} onChange={(e) => { setFrom(Number(e.target.value)); setToPicked(''); setDuePicked('') }}>
            {years.map((y) => <option key={y.id} value={y.id}>{y.name}</option>)}
          </select>
        </label>
        <label className="block text-sm">{t('yearEnd.to')}
          <select className="mt-1 w-full rounded border p-2" value={to} onChange={(e) => { setToPicked(e.target.value); setDuePicked('') }}>
            <option value="">—</option>
            {years.filter((y) => y.id !== sourceId).map((y) => <option key={y.id} value={y.id}>{y.name}</option>)}
          </select>
        </label>
        <label className="block text-sm">{t('yearEnd.firstDue')}<DateInput className="mt-1 w-full rounded border p-2" value={firstDue} onChange={(e) => setDuePicked(e.target.value)} /></label>
        <label className="block text-sm">{t('yearEnd.installments')}<input type="number" min="1" max="12" className="mt-1 w-full rounded border p-2" value={count} onChange={(e) => setCount(e.target.value)} /></label>
      </div>

      <div className="space-y-2">
        <div className="flex flex-wrap items-center justify-between gap-2">
          <h2 className="text-lg">{t('yearEnd.owing')} <span className="text-sm font-normal text-slate-500">({chosen.length})</span></h2>
          <p className="font-bold">{t('yearEnd.total')}: <Money value={total.toFixed(2)} currency={c} /></p>
        </div>
        {!data ? <p className="text-slate-500">{t('common.loading')}</p> : data.students.length === 0 ? <p className="rounded-lg border bg-white p-6 text-center text-slate-500">{t('yearEnd.nobody')}</p> : (
          <div className="overflow-x-auto rounded-lg border bg-white">
            <table className="w-full text-start text-sm">
              <thead className="bg-slate-50"><tr><th className="p-2"><span className="sr-only">{t('yearEnd.include')}</span></th>{['number', 'student', 'remaining', 'overdue'].map((k) => <th key={k} className="p-2 text-start font-medium">{t(`yearEnd.cols.${k}`)}</th>)}</tr></thead>
              <tbody>
                {data.students.map((s) => (
                  <tr key={s.student_id} className="border-t">
                    <td className="p-2"><input type="checkbox" aria-label={t('yearEnd.includeFor', { name: nameOf(s) })} checked={!skipped.has(s.student_id)} onChange={() => toggle(s.student_id)} disabled={!data.can_manage} /></td>
                    <td className="p-2" dir="ltr">{s.student_number}</td>
                    <td className="p-2 font-medium"><Link className="text-brand underline" to={`${base}/finance/students/${s.student_id}`}><bdi>{nameOf(s)}</bdi></Link></td>
                    <td className="p-2"><Money value={s.remaining} currency={c} /></td>
                    <td className={`p-2 ${Number(s.overdue) > 0 ? 'text-red-700' : ''}`}><Money value={s.overdue} currency={c} /></td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
        {data?.can_manage ? (
          <button type="button" disabled={!canRun || run.isPending} onClick={() => setConfirm(true)} className="cta bg-brand text-white disabled:opacity-50">{t('yearEnd.carry', { count: chosen.length })}</button>
        ) : <p className="text-sm text-slate-500">{t('yearEnd.readOnly')}</p>}
      </div>

      <div className="space-y-2">
        <h2 className="text-lg">{t('yearEnd.history')}</h2>
        {(data?.moves ?? []).length === 0 ? <p className="text-sm text-slate-500">{t('yearEnd.noMoves')}</p> : (
          <ul className="divide-y rounded-lg border bg-white text-sm">
            {data.moves.map((m) => (
              <li key={m.id} className={`flex flex-wrap items-center justify-between gap-2 p-3 ${m.reverted ? 'text-slate-400 line-through' : ''}`}>
                <span><bdi className="font-medium">{nameOf(m.student)}</bdi> · <span dir="ltr">{m.from} → {m.to}</span> · <Money value={m.amount} currency={c} /> · <span dir="ltr">{m.date}</span></span>
                {m.reverted ? <span className="rounded-full bg-slate-100 px-2 py-0.5 text-xs no-underline">{t('yearEnd.reverted')}</span>
                  : data.can_manage && <button type="button" disabled={!m.can_undo} title={m.can_undo ? '' : t('yearEnd.cannotUndo')} onClick={() => setUndoing(m)} className="rounded border px-3 py-1 text-red-700 disabled:opacity-40">{t('yearEnd.undo')}</button>}
              </li>
            ))}
          </ul>
        )}
      </div>

      <ConfirmDialog open={confirm} danger={false} message={t('yearEnd.confirm', { count: chosen.length, total: total.toFixed(2), from: years.find((y) => y.id === sourceId)?.name, to: years.find((y) => String(y.id) === String(to))?.name })} confirmLabel={t('yearEnd.carryShort')} onConfirm={() => run.mutate()} onCancel={() => setConfirm(false)} />
      <ConfirmDialog open={undoing !== null} message={t('yearEnd.confirmUndo', { name: nameOf(undoing?.student) })} confirmLabel={t('yearEnd.undo')} onConfirm={() => undo.mutate(undoing.id)} onCancel={() => setUndoing(null)} />
    </section>
  )
}
