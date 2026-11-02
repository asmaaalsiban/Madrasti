import { useQuery } from '@tanstack/react-query'
import { useState } from 'react'
import { useParams, useSearchParams } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { api } from '../../../api/client'
import { useAuth } from '../../Auth/AuthProvider'
import PayOnlineDialog from '../components/PayOnlineDialog'
import Statement from '../components/Statement'

// After a provider's page sends the parent back (?online=reference) this tells them how the payment ended.
function ReturnNotice({ reference }) {
  const { t } = useTranslation()
  const { data } = useQuery({
    queryKey: ['finance', 'online', reference],
    queryFn: async () => (await api.get(`/online-payments/${reference}`)).data.data,
    refetchInterval: (q) => (q.state.data?.status === 'pending' ? 3000 : false),
  })
  if (!data) return null
  if (data.status === 'pending') return <p role="status" className="rounded bg-amber-50 p-3 text-amber-800">{t('finance.online.pending')}</p>
  if (data.status === 'paid') return <p role="status" className="rounded bg-green-50 p-3 text-green-800">{t('finance.online.paid', { receipt: data.receipt })}</p>
  if (data.status === 'review') return <p role="alert" className="rounded bg-amber-50 p-3 text-amber-800">{t('finance.online.review')}</p>
  return <p role="alert" className="rounded bg-red-50 p-3 text-red-700">{t(`finance.online.failed.${data.failure_reason}`, { defaultValue: t('finance.online.failed.default') })}</p>
}

// A parent reads the fee statement of one child and can pay online when the school has a payment provider.
export default function ChildFeesPage() {
  const { t, i18n } = useTranslation()
  const { id } = useParams()
  const [params] = useSearchParams()
  const { user } = useAuth()
  const [paying, setPaying] = useState(null)
  const child = (user?.children ?? []).find((c) => String(c.id) === id)
  const name = child ? child[`name_${i18n.language}`] || child.name_ar : ''
  const { data, isLoading } = useQuery({ queryKey: ['finance', 'child', id], queryFn: async () => (await api.get(`/students/${id}/fees`)).data.data })
  const online = data?.online?.enabled
  return (
    <section className="space-y-4">
      <h1 className="text-xl font-bold">{t('finance.childTitle', { name })}</h1>
      {params.get('online') && <ReturnNotice reference={params.get('online')} />}
      {isLoading ? <p>{t('common.loading')}</p> : <Statement data={data} onPayOnline={online ? setPaying : null} />}
      {paying && <PayOnlineDialog studentId={id} fee={paying} currency={data.currency} payerDefault={user?.name} demo={data.online.mode === 'demo'} onClose={() => setPaying(null)} />}
    </section>
  )
}
