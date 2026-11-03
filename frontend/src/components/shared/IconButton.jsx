import { Link } from 'react-router-dom'

// A round icon link with an optional unread badge: chat, announcements.
export default function IconButton({ to, icon: Icon, label, count = 0 }) {
  return (
    <Link to={to} title={label} className="relative grid h-9 w-9 place-items-center sm:h-10 sm:w-10 rounded-full border bg-snow text-deep-harbor">
      <Icon aria-hidden size={20} />
      {/* The name a screen reader says; the visible number is part of it, so the two match. */}
      <span className="sr-only">{count > 0 ? `${label} (${count})` : label}</span>
      {count > 0 && <span aria-hidden className="absolute -end-1 -top-1 grid min-w-5 place-items-center rounded-full bg-brand px-1 text-[11px] font-bold leading-5 text-white">{count}</span>}
    </Link>
  )
}
