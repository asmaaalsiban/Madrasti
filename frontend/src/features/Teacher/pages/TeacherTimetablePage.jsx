import { useTranslation } from 'react-i18next'
import WeekTimetable from '../../Scheduling/components/WeekTimetable'

// The teacher's own week: which section they teach in every period.
export default function TeacherTimetablePage() {
  const { t } = useTranslation()
  return (
    <section className="space-y-4">
      <h1 className="text-xl font-bold">{t('timetable.myTimetable')}</h1>
      <WeekTimetable endpoint="/timetable/me" show="classroom" emptyLabel="timetable.free" />
    </section>
  )
}
