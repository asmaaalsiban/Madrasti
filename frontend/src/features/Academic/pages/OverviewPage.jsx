import { useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { Link, NavLink } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { BookOpen, CalendarRange, DoorOpen, GraduationCap, Layers, Megaphone, Plus, Upload, UserCheck, Users, Wallet } from 'lucide-react'
import { api } from '../../../api/client'
import useBadges from '../../Badges/useBadges'
import { PageEnter, PillTabs, Reveal, CountUp } from '../../../components/shared/motion'
import useLayout from '../../Layout/useLayout'
import ShortcutsDialog, { CATALOG, DEFAULT_SHORTCUTS } from '../components/ShortcutsDialog'
import { BlurSwap, CardTitle, GlassCard, PillColumns, SegmentGauge, StatPill } from '../components/glass'

const COUNTS = [['academic_years', 'admin.nav.academicYears', CalendarRange], ['grade_levels', 'admin.nav.gradeLevels', Layers], ['classrooms', 'admin.nav.classrooms', DoorOpen], ['subjects', 'admin.nav.subjects', BookOpen]]

const rate = (c) => {
  const total = c.present + c.late + c.absent + c.excused
  return total ? ((c.present + c.late) / total) * 100 : null
}

// The principal's home, on a dark glass stage: a pill menu, the numbers that matter as pills, a gauge for today's attendance,
// an analytics card with tabs, and what waits for a decision. Everything blurs in, counts up and grows (still for reduced motion).
export default function OverviewPage() {
  const { t, i18n } = useTranslation()
  const [tab, setTab] = useState('money')
  const [editing, setEditing] = useState(false)
  // The shortcuts of the pill menu are the principal's own (saved on his account): the overview is always first, the rest he chooses.
  const shortcuts = useLayout('admin_shortcuts')
  const paths = shortcuts.custom ? shortcuts.items : DEFAULT_SHORTCUTS
  const nav = paths.map((p) => CATALOG.find((c) => c.path === p)).filter(Boolean)
  const surveys = useBadges().surveys
  const { data } = useQuery({ queryKey: ['admin-overview'], queryFn: () => api.get('/admin/overview').then((r) => r.data.data) })
  const { data: d } = useQuery({ queryKey: ['admin-dashboard'], queryFn: () => api.get('/admin/dashboard').then((r) => r.data.data) })
  const ready = Boolean(d)
  const name = (o) => o[`name_${i18n.language}`] || o.name_ar
  const money = (v) => Number(v).toLocaleString(i18n.language, { maximumFractionDigits: 0 })
  const kids = d ? rate(d.students_today) : null
  const staff = d ? rate(d.staff_today) : null
  const fin = d?.finance
  const paidPct = fin && fin.billed > 0 ? (fin.collected / fin.billed) * 100 : 0
  const waiting = d ? [['/admin/procurement', 'dashboard.waiting.procurement', d.waiting.procurement], ['/admin/privacy-requests', 'dashboard.waiting.privacy', d.waiting.privacy]].filter((w) => w[2] > 0) : []

  // The analytics card: one chart per tab.
  const charts = d && {
    money: { label: t('dashboard.money.monthly'), items: fin.monthly.map((m) => ({ label: m.month.slice(5), value: m.amount })), format: money },
    attendance: { label: t('dashboard.trend'), items: d.attendance_trend.map((p) => ({ label: p.date.slice(8), value: p.rate })), format: (v) => `${v}%`, maxValue: 100 },
    levels: { label: t('dashboard.byLevel'), items: d.students_by_level.map((l) => ({ label: name(l), value: l.count })), format: (v) => v },
    marks: { label: t('dashboard.marks'), items: d.marks_by_subject.map((s) => ({ label: name(s), value: s.average })), format: (v) => `${v}%`, maxValue: 100 },
  }
  const chart = charts?.[tab]
  const none = { money: 'dashboard.noData', attendance: 'dashboard.notTaken', levels: 'dashboard.noData', marks: 'dashboard.noMarks' }[tab]

  return (
    <PageEnter>
      <section className="dash-stage min-h-[calc(100vh-7rem)] space-y-4">
        <div className="lp-aurora" aria-hidden><i /><i /><i /></div>

        {/* The pill menu: the sections the principal opens most. */}
        <Reveal instant>
          <nav aria-label={t('academic.overview.title')} className="flex items-center gap-2 rounded-full bg-white/10 p-1.5 backdrop-blur">
            <ul className="flex min-w-0 flex-1 gap-1 overflow-x-auto">
              {[{ path: '/admin', key: 'overview', end: true }, ...nav].map(({ path, key, end }) => (
                <li key={path}>
                  <NavLink to={path} end={end} className={({ isActive }) => `block whitespace-nowrap rounded-full px-4 py-1.5 text-sm transition-colors ${isActive ? 'bg-sky-tint font-bold text-deep-harbor' : 'text-white/75 hover:bg-white/10 hover:text-white'}`}>{t(`admin.nav.${key}`)}</NavLink>
                </li>
              ))}
            </ul>
            <button type="button" aria-label={t('dashboard.shortcuts.edit')} title={t('dashboard.shortcuts.edit')} onClick={() => setEditing(true)} className="grid h-9 w-9 shrink-0 place-items-center rounded-full bg-white text-deep-harbor transition-transform hover:scale-110"><Plus size={18} aria-hidden /></button>
          </nav>
        </Reveal>

        {/* The title and the pills with the numbers that matter. */}
        <div className="flex flex-wrap items-end justify-between gap-4">
          <Reveal instant>
            <h1 className="text-3xl font-extrabold !text-white">{t('academic.overview.title')}</h1>
            <p className="mt-1 text-sm text-white/60">{t('dashboard.glass.subtitle')}</p>
          </Reveal>
          <div className="flex flex-wrap gap-2">
            <StatPill instant icon={GraduationCap} value={kids === null ? '–' : Math.round(kids)} suffix="%" label={t('dashboard.students.title')} />
            <StatPill instant icon={Wallet} value={fin ? Math.round(paidPct) : '–'} suffix="%" label={t('dashboard.money.collectedOf')} />
            <StatPill instant icon={Users} value={d ? d.people.students : '–'} label={t('dashboard.glass.students')} />
          </div>
        </div>

        {surveys > 0 && <Link to="/admin/my-surveys" className="block rounded-2xl bg-white/90 p-3 font-bold text-deep-harbor">{t('surveys.waiting', { count: surveys })}</Link>}

        <div className="grid gap-4 lg:grid-cols-3">
          {/* At a glance (tall, like the spotlight card). */}
          <GlassCard i={5} ready={ready} className="lg:row-span-2">
            <CardTitle>{t('dashboard.glass.glance')}</CardTitle>
            <div className="mb-4 flex items-center gap-3">
              <span aria-hidden className="grid h-14 w-14 place-items-center rounded-2xl bg-sky-tint text-deep-harbor"><UserCheck size={26} /></span>
              <div><p className="text-sm text-white/60">{t('dashboard.glass.teachersLabel')}</p><p className="text-lg font-bold text-white">{t('dashboard.teachers', { count: d?.people.teachers ?? 0 })}</p></div>
            </div>
            {d && (
              <ul className="divide-y divide-white/10 text-sm">
                <li className="flex justify-between py-2"><span className="text-white/60">{t('dashboard.total', { count: d.people.students })}</span></li>
                {['present', 'late', 'absent', 'excused'].map((k) => <li key={k} className="flex justify-between py-2"><span className="text-white/60">{t(k === 'excused' ? 'dashboard.excused' : `attendance.${k}`)}</span><b dir="ltr"><CountUp value={d.students_today[k]} /></b></li>)}
                {['present', 'late', 'absent'].map((k) => <li key={`s${k}`} className="flex justify-between py-2"><span className="text-white/60">{t('dashboard.glass.staffLine', { status: t(`staffAttendance.status.${k}`) })}</span><b dir="ltr"><CountUp value={d.staff_today[k]} /></b></li>)}
              </ul>
            )}
            <div className="mt-4 grid grid-cols-2 gap-2">
              {COUNTS.map(([key, label, Icon]) => (
                <div key={key} className="rounded-2xl bg-white/8 p-3">
                  <Icon size={16} aria-hidden className="text-sky-100" />
                  <p className="mt-1 text-xl font-bold text-white" dir="ltr"><CountUp value={data?.[key] ?? '–'} /></p>
                  <p className="text-[11px] text-white/60">{t(label)}</p>
                </div>
              ))}
            </div>
          </GlassCard>

          <div className="grid gap-4 lg:row-span-2 [grid-template-rows:auto_1fr]">
            {/* Today's attendance as a segmented gauge. */}
            <GlassCard i={6} ready={ready}>
              <CardTitle>{t('dashboard.students.title')}</CardTitle>
              {kids === null ? <p className="py-8 text-center text-sm text-white/60">{t('dashboard.notTaken')}</p> : <SegmentGauge percent={kids} label={t('dashboard.students.title')} caption={t('dashboard.glass.ofStudents')} />}
              {staff !== null && <p className="mt-3 text-center text-xs text-white/60">{t('dashboard.staff.title')}: <b dir="ltr" className="text-white">{Math.round(staff)}%</b></p>}
            </GlassCard>
            {/* The dark card: quick actions. */}
            <GlassCard i={7} ready dark>
              <p className="text-2xl font-extrabold leading-tight text-white">{t('dashboard.quick.title')}</p>
              {waiting.length > 0 && <p className="mt-1 text-xs text-white/50">{t('dashboard.glass.waitingCount', { count: waiting.reduce((s, w) => s + w[2], 0) })}</p>}
              <ul className="mt-4 space-y-2">
                {[['/admin/students', 'dashboard.quick.student', Users], ['/admin/announcements', 'dashboard.quick.announce', Megaphone], ['/admin/import', 'dashboard.quick.import', Upload]].map(([to, label, Icon]) => (
                  <li key={to}>
                    <Link to={to} className="group flex items-center gap-3 rounded-full bg-white/10 p-1.5 pe-4 text-sm text-white transition hover:bg-white hover:text-deep-harbor">
                      <span aria-hidden className="grid h-9 w-9 shrink-0 place-items-center rounded-full bg-white/15 group-hover:bg-sky-tint"><Icon size={16} /></span>{t(label)}
                    </Link>
                  </li>
                ))}
              </ul>
            </GlassCard>
          </div>

          {/* Analytics with tabs. */}
          <GlassCard i={8} ready={ready} className="lg:row-span-2">
            <CardTitle>{t('dashboard.glass.analytics')}</CardTitle>
            <PillTabs layoutId="overview-analytics" label={t('dashboard.glass.analytics')} value={tab} onChange={setTab} tabs={[['money', t('dashboard.money.title')], ['attendance', t('dashboard.glass.tabAttendance')], ['levels', t('dashboard.glass.tabLevels')], ['marks', t('dashboard.glass.tabMarks')]]} />
            <div className="mt-6 min-h-64">
              {chart && (
                <BlurSwap id={tab}>
                  {chart.items.length === 0 ? <p className="py-16 text-center text-sm text-white/60">{t(none)}</p> : <PillColumns label={chart.label} items={chart.items} format={chart.format} maxValue={chart.maxValue} avgLabel={t('dashboard.glass.avg')} />}
                </BlurSwap>
              )}
            </div>
            {fin && (
              <ul className="mt-4 grid grid-cols-3 gap-2 text-center">
                {[['dashboard.money.billed', fin.billed, ''], ['dashboard.money.collected', fin.collected, ''], ['dashboard.money.overdue', fin.overdue, fin.overdue > 0 ? 'text-red-200' : '']].map(([label, value, tone]) => (
                  <li key={label} className="rounded-2xl bg-white/8 p-2"><p className="text-[11px] text-white/60">{t(label)}</p><b className={`text-sm ${tone || 'text-white'}`} dir="ltr">{money(value)} {fin.currency}</b></li>
                ))}
              </ul>
            )}
          </GlassCard>
        </div>

        <div className="grid gap-4 lg:grid-cols-3">
          <GlassCard i={9} ready={ready}>
            <CardTitle>{t('dashboard.waiting.title')}</CardTitle>
            {waiting.length === 0 ? <p className="text-sm text-white/60">{t('dashboard.waiting.none')}</p> : (
              <ul className="space-y-2">
                {waiting.map(([to, label, count]) => <li key={to}><Link to={to} className="flex items-center justify-between rounded-2xl bg-amber-300/20 px-3 py-2 text-sm text-amber-100 transition hover:bg-amber-300/30"><span>{t(label)}</span><b dir="ltr">{count}</b></Link></li>)}
              </ul>
            )}
          </GlassCard>
          <GlassCard i={10} ready={ready} className="lg:col-span-2">
            <CardTitle>{t('dashboard.absences.title')}</CardTitle>
            {d && (d.frequent_absences.length === 0 ? <p className="text-sm text-white/60">{t('dashboard.absences.none')}</p> : (
              <ul className="grid gap-2 sm:grid-cols-2">
                {d.frequent_absences.map((s) => <li key={s.id} className="flex justify-between rounded-2xl bg-red-300/15 px-3 py-2 text-sm text-red-100"><bdi>{name(s)}</bdi><b>{t('dashboard.absences.days', { count: s.days })}</b></li>)}
              </ul>
            ))}
          </GlassCard>
        </div>
      </section>
      <ShortcutsDialog open={editing} onClose={() => setEditing(false)} paths={paths} custom={shortcuts.custom} onChange={(items) => shortcuts.save({ items })} onReset={shortcuts.reset} />
    </PageEnter>
  )
}
