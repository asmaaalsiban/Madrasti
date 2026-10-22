import { useParams } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { useAuth } from '../../Auth/AuthProvider'
import WeekTimetable from '../../Scheduling/components/WeekTimetable'

// The week of one child of the signed-in parent.
export default function ChildTimetablePage() {
  const { t, i18n } = useTranslation()
  const { id } = useParams()
  const { user } = useAuth()
  const child = (user?.children ?? []).find((c) => String(c.id) === id)
  const name = child ? child[`name_${i18n.language}`] || child.name_ar : ''
  return (
    <section className="space-y-4">
      <h1 className="text-xl font-bold">{t('timetable.childTitle', { name })}</h1>
      <WeekTimetable endpoint={`/students/${id}/timetable`} show="teacher" />
    </section>
  )
}
