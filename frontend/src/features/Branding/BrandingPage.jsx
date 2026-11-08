import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useEffect, useRef, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { ImagePlus, Check } from 'lucide-react'
import { api } from '../../api/client'
import ConfirmDialog from '../../components/shared/ConfirmDialog'
import SchoolLogo from '../../components/shared/SchoolLogo'
import { paletteFromFile, shownPrimary } from '../../tenant/palette'
import { applyTheme, clearTheme } from '../../tenant/theme'
import { useSchool } from '../../tenant/useSchool'
import SchoolDetailsCard from './SchoolDetailsCard'

const MAX_BYTES = 2 * 1024 * 1024
const TYPES = ['image/png', 'image/jpeg', 'image/webp']
const errorOf = (err, fallback) => Object.values(err.response?.data?.errors ?? {})[0]?.[0] || err.response?.data?.message || fallback

// A small copy of the site in the colours that will be used: a navy header with the logo, a button, a tile.
function Preview({ logo, primary, dark, name }) {
  const brand = shownPrimary(primary)
  return (
    <div aria-hidden className="overflow-hidden rounded-2xl border bg-white shadow-sm">
      <div className="flex items-center justify-between gap-3 px-4 py-3 text-white" style={{ background: dark }}>
        <span className="flex items-center gap-2 font-extrabold">
          {logo && <span className="grid h-8 w-8 place-items-center overflow-hidden rounded-xl bg-white p-1"><img src={logo} alt="" className="h-full w-full object-contain" /></span>}
          {name}
        </span>
        <span className="rounded-full px-3 py-1 text-xs font-bold text-white" style={{ background: brand }}>●</span>
      </div>
      <div className="flex items-center gap-3 p-4" style={{ background: '#f9f7f0' }}>
        <span className="rounded-full px-5 py-2 text-sm font-bold text-white" style={{ background: brand }}>OK</span>
        <span className="rounded-xl px-4 py-2 text-sm font-bold" style={{ background: `color-mix(in srgb, ${brand} 20%, white)`, color: dark }}>Aa</span>
        <span className="text-sm font-bold" style={{ color: brand }}>link</span>
      </div>
    </div>
  )
}

// The principal's school look: upload the logo, the site reads its colours and turns into the school's theme. Everything else stays as it is.
export default function BrandingPage() {
  const { t } = useTranslation()
  const client = useQueryClient()
  const { data: school } = useSchool()
  const { data } = useQuery({ queryKey: ['branding'], queryFn: async () => (await api.get('/branding')).data.data })
  const input = useRef(null)
  const [file, setFile] = useState(null)
  const [preview, setPreview] = useState(null) // object URL of the chosen file
  const [palette, setPalette] = useState(null) // { primary, dark, swatches } | null
  const [primary, setPrimary] = useState(null)
  const [message, setMessage] = useState(null)
  const [confirmReset, setConfirmReset] = useState(false)

  useEffect(() => () => { if (preview) URL.revokeObjectURL(preview) }, [preview])

  const pick = async (e) => {
    const f = e.target.files?.[0]
    e.target.value = ''
    if (!f) return
    setMessage(null)
    if (!TYPES.includes(f.type)) return setMessage({ ok: false, text: t('branding.badType') })
    if (f.size > MAX_BYTES) return setMessage({ ok: false, text: t('branding.tooBig') })
    setFile(f)
    setPreview(URL.createObjectURL(f))
    try {
      const p = await paletteFromFile(f)
      setPalette(p)
      setPrimary(p?.primary ?? null)
      if (!p) setMessage({ ok: false, text: t('branding.noColours') })
    } catch {
      setPalette(null)
      setPrimary(null)
      setMessage({ ok: false, text: t('branding.unreadable') })
    }
  }

  const refreshed = () => { client.invalidateQueries({ queryKey: ['branding'] }); client.invalidateQueries({ queryKey: ['school'] }); client.invalidateQueries({ queryKey: ['auth-image'] }) }
  const save = useMutation({
    mutationFn: () => {
      const body = new FormData()
      if (file) body.append('logo', file)
      if (primary) body.append('primary_color', primary)
      if (palette?.dark) body.append('dark_color', palette.dark)
      return api.post('/branding', body)
    },
    onSuccess: () => {
      if (primary) applyTheme({ primary, dark: palette?.dark })
      setFile(null); setPreview(null); setPalette(null); setPrimary(null)
      setMessage({ ok: true, text: t('branding.saved') })
      refreshed()
    },
    onError: (err) => setMessage({ ok: false, text: errorOf(err, t('admin.actions.error')) }),
  })
  const reset = useMutation({
    mutationFn: () => api.delete('/branding'),
    onSuccess: () => { clearTheme(); setMessage({ ok: true, text: t('branding.resetDone') }); refreshed() },
    onError: (err) => setMessage({ ok: false, text: errorOf(err, t('admin.actions.error')) }),
    onSettled: () => setConfirmReset(false),
  })

  const hasCurrent = Boolean(data?.logo_url || data?.primary_color)
  const darkened = primary && shownPrimary(primary).toLowerCase() !== primary.toLowerCase()
  return (
    <section className="space-y-5">
      <h1 className="text-xl font-bold">{t('branding.title')}</h1>
      <p className="max-w-2xl text-sm text-slate-600">{t('branding.help')}</p>
      {message && <p role={message.ok ? 'status' : 'alert'} className={`rounded p-3 text-sm ${message.ok ? 'bg-green-50 text-green-800' : 'bg-red-50 text-red-700'}`}>{message.text}</p>}

      <div className="grid gap-5 lg:grid-cols-2">
        <div className="space-y-4 rounded-lg border bg-white p-4">
          <h2 className="text-lg">{t('branding.logo')}</h2>
          <div className="flex items-center gap-4">
            {preview ? <span className="grid h-20 w-20 place-items-center overflow-hidden rounded-2xl border bg-white p-1"><img src={preview} alt={t('branding.chosen')} className="h-full w-full object-contain" /></span>
              : data?.logo_url ? <SchoolLogo url={data.logo_url} className="h-20 w-20" />
              : <span aria-hidden className="grid h-20 w-20 place-items-center rounded-2xl border border-dashed text-slate-400"><ImagePlus size={28} /></span>}
            <div className="space-y-2">
              <input ref={input} type="file" accept="image/png,image/jpeg,image/webp" className="sr-only" aria-label={t('branding.choose')} tabIndex={-1} onChange={pick} />
              <button type="button" className="rounded border px-4 py-2 text-brand" onClick={() => input.current?.click()}>{t('branding.choose')}</button>
              <p className="text-xs text-slate-500">{t('branding.hint')}</p>
            </div>
          </div>

          {palette && (
            <div className="space-y-3 border-t pt-4">
              <h3 className="text-sm font-bold">{t('branding.mainColour')}</h3>
              <ul className="flex flex-wrap gap-2" aria-label={t('branding.mainColour')}>
                {palette.swatches.map((c) => (
                  <li key={c}>
                    <button type="button" aria-label={c} aria-pressed={primary === c} onClick={() => setPrimary(c)} className={`grid h-11 w-11 place-items-center rounded-full border-2 ${primary === c ? 'border-deep-harbor' : 'border-transparent'}`} style={{ background: c }}>
                      {primary === c && <Check size={18} className="text-white drop-shadow" aria-hidden />}
                    </button>
                  </li>
                ))}
                <li className="flex items-center gap-2 ps-2 text-xs text-slate-600"><span aria-hidden className="h-6 w-6 rounded-full border" style={{ background: palette.dark }} />{t('branding.darkColour')}</li>
              </ul>
              {darkened && <p className="text-xs text-slate-500">{t('branding.darkened')}</p>}
            </div>
          )}

          <div className="flex flex-wrap gap-2 border-t pt-4">
            <button type="button" disabled={(!file && !primary) || save.isPending} onClick={() => save.mutate()} className="cta bg-brand text-white disabled:opacity-50">{t('admin.actions.save')}</button>
            {hasCurrent && <button type="button" onClick={() => setConfirmReset(true)} className="rounded-full border border-red-300 px-5 py-2 text-red-700">{t('branding.reset')}</button>}
          </div>
        </div>

        <div className="space-y-3">
          <h2 className="text-lg">{t('branding.preview')}</h2>
          <Preview logo={preview} primary={primary ?? data?.primary_color ?? '#006ddb'} dark={palette?.dark ?? data?.dark_color ?? '#13426f'} name={school?.name ?? ''} />
          <p className="text-xs text-slate-500">{t('branding.previewHelp')}</p>
        </div>
      </div>
      <SchoolDetailsCard />
      <ConfirmDialog open={confirmReset} message={t('branding.confirmReset')} confirmLabel={t('branding.reset')} onConfirm={() => reset.mutate()} onCancel={() => setConfirmReset(false)} />
    </section>
  )
}
