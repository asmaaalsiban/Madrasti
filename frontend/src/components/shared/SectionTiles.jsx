import { Link } from 'react-router-dom'

// Icon tiles to the sections of a page (a student's or a child's: grades, timetable, homework...).
export default function SectionTiles({ tiles }) {
  return (
    // Nine tiles sit in three tidy rows of three; eight in two rows of four.
    <div className={`grid grid-cols-2 gap-3 ${tiles.length % 3 === 0 ? 'sm:grid-cols-3' : 'sm:grid-cols-3 lg:grid-cols-4'}`}>
      {tiles.map(({ to, label, icon: Icon, badge }) => (
        <Link key={to} to={to} className="group relative flex flex-col items-center gap-3 rounded-lg border bg-snow px-3 py-5 text-center transition-transform hover:-translate-y-0.5">
          <span aria-hidden className="grid h-14 w-14 place-items-center rounded-2xl bg-sky-tint text-deep-harbor transition-colors group-hover:bg-deep-harbor group-hover:text-white"><Icon size={26} /></span>
          <span className="text-sm font-bold leading-snug text-deep-harbor">{label}</span>
          {badge > 0 && <span className="absolute end-3 top-3 grid min-w-6 place-items-center rounded-full bg-brand px-1.5 text-xs font-bold leading-6 text-white">{badge}</span>}
        </Link>
      ))}
    </div>
  )
}
