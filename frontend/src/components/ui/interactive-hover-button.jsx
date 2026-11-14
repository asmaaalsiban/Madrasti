import { ArrowRight } from 'lucide-react'
import { forwardRef } from 'react'

// The primary action button. On a desktop with a mouse (md and up, hover capable) it is a white pill with a small dot; on hover the dot grows
// to fill the button and the text slides out while the same text and an arrow slide in. On a phone or tablet it stays a plain solid brand button.
// The effect is plain CSS (.ihb in index.css), so the button is still an ordinary <button>: it takes every normal prop (type, onClick, disabled...).
// Text that is only a string slides; anything else (an icon plus text, for example) just fills and turns white, so nothing is drawn twice.
const InteractiveHoverButton = forwardRef(({ children, className = '', ...props }, ref) => {
  const text = typeof children === 'string' ? children : null
  const sized = /(^|\s)p[xy]?-/.test(className) // a caller that sets its own padding keeps it
  return (
    <button ref={ref} className={`ihb ${text === null ? 'ihb-plain' : ''} ${sized ? '' : 'px-5 py-1.5'} ${className}`} {...props}>
      <span className="ihb-text">{children}</span>
      {text !== null && <span aria-hidden className="ihb-in" data-text={text}><ArrowRight size={16} /></span>}
      <span aria-hidden className="ihb-dot" />
    </button>
  )
})
InteractiveHoverButton.displayName = 'InteractiveHoverButton'

export { InteractiveHoverButton }
export default InteractiveHoverButton
