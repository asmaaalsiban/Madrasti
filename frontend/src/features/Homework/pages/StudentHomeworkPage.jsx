import { useTranslation } from 'react-i18next'
import HomeworkList from '../components/HomeworkList'

export default function StudentHomeworkPage() {
  const { t } = useTranslation()
  return (
    <section className="space-y-4">
      <h1 className="text-xl font-bold">{t('homework.title')}</h1>
      <HomeworkList endpoint="/homework/me" />
    </section>
  )
}
