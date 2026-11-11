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
    <div className="fixed inset-0 z-50 flex items-end justify-center overflow-y-auto bg-black/40 sm:items-center sm:p-4">
      <div
        role="dialog" aria-modal="true" aria-label={title}
        className={`max-h-[90dvh] w-full overflow-y-auto rounded-t-2xl bg-white p-5 pb-[max(1.25rem,env(safe-area-inset-bottom))] shadow-lg sm:max-h-[calc(100dvh-2rem)] sm:rounded-lg ${size === 'lg' ? 'max-w-2xl' : 'max-w-md'}`}
      >
        <div aria-hidden className="mx-auto -mt-2 mb-3 h-1 w-10 rounded-full bg-slate-300 sm:hidden" />
        {!hideTitle && <h2 className="mb-4 text-lg font-bold">{title}</h2>}
        {children}
      </div>
    </div>
  )
}
