import i18n from 'i18next'
import { initReactI18next } from 'react-i18next'
import ar from './ar.json'
import en from './en.json'

export const LANGS = ['ar', 'en']
const stored = (() => {
  try { return localStorage.getItem('lang') } catch { return null }
})()

i18n.use(initReactI18next).init({
  resources: { ar: { translation: ar }, en: { translation: en } },
  lng: LANGS.includes(stored) ? stored : 'ar',
  fallbackLng: 'ar',
  interpolation: { escapeValue: false },
})

// Keep <html dir/lang> in sync with the selected language.
export function applyDirection(lng) {
  document.documentElement.lang = lng
  document.documentElement.dir = lng === 'ar' ? 'rtl' : 'ltr'
}
applyDirection(i18n.language)
i18n.on('languageChanged', (lng) => {
  applyDirection(lng)
  try { localStorage.setItem('lang', lng) } catch { /* ignore */ }
})

export default i18n
