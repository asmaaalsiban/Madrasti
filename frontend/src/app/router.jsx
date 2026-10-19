import { createBrowserRouter } from 'react-router-dom'
import AdminLayout from '../layouts/AdminLayout'
import DashboardLayout from '../layouts/DashboardLayout'
import LoginPage from '../features/Auth/pages/LoginPage'
import LandingPage from '../features/Auth/pages/LandingPage'
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
            children: [{ path: '/admin', element: <RoleHome /> }],
          },
        ],
      },
    ],
  },
]

export const createRouter = () => createBrowserRouter(routes)
