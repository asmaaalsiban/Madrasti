// On a phone every data table (3 columns or more) is shown as a stack of cards, one per row, each cell with its column name beside it (CSS in
// index.css, `table[data-stack]`). The names come from the table's own header cells, so no table needs to be changed: this runs once on the
// whole page and again whenever tables appear. A table that is a real grid (a timetable) opts out with `data-grid` and is shrunk instead.
// Like the lists made with DataTable, the cell of the name column becomes the card's bold title and a cell that holds only buttons or links
// (under a header such as "Actions") becomes a row of buttons at the bottom of the card.
const MIN_COLUMNS = 3
const TITLE = /name|اسم|student|طالب|teacher|معلم|subject|مادة|employee|موظف|title|عنوان|supplier|مورد/i
const ACTIONS = /actions|إجراءات|اجراءات/i

const textOf = (th) => (th.textContent ?? '').replace(/\s+/g, ' ').trim()
const onlyButtons = (cell) => cell.querySelector('button, a') !== null && cell.querySelector('input, select, textarea') === null

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
    // A DataTable already marks its title and actions cells itself.
    const own = table.hasAttribute('data-own-cards')
    const titleCol = own ? -1 : labels.findIndex((l) => TITLE.test(l))
    const actionsCol = own ? -1 : labels.length - 1 - [...labels].reverse().findIndex((l) => ACTIONS.test(l))
    for (const body of table.tBodies) {
      for (const row of body.rows) {
        let col = 0
        for (const cell of row.cells) {
          const span = cell.colSpan || 1
          const label = span === 1 ? labels[col] : ''
          if (label) cell.setAttribute('data-label', label)
          else cell.removeAttribute('data-label')
          if (!own) {
            if (span === 1 && col === titleCol) cell.setAttribute('data-title', '')
            else cell.removeAttribute('data-title')
            if (span === 1 && col === actionsCol && actionsCol < labels.length && ACTIONS.test(labels[col]) && onlyButtons(cell)) cell.setAttribute('data-actions', '')
            else cell.removeAttribute('data-actions')
          }
          col += span
        }
      }
    }
  })
}
