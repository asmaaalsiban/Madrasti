import { render } from '@testing-library/react'
import { describe, expect, it } from 'vitest'
import ResponsiveTables from '../src/components/shared/ResponsiveTables'
import { labelTables } from '../src/components/shared/labelTables'

const table = (cols, extra = '', attrs = '') => `
  <table ${attrs}><thead><tr>${cols.map((c) => `<th>${c}</th>`).join('')}</tr></thead>
  <tbody><tr>${cols.map((c) => `<td>${c}-value</td>`).join('')}</tr>${extra}</tbody></table>`

describe('labelTables', () => {
  it('turns a wide table (5+ columns) into cards: marks it and names every cell after its column', () => {
    document.body.innerHTML = table(['Name', 'Class', 'Phone', 'Due', 'Paid'])
    labelTables()
    const t = document.querySelector('table')
    expect(t).toHaveAttribute('data-stack')
    expect([...t.querySelectorAll('tbody td')].map((td) => td.getAttribute('data-label'))).toEqual(['Name', 'Class', 'Phone', 'Due', 'Paid'])
  })

  it('leaves a narrow table (under 5 columns) alone: it is shrunk by CSS, not stacked', () => {
    document.body.innerHTML = table(['Name', 'Class', 'Phone', 'Due'])
    labelTables()
    expect(document.querySelector('table')).not.toHaveAttribute('data-stack')
    expect(document.querySelector('td')).not.toHaveAttribute('data-label')
  })

  it('leaves a grid (data-grid) alone, however many columns it has', () => {
    document.body.innerHTML = table(['A', 'B', 'C', 'D', 'E', 'F'], '', 'data-grid')
    labelTables()
    expect(document.querySelector('table')).not.toHaveAttribute('data-stack')
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
