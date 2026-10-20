import { useTranslation } from 'react-i18next'
import { clearSchool, getSchool, goTo } from '../../tenant/school'
import BackArrow from './BackArrow'

// On the sign-in page: go back to the school code page, with the current code filled in so a typo is easy to fix.
export default function ChangeSchoolCodeButton() {
  const { t } = useTranslation()
  const back = () => {
    const code = getSchool()
    clearSchool()
    goTo(code ? `/find-school?code=${encodeURIComponent(code)}` : '/find-school')
  }
  return (
    <button type="button" className="flex items-center gap-2 rounded border px-3 py-1 text-sm text-brand" onClick={back}>
      <BackArrow />
      {t('central.changeSchoolCode')}
    </button>
  )
}
