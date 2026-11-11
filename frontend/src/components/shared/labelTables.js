// On a phone a wide table (5 columns or more) is shown as a stack of cards, one per row, each cell with its column name beside it (CSS in
// index.css, `table[data-stack]`). The names come from the table's own header cells, so no table needs to be changed: this runs once on
// the whole page and again whenever tables appear. A table that is a real grid (a timetable) opts out with `data-grid` and is shrunk instead.
const MIN_COLUMNS = 5

const textOf = (th) => (th.textContent ?? '').replace(/\s+/g, ' ').trim()

export function labelTables(root = document) {
  root.querySelectorAll('table:not([data-grid])').forEach((table) => {
    const headRows = table.tHead?.rows
    if (!headRows || headRows.length === 0) return
    const labels = []
    for (const th of headRows[headRows.length - 1].cells) {
      const span = th.colSpan || 1
      for (let i = 0; i < span; i += 1) labels.push(textOf(th))
    }
    if (labels.length < MIN_COLUMNS) { table.removeAttribute('data-stack'); return }
    table.setAttribute('data-stack', '')
    for (const body of table.tBodies) {
      for (const row of body.rows) {
        let col = 0
        for (const cell of row.cells) {
          const span = cell.colSpan || 1
          const label = span === 1 ? labels[col] : ''
          if (label) cell.setAttribute('data-label', label)
          else cell.removeAttribute('data-label')
          col += span
        }
      }
    }
  })
}
