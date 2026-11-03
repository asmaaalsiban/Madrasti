import { api } from './client'

// Uploads files to an owner (task, task_part, survey) after it exists.
export async function uploadAttachments(type, id, files) {
  if (!files?.length) return null
  const data = new FormData()
  files.forEach((f) => data.append('files[]', f))
  return api.post(`/attachments/${type}/${id}`, data)
}

// Opens or downloads a private file: it is fetched with the sign-in token, then shown from a blob.
export async function openAttachment(item) {
  const blob = (await api.get(`/attachments/${item.id}`, { responseType: 'blob' })).data
  const url = URL.createObjectURL(blob)
  if (item.is_image) {
    window.open(url, '_blank', 'noopener')
    return
  }
  const a = document.createElement('a')
  a.href = url
  a.download = item.name
  document.body.appendChild(a)
  a.click()
  a.remove()
  setTimeout(() => URL.revokeObjectURL(url), 10000)
}
