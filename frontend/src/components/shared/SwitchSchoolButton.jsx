import { useTranslation } from 'react-i18next'
import { clearSchool, goTo } from '../../tenant/school'

// The site has one URL for every school: switching only forgets the chosen school
// (each school keeps its own sign-in) and reloads from the start page.
export default function SwitchSchoolButton({ className = 'rounded border px-3 py-1 text-sm' }) {
  const { t } = useTranslation()
  const switchSchool = () => {
    clearSchool()
    goTo('/')
  }
  return (
    <button type="button" className={className} onClick={switchSchool}>
      {t('central.switchSchool')}
    </button>
  )
}
