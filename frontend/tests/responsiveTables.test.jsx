import { render } from '@testing-library/react'
import { describe, expect, it } from 'vitest'
import ResponsiveTables from '../src/components/shared/ResponsiveTables'
import { labelTables } from '../src/components/shared/labelTables'

const table = (cols, extra = '', attrs = '') => `
  <table ${attrs}><thead><tr>${cols.map((c) => `<th>${c}</th>`).join('')}</tr></thead>
  <tbody><tr>${cols.map((c) => `<td>${c}-value</td>`).join('')}</tr>${extra}</tbody></table>`

describe('labelTables', () => {
  it('turns a data table (3+ columns) into cards: marks it and names every cell after its column', () => {
    document.body.innerHTML = table(['Name', 'Class', 'Phone', 'Due', 'Paid'])
    labelTables()
    const t = document.querySelector('table')
    expect(t).toHaveAttribute('data-stack')
    expect([...t.querySelectorAll('tbody td')].map((td) => td.getAttribute('data-label'))).toEqual(['Name', 'Class', 'Phone', 'Due', 'Paid'])
  })

  it('leaves a table of two columns alone: it is shrunk by CSS, not stacked', () => {
    document.body.innerHTML = table(['Name', 'Class'])
    labelTables()
    expect(document.querySelector('table')).not.toHaveAttribute('data-stack')
    expect(document.querySelector('td')).not.toHaveAttribute('data-label')
  })

  it('leaves a grid (data-grid) alone, however many columns it has', () => {
    document.body.innerHTML = table(['A', 'B', 'C', 'D', 'E', 'F'], '', 'data-grid')
    labelTables()
    expect(document.querySelector('table')).not.toHaveAttribute('data-stack')
  })

  it('makes the name column the title of the card and a cell of only buttons or links its actions', () => {
    document.body.innerHTML = `<table><thead><tr><th>Code</th><th>Student name</th><th>Class</th><th><span>Actions</span></th></tr></thead>
      <tbody><tr><td>S-1</td><td>Ahmad</td><td>5-A</td><td><button>Edit</button><a href="#">Open</a></td></tr>
      <tr><td>S-2</td><td>Sara</td><td>5-B</td><td><input type="checkbox" /></td></tr></tbody></table>`
    labelTables()
    const rows = document.querySelectorAll('tbody tr')
    expect(rows[0].querySelectorAll('td')[1]).toHaveAttribute('data-title')
    expect(rows[0].querySelectorAll('td')[0]).not.toHaveAttribute('data-title')
    expect(rows[0].querySelectorAll('td')[3]).toHaveAttribute('data-actions')
    expect(rows[1].querySelectorAll('td')[3]).not.toHaveAttribute('data-actions') // a checkbox is a field to fill, not an action
  })

  it('leaves the title and actions of a DataTable to the DataTable (data-own-cards)', () => {
    document.body.innerHTML = `<table data-own-cards><thead><tr><th>Name</th><th>Class</th><th>Actions</th></tr></thead><tbody><tr><td>Ahmad</td><td>5-A</td><td><button>Edit</button></td></tr></tbody></table>`
    labelTables()
    expect(document.querySelector('td')).not.toHaveAttribute('data-title')
    expect(document.querySelectorAll('td')[2]).not.toHaveAttribute('data-actions')
    expect(document.querySelector('td')).toHaveAttribute('data-label', 'Name')
  })

  it('gives a cell that spans columns (an empty-state or totals row) no label', () => {
    document.body.innerHTML = table(['A', 'B', 'C', 'D', 'E'], '<tr><td colspan="5">Nothing here</td></tr><tr><td colspan="2">Total</td><td>x</td><td>y</td><td>z</td></tr>')
    labelTables()
    const rows = document.querySelectorAll('tbody tr')
    expect(rows[1].querySelector('td')).not.toHaveAttribute('data-label')
    expect([...rows[2].querySelectorAll('td')].map((td) => td.getAttribute('data-label'))).toEqual([null, 'C', 'D', 'E'])
  })

  it('reads the names from the last header row and skips the screen-reader-only actions header', () => {
    document.body.innerHTML = `<table><thead><tr><th colspan="2">Group</th><th colspan="3">Other</th></tr><tr><th>A</th><th>B</th><th>C</th><th>D</th><th>E</th></tr></thead><tbody><tr><td>1</td><td>2</td><td>3</td><td>4</td><td>5</td></tr></tbody></table>`
    labelTables()
    expect([...document.querySelectorAll('tbody td')].map((td) => td.getAttribute('data-label'))).toEqual(['A', 'B', 'C', 'D', 'E'])
  })
})

describe('ResponsiveTables', () => {
  it('labels tables that are already there and tables that appear later', async () => {
    document.body.innerHTML = table(['A', 'B', 'C', 'D', 'E'])
    render(<ResponsiveTables />)
    expect(document.querySelector('table')).toHaveAttribute('data-stack')
    document.body.insertAdjacentHTML('beforeend', table(['V', 'W', 'X', 'Y', 'Z']))
    await new Promise((resolve) => setTimeout(resolve, 60))
    expect(document.querySelectorAll('table[data-stack]')).toHaveLength(2)
  })
})
