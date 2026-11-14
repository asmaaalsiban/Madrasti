import { useQuery } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'
import { Pricing } from '../../components/ui/pricing'
import { publicPlans } from './api'

// The website's pricing section: the plans the platform owner set (nothing is shown until there is at least one active plan).
export default function PublicPricing({ href }) {
  const { t, i18n } = useTranslation()
  const { data } = useQuery({ queryKey: ['central', 'plans'], queryFn: publicPlans, staleTime: 5 * 60 * 1000, retry: false })
  if (!data?.length) return null
  const ar = i18n.language === 'ar'
  const plans = data.map((p) => ({
    name: ar ? p.name_ar : p.name_en,
    price: p.price_monthly,
    yearlyPrice: p.price_yearly,
    currency: p.currency,
    period: t('pricing.perMonth'),
    features: [p.max_students ? t('pricing.upTo', { count: p.max_students }) : t('pricing.unlimited'), ...(ar ? p.features_ar : p.features_en)],
    description: (ar ? p.description_ar : p.description_en) ?? '',
    buttonText: t('pricing.choose'),
    href,
    isPopular: p.is_popular,
  }))
  const labels = Object.fromEntries(['monthly', 'annual', 'save', 'popular', 'billedMonthly', 'billedAnnually'].map((k) => [k, t(`pricing.${k}`)]))
  return (
    <section id="pricing" className="mx-auto max-w-6xl px-4">
      <Pricing plans={plans} title={t('pricing.title')} description={t('pricing.description')} labels={labels} locale={i18n.language} />
    </section>
  )
}
