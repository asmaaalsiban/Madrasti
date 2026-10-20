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
import SubjectsPage from '../features/Academic/pages/SubjectsPage'
import RoleHome from '../features/Auth/pages/RoleHome'
import StudentsPage from '../features/People/pages/StudentsPage'
import StaffPage from '../features/People/pages/StaffPage'
import ParentHome from '../features/Parent/pages/ParentHome'
import { RequireAuth, RequireRole } from './guards'

const ROLES = ['admin', 'teacher', 'student', 'parent']

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
          children: [{ index: true, element: role === 'parent' ? <ParentHome /> : <RoleHome /> }],
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
              { path: '/admin/subjects', element: <SubjectsPage /> },
              { path: '/admin/staff', element: <StaffPage /> },
              { path: '/admin/students', element: <StudentsPage /> },
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
