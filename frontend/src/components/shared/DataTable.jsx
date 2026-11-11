import { useTranslation } from 'react-i18next'

// extraActions: [{ key, label, onClick(row), when?(row) }] shown between Edit and Delete.
export default function DataTable({ columns, rows, loading, search, onSearch, onEdit, onDelete, extraActions = [], page = 1, lastPage = 1, onPage }) {
  const { t } = useTranslation()
  const hasActions = Boolean(onEdit || onDelete || extraActions.length)
  // On a phone a row is a card: the name column is its title and the actions a row of buttons (CSS in index.css, `data-title` / `data-actions`).
  const titleKey = columns.find((c) => /^name/.test(c.key))?.key
  return (
    <div className="space-y-3">
      <input
        type="search"
        className="w-full max-w-xs rounded border p-2 text-sm"
        placeholder={t('admin.actions.search')}
        value={search}
        onChange={(e) => onSearch(e.target.value)}
      />
      <div className="overflow-x-auto rounded-lg bg-white shadow-sm">
        <table className="w-full text-start text-sm">
          <thead className="bg-slate-100">
            <tr>
              {columns.map((c) => <th key={c.key} className="p-2 text-start font-medium sm:p-3">{c.label}</th>)}
              {hasActions && <th className="p-2 text-start font-medium sm:p-3">{t('admin.actions.actions')}</th>}
            </tr>
          </thead>
          <tbody>
            {rows.map((row) => (
              <tr key={row.id} className="border-t">
                {columns.map((c) => <td key={c.key} className="p-2 sm:p-3" data-title={c.key === titleKey ? '' : undefined}>{c.render ? c.render(row) : row[c.key]}</td>)}
                {hasActions && (
                  <td className="p-2 sm:p-3" data-actions=""><div className="flex flex-wrap gap-2">
                    {onEdit && <button type="button" className="text-brand" onClick={() => onEdit(row)}>{t('admin.actions.edit')}</button>}
                    {extraActions.filter((a) => !a.when || a.when(row)).map((a) => (
                      <button key={a.key} type="button" className="text-slate-700 underline" onClick={() => a.onClick(row)}>{a.label}</button>
                    ))}
                    {onDelete && <button type="button" className="text-red-600" onClick={() => onDelete(row)}>{t('admin.actions.delete')}</button>}
                  </div></td>
                )}
              </tr>
            ))}
          </tbody>
        </table>
        {!loading && rows.length === 0 && <p className="p-6 text-center text-slate-500">{t('admin.actions.empty')}</p>}
      </div>
      {lastPage > 1 && (
        <div className="flex items-center justify-between text-sm">
          <button type="button" className="rounded border px-3 py-1 disabled:opacity-50" disabled={page <= 1} onClick={() => onPage(page - 1)}>{t('admin.actions.prev')}</button>
          <span>{page} / {lastPage}</span>
          <button type="button" className="rounded border px-3 py-1 disabled:opacity-50" disabled={page >= lastPage} onClick={() => onPage(page + 1)}>{t('admin.actions.next')}</button>
        </div>
      )}
    </div>
  )
}
