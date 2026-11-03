import { useEffect } from 'react'

// size: 'md' (default) or 'lg' for forms with many fields. A tall dialog scrolls inside the screen instead of running off it.
export default function Modal({ open, title, onClose, children, size = 'md', hideTitle = false }) {
  useEffect(() => {
    if (!open) return undefined
    const onKey = (e) => e.key === 'Escape' && onClose()
    document.addEventListener('keydown', onKey)
    return () => document.removeEventListener('keydown', onKey)
  }, [open, onClose])

  if (!open) return null
  return (
    <div className="fixed inset-0 z-50 flex items-start justify-center overflow-y-auto bg-black/40 p-4 sm:items-center">
      <div
        role="dialog" aria-modal="true" aria-label={title}
        className={`max-h-[calc(100dvh-2rem)] w-full overflow-y-auto rounded-lg bg-white p-5 shadow-lg ${size === 'lg' ? 'max-w-2xl' : 'max-w-md'}`}
      >
        {!hideTitle && <h2 className="mb-4 text-lg font-bold">{title}</h2>}
        {children}
      </div>
    </div>
  )
}
