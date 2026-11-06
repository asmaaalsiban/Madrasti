// The screens of a member of staff that the principal can switch off. `paths` are under /teacher/; a longer path wins over a shorter one.
export const GROUPS = [
  { key: 'general', features: ['chat', 'announcements', 'stories', 'tasks'] },
  { key: 'teaching', features: ['timetable', 'attendance', 'attendance_report', 'exams', 'lesson_plans', 'daily_summary', 'homework', 'question_bank', 'grades'] },
  { key: 'followUp', features: ['behavior', 'motivation', 'surveys', 'signature', 'lesson_plan_review', 'department'] },
  { key: 'mine', features: ['duties', 'staff_attendance', 'payslips', 'procurement'] },
  { key: 'money', features: ['finance', 'renewals', 'payroll'] },
]

const PATHS = {
  chat: ['chat'], announcements: ['announcements'], stories: ['stories'], tasks: ['tasks'],
  timetable: ['timetable'], attendance: ['attendance'], attendance_report: ['attendance/report'], exams: ['exams'], lesson_plans: ['lesson-plans'], daily_summary: ['daily-summary'], homework: ['homework'], question_bank: ['question-bank'], grades: ['grades'],
  behavior: ['behavior'], motivation: ['motivation'], surveys: ['surveys', 'my-surveys'], signature: ['signature'], lesson_plan_review: ['lesson-plans/review'], department: ['department'],
  duties: ['duties'], staff_attendance: ['staff-attendance'], payslips: ['payslips'], procurement: ['procurement'],
  finance: ['finance'], renewals: ['renewals'], payroll: ['payroll'],
}

const TABLE = Object.entries(PATHS).flatMap(([key, paths]) => paths.map((p) => [`/teacher/${p}`, key])).sort((a, b) => b[0].length - a[0].length)

// The feature a staff address belongs to (null when it is not one that can be switched off).
export function featureOfPath(path) {
  const clean = (path ?? '').split('?')[0].replace(/\/+$/, '')
  return TABLE.find(([p]) => clean === p || clean.startsWith(`${p}/`))?.[1] ?? null
}

// Is this address hidden from the signed-in user? Only staff are affected.
export function isHidden(user, path) {
  if (user?.role !== 'teacher') return false
  const key = featureOfPath(path)
  return key !== null && (user.hidden_features ?? []).includes(key)
}
