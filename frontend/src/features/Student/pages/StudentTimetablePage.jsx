import { useTranslation } from 'react-i18next'
import WeekTimetable from '../../Scheduling/components/WeekTimetable'

// The week of the student's section, with the teacher of every lesson.
export default function StudentTimetablePage() {
  const { t } = useTranslation()
  return (
    <section className="space-y-4">
      <h1 className="text-xl font-bold">{t('timetable.myTimetable')}</h1>
      <WeekTimetable endpoint="/timetable/me" show="teacher" />
    </section>
  )
}
