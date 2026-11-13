import { useEffect } from 'react'

// size: 'md' (default) or 'lg' for forms with many fields. On a phone a tall dialog is a bottom sheet that scrolls inside itself;
// on a bigger screen the dialog grows to its content and the page behind the dark layer is the only thing that scrolls (no scrollbar inside the dialog).
export default function Modal({ open, title, onClose, children, size = 'md', hideTitle = false }) {
  useEffect(() => {
    if (!open) return undefined
    const onKey = (e) => e.key === 'Escape' && onClose()
    document.addEventListener('keydown', onKey)
    const previous = document.body.style.overflow
    document.body.style.overflow = 'hidden' // the page behind must not scroll while a dialog is open
    return () => {
      document.removeEventListener('keydown', onKey)
      document.body.style.overflow = previous
    }
  }, [open, onClose])

  if (!open) return null
  return (
    <div className="fixed inset-0 z-50 flex justify-center overflow-y-auto bg-black/40 sm:p-4">
      <div
        role="dialog" aria-modal="true" aria-label={title}
        className={`mt-auto max-h-[90dvh] w-full overflow-y-auto rounded-t-2xl bg-white p-5 pb-[max(1.25rem,env(safe-area-inset-bottom))] shadow-lg sm:my-auto sm:max-h-none sm:overflow-visible sm:rounded-lg ${size === 'lg' ? 'max-w-2xl' : 'max-w-md'}`}
      >
        <div aria-hidden className="mx-auto -mt-2 mb-3 h-1 w-10 rounded-full bg-slate-300 sm:hidden" />
        {!hideTitle && <h2 className="mb-4 text-lg font-bold">{title}</h2>}
        {children}
      </div>
    </div>
  )
}
