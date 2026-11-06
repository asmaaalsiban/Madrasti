import katex from 'katex'
import 'katex/dist/katex.min.css'

// Text with equations: $x^2$ inside a line, $$x^2$$ on its own line. KaTeX builds the markup (no raw HTML from the author is ever used).
const PART = /(\$\$[^$]+\$\$|\$[^$\n]+\$)/g

function math(src, display) {
  try {
    return katex.renderToString(src, { displayMode: display, throwOnError: false, trust: false, output: 'html' })
  } catch {
    return null
  }
}

export default function RichText({ text, className = '' }) {
  const parts = String(text ?? '').split(PART)
  return (
    <span dir="auto" className={`${className} whitespace-pre-line`}>
      {parts.map((p, i) => {
        const display = p.startsWith('$$') && p.endsWith('$$') && p.length > 4
        const inline = !display && p.startsWith('$') && p.endsWith('$') && p.length > 2
        if (display || inline) {
          const html = math(display ? p.slice(2, -2) : p.slice(1, -1), display)
          if (html) return <span key={i} dir="ltr" className={display ? 'block text-center' : ''} dangerouslySetInnerHTML={{ __html: html }} />
        }
        return <span key={i}>{p}</span>
      })}
    </span>
  )
}
