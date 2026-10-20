import { useState } from 'react'
import { useTranslation } from 'react-i18next'

const Eye = ({ off }) => (
  <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
    <path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12z" />
    <circle cx="12" cy="12" r="3" />
    {off && <path d="M3 3l18 18" />}
  </svg>
)

// A password field with an eye button that shows what was typed.
export default function PasswordInput({ className = '', ...props }) {
  const { t } = useTranslation()
  const [visible, setVisible] = useState(false)
  return (
    <div className="relative">
      <input {...props} type={visible ? 'text' : 'password'} dir="ltr" className={`w-full rounded border p-2 pe-10 ${className}`} />
      <button
        type="button"
        aria-label={t(visible ? 'auth.hidePassword' : 'auth.showPassword')}
        aria-pressed={visible}
        className="absolute inset-y-0 end-0 flex items-center px-2 text-slate-500 hover:text-slate-800"
        onClick={() => setVisible((v) => !v)}
      >
        <Eye off={visible} />
      </button>
    </div>
  )
}
