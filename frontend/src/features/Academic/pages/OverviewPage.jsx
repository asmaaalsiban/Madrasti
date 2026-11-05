import { useQuery } from '@tanstack/react-query'
import useBadges from '../../Badges/useBadges'
import { Link } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { api } from '../../../api/client'
import { Bars, Columns, LineChart, Ring } from '../components/charts'
import { levelColor } from '../components/levelColor'

const CARDS = [
  ['academic_years', 'admin.nav.academicYears'],
  ['grade_levels', 'admin.nav.gradeLevels'],
  ['classrooms', 'admin.nav.classrooms'],
  ['subjects', 'admin.nav.subjects'],
]

const Panel = ({ title, children, className = '' }) => (
  <section className={`space-y-3 rounded-lg border bg-white p-4 shadow-sm ${className}`}>
    <h2 className="text-sm font-bold text-slate-700">{title}</h2>
    {children}
  </section>
)

const rate = (c) => {
  const total = c.present + c.late + c.absent + c.excused
  return total ? ((c.present + c.late) / total) * 100 : null
}

// The principal's home: today at a glance, the last two weeks, the money, and what waits for a decision.
export default function OverviewPage() {
  const { t, i18n } = useTranslation()
  const pending = { data: useBadges().surveys }
  const { data } = useQuery({ queryKey: ['admin-overview'], queryFn: () => api.get('/admin/overview').then((r) => r.data.data) })
  const { data: d } = useQuery({ queryKey: ['admin-dashboard'], queryFn: () => api.get('/admin/dashboard').then((r) => r.data.data) })
  const name = (o) => o[`name_${i18n.language}`] || o.name_ar
  const money = (v) => Number(v).toLocaleString(i18n.language, { maximumFractionDigits: 0 })
  const kids = d ? rate(d.students_today) : null
  const staff = d ? rate(d.staff_today) : null
  const waiting = d ? [['/admin/procurement', 'dashboard.waiting.procurement', d.waiting.procurement], ['/admin/privacy-requests', 'dashboard.waiting.privacy', d.waiting.privacy]].filter((w) => w[2] > 0) : []
  const fin = d?.finance
  const paidPct = fin && fin.billed > 0 ? (fin.collected / fin.billed) * 100 : 0
  return (
    <section className="space-y-4">
      <h1 className="text-xl font-bold">{t('academic.overview.title')}</h1>
      {pending.data > 0 && <Link to="/admin/my-surveys" className="block rounded-lg border border-brand bg-blue-50 p-4 font-medium text-brand">{t('surveys.waiting', { count: pending.data })}</Link>}

      <div className="grid grid-cols-2 gap-4 lg:grid-cols-4">
        {CARDS.map(([key, label]) => (
          <div key={key} className="rounded-lg border-t-4 border-brand bg-white p-4 shadow-sm">
            <p className="text-3xl font-bold">{data?.[key] ?? '–'}</p>
            <p className="mt-1 text-sm text-slate-600">{t(label)}</p>
          </div>
        ))}
      </div>

      {!d ? <p className="text-slate-500">{t('common.loading')}</p> : (
        <>
          <div className="grid gap-4 md:grid-cols-2 lg:grid-cols-3">
            <Panel title={t('dashboard.students.title')}>
              <div className="flex items-center gap-4">
                {kids === null ? <p className="text-sm text-slate-500">{t('dashboard.notTaken')}</p> : <Ring percent={kids} label={t('dashboard.students.title')} color={levelColor(kids)} />}
                <ul className="space-y-1 text-sm">
                  <li>{t('dashboard.total', { count: d.people.students })}</li>
                  {['present', 'late', 'absent', 'excused'].map((k) => <li key={k} className="text-slate-600">{t(k === 'excused' ? 'dashboard.excused' : `attendance.${k}`)}: <b dir="ltr">{d.students_today[k]}</b></li>)}
                </ul>
              </div>
            </Panel>
            <Panel title={t('dashboard.staff.title')}>
              <div className="flex items-center gap-4">
                {staff === null ? <p className="text-sm text-slate-500">{t('dashboard.notTaken')}</p> : <Ring percent={staff} label={t('dashboard.staff.title')} color={levelColor(staff)} />}
                <ul className="space-y-1 text-sm">
                  <li>{t('dashboard.teachers', { count: d.people.teachers })}</li>
                  {['present', 'late', 'absent'].map((k) => <li key={k} className="text-slate-600">{t(`staffAttendance.status.${k}`)}: <b dir="ltr">{d.staff_today[k]}</b></li>)}
                </ul>
              </div>
            </Panel>
            <Panel title={t('dashboard.waiting.title')}>
              {waiting.length === 0 ? <p className="text-sm text-slate-500">{t('dashboard.waiting.none')}</p> : (
                <ul className="space-y-2">
                  {waiting.map(([to, label, count]) => <li key={to}><Link to={to} className="flex items-center justify-between rounded border border-amber-300 bg-amber-50 px-3 py-2 text-sm text-amber-900"><span>{t(label)}</span><b dir="ltr">{count}</b></Link></li>)}
                </ul>
              )}
            </Panel>
          </div>

          <div className="grid gap-4 lg:grid-cols-2">
            <Panel title={t('dashboard.trend')}>
              {d.attendance_trend.length === 0 ? <p className="text-sm text-slate-500">{t('dashboard.notTaken')}</p> : <LineChart label={t('dashboard.trend')} points={d.attendance_trend.map((p) => ({ label: p.date, value: p.rate }))} />}
            </Panel>
            <Panel title={t('dashboard.money.title')}>
              <div className="flex flex-wrap items-center gap-4">
                <Ring percent={paidPct} label={t('dashboard.money.collectedOf')} color={levelColor(paidPct)} />
                <ul className="space-y-1 text-sm">
                  <li>{t('dashboard.money.billed')}: <b dir="ltr">{money(fin.billed)} {fin.currency}</b></li>
                  <li>{t('dashboard.money.collected')}: <b dir="ltr">{money(fin.collected)} {fin.currency}</b></li>
                  <li className={fin.overdue > 0 ? 'text-red-700' : 'text-slate-600'}>{t('dashboard.money.overdue')}: <b dir="ltr">{money(fin.overdue)} {fin.currency}</b></li>
                </ul>
              </div>
              <Columns label={t('dashboard.money.monthly')} items={fin.monthly.map((m) => ({ label: m.month.slice(5), value: m.amount }))} format={money} />
            </Panel>
          </div>

          <div className="grid gap-4 lg:grid-cols-3">
            <Panel title={t('dashboard.byLevel')}>
              {d.students_by_level.length === 0 ? <p className="text-sm text-slate-500">{t('dashboard.noData')}</p> : <Bars label={t('dashboard.byLevel')} items={d.students_by_level.map((l) => ({ label: name(l), value: l.count }))} />}
            </Panel>
            <Panel title={t('dashboard.marks')}>
              {d.marks_by_subject.length === 0 ? <p className="text-sm text-slate-500">{t('dashboard.noMarks')}</p> : <Bars label={t('dashboard.marks')} suffix="%" items={d.marks_by_subject.map((s) => ({ label: name(s), value: s.average, max: 100, color: levelColor(s.average) }))} />}
            </Panel>
            <Panel title={t('dashboard.absences.title')}>
              {d.frequent_absences.length === 0 ? <p className="text-sm text-slate-500">{t('dashboard.absences.none')}</p> : (
                <ul className="space-y-2 text-sm">
                  {d.frequent_absences.map((s) => <li key={s.id} className="flex justify-between rounded bg-red-50 px-3 py-2 text-red-800"><bdi>{name(s)}</bdi><b>{t('dashboard.absences.days', { count: s.days })}</b></li>)}
                </ul>
              )}
            </Panel>
          </div>

          <nav aria-label={t('dashboard.quick.title')} className="flex flex-wrap gap-2">
            {[['/admin/students', 'dashboard.quick.student'], ['/admin/announcements', 'dashboard.quick.announce'], ['/admin/import', 'dashboard.quick.import']].map(([to, label]) => <Link key={to} to={to} className="rounded-full border border-brand px-4 py-1.5 text-sm text-brand hover:bg-blue-50">{t(label)}</Link>)}
          </nav>
        </>
      )}
    </section>
  )
}
