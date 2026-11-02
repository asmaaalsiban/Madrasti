import { Navigate, createBrowserRouter, useLocation } from 'react-router-dom'
import AdminLayout from '../layouts/AdminLayout'
import DashboardLayout from '../layouts/DashboardLayout'
import ChangePasswordPage from '../features/Auth/pages/ChangePasswordPage'
import LoginPage from '../features/Auth/pages/LoginPage'
import ForgotPasswordPage from '../features/Auth/pages/ForgotPasswordPage'
import LandingPage from '../features/Auth/pages/LandingPage'
import FindSchoolPage from '../features/Central/FindSchoolPage'
import RegisterSchoolPage from '../features/Central/RegisterSchoolPage'
import WelcomePage from '../features/Central/WelcomePage'
import { getSchool } from '../tenant/school'
import AcademicYearsPage from '../features/Academic/pages/AcademicYearsPage'
import ClassroomsPage from '../features/Academic/pages/ClassroomsPage'
import GradeLevelsPage from '../features/Academic/pages/GradeLevelsPage'
import OverviewPage from '../features/Academic/pages/OverviewPage'
import TeachingAssignmentsPage from '../features/Academic/pages/TeachingAssignmentsPage'
import TermsPage from '../features/Academic/pages/TermsPage'
import SubjectsPage from '../features/Academic/pages/SubjectsPage'
import RoleHome from '../features/Auth/pages/RoleHome'
import AttendanceReportPage from '../features/Attendance/pages/AttendanceReportPage'
import TakeAttendancePage from '../features/Attendance/pages/TakeAttendancePage'
import DepartmentPage from '../features/Teacher/pages/DepartmentPage'
import TeacherHome from '../features/Teacher/pages/TeacherHome'
import AssessmentsPage from '../features/Grading/pages/AssessmentsPage'
import ResultsPage from '../features/Grading/pages/ResultsPage'
import ScoreSheetPage from '../features/Grading/pages/ScoreSheetPage'
import ChildGradesPage from '../features/Parent/pages/ChildGradesPage'
import StudentHome from '../features/Student/pages/StudentHome'
import BuildingsPage from '../features/Academic/pages/BuildingsPage'
import GenerateClassroomsPage from '../features/Academic/pages/GenerateClassroomsPage'
import CurriculumPage from '../features/Academic/pages/CurriculumPage'
import SchoolDayPage from '../features/Scheduling/pages/SchoolDayPage'
import TeacherAvailabilityPage from '../features/Scheduling/pages/TeacherAvailabilityPage'
import TimetablePage from '../features/Scheduling/pages/TimetablePage'
import StudentTimetablePage from '../features/Student/pages/StudentTimetablePage'
import TeacherTimetablePage from '../features/Teacher/pages/TeacherTimetablePage'
import ChildTimetablePage from '../features/Parent/pages/ChildTimetablePage'
import StudentsPage from '../features/People/pages/StudentsPage'
import StaffPage from '../features/People/pages/StaffPage'
import BehaviorPage from '../features/Behavior/pages/BehaviorPage'
import BehaviorCriteriaPage from '../features/Behavior/pages/BehaviorCriteriaPage'
import StudentBehaviorPage, { MyBehaviorPage } from '../features/Behavior/pages/StudentBehaviorPage'
import ImportPage from '../features/Import/pages/ImportPage'
import SignaturesPage from '../features/Reports/pages/SignaturesPage'
import ReportCardPage, { MyReportCardPage } from '../features/Reports/pages/ReportCardPage'
import MySurveysPage from '../features/Surveys/pages/MySurveysPage'
import SurveyResponsesPage from '../features/Surveys/pages/SurveyResponsesPage'
import SurveyResultsPage from '../features/Surveys/pages/SurveyResultsPage'
import SurveysPage from '../features/Surveys/pages/SurveysPage'
import MotivationPage from '../features/Motivation/pages/MotivationPage'
import MotivationSettingsPage from '../features/Motivation/pages/MotivationSettingsPage'
import StudentMotivationPage, { MyMotivationPage } from '../features/Motivation/pages/StudentMotivationPage'
import HomeworkPage from '../features/Homework/pages/HomeworkPage'
import StudentHomeworkPage from '../features/Homework/pages/StudentHomeworkPage'
import ChildHomeworkPage from '../features/Homework/pages/ChildHomeworkPage'
import DailySummaryPage from '../features/DailySummary/pages/DailySummaryPage'
import { ChildSummariesPage, StudentSummariesPage } from '../features/DailySummary/pages/SummariesPages'
import LessonPlansPage from '../features/LessonPlans/pages/LessonPlansPage'
import ReviewPlansPage from '../features/LessonPlans/pages/ReviewPlansPage'
import { ChildPlansPage, StudentPlansPage } from '../features/LessonPlans/pages/PlansPages'
import AnnouncementsPage from '../features/Announcements/pages/AnnouncementsPage'
import FinanceDashboardPage from '../features/Finance/pages/FinanceDashboardPage'
import FeeStructuresPage from '../features/Finance/pages/FeeStructuresPage'
import AccountsPage from '../features/Finance/pages/AccountsPage'
import StudentAccountPage from '../features/Finance/pages/StudentAccountPage'
import ChildFeesPage from '../features/Finance/pages/ChildFeesPage'
import PayrollPage from '../features/Payroll/pages/PayrollPage'
import PayrollRunPage from '../features/Payroll/pages/PayrollRunPage'
import PayslipPage from '../features/Payroll/pages/PayslipPage'
import MyPayslipsPage from '../features/Payroll/pages/MyPayslipsPage'
import ContractsRoute from '../features/Payroll/pages/ContractsRoute'
import ChatPage from '../features/Chat/pages/ChatPage'
import ParentHome from '../features/Parent/pages/ParentHome'
import { useAuth } from '../features/Auth/AuthProvider'
import { RequireAuth, RequireRole } from './guards'

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
    { path: 'children/:id/homework', element: <ChildHomeworkPage /> },
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
    { path: 'grades', element: <StudentHome /> },
    { path: 'homework', element: <StudentHomeworkPage /> },
    { path: 'behavior', element: <MyBehaviorPage /> },
    { path: 'motivation', element: <MyMotivationPage /> },
    { path: 'report-card', element: <MyReportCardPage /> },
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
    { path: 'daily-summary', element: <DailySummaryPage /> },
    { path: 'lesson-plans', element: <LessonPlansPage /> },
    { path: 'lesson-plans/review', element: <ReviewPlansPage /> },
    { path: 'finance', element: <FinanceDashboardPage base="/teacher" /> },
    { path: 'finance/structures', element: <FeeStructuresPage /> },
    { path: 'finance/accounts', element: <AccountsPage base="/teacher" /> },
    { path: 'finance/students/:id', element: <StudentAccountPage /> },
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
  { path: '/forgot-password', element: <ForgotPasswordPage /> },
  { path: '/chat', element: <ChatRedirect /> },
  {
    element: <RequireAuth />,
    children: [
      { path: '/change-password', element: <ChangePasswordPage /> },
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
              { path: '/admin/academic-years', element: <AcademicYearsPage /> },
              { path: '/admin/grade-levels', element: <GradeLevelsPage /> },
              { path: '/admin/classrooms', element: <ClassroomsPage /> },
              { path: '/admin/classrooms/generate', element: <GenerateClassroomsPage /> },
              { path: '/admin/subjects', element: <SubjectsPage /> },
              { path: '/admin/buildings', element: <BuildingsPage /> },
              { path: '/admin/curriculum', element: <CurriculumPage /> },
              { path: '/admin/school-day', element: <SchoolDayPage /> },
              { path: '/admin/timetable', element: <TimetablePage /> },
              { path: '/admin/availability', element: <TeacherAvailabilityPage /> },
              { path: '/admin/terms', element: <TermsPage /> },
              { path: '/admin/teaching', element: <TeachingAssignmentsPage /> },
              { path: '/admin/staff', element: <StaffPage /> },
              { path: '/admin/students', element: <StudentsPage /> },
              { path: '/admin/announcements', element: <AnnouncementsPage /> },
              { path: '/admin/finance', element: <FinanceDashboardPage base="/admin" /> },
              { path: '/admin/finance/structures', element: <FeeStructuresPage /> },
              { path: '/admin/finance/accounts', element: <AccountsPage base="/admin" /> },
              { path: '/admin/finance/students/:id', element: <StudentAccountPage /> },
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
]

// No school selected yet: only registration and the school-code lookup are available.
export const centralRoutes = [
  { path: '/', element: <WelcomePage /> },
  { path: '/register', element: <RegisterSchoolPage /> },
  { path: '/find-school', element: <FindSchoolPage /> },
  { path: '*', element: <Navigate to="/" replace /> },
]

export const routesFor = (school) => (school ? routes : centralRoutes)

export const createRouter = () => createBrowserRouter(routesFor(getSchool()))
