import { useQuery } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'
import { api } from '../../../api/client'

const DASH = '—'

// The head of department's view: the teachers of the same subject and what they are doing.
export default function DepartmentPage() {
  const { t, i18n } = useTranslation()
  const { data, isLoading } = useQuery({ queryKey: ['department'], queryFn: async () => (await api.get('/department')).data.data })
  const subject = data?.subject?.[`name_${i18n.language}`] ?? data?.subject?.name_ar ?? ''
  const teachers = data?.teachers ?? []

  return (
    <section className="space-y-4">
      <h1 className="text-xl font-bold">{t('department.title', { subject })}</h1>
      <div className="overflow-x-auto rounded-lg bg-white shadow-sm">
        <table className="w-full text-start text-sm">
          <thead className="bg-slate-100">
            <tr>
              {['teacher', 'homeroom', 'classrooms', 'attendanceToday', 'daysRecorded'].map((k) => (
                <th key={k} className="p-2 text-start font-medium sm:p-3">{t(`department.${k}`)}</th>
              ))}
            </tr>
          </thead>
          <tbody>
            {teachers.map((row) => (
              <tr key={row.id} className="border-t">
                <td className="p-2 sm:p-3">
                  <span className="block font-medium">{row.name}</span>
                  <span dir="ltr" className="block text-xs text-slate-500">{[row.email, row.phone].filter(Boolean).join(' · ')}</span>
                </td>
                <td className="p-2 sm:p-3">{row.homeroom ?? DASH}</td>
                <td className="p-2 sm:p-3">{row.classrooms.length ? row.classrooms.join(', ') : DASH}</td>
                <td className="p-2 sm:p-3">
                  {row.attendance === null ? DASH : (
                    <span className={row.attendance.recorded_today ? 'text-green-700' : 'text-red-700'}>
                      {t(row.attendance.recorded_today ? 'department.recorded' : 'department.notYet')}
                    </span>
                  )}
                </td>
                <td className="p-2 sm:p-3">{row.attendance === null ? DASH : row.attendance.days_last_30}</td>
              </tr>
            ))}
          </tbody>
        </table>
        {!isLoading && teachers.length === 0 && <p className="p-6 text-center text-slate-500">{t('department.empty')}</p>}
      </div>
    </section>
  )
}
