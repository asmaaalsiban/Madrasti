import { useState } from 'react'
import { Link } from 'react-router-dom'
import { ArrangeBar, MoveButtons } from './Arrange'
import useLayout from '../../features/Layout/useLayout'
import { orderBy, swap } from '../../features/Layout/arrange'

// Icon tiles to the sections of a page (a student's or a child's: grades, timetable, homework...).
// With a `surface`, the person can arrange them: each tile has a `key`, the order is saved on their account.
export default function SectionTiles({ tiles, surface }) {
  const layout = useLayout(surface ?? 'none')
  const [editing, setEditing] = useState(false)
  const keyOf = (tile) => tile.key ?? tile.to
  const shown = surface ? orderBy(tiles, keyOf, layout.items) : tiles
  const keys = shown.map(keyOf)
  const move = (key, delta) => layout.save({ groups: layout.groups, items: swap(keys, key, delta) })
  return (
    <div className="space-y-3">
      {surface && <ArrangeBar editing={editing} onToggle={() => setEditing(!editing)} onReset={layout.reset} custom={layout.custom} />}
      <div className={`grid grid-cols-2 gap-3 ${shown.length % 3 === 0 ? 'sm:grid-cols-3' : 'sm:grid-cols-3 lg:grid-cols-4'}`}>
        {shown.map((tile, i) => {
          const { to, label, icon: Icon, badge } = tile
          const Wrapper = editing ? 'div' : Link
          return (
            <Wrapper key={keyOf(tile)} {...(editing ? {} : { to })} className={`group relative flex flex-col items-center gap-3 rounded-lg border bg-snow px-3 py-5 text-center transition-transform ${editing ? 'border-dashed border-brand' : 'hover:-translate-y-0.5'}`}>
              <span aria-hidden className="grid h-14 w-14 place-items-center rounded-2xl bg-sky-tint text-deep-harbor transition-colors group-hover:bg-deep-harbor group-hover:text-white"><Icon size={26} /></span>
              <span className="text-sm font-bold leading-snug text-deep-harbor">{label}</span>
              {!editing && badge > 0 && <span className="absolute end-3 top-3 grid min-w-6 place-items-center rounded-full bg-brand px-1.5 text-xs font-bold leading-6 text-white">{badge}</span>}
              {editing && <MoveButtons label={label} canEarlier={i > 0} canLater={i < shown.length - 1} onEarlier={() => move(keyOf(tile), -1)} onLater={() => move(keyOf(tile), 1)} />}
            </Wrapper>
          )
        })}
      </div>
    </div>
  )
}
