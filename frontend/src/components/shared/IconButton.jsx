import { Link } from 'react-router-dom'

// A round icon link with an optional unread badge: chat, announcements.
export default function IconButton({ to, icon: Icon, label, count = 0 }) {
  return (
    <Link to={to} aria-label={count > 0 ? `${label} (${count})` : label} title={label} className="relative grid h-9 w-9 place-items-center sm:h-10 sm:w-10 rounded-full border bg-snow text-deep-harbor">
      <Icon aria-hidden size={20} />
      {count > 0 && <span aria-hidden className="absolute -end-1 -top-1 grid min-w-5 place-items-center rounded-full bg-brand px-1 text-[11px] font-bold leading-5 text-white">{count}</span>}
    </Link>
  )
}
