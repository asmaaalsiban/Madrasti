import { useEffect, useState } from 'react'

// True below Tailwind's md breakpoint (768px): a phone or a narrow window. False where matchMedia does not exist (the tests).
const QUERY = '(max-width: 767px)'

export default function useIsPhone() {
  const [phone, setPhone] = useState(() => typeof window !== 'undefined' && typeof window.matchMedia === 'function' && window.matchMedia(QUERY).matches)
  useEffect(() => {
    if (typeof window.matchMedia !== 'function') return undefined
    const media = window.matchMedia(QUERY)
    const update = () => setPhone(media.matches)
    media.addEventListener('change', update)
    return () => media.removeEventListener('change', update)
  }, [])
  return phone
}
