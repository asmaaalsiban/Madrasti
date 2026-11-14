import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { api } from '../../api/client'
import { InteractiveHoverButton } from '../ui/interactive-hover-button'

// The file name the server chose (Content-Disposition), or a fallback.
const fileName = (headers, fallback) => /filename\*?=(?:UTF-8'')?"?([^";]+)"?/i.exec(headers?.['content-disposition'] ?? '')?.[1] ?? fallback

// "Export to Excel": downloads what the page is showing, with the same filters. `params` must not include the page number.
export default function ExportButton({ path, params = {}, fallbackName = 'export.xlsx', label = null }) {
  const { t } = useTranslation()
  const [busy, setBusy] = useState(false)
  const [failed, setFailed] = useState(false)

  const download = async () => {
    setBusy(true)
    setFailed(false)
    try {
      const response = await api.get(path, { params, responseType: 'blob' })
      const url = URL.createObjectURL(response.data)
      const link = document.createElement('a')
      link.href = url
      link.download = decodeURIComponent(fileName(response.headers, fallbackName))
      document.body.appendChild(link)
      link.click()
      link.remove()
      URL.revokeObjectURL(url)
    } catch {
      setFailed(true)
    } finally {
      setBusy(false)
    }
  }

  return (
    <span className="inline-flex items-center gap-2">
      <InteractiveHoverButton variant="outline" type="button" disabled={busy} className="px-3 py-1.5 text-sm text-brand disabled:opacity-50" onClick={download}>{label ?? t('common.exportExcel')}</InteractiveHoverButton>
      {failed && <span role="alert" className="text-sm text-red-700">{t('common.exportFailed')}</span>}
    </span>
  )
}
