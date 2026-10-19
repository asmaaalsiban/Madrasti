import { useTranslation } from 'react-i18next'

export default function DataTable({ columns, rows, loading, search, onSearch, onEdit, onDelete, page = 1, lastPage = 1, onPage }) {
  const { t } = useTranslation()
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
              {columns.map((c) => <th key={c.key} className="p-3 text-start font-medium">{c.label}</th>)}
              {(onEdit || onDelete) && <th className="p-3 text-start font-medium">{t('admin.actions.actions')}</th>}
            </tr>
          </thead>
          <tbody>
            {rows.map((row) => (
              <tr key={row.id} className="border-t">
                {columns.map((c) => <td key={c.key} className="p-3">{c.render ? c.render(row) : row[c.key]}</td>)}
                {(onEdit || onDelete) && (
                  <td className="flex gap-2 p-3">
                    {onEdit && <button type="button" className="text-brand" onClick={() => onEdit(row)}>{t('admin.actions.edit')}</button>}
                    {onDelete && <button type="button" className="text-red-600" onClick={() => onDelete(row)}>{t('admin.actions.delete')}</button>}
                  </td>
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
