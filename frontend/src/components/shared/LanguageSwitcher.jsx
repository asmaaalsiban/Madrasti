import { useTranslation } from 'react-i18next'

export default function LanguageSwitcher() {
  const { t, i18n } = useTranslation()
  return (
    <button
      type="button"
      className="rounded border px-3 py-1 text-sm"
      onClick={() => i18n.changeLanguage(i18n.language === 'ar' ? 'en' : 'ar')}
    >
      {t('common.language')}
    </button>
  )
}
