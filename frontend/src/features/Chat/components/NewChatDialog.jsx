import { useMutation, useQuery } from '@tanstack/react-query'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { api } from '../../../api/client'
import Modal from '../../../components/shared/Modal'
import { InteractiveHoverButton } from '../../../components/ui/interactive-hover-button'

// Start a chat: search the people you may write to and pick one.
export default function NewChatDialog({ onClose, onOpened }) {
  const { t } = useTranslation()
  const [search, setSearch] = useState('')
  const contacts = useQuery({ queryKey: ['chat', 'contacts', search], queryFn: async () => (await api.get('/chat/contacts', { params: { search } })).data.data })
  const open = useMutation({ mutationFn: (userId) => api.post('/chat/conversations', { user_id: userId }).then((r) => r.data.data), onSuccess: (c) => onOpened(c.id) })

  return (
    <Modal open title={t('chat.newChat')} onClose={onClose}>
      <div className="space-y-3">
        <input aria-label={t('chat.search')} placeholder={t('chat.search')} className="w-full rounded border p-2" value={search} onChange={(e) => setSearch(e.target.value)} />
        {contacts.isSuccess && contacts.data.length === 0 && <p className="text-sm text-slate-500">{t('chat.noContacts')}</p>}
        <ul className="max-h-80 divide-y overflow-y-auto">
          {(contacts.data ?? []).map((p) => (
            <li key={p.id}>
              <button type="button" className="block w-full p-2 text-start hover:bg-slate-50" onClick={() => open.mutate(p.id)}>
                <span className="font-medium">{p.name}</span>
                <span className="block text-xs text-slate-500">{t(`roles.${p.role}`)}{p.label ? ` · ${p.label}` : ''}</span>
              </button>
            </li>
          ))}
        </ul>
        <div className="flex justify-end"><InteractiveHoverButton variant="outline" type="button" className="px-3 py-1.5" onClick={onClose}>{t('admin.actions.cancel')}</InteractiveHoverButton></div>
      </div>
    </Modal>
  )
}
