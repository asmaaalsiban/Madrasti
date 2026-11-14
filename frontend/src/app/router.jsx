import { lazy } from 'react'
import { Navigate, createBrowserRouter, useLocation } from 'react-router-dom'
import NotFoundPage from '../components/shared/NotFoundPage'
import AdminLayout from '../layouts/AdminLayout'
import DashboardLayout from '../layouts/DashboardLayout'
import SuspendedPage from '../features/Auth/SuspendedPage'
import LoginPage from '../features/Auth/pages/LoginPage'
import ForgotPasswordPage from '../features/Auth/pages/ForgotPasswordPage'
import LandingPage from '../features/Auth/pages/LandingPage'
import WelcomePage from '../features/Central/WelcomePage'
import SuperAdminLayout from '../features/SuperAdmin/SuperAdminLayout'
import { getSchool } from '../tenant/school'
import LegalShell from '../features/Legal/LegalFooter'
import { useAuth } from '../features/Auth/AuthProvider'
import { RequireAuth, RequireRole } from './guards'

// Every page loads on demand (the first screen no longer downloads the code of all 100+ pages).
const ChangePasswordPage = lazy(() => import('../features/Auth/pages/ChangePasswordPage'))
const DownloadYourDataPage = lazy(() => import('../features/Auth/DownloadYourDataPage'))
const FindSchoolPage = lazy(() => import('../features/Central/FindSchoolPage'))
const RegisterSchoolPage = lazy(() => import('../features/Central/RegisterSchoolPage'))
const SchoolsPage = lazy(() => import('../features/SuperAdmin/SchoolsPage'))
const SchoolPage = lazy(() => import('../features/SuperAdmin/SchoolPage'))
const AddSchoolPage = lazy(() => import('../features/SuperAdmin/AddSchoolPage'))
const PlansPage = lazy(() => import('../features/SuperAdmin/PlansPage'))
const AcademicYearsPage = lazy(() => import('../features/Academic/pages/AcademicYearsPage'))
const ClassroomsPage = lazy(() => import('../features/Academic/pages/ClassroomsPage'))
const GradeLevelsPage = lazy(() => import('../features/Academic/pages/GradeLevelsPage'))
const OverviewPage = lazy(() => import('../features/Academic/pages/OverviewPage'))
const TeachingAssignmentsPage = lazy(() => import('../features/Academic/pages/TeachingAssignmentsPage'))
const TermsPage = lazy(() => import('../features/Academic/pages/TermsPage'))
const SubjectsPage = lazy(() => import('../features/Academic/pages/SubjectsPage'))
const RoleHome = lazy(() => import('../features/Auth/pages/RoleHome'))
const AttendanceReportPage = lazy(() => import('../features/Attendance/pages/AttendanceReportPage'))
const TakeAttendancePage = lazy(() => import('../features/Attendance/pages/TakeAttendancePage'))
const DepartmentPage = lazy(() => import('../features/Teacher/pages/DepartmentPage'))
const TeacherHome = lazy(() => import('../features/Teacher/pages/TeacherHome'))
const AssessmentsPage = lazy(() => import('../features/Grading/pages/AssessmentsPage'))
const ResultsPage = lazy(() => import('../features/Grading/pages/ResultsPage'))
const ScoreSheetPage = lazy(() => import('../features/Grading/pages/ScoreSheetPage'))
const ChildGradesPage = lazy(() => import('../features/Parent/pages/ChildGradesPage'))
const StudentHome = lazy(() => import('../features/Student/pages/StudentHome'))
const BuildingsPage = lazy(() => import('../features/Academic/pages/BuildingsPage'))
const GenerateClassroomsPage = lazy(() => import('../features/Academic/pages/GenerateClassroomsPage'))
const CurriculumPage = lazy(() => import('../features/Academic/pages/CurriculumPage'))
const SchoolDayPage = lazy(() => import('../features/Scheduling/pages/SchoolDayPage'))
const TeacherAvailabilityPage = lazy(() => import('../features/Scheduling/pages/TeacherAvailabilityPage'))
const TimetablePage = lazy(() => import('../features/Scheduling/pages/TimetablePage'))
const StudentTimetablePage = lazy(() => import('../features/Student/pages/StudentTimetablePage'))
const TeacherTimetablePage = lazy(() => import('../features/Teacher/pages/TeacherTimetablePage'))
const ChildTimetablePage = lazy(() => import('../features/Parent/pages/ChildTimetablePage'))
const StudentsPage = lazy(() => import('../features/People/pages/StudentsPage'))
const StudentFilesPage = lazy(() => import('../features/StudentFiles/StudentFilesPage'))
const StaffPage = lazy(() => import('../features/People/pages/StaffPage'))
const BehaviorPage = lazy(() => import('../features/Behavior/pages/BehaviorPage'))
const BehaviorCriteriaPage = lazy(() => import('../features/Behavior/pages/BehaviorCriteriaPage'))
const StudentBehaviorPage = lazy(() => import('../features/Behavior/pages/StudentBehaviorPage'))
const MyBehaviorPage = lazy(() => import('../features/Behavior/pages/StudentBehaviorPage').then((m) => ({ default: m.MyBehaviorPage })))
const ImportPage = lazy(() => import('../features/Import/pages/ImportPage'))
const BrandingPage = lazy(() => import('../features/Branding/BrandingPage'))
const SignaturesPage = lazy(() => import('../features/Reports/pages/SignaturesPage'))
const ReportCardPage = lazy(() => import('../features/Reports/pages/ReportCardPage'))
const MyReportCardPage = lazy(() => import('../features/Reports/pages/ReportCardPage').then((m) => ({ default: m.MyReportCardPage })))
const MySurveysPage = lazy(() => import('../features/Surveys/pages/MySurveysPage'))
const SurveyResponsesPage = lazy(() => import('../features/Surveys/pages/SurveyResponsesPage'))
const SurveyResultsPage = lazy(() => import('../features/Surveys/pages/SurveyResultsPage'))
const SurveysPage = lazy(() => import('../features/Surveys/pages/SurveysPage'))
const MotivationPage = lazy(() => import('../features/Motivation/pages/MotivationPage'))
const MotivationSettingsPage = lazy(() => import('../features/Motivation/pages/MotivationSettingsPage'))
const StudentMotivationPage = lazy(() => import('../features/Motivation/pages/StudentMotivationPage'))
const MyMotivationPage = lazy(() => import('../features/Motivation/pages/StudentMotivationPage').then((m) => ({ default: m.MyMotivationPage })))
const QuestionBankPage = lazy(() => import('../features/QuestionBank/pages/QuestionBankPage'))
const QuizzesPage = lazy(() => import('../features/QuestionBank/pages/QuizzesPage'))
const QuizRunPage = lazy(() => import('../features/QuestionBank/pages/QuizRunPage'))
const ChildQuizzesPage = lazy(() => import('../features/QuestionBank/pages/ChildQuizzesPage'))
const WarningsPage = lazy(() => import('../features/Warnings/pages/WarningsPage'))
const ChildWarningsPage = lazy(() => import('../features/Warnings/pages/ChildWarningsPage'))
const MyWarningsPage = lazy(() => import('../features/Warnings/pages/MyWarningsPage'))
const HomeworkPage = lazy(() => import('../features/Homework/pages/HomeworkPage'))
const StudentHomeworkPage = lazy(() => import('../features/Homework/pages/StudentHomeworkPage'))
const ChildHomeworkPage = lazy(() => import('../features/Homework/pages/ChildHomeworkPage'))
const DailySummaryPage = lazy(() => import('../features/DailySummary/pages/DailySummaryPage'))
const ChildSummariesPage = lazy(() => import('../features/DailySummary/pages/SummariesPages').then((m) => ({ default: m.ChildSummariesPage })))
const StudentSummariesPage = lazy(() => import('../features/DailySummary/pages/SummariesPages').then((m) => ({ default: m.StudentSummariesPage })))
const LessonPlansPage = lazy(() => import('../features/LessonPlans/pages/LessonPlansPage'))
const ReviewPlansPage = lazy(() => import('../features/LessonPlans/pages/ReviewPlansPage'))
const ChildPlansPage = lazy(() => import('../features/LessonPlans/pages/PlansPages').then((m) => ({ default: m.ChildPlansPage })))
const StudentPlansPage = lazy(() => import('../features/LessonPlans/pages/PlansPages').then((m) => ({ default: m.StudentPlansPage })))
const AnnouncementsPage = lazy(() => import('../features/Announcements/pages/AnnouncementsPage'))
const FinanceDashboardPage = lazy(() => import('../features/Finance/pages/FinanceDashboardPage'))
const FeeStructuresPage = lazy(() => import('../features/Finance/pages/FeeStructuresPage'))
const AccountsPage = lazy(() => import('../features/Finance/pages/AccountsPage'))
const StudentAccountPage = lazy(() => import('../features/Finance/pages/StudentAccountPage'))
const ChildFeesPage = lazy(() => import('../features/Finance/pages/ChildFeesPage'))
const PayrollPage = lazy(() => import('../features/Payroll/pages/PayrollPage'))
const PayrollRunPage = lazy(() => import('../features/Payroll/pages/PayrollRunPage'))
const PayslipPage = lazy(() => import('../features/Payroll/pages/PayslipPage'))
const MyPayslipsPage = lazy(() => import('../features/Payroll/pages/MyPayslipsPage'))
const RenewalsPage = lazy(() => import('../features/Renewals/pages/RenewalsPage'))
const StaffAttendancePage = lazy(() => import('../features/StaffAttendance/StaffAttendancePage'))
const RulesPage = lazy(() => import('../features/Payroll/pages/RulesPage'))
const TemplatesPage = lazy(() => import('../features/Payroll/pages/TemplatesPage'))
const AuditLogPage = lazy(() => import('../features/Audit/AuditLogPage'))
const ExamsAdminPage = lazy(() => import('../features/Exams/ExamsAdminPage'))
const MyExamsPage = lazy(() => import('../features/Exams/MyExamsPage'))
const ChildExamsPage = lazy(() => import('../features/Exams/ChildExamsPage'))
const DutiesAdminPage = lazy(() => import('../features/Duties/DutiesAdminPage'))
const MyDutiesPage = lazy(() => import('../features/Duties/MyDutiesPage'))
const StoriesPage = lazy(() => import('../features/Stories/StoriesPage'))
const ProcurementPage = lazy(() => import('../features/Procurement/ProcurementPage'))
const PrivacyPage = lazy(() => import('../features/Privacy/PrivacyPage'))
const ProfilePage = lazy(() => import('../features/Profile/ProfilePage'))
const PhotoConsentPage = lazy(() => import('../features/Privacy/PhotoConsentPage'))
const PrivacyRequestsPage = lazy(() => import('../features/Privacy/PrivacyRequestsPage'))
const LegalPage = lazy(() => import('../features/Legal/LegalPage'))
const YearEndPage = lazy(() => import('../features/Finance/pages/YearEndPage'))
const AccountingReportsPage = lazy(() => import('../features/Finance/pages/AccountingReportsPage'))
const BudgetsPage = lazy(() => import('../features/Finance/pages/BudgetsPage'))
const TaxPage = lazy(() => import('../features/Finance/pages/TaxPage'))
const FullTimetablePage = lazy(() => import('../features/Scheduling/pages/FullTimetablePage'))
const CoverPage = lazy(() => import('../features/Scheduling/pages/CoverPage'))
const JournalPage = lazy(() => import('../features/Finance/pages/JournalPage'))
const MoneyAccountsPage = lazy(() => import('../features/Finance/pages/MoneyAccountsPage'))
const MoneyLedgerPage = lazy(() => import('../features/Finance/pages/MoneyLedgerPage'))
const ExpensesPage = lazy(() => import('../features/Finance/pages/ExpensesPage'))
const SuppliersPage = lazy(() => import('../features/Finance/pages/SuppliersPage'))
const TransfersPage = lazy(() => import('../features/Finance/pages/TransfersPage'))
const StaffAccessPage = lazy(() => import('../features/StaffAccess/StaffAccessPage'))
const TasksPage = lazy(() => import('../features/Tasks/TasksPage'))
const TransportPage = lazy(() => import('../features/Transport/TransportPage'))
const MyTransportPage = lazy(() => import('../features/Transport/MyTransportPage'))
const BoardingReportPage = lazy(() => import('../features/Transport/BoardingReportPage'))
const MyBusPage = lazy(() => import('../features/Transport/MyBusPage'))
const ContractsRoute = lazy(() => import('../features/Payroll/pages/ContractsRoute'))
const ChatPage = lazy(() => import('../features/Chat/pages/ChatPage'))
const ParentHome = lazy(() => import('../features/Parent/pages/ParentHome'))

const GlassDemoPage = lazy(() => import('../features/Demo/GlassDemoPage'))
const PricingDemoPage = lazy(() => import('../features/Demo/PricingDemoPage'))

const ROLES = ['admin', 'teacher', 'student', 'parent']

// A notification link /chat?c=5 goes to the chat page of whoever opens it.
function ChatRedirect() {
  const { user, loading } = useAuth()
  const { search } = useLocation()
  if (loading) return null
  return <Navigate to={user ? `/${user.role}/chat${search}` : '/login'} replace />
}

// Pages of the non-admin roles (anything not listed shows the placeholder home).
const HOME_ROUTES = {
  parent: [
    { index: true, element: <ParentHome /> },
    { path: 'children/:id/grades', element: <ChildGradesPage /> },
    { path: 'children/:id/timetable', element: <ChildTimetablePage /> },
    { path: 'children/:id/exams', element: <ChildExamsPage /> },
    { path: 'privacy', element: <PrivacyPage /> },
    { path: 'profile', element: <ProfilePage /> },
    { path: 'children/:id/transport', element: <MyTransportPage /> },
    { path: 'stories', element: <StoriesPage /> },
    { path: 'children/:id/stories', element: <Navigate to="/parent/stories" replace /> }, // old notifications pointed here
    { path: 'children/:id/homework', element: <ChildHomeworkPage /> },
    { path: 'children/:id/quizzes', element: <ChildQuizzesPage /> },
    { path: 'children/:id/warnings', element: <ChildWarningsPage /> },
    { path: 'children/:id/behavior', element: <StudentBehaviorPage /> },
    { path: 'children/:id/motivation', element: <StudentMotivationPage /> },
    { path: 'children/:id/report-card', element: <ReportCardPage /> },
    { path: 'surveys', element: <MySurveysPage /> },
    { path: 'children/:id/fees', element: <ChildFeesPage /> },
    { path: 'children/:id/summaries', element: <ChildSummariesPage /> },
    { path: 'children/:id/lesson-plans', element: <ChildPlansPage /> },
  ],
  student: [
    { index: true, element: <StudentHome /> },
    { path: 'timetable', element: <StudentTimetablePage /> },
    { path: 'privacy', element: <PrivacyPage /> },
    { path: 'profile', element: <ProfilePage /> },
    { path: 'grades', element: <StudentHome /> },
    { path: 'homework', element: <StudentHomeworkPage /> },
    { path: 'quizzes', element: <QuizzesPage /> },
    { path: 'warnings', element: <MyWarningsPage /> },
    { path: 'quizzes/:id', element: <QuizRunPage /> },
    { path: 'behavior', element: <MyBehaviorPage /> },
    { path: 'motivation', element: <MyMotivationPage /> },
    { path: 'report-card', element: <MyReportCardPage /> },
    { path: 'exams', element: <MyExamsPage /> },
    { path: 'transport', element: <MyTransportPage /> },
    { path: 'stories', element: <StoriesPage /> },
    { path: 'surveys', element: <MySurveysPage /> },
    { path: 'summaries', element: <StudentSummariesPage /> },
    { path: 'lesson-plans', element: <StudentPlansPage /> },
  ],
  teacher: [
    { index: true, element: <TeacherHome /> },
    { path: 'attendance', element: <TakeAttendancePage /> },
    { path: 'attendance/report', element: <AttendanceReportPage /> },
    { path: 'department', element: <DepartmentPage /> },
    { path: 'timetable', element: <TeacherTimetablePage /> },
    { path: 'behavior', element: <BehaviorPage base="/teacher" /> },
    { path: 'behavior/students/:id', element: <StudentBehaviorPage staff /> },
    { path: 'motivation', element: <MotivationPage base="/teacher" /> },
    { path: 'surveys', element: <SurveysPage base="/teacher" /> },
    { path: 'signature', element: <SignaturesPage /> },
    { path: 'my-surveys', element: <MySurveysPage /> },
    { path: 'surveys/:id', element: <SurveyResultsPage base="/teacher" /> },
    { path: 'surveys/:id/responses', element: <SurveyResponsesPage base="/teacher" /> },
    { path: 'motivation/students/:id', element: <StudentMotivationPage staff /> },
    { path: 'homework', element: <HomeworkPage /> },
    { path: 'question-bank', element: <QuestionBankPage /> },
    { path: 'warnings', element: <MyWarningsPage /> },
    { path: 'daily-summary', element: <DailySummaryPage /> },
    { path: 'lesson-plans', element: <LessonPlansPage /> },
    { path: 'lesson-plans/review', element: <ReviewPlansPage /> },
    { path: 'finance', element: <FinanceDashboardPage base="/teacher" /> },
    { path: 'finance/structures', element: <FeeStructuresPage /> },
    { path: 'finance/accounts', element: <AccountsPage base="/teacher" /> },
    { path: 'finance/transfers', element: <TransfersPage base="/teacher" /> },
    { path: 'my-bus', element: <MyBusPage /> },
    { path: 'finance/year-end', element: <YearEndPage base="/teacher" /> },
    { path: 'finance/money', element: <MoneyAccountsPage base="/teacher" /> },
    { path: 'finance/journal', element: <JournalPage /> },
    { path: 'finance/tax', element: <TaxPage /> },
    { path: 'finance/budgets', element: <BudgetsPage /> },
    { path: 'finance/accounting-reports', element: <AccountingReportsPage /> },
    { path: 'finance/money/:id', element: <MoneyLedgerPage base="/teacher" /> },
    { path: 'finance/expenses', element: <ExpensesPage base="/teacher" /> },
    { path: 'finance/suppliers', element: <SuppliersPage base="/teacher" /> },
    { path: 'finance/students/:id', element: <StudentAccountPage /> },
    { path: 'renewals', element: <RenewalsPage /> },
    { path: 'exams', element: <MyExamsPage /> },
    { path: 'staff-attendance', element: <StaffAttendancePage /> },
    { path: 'payroll/rules', element: <RulesPage base="/teacher" /> },
    { path: 'payroll/templates', element: <TemplatesPage base="/teacher" /> },
    { path: 'duties', element: <MyDutiesPage /> },
    { path: 'procurement', element: <ProcurementPage /> },
    { path: 'tasks', element: <TasksPage /> },
    { path: 'privacy', element: <PrivacyPage /> },
    { path: 'profile', element: <ProfilePage /> },
    { path: 'stories', element: <StoriesPage /> },
    { path: 'payroll', element: <PayrollPage base="/teacher" /> },
    { path: 'payroll/contracts', element: <ContractsRoute base="/teacher" /> },
    { path: 'payroll/payslips/:id', element: <PayslipPage /> },
    { path: 'payroll/:id', element: <PayrollRunPage base="/teacher" /> },
    { path: 'payslips', element: <MyPayslipsPage base="/teacher" /> },
    { path: 'payslips/:id', element: <PayslipPage /> },
    { path: 'grades', element: <AssessmentsPage /> },
    { path: 'grades/results', element: <ResultsPage /> },
    { path: 'grades/:id', element: <ScoreSheetPage /> },
  ],
}

export const routes = [
  { path: '/', element: <LandingPage /> },
  { path: '/login', element: <LoginPage /> },
  { path: '/suspended', element: <SuspendedPage /> },
  { path: '/forgot-password', element: <ForgotPasswordPage /> },
  { path: '/chat', element: <ChatRedirect /> },
  {
    element: <RequireAuth />,
    children: [
      { path: '/change-password', element: <ChangePasswordPage /> },
      { element: <RequireRole role="admin" />, children: [{ path: '/download-your-data', element: <DownloadYourDataPage /> }] },
      {
        element: <DashboardLayout />,
        children: ROLES.filter((role) => role !== 'admin').map((role) => ({
          path: `/${role}`,
          element: <RequireRole role={role} />,
          children: [...(HOME_ROUTES[role] ?? [{ index: true, element: <RoleHome /> }]), { path: 'announcements', element: <AnnouncementsPage /> }, { path: 'chat', element: <ChatPage /> }],
        })),
      },
      {
        element: <RequireRole role="admin" />,
        children: [
          {
            element: <AdminLayout />,
            children: [
              { path: '/admin', element: <OverviewPage /> },
              { path: '/admin/glass-demo', element: <GlassDemoPage /> }, // trial page of the glass dashboard component (sample data)
              { path: '/admin/pricing-demo', element: <PricingDemoPage /> }, // trial page of the pricing block (sample plans, not real prices)
              { path: '/admin/academic-years', element: <AcademicYearsPage /> },
              { path: '/admin/grade-levels', element: <GradeLevelsPage /> },
              { path: '/admin/classrooms', element: <ClassroomsPage /> },
              { path: '/admin/classrooms/generate', element: <GenerateClassroomsPage /> },
              { path: '/admin/subjects', element: <SubjectsPage /> },
              { path: '/admin/buildings', element: <BuildingsPage /> },
              { path: '/admin/curriculum', element: <CurriculumPage /> },
              { path: '/admin/school-day', element: <SchoolDayPage /> },
              { path: '/admin/timetable', element: <TimetablePage /> },
              { path: '/admin/timetable/full', element: <FullTimetablePage /> },
              { path: '/admin/cover', element: <CoverPage /> },
              { path: '/admin/availability', element: <TeacherAvailabilityPage /> },
              { path: '/admin/terms', element: <TermsPage /> },
              { path: '/admin/teaching', element: <TeachingAssignmentsPage /> },
              { path: '/admin/staff', element: <StaffPage /> },
              { path: '/admin/students', element: <StudentsPage /> },
              { path: '/admin/student-files', element: <StudentFilesPage /> },
              { path: '/admin/announcements', element: <AnnouncementsPage /> },
              { path: '/admin/finance', element: <FinanceDashboardPage base="/admin" /> },
              { path: '/admin/finance/structures', element: <FeeStructuresPage /> },
              { path: '/admin/finance/accounts', element: <AccountsPage base="/admin" /> },
              { path: '/admin/finance/transfers', element: <TransfersPage base="/admin" /> },
              { path: '/admin/finance/year-end', element: <YearEndPage base="/admin" /> },
              { path: '/admin/finance/money', element: <MoneyAccountsPage base="/admin" /> },
              { path: '/admin/question-bank', element: <QuestionBankPage /> },
              { path: '/admin/warnings', element: <WarningsPage /> },
              { path: '/admin/transport/boarding', element: <BoardingReportPage /> },
              { path: '/admin/finance/journal', element: <JournalPage /> },
              { path: '/admin/finance/tax', element: <TaxPage /> },
              { path: '/admin/finance/budgets', element: <BudgetsPage /> },
              { path: '/admin/finance/accounting-reports', element: <AccountingReportsPage /> },
              { path: '/admin/finance/money/:id', element: <MoneyLedgerPage base="/admin" /> },
              { path: '/admin/finance/expenses', element: <ExpensesPage base="/admin" /> },
              { path: '/admin/finance/suppliers', element: <SuppliersPage base="/admin" /> },
              { path: '/admin/finance/students/:id', element: <StudentAccountPage /> },
              { path: '/admin/renewals', element: <RenewalsPage /> },
              { path: '/admin/exams', element: <ExamsAdminPage /> },
              { path: '/admin/duties', element: <DutiesAdminPage /> },
              { path: '/admin/procurement', element: <ProcurementPage /> },
              { path: '/admin/tasks', element: <TasksPage /> },
              { path: '/admin/privacy', element: <PrivacyPage /> },
              { path: '/admin/profile', element: <ProfilePage /> },
              { path: '/admin/privacy-requests', element: <PrivacyRequestsPage /> },
              { path: '/admin/photo-consent', element: <PhotoConsentPage /> },
              { path: '/admin/staff-access', element: <StaffAccessPage /> },
              { path: '/admin/transport', element: <TransportPage /> },
              { path: '/admin/stories', element: <StoriesPage /> },
              { path: '/admin/audit-log', element: <AuditLogPage /> },
              { path: '/admin/staff-attendance', element: <StaffAttendancePage /> },
              { path: '/admin/payroll/rules', element: <RulesPage base="/admin" /> },
              { path: '/admin/payroll/templates', element: <TemplatesPage base="/admin" /> },
              { path: '/admin/payroll', element: <PayrollPage base="/admin" /> },
              { path: '/admin/payroll/contracts', element: <ContractsRoute base="/admin" /> },
              { path: '/admin/payroll/payslips/:id', element: <PayslipPage /> },
              { path: '/admin/payroll/:id', element: <PayrollRunPage base="/admin" /> },
              { path: '/admin/payslips', element: <MyPayslipsPage base="/admin" /> },
              { path: '/admin/payslips/:id', element: <PayslipPage /> },
              { path: '/admin/chat', element: <ChatPage /> },
              { path: '/admin/behavior', element: <BehaviorPage base="/admin" /> },
              { path: '/admin/behavior/students/:id', element: <StudentBehaviorPage staff /> },
              { path: '/admin/behavior-criteria', element: <BehaviorCriteriaPage /> },
              { path: '/admin/report-card/:id', element: <ReportCardPage /> },
              { path: '/admin/surveys', element: <SurveysPage base="/admin" /> },
              { path: '/admin/signatures', element: <SignaturesPage admin /> },
              { path: '/admin/my-surveys', element: <MySurveysPage /> },
              { path: '/admin/surveys/:id', element: <SurveyResultsPage base="/admin" /> },
              { path: '/admin/surveys/:id/responses', element: <SurveyResponsesPage base="/admin" /> },
              { path: '/admin/import', element: <ImportPage /> },
              { path: '/admin/branding', element: <BrandingPage /> },
              { path: '/admin/motivation', element: <MotivationPage base="/admin" /> },
              { path: '/admin/motivation/students/:id', element: <StudentMotivationPage staff /> },
              { path: '/admin/motivation-settings', element: <MotivationSettingsPage /> },
              { path: '/admin/grades', element: <AssessmentsPage /> },
              { path: '/admin/grades/results', element: <ResultsPage /> },
              { path: '/admin/grades/:id', element: <ScoreSheetPage /> },
              { path: '/admin/attendance', element: <TakeAttendancePage /> },
              { path: '/admin/attendance-report', element: <AttendanceReportPage /> },
            ],
          },
        ],
      },
    ],
  },
  // Any address that matches nothing above (a school is selected): show the "page not found" screen.
  { path: '*', element: <NotFoundPage /> },
]

// The platform owner's area. Not linked from any public page; its own sign-in (the layout shows it without a token) and its own token,
// and it works whether or not a school is selected in this browser.
const superAdminRoutes = [
  {
    path: '/super-admin',
    element: <SuperAdminLayout />,
    children: [
      { index: true, element: <Navigate to="/super-admin/schools" replace /> },
      { path: 'schools', element: <SchoolsPage /> },
      { path: 'schools/new', element: <AddSchoolPage /> },
      { path: 'schools/:id', element: <SchoolPage /> },
      { path: 'plans', element: <PlansPage /> },
    ],
  },
]

// No school selected yet: only registration and the school-code lookup are available.
export const centralRoutes = [
  { path: '/', element: <WelcomePage /> },
  { path: '/register', element: <RegisterSchoolPage /> },
  { path: '/find-school', element: <FindSchoolPage /> },
  { path: '*', element: <Navigate to="/" replace /> },
]

// The legal pages are public (also before a school is chosen), and every page ends with a footer that links to them.
const legalRoutes = [
  { path: '/privacy', element: <LegalPage doc="privacy" /> },
  { path: '/terms', element: <LegalPage doc="terms" /> },
  { path: '/cookies', element: <LegalPage doc="cookies" /> },
]
const withLegal = (list) => [{ element: <LegalShell />, children: [...legalRoutes, ...list] }]

export const routesFor = (school) => withLegal([...superAdminRoutes, ...(school ? routes : centralRoutes)])

export const createRouter = () => createBrowserRouter(routesFor(getSchool()))
