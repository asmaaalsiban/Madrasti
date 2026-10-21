import { Navigate, createBrowserRouter } from 'react-router-dom'
import AdminLayout from '../layouts/AdminLayout'
import DashboardLayout from '../layouts/DashboardLayout'
import ChangePasswordPage from '../features/Auth/pages/ChangePasswordPage'
import LoginPage from '../features/Auth/pages/LoginPage'
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
import StudentsPage from '../features/People/pages/StudentsPage'
import StaffPage from '../features/People/pages/StaffPage'
import ParentHome from '../features/Parent/pages/ParentHome'
import { RequireAuth, RequireRole } from './guards'

const ROLES = ['admin', 'teacher', 'student', 'parent']

// Pages of the non-admin roles (anything not listed shows the placeholder home).
const HOME_ROUTES = {
  parent: [{ index: true, element: <ParentHome /> }, { path: 'children/:id/grades', element: <ChildGradesPage /> }],
  student: [{ index: true, element: <StudentHome /> }],
  teacher: [
    { index: true, element: <TeacherHome /> },
    { path: 'attendance', element: <TakeAttendancePage /> },
    { path: 'attendance/report', element: <AttendanceReportPage /> },
    { path: 'department', element: <DepartmentPage /> },
    { path: 'grades', element: <AssessmentsPage /> },
    { path: 'grades/results', element: <ResultsPage /> },
    { path: 'grades/:id', element: <ScoreSheetPage /> },
  ],
}

export const routes = [
  { path: '/', element: <LandingPage /> },
  { path: '/login', element: <LoginPage /> },
  {
    element: <RequireAuth />,
    children: [
      { path: '/change-password', element: <ChangePasswordPage /> },
      {
        element: <DashboardLayout />,
        children: ROLES.filter((role) => role !== 'admin').map((role) => ({
          path: `/${role}`,
          element: <RequireRole role={role} />,
          children: HOME_ROUTES[role] ?? [{ index: true, element: <RoleHome /> }],
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
              { path: '/admin/terms', element: <TermsPage /> },
              { path: '/admin/teaching', element: <TeachingAssignmentsPage /> },
              { path: '/admin/staff', element: <StaffPage /> },
              { path: '/admin/students', element: <StudentsPage /> },
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
