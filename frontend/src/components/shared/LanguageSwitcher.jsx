import { useTranslation } from 'react-i18next'
import { InteractiveHoverButton } from '../ui/interactive-hover-button'

export default function LanguageSwitcher() {
  const { t, i18n } = useTranslation()
  return (
    <InteractiveHoverButton variant="outline"
      type="button"
      className="px-3 py-1 text-sm"
      onClick={() => i18n.changeLanguage(i18n.language === 'ar' ? 'en' : 'ar')}
    >
      {t('common.language')}
    </InteractiveHoverButton>
  )
}
