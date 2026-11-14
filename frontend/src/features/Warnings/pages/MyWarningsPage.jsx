import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Plus } from 'lucide-react'
import { api } from '../../../api/client'
import { useAuth } from '../../Auth/AuthProvider'
import NewWarningDialog from '../components/NewWarningDialog'
import WarningCard from '../components/WarningCard'
import { InteractiveHoverButton } from '../../../components/ui/interactive-hover-button'

// The signed-in student's or member of staff's own warnings. A teacher can also propose a warning for a student here (the admin approves it).
export default function MyWarningsPage() {
  const { t } = useTranslation()
  const { user } = useAuth()
  const client = useQueryClient()
  const [adding, setAdding] = useState(false)
  const isTeacher = user?.role === 'teacher'
  const mine = useQuery({ queryKey: ['warnings', 'mine'], queryFn: async () => (await api.get('/warnings/mine')).data.data })
  const proposed = useQuery({ queryKey: ['warnings', 'proposed'], enabled: isTeacher, queryFn: async () => (await api.get('/warnings')).data.data })
  const seen = useMutation({ mutationFn: (wid) => api.post(`/warnings/${wid}/acknowledge`), onSuccess: () => client.invalidateQueries({ queryKey: ['warnings'] }) })
  return (
    <section className="space-y-5">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <h1 className="text-xl font-bold">{t('warnings.mineTitle')}</h1>
        {isTeacher && <InteractiveHoverButton type="button" className="px-4 py-1.5" onClick={() => setAdding(true)}><Plus size={16} aria-hidden />{t('warnings.proposeForStudent')}</InteractiveHoverButton>}
      </div>
      {!mine.data ? <p className="text-slate-500">{t('common.loading')}</p> : mine.data.length === 0 ? <p className="rounded-lg border bg-white p-6 text-center text-slate-500">{t('warnings.noneForMe')}</p> : (
        <div className="space-y-3">
          {mine.data.map((w) => (
            <WarningCard key={w.id} w={w}>
              {isTeacher && !w.acknowledged_at && <InteractiveHoverButton type="button" disabled={seen.isPending} className="px-4 py-1.5 text-sm" onClick={() => seen.mutate(w.id)}>{t('warnings.iHaveSeen')}</InteractiveHoverButton>}
            </WarningCard>
          ))}
        </div>
      )}
      {isTeacher && (proposed.data ?? []).length > 0 && (
        <div className="space-y-2">
          <h2 className="font-bold">{t('warnings.myProposals')}</h2>
          <div className="grid gap-3 lg:grid-cols-2">{proposed.data.map((w) => <WarningCard key={w.id} w={w} who={w.student?.name_ar} />)}</div>
        </div>
      )}
      {adding && <NewWarningDialog canWarnStaff={false} onClose={() => setAdding(false)} />}
    </section>
  )
}
