import { useTranslation } from 'react-i18next'

// The student's name in the selected language (falls back to Arabic).
export function useStudentName() {
  const { i18n } = useTranslation()
  return (s) => s[`name_${i18n.language}`] || s.name_ar
}
