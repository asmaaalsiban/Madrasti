import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useParams } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { api } from '../../../api/client'
import { useAuth } from '../../Auth/AuthProvider'
import WarningCard from '../components/WarningCard'
import { InteractiveHoverButton } from '../../../components/ui/interactive-hover-button'

// A child's warnings, for the parent (who can say they have seen each one).
export default function ChildWarningsPage() {
  const { t, i18n } = useTranslation()
  const { id } = useParams()
  const { user } = useAuth()
  const client = useQueryClient()
  const child = (user?.children ?? []).find((c) => String(c.id) === id)
  const name = child ? child[`name_${i18n.language}`] || child.name_ar : ''
  const { data } = useQuery({ queryKey: ['warnings', 'child', id], queryFn: async () => (await api.get(`/students/${id}/warnings`)).data.data })
  const seen = useMutation({ mutationFn: (wid) => api.post(`/warnings/${wid}/acknowledge`), onSuccess: () => client.invalidateQueries({ queryKey: ['warnings'] }) })
  return (
    <section className="space-y-4">
      <h1 className="text-xl font-bold">{t('warnings.childTitle', { name })}</h1>
      {!data ? <p className="text-slate-500">{t('common.loading')}</p> : data.length === 0 ? <p className="rounded-lg border bg-white p-6 text-center text-slate-500">{t('warnings.noneForChild')}</p> : (
        <div className="space-y-3">
          {data.map((w) => (
            <WarningCard key={w.id} w={w}>
              {!w.acknowledged_at && <InteractiveHoverButton type="button" disabled={seen.isPending} className="px-4 py-1.5 text-sm" onClick={() => seen.mutate(w.id)}>{t('warnings.iHaveSeen')}</InteractiveHoverButton>}
            </WarningCard>
          ))}
        </div>
      )}
    </section>
  )
}
