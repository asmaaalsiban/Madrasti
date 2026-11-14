import { Pencil, Plus, Star, Trash2 } from 'lucide-react'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import ConfirmDialog from '../../components/shared/ConfirmDialog'
import Modal from '../../components/shared/Modal'
import { IconActionButton } from '../../components/ui/icon-action-button'
import { InteractiveHoverButton } from '../../components/ui/interactive-hover-button'
import { errorOf, fieldErrors, useDeletePlan, usePlans, useSavePlan } from './useSuperAdmin'

const EMPTY = { name_ar: '', name_en: '', price_monthly: '', price_yearly: '', currency: 'JOD', max_students: '', description_ar: '', description_en: '', features_ar: '', features_en: '', is_popular: false, is_active: true, sort_order: 0 }
const inputClass = 'mt-1 w-full rounded border p-2'
const lines = (text) => text.split('\n').map((l) => l.trim()).filter(Boolean)

// The pricing plans of the website. Only the owner can change them; the website shows the active ones, in this order.
export default function PlansPage() {
  const { t, i18n } = useTranslation()
  const plans = usePlans()
  const save = useSavePlan()
  const remove = useDeletePlan()
  const [form, setForm] = useState(null) // null = closed; otherwise the plan being added or edited
  const [removing, setRemoving] = useState(null)
  const errors = save.isError ? fieldErrors(save.error) : {}
  const set = (name) => (e) => setForm({ ...form, [name]: e.target.type === 'checkbox' ? e.target.checked : e.target.value })
  const ar = i18n.language === 'ar'

  const open = (plan) => { save.reset(); setForm(plan ? { ...EMPTY, ...plan, max_students: plan.max_students ?? '', description_ar: plan.description_ar ?? '', description_en: plan.description_en ?? '', features_ar: plan.features_ar.join('\n'), features_en: plan.features_en.join('\n') } : EMPTY) }
  const submit = (e) => {
    e.preventDefault()
    const { features_ar: fa, features_en: fe, max_students: max, ...rest } = form
    save.mutate({ ...rest, max_students: max === '' ? null : Number(max), features_ar: lines(fa), features_en: lines(fe), price_monthly: Number(form.price_monthly), price_yearly: Number(form.price_yearly), sort_order: Number(form.sort_order) || 0 }, { onSuccess: () => setForm(null) })
  }
  const field = (name, label, props = {}) => (
    <label className="block text-sm">{label}
      <input className={inputClass} value={form[name]} onChange={set(name)} {...props} />
      {errors[name] && <span role="alert" className="text-xs text-red-700">{errors[name][0]}</span>}
    </label>
  )
  const area = (name, label) => (
    <label className="block text-sm">{label}
      <textarea rows={5} className={inputClass} value={form[name]} onChange={set(name)} />
      {errors[name] && <span role="alert" className="text-xs text-red-700">{errors[name][0]}</span>}
    </label>
  )

  return (
    <section className="space-y-4">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <h1 className="text-xl font-bold">{t('superAdmin.plans.title')}</h1>
        <InteractiveHoverButton type="button" className="px-4 py-2" onClick={() => open(null)}><Plus size={16} aria-hidden />{t('superAdmin.plans.add')}</InteractiveHoverButton>
      </div>
      <p className="text-sm text-slate-600">{t('superAdmin.plans.help')}</p>

      {plans.isLoading && <p className="text-slate-500">{t('common.loading')}</p>}
      {plans.isError && <p role="alert" className="text-red-700">{t('superAdmin.genericError')}</p>}
      {plans.data && (
        <div className="rounded-lg border bg-white">
          <table className="w-full text-start text-sm">
            <thead className="bg-slate-50">
              <tr>
                {['name', 'monthly', 'yearly', 'students', 'shown', 'order', 'actions'].map((k) => <th key={k} className="p-2 text-start font-medium">{t(`superAdmin.plans.cols.${k}`)}</th>)}
              </tr>
            </thead>
            <tbody>
              {plans.data.map((p) => (
                <tr key={p.id} className="border-t">
                  <td className="p-2 font-semibold">{ar ? p.name_ar : p.name_en}{p.is_popular && <Star size={14} aria-label={t('superAdmin.plans.popular')} className="ms-2 inline fill-current text-amber-500" />}</td>
                  <td className="p-2"><span dir="ltr" className="inline-block">{p.price_monthly} {p.currency}</span></td>
                  <td className="p-2"><span dir="ltr" className="inline-block">{p.price_yearly} {p.currency}</span></td>
                  <td className="p-2">{p.max_students ?? t('superAdmin.unlimited')}</td>
                  <td className="p-2">{p.is_active ? t('superAdmin.plans.yes') : t('superAdmin.plans.no')}</td>
                  <td className="p-2">{p.sort_order}</td>
                  <td className="p-2"><div className="flex gap-2">
                    <IconActionButton icon={Pencil} label={t('admin.actions.edit')} onClick={() => open(p)} />
                    <IconActionButton icon={Trash2} tone="red" label={t('admin.actions.delete')} onClick={() => setRemoving(p)} />
                  </div></td>
                </tr>
              ))}
              {plans.data.length === 0 && <tr><td colSpan={7} className="p-4 text-center text-slate-500">{t('superAdmin.plans.none')}</td></tr>}
            </tbody>
          </table>
        </div>
      )}

      <Modal open={Boolean(form)} title={form?.id ? t('superAdmin.plans.edit') : t('superAdmin.plans.add')} onClose={() => setForm(null)} size="lg">
        {form && (
          <form onSubmit={submit} className="grid gap-3 sm:grid-cols-2">
            {field('name_ar', t('superAdmin.plans.fields.nameAr'), { required: true })}
            {field('name_en', t('superAdmin.plans.fields.nameEn'), { required: true, dir: 'ltr' })}
            {field('price_monthly', t('superAdmin.plans.fields.monthly'), { type: 'number', min: 0, step: '0.01', required: true, dir: 'ltr' })}
            {field('price_yearly', t('superAdmin.plans.fields.yearly'), { type: 'number', min: 0, step: '0.01', required: true, dir: 'ltr' })}
            {field('currency', t('superAdmin.plans.fields.currency'), { maxLength: 3, required: true, dir: 'ltr' })}
            {field('max_students', t('superAdmin.plans.fields.students'), { type: 'number', min: 1, dir: 'ltr', placeholder: t('superAdmin.unlimited') })}
            {field('description_ar', t('superAdmin.plans.fields.descriptionAr'))}
            {field('description_en', t('superAdmin.plans.fields.descriptionEn'), { dir: 'ltr' })}
            {area('features_ar', t('superAdmin.plans.fields.featuresAr'))}
            {area('features_en', t('superAdmin.plans.fields.featuresEn'))}
            {field('sort_order', t('superAdmin.plans.fields.order'), { type: 'number', min: 0, dir: 'ltr' })}
            <div className="flex flex-col justify-end gap-2 text-sm">
              <label className="flex items-center gap-2"><input type="checkbox" checked={form.is_popular} onChange={set('is_popular')} />{t('superAdmin.plans.fields.popular')}</label>
              <label className="flex items-center gap-2"><input type="checkbox" checked={form.is_active} onChange={set('is_active')} />{t('superAdmin.plans.fields.shown')}</label>
            </div>
            {save.isError && !Object.keys(errors).length && <p role="alert" className="text-sm text-red-700 sm:col-span-2">{errorOf(save.error, t('superAdmin.genericError'))}</p>}
            <div className="flex justify-end gap-2 sm:col-span-2">
              <InteractiveHoverButton variant="outline" type="button" className="px-4 py-2" onClick={() => setForm(null)}>{t('admin.actions.cancel')}</InteractiveHoverButton>
              <InteractiveHoverButton type="submit" disabled={save.isPending} className="px-4 py-2 disabled:opacity-60">{t('admin.actions.save')}</InteractiveHoverButton>
            </div>
          </form>
        )}
      </Modal>

      <ConfirmDialog open={Boolean(removing)} message={t('superAdmin.plans.deleteQuestion', { name: ar ? removing?.name_ar : removing?.name_en })} busy={remove.isPending} onCancel={() => setRemoving(null)} onConfirm={() => remove.mutate(removing.id, { onSuccess: () => setRemoving(null) })} />
    </section>
  )
}
