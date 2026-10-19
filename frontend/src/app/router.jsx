import { createBrowserRouter } from 'react-router-dom'
import AdminLayout from '../layouts/AdminLayout'
import DashboardLayout from '../layouts/DashboardLayout'
import LoginPage from '../features/Auth/pages/LoginPage'
import LandingPage from '../features/Auth/pages/LandingPage'
import AcademicYearsPage from '../features/Academic/pages/AcademicYearsPage'
import ClassroomsPage from '../features/Academic/pages/ClassroomsPage'
import GradeLevelsPage from '../features/Academic/pages/GradeLevelsPage'
import OverviewPage from '../features/Academic/pages/OverviewPage'
import SubjectsPage from '../features/Academic/pages/SubjectsPage'
import RoleHome from '../features/Auth/pages/RoleHome'
import { RequireAuth, RequireRole } from './guards'

const ROLES = ['admin', 'teacher', 'student', 'parent']

export const routes = [
  { path: '/', element: <LandingPage /> },
  { path: '/login', element: <LoginPage /> },
  {
    element: <RequireAuth />,
    children: [
      {
        element: <DashboardLayout />,
        children: ROLES.filter((role) => role !== 'admin').map((role) => ({
          path: `/${role}`,
          element: <RequireRole role={role} />,
          children: [{ index: true, element: <RoleHome /> }],
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
            ],
          },
        ],
      },
    ],
  },
]

export const createRouter = () => createBrowserRouter(routes)
