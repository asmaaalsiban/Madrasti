import { useTranslation } from 'react-i18next'

const STYLES = {
  pass: 'bg-green-100 text-green-800',
  fail: 'bg-red-100 text-red-800',
  pending: 'bg-amber-100 text-amber-800',
  retake: 'bg-orange-100 text-orange-800',
  none: 'bg-slate-100 text-slate-600',
}
const LABELS = { pass: 'grading.pass', fail: 'grading.fail', pending: 'grading.makeupPending', retake: 'grading.retakePending', none: 'grading.notGraded' }

// kind: 'pass' | 'fail' | 'pending' (waiting for a makeup exam) | 'retake' (failed, waiting for the retake) | 'none'; label overrides the text
export default function ResultBadge({ kind, label }) {
  const { t } = useTranslation()
  return <span className={`inline-block rounded px-2 py-0.5 text-xs font-medium ${STYLES[kind]}`}>{label ?? t(LABELS[kind])}</span>
}
