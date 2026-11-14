import { useTranslation } from 'react-i18next'
import { Pricing } from '../../components/ui/pricing'

// A trial page for the pricing block (sample plans and prices only, NOT the real ones). Open it at /admin/pricing-demo.
export default function PricingDemoPage() {
  const { t, i18n } = useTranslation()
  const plans = ['basic', 'standard', 'premium'].map((key, i) => ({
    name: t(`pricingDemo.${key}.name`),
    price: [50, 99, 299][i],
    yearlyPrice: [40, 79, 239][i],
    period: t('pricingDemo.perMonth'),
    features: t(`pricingDemo.${key}.features`, { returnObjects: true }),
    description: t(`pricingDemo.${key}.description`),
    buttonText: t('pricingDemo.choose'),
    href: '/admin',
    isPopular: key === 'standard',
  }))
  const labels = Object.fromEntries(['monthly', 'annual', 'save', 'popular', 'billedMonthly', 'billedAnnually'].map((k) => [k, t(`pricingDemo.${k}`)]))
  return <Pricing plans={plans} title={t('pricingDemo.title')} description={t('pricingDemo.description')} labels={labels} locale={i18n.language} />
}
