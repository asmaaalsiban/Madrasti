import AuthImage from './AuthImage'

// The school's logo. It is fetched with the school header (an <img src> cannot send it) and drawn on a white rounded tile,
// so a logo with any background looks right on the navy or the cream of the site.
export default function SchoolLogo({ url, className = 'h-10 w-10' }) {
  if (!url) return null
  const path = url.replace(/^\/api/, '')
  return (
    <span className={`inline-grid shrink-0 place-items-center overflow-hidden rounded-2xl bg-white p-1 shadow-sm ${className}`}>
      <AuthImage path={path} className="h-full w-full object-contain" />
    </span>
  )
}
