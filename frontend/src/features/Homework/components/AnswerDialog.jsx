import { useMutation, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { api } from '../../../api/client'
import Modal from '../../../components/shared/Modal'
import ImagePicker from './ImagePicker'
import { InteractiveHoverButton } from '../../../components/ui/interactive-hover-button'

// The student's answer to a homework: a text and/or a photo of the notebook. Sending again replaces the answer.
export default function AnswerDialog({ homework, onClose }) {
  const { t } = useTranslation()
  const client = useQueryClient()
  const mine = homework.submission
  const [body, setBody] = useState(mine?.body ?? '')
  const [files, setFiles] = useState([])
  const [dropped, setDropped] = useState([]) // ids of saved pages to remove
  const [error, setError] = useState('')

  const send = useMutation({
    mutationFn: () => {
      const data = new FormData()
      data.append('body', body)
      files.forEach((f) => data.append('images[]', f))
      dropped.forEach((id) => data.append('remove_file_ids[]', String(id)))
      return api.post(`/homework/${homework.id}/submission`, data)
    },
    onSuccess: () => { client.invalidateQueries({ queryKey: ['homework'] }); onClose() },
    onError: (err) => setError(Object.values(err.response?.data?.errors ?? {})[0]?.[0] || t('admin.actions.error')),
  })
  const remove = useMutation({ mutationFn: () => api.delete(`/homework/${homework.id}/submission`), onSuccess: () => { client.invalidateQueries({ queryKey: ['homework'] }); onClose() } })

  return (
    <Modal open title={t('homework.answerTitle', { title: homework.title })} onClose={onClose}>
      <form className="space-y-3" onSubmit={(e) => { e.preventDefault(); send.mutate() }}>
        {error && <p role="alert" className="rounded bg-red-50 p-2 text-sm text-red-700">{error}</p>}
        <label className="block text-sm">{t('homework.yourAnswer')}<textarea rows={4} className="mt-1 w-full rounded border p-2" value={body} onChange={(e) => setBody(e.target.value)} /></label>
        <div className="space-y-1">
          <span className="block text-sm">{t('homework.fields.image')}</span>
          <ImagePicker files={files} onChange={setFiles} saved={(mine?.images ?? []).filter((id) => !dropped.includes(id))} onRemoveSaved={(id) => setDropped([...dropped, id])} />
        </div>
        <div className="flex flex-wrap justify-between gap-2">
          {mine ? <button type="button" className="rounded border border-red-300 px-3 py-1.5 text-red-700" onClick={() => remove.mutate()}>{t('homework.withdraw')}</button> : <span />}
          <span className="flex gap-2">
            <button type="button" className="rounded border px-3 py-1.5" onClick={onClose}>{t('admin.actions.cancel')}</button>
            <InteractiveHoverButton type="submit" className="px-4 py-1.5 disabled:opacity-50" disabled={send.isPending}>{t('homework.send')}</InteractiveHoverButton>
          </span>
        </div>
      </form>
    </Modal>
  )
}
